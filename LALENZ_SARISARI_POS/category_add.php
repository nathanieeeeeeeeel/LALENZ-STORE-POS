<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/action_log.php';


// =========================================================
// AUTHENTICATION
// =========================================================

$userId = fetchUser();


// =========================================================
// GET CURRENT USER
// =========================================================

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {

    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}


// =========================================================
// USER ROLE
// =========================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !hasAppPermission($pdo, $userId, 'categories.manage')
) {
    appLogAction($pdo, $userId, 'category.created', 'category', null, trim((string) ($_POST['name'] ?? '')) ?: null, 'failure', [
        'reason' => 'Permission denied.',
    ]);
}
requireAppPermission($pdo, $userId, 'categories.manage');
ensureActionLogTable($pdo);


// =========================================================
// FORM VALUES
// =========================================================

$name = '';

$errors = [];


// =========================================================
// HANDLE FORM SUBMISSION
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // -----------------------------------------------------
    // Get submitted name
    // -----------------------------------------------------

    $name = trim($_POST['name'] ?? '');


    // =====================================================
    // VALIDATION
    // =====================================================

    if ($name === '') {

        $errors[] = 'Category name is required.';

    } elseif (mb_strlen($name) > 100) {

        $errors[] = 'Category name cannot exceed 100 characters.';
    }


    // =====================================================
    // CHECK DUPLICATE CATEGORY NAME
    // =====================================================

    if (empty($errors)) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM categories
            WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
            LIMIT 1
        ");

        $stmt->execute([$name]);

        if ($stmt->fetch()) {

            $errors[] =
                'A category with this name already exists.';
        }
    }


    // =====================================================
    // CREATE CATEGORY
    // =====================================================

    if (empty($errors)) {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO categories (name)
                VALUES (?)
            ");

            $pdo->beginTransaction();
            $stmt->execute([$name]);

            $categoryId = (int) $pdo->lastInsertId();
            appLogAction($pdo, $userId, 'category.created', 'category', $categoryId, $name, 'success');
            $pdo->commit();

            // -------------------------------------------------
            // Redirect after successful creation
            // -------------------------------------------------

            header('Location: categories.php');
            exit;


        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getCode() === '23000') {

                $errors[] =
                    'A category with this name already exists.';

            } else {

                $errors[] =
                    'Unable to create the category. Please try again.';

                // DEVELOPMENT ONLY:
                // $errors[] = $e->getMessage();
            }
        }
    }

    if ($errors) {
        appLogAction($pdo, $userId, 'category.created', 'category', null, $name !== '' ? $name : null, 'failure', [
            'errors' => $errors,
        ]);
    }
}


// =========================================================
// CURRENT USER DISPLAY
// =========================================================

$fullName =
    $user['full_name']
    ?? $user['name']
    ?? $user['username']
    ?? 'User';

$currentPage = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Add Category | <?= htmlspecialchars($systemName) ?>
</title>

<meta
    name="description"
    content="Add a new product category"
>

<link
    rel="icon"
    href="<?= htmlspecialchars($logo) ?>"
    type="image/x-icon"
/>

<script src="https://cdn.tailwindcss.com"></script>

<script>

tailwind.config = {
    darkMode: 'class',
    theme: {

        extend: {

            colors: {

                brand: {

                    50: "#f0fdf4",
                    100: "#dcfce7",
                    200: "#bbf7d0",
                    300: "#86efac",
                    400: "#4ade80",
                    500: "#22c55e",
                    600: "#16a34a",
                    700: "#15803d",
                    800: "#166534",
                    900: "#14532d"

                }

            }

        }

    }

};

</script>
<script>
    (function () {
        const savedTheme = localStorage.getItem('lalenz_theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.documentElement.classList.add('dark');
        }
    })();
</script>

<style>

body {

    font-family:
        Inter,
        ui-sans-serif,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;

}

.background-grid {

    background-image:

        linear-gradient(
            rgba(34, 197, 94, 0.045) 1px,
            transparent 1px
        ),

        linear-gradient(
            90deg,
            rgba(34, 197, 94, 0.045) 1px,
            transparent 1px
        );

    background-size: 42px 42px;

}

html.dark body {
    background-color: #020817;
    color: #e2e8f0;
}

html.dark aside,
html.dark header,
html.dark .bg-white {
    background-color: #0f172a !important;
}

html.dark aside,
html.dark header,
html.dark .border-slate-200,
html.dark .border-slate-100 {
    border-color: #334155 !important;
}

html.dark aside a {
    color: #cbd5e1;
}

html.dark aside a:hover {
    background-color: #1e293b;
    color: #4ade80;
}

html.dark aside a.bg-green-50 {
    background-color: #14532d;
    color: #bbf7d0;
}

html.dark .bg-slate-50 {
    background-color: #111827 !important;
}

html.dark .text-slate-900,
html.dark .text-slate-800,
html.dark .text-slate-700 {
    color: #e2e8f0 !important;
}

html.dark .text-slate-600,
html.dark .text-slate-500 {
    color: #cbd5e1 !important;
}

html.dark .text-slate-400 {
    color: #94a3b8 !important;
}

html.dark input,
html.dark select,
html.dark textarea {
    background-color: #020817;
    border-color: #334155;
    color: #f8fafc;
}

html.dark .bg-green-50,
html.dark .bg-green-100 {
    background-color: #052e16;
}

html.dark .bg-blue-50 {
    background-color: #172554;
}

html.dark .bg-red-50 {
    background-color: #450a0a;
}

html.dark .text-green-700,
html.dark .text-green-800 {
    color: #86efac;
}

html.dark .text-blue-700,
html.dark .text-blue-800 {
    color: #93c5fd;
}

html.dark .text-red-700,
html.dark .text-red-800 {
    color: #fca5a5;
}

</style>

</head>


<body class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">


<div
    class="fixed inset-0 background-grid pointer-events-none"
></div>

<div
    class="fixed -top-40 -right-40 w-[500px] h-[500px] bg-green-200/30 rounded-full blur-3xl pointer-events-none"
></div>

<div
    class="fixed -bottom-40 -left-40 w-[500px] h-[500px] bg-emerald-100/40 rounded-full blur-3xl pointer-events-none"
></div>


<div class="relative min-h-screen flex">


<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="flex-1 lg:ml-64">


<header
    class="h-20 bg-white/90 backdrop-blur border-b border-slate-200 sticky top-0 z-30"
>

    <div
        class="h-full px-4 sm:px-6 lg:px-8 flex items-center justify-between"
    >

        <div>

            <div class="flex items-center gap-2">

                <a
                    href="/LALENZ_SARISARI_POS/categories.php"
                    class="text-slate-400 hover:text-green-600 transition"
                >
                    Categories
                </a>

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="w-4 h-4 text-slate-300"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 5l7 7-7 7"
                    />

                </svg>

                <span class="font-semibold text-slate-800">
                    Add Category
                </span>

            </div>

        </div>


        <div class="flex items-center gap-3">

        </div>

    </div>

</header>


<div
    class="p-4 sm:p-6 lg:p-8 max-w-4xl mx-auto"
>


<div class="mb-8">

    <div class="flex items-center gap-3">

        <div
            class="w-12 h-12 rounded-2xl bg-green-100 text-green-700 flex items-center justify-center"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-6 h-6"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M4 6h7v7H4V6zm9 0h7v7h-7V6zM4 15h7v3H4v-3zm9 0h7v3h-7v-3z"
                />

            </svg>

        </div>


        <div>

            <h1
                class="text-2xl sm:text-3xl font-black tracking-tight"
            >
                Add Category
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                Create a new category for your products.
            </p>

        </div>

    </div>

</div>


<?php if (!empty($errors)): ?>

<div
    class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4"
>

    <div class="flex items-start gap-3">

        <div
            class="w-9 h-9 rounded-xl bg-red-100 text-red-600 flex items-center justify-center flex-shrink-0"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 9v3m0 4h.01M10.29 3.86l-8.82 15a2 2 0 001.71 3h17.64a2 2 0 001.71-3l-8.82-15a2 2 0 00-3.42 0z"
                />

            </svg>

        </div>


        <div>

            <p class="font-bold text-red-800">
                Please fix the following:
            </p>

            <ul
                class="mt-2 space-y-1 text-sm text-red-700 list-disc list-inside"
            >

                <?php foreach ($errors as $error): ?>

                    <li>
                        <?= htmlspecialchars($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    </div>

</div>

<?php endif; ?>


<form
    action="category_add.php"
    method="POST"
    class="space-y-6"
>


<div
    class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden"
>

    <div
        class="px-6 py-5 border-b border-slate-100"
    >

        <h2 class="text-lg font-black text-slate-900">
            Category Information
        </h2>

        <p class="text-sm text-slate-500 mt-1">
            Enter the name of the new product category.
        </p>

    </div>


    <div class="p-6 space-y-5">


        <div>

            <label
                for="name"
                class="block text-sm font-bold text-slate-700 mb-2"
            >

                Category Name

                <span class="text-red-500">
                    *
                </span>

            </label>


            <input
                type="text"
                id="name"
                name="name"
                value="<?= htmlspecialchars($name) ?>"
                maxlength="100"
                required
                autofocus
                placeholder="e.g. Beverages"
                class="w-full h-12 px-4 rounded-xl border border-slate-200 bg-white text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition"
            >


            <p class="mt-1.5 text-xs text-slate-400">
                Use a clear name that describes the products in this category.
            </p>

        </div>


        <div
            class="rounded-2xl bg-slate-50 border border-slate-200 p-4"
        >

            <div class="flex items-start gap-3">

                <div
                    class="w-10 h-10 rounded-xl bg-green-100 text-green-700 flex items-center justify-center flex-shrink-0"
                >

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="w-5 h-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.8"
                            d="M4 6h7v7H4V6zm9 0h7v7h-7V6z"
                        />

                    </svg>

                </div>


                <div>

                    <p class="text-sm font-bold text-slate-700">
                        New Category
                    </p>

                    <p class="text-xs text-slate-400 mt-1">
                        Products can be assigned to this category after it has been created.
                    </p>

                </div>

            </div>

        </div>


    </div>

</div>

<div
    class="rounded-2xl border border-blue-200 bg-blue-50 p-4"
>

    <div class="flex items-start gap-3">

        <div
            class="w-9 h-9 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center flex-shrink-0"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M13 16h-1v-4h-1m1-4h.01M12 21a9 9 0 100-18 9 9 0 000 18z"
                />

            </svg>

        </div>


        <div>

            <p class="font-bold text-blue-800">
                Category information
            </p>

            <p class="text-sm text-blue-700 mt-1">
                Make sure the category name is clear and unique. Products can be assigned to this category when adding or editing products.
            </p>

        </div>

    </div>

</div>


<div
    class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3"
>


    <a
        href="/LALENZ_SARISARI_POS/categories.php"
        class="inline-flex items-center justify-center gap-2 h-12 px-6 rounded-xl border border-slate-200 bg-white text-slate-700 font-bold text-sm hover:bg-slate-50 transition"
    >

        <svg
            xmlns="http://www.w3.org/2000/svg"
            class="w-5 h-5"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
        >

            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M6 18L18 6M6 6l12 12"
            />

        </svg>

        Cancel

    </a>


    <button
        type="submit"
        class="inline-flex items-center justify-center gap-2 h-12 px-7 rounded-xl bg-green-600 hover:bg-green-700 active:bg-green-800 text-white font-bold text-sm shadow-lg shadow-green-600/20 transition"
    >

        <svg
            xmlns="http://www.w3.org/2000/svg"
            class="w-5 h-5"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
        >

            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 5v14M5 12h14"
            />

        </svg>

        Add Category

    </button>

</div>


</form>

</div>

</main>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
