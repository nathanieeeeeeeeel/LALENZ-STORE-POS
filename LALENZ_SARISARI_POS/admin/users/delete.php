<?php

require_once __DIR__ . '/_bootstrap.php';
$adminPageTitle = 'Permanently Delete User';
$adminPageDescription = 'Permanently remove a user account after confirming the action.';
$adminUsersNotice = '';
$adminUsersError = '';
$targetId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$targetId || $targetId < 1) {
    http_response_code(400);
    exit('A valid user ID is required.');
}

$targetUser = adminUserFind($pdo, (int) $targetId);
if (!$targetUser) {
    http_response_code(404);
    exit('User account not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($adminUsersCsrfToken, $postedToken)) {
        $adminUsersError = 'Your session could not be verified. Refresh the page and try again.';
    } elseif (($_POST['confirm_delete'] ?? '') !== 'yes') {
        $adminUsersError = 'Confirm that you understand this deletion is permanent.';
    } elseif ((int) $targetId === $adminUserId) {
        $adminUsersError = 'You cannot permanently delete your own account.';
    } else {
        try {
            $pdo->beginTransaction();
            $lockedUser = adminUserFind($pdo, (int) $targetId, true);
            if (!$lockedUser) {
                throw new RuntimeException('User account not found.');
            }
            if (strtolower((string) $lockedUser['role']) === 'admin' && !$adminActorIsAdmin) {
                throw new RuntimeException('Only an administrator can permanently delete an administrator account.');
            }

            $activeAdminIds = adminUserActiveAdmins($pdo, true);
            if (
                (int) $lockedUser['is_active'] === 1
                && strtolower((string) $lockedUser['role']) === 'admin'
                && count($activeAdminIds) <= 1
            ) {
                throw new RuntimeException('The last active administrator cannot be permanently deleted.');
            }

            adminUserDetachHistory($pdo, 'sales', (int) $targetId);
            adminUserDetachHistory($pdo, 'expenses', (int) $targetId);

            $deleteOverrides = $pdo->prepare('DELETE FROM app_user_permissions WHERE user_id = ?');
            $deleteOverrides->execute([(int) $targetId]);
            $delete = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $delete->execute([(int) $targetId]);
            $pdo->commit();

            $_SESSION['admin_users_flash'] = 'User account permanently deleted. Sales and expense records were retained.';
            header('Location: index.php');
            exit;
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $adminUsersError = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Unable to permanently delete user account: ' . $exception->getMessage());
            $adminUsersError = 'The account could not be deleted because of a database constraint. Deactivate it instead, or check the application error log.';
        }
    }
}

require __DIR__ . '/_header.php';
?>
        <section class="max-w-3xl rounded-2xl border border-red-200 bg-white p-5 shadow-sm dark:border-red-900 dark:bg-slate-900 sm:p-7">
            <h2 class="text-lg font-black text-red-800 dark:text-red-300">Delete <?= adminUserEscape((string) ($targetUser['full_name'] ?: $targetUser['username'])) ?> permanently?</h2>
            <p class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
                This permanently removes the account and cannot be undone. Existing sales and expense entries are retained; their user attribution will be cleared when the database permits it. If their history cannot be safely detached, deletion will be blocked and you can deactivate the account instead.
            </p>
            <form method="POST" class="mt-6 space-y-5">
                <input type="hidden" name="csrf_token" value="<?= adminUserEscape($adminUsersCsrfToken) ?>">
                <label class="flex items-start gap-3 text-sm font-semibold text-slate-700 dark:text-slate-200">
                    <input type="checkbox" name="confirm_delete" value="yes" required class="mt-1 accent-red-600">
                    <span>I understand this account will be permanently deleted.</span>
                </label>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="rounded-xl bg-red-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-red-800">Permanently Delete</button>
                    <a href="index.php" class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-700 dark:border-slate-700 dark:text-slate-200">Cancel</a>
                </div>
            </form>
        </section>
<?php require __DIR__ . '/_footer.php'; ?>
