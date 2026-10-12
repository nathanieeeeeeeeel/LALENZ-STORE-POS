<?php
/*
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
| This sidebar includes its own mobile toggle functionality.
| No Alpine.js is required.
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../config/permissions.php';
include __DIR__ . '/page_transition.php';
?>

<script>
    try {
        if (localStorage.getItem('lalenz_sidebar_collapsed') === 'true') {
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (error) {
        // Keep the sidebar usable when browser storage is unavailable.
    }
</script>
<style>
    @media (min-width: 1024px) {
        body.sidebar-collapsed #mainSidebar:not(.sidebar-layout-initialized) {
            width: 5rem !important;
        }

        body.sidebar-collapsed [class~="lg:ml-64"]:not(.sidebar-layout-initialized):not(.sidebar-tailwind-safelist) {
            margin-left: 5rem !important;
        }

        body.sidebar-collapsed #sidebarBrandHeader {
            height: auto;
            flex-direction: column;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 0.5rem;
        }

        body.sidebar-collapsed #sidebarBrandHeader > a {
            justify-content: center;
        }

        body.sidebar-collapsed #sidebarBrandHeader > a > div:last-child,
        body.sidebar-collapsed #mainSidebar nav a > span,
        body.sidebar-collapsed #mainSidebar > div:last-child > div > div:last-child,
        body.sidebar-collapsed #mainSidebar > div:last-child > a > span {
            display: none !important;
        }

        body.sidebar-collapsed #mainSidebar nav {
            padding: 0.5rem;
        }

        body.sidebar-collapsed #mainSidebar nav a,
        body.sidebar-collapsed #mainSidebar > div:last-child > a {
            justify-content: center;
            gap: 0;
            padding-left: 0;
            padding-right: 0;
        }

        body.sidebar-collapsed #mainSidebar > div:last-child {
            padding: 0.5rem;
        }

        body.sidebar-collapsed #mainSidebar > div:last-child > div {
            justify-content: center;
            padding-left: 0;
            padding-right: 0;
        }

    }

    @media (prefers-reduced-motion: reduce) {
        #mainSidebar,
        [class~="lg:ml-64"],
        [class~="lg:ml-20"] {
            transition: none !important;
        }
    }
</style>

<!-- MOBILE MENU BUTTON -->
<button
    id="mobileSidebarToggle"
    type="button"
    aria-label="Open navigation menu"
    aria-controls="mainSidebar"
    aria-expanded="false"
        class="lg:hidden
            w-10 h-10 shrink-0
           flex items-center justify-center
           rounded-xl
           bg-white
           border border-slate-200
           text-slate-700
           hover:bg-slate-50
           hover:text-green-600
           transition
           dark:bg-slate-900
           dark:border-slate-700
           dark:text-slate-100
           dark:hover:bg-slate-800
           dark:hover:text-green-400"
>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        class="w-6 h-6"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2"
    >
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M4 6h16M4 12h16M4 18h16"
        />
    </svg>
</button>


<!-- ==========================================================
     SIDEBAR
=========================================================== -->

<aside
    id="mainSidebar"
    class="fixed inset-y-0 left-0 z-50
           w-64
           bg-white
           border-r border-slate-200
           flex flex-col
           -translate-x-full
           lg:translate-x-0
           transition-[width,transform] duration-300 ease-in-out
           dark:bg-slate-900
           dark:border-slate-700"
>
    <span class="hidden w-20 lg:ml-20 sidebar-tailwind-safelist" aria-hidden="true"></span>

    <!-- ======================================================
         LOGO / BRAND
    ======================================================= -->

    <div
        id="sidebarBrandHeader"
        class="h-20
               flex items-center justify-between
               px-6
               border-b border-slate-100
               shrink-0
               dark:border-slate-700"
    >

        <a
            href="/LALENZ_SARISARI_POS/dashboard.php"
            data-tippy-content="<?= htmlspecialchars($systemName ?? 'Home', ENT_QUOTES, 'UTF-8') ?>"
            class="flex items-center gap-3"
        >

            <!-- LOGO -->

            <div
                class="w-11 h-11
                       rounded-2xl
                       bg-white
                       flex items-center justify-center
                       overflow-hidden
                       shrink-0
                       dark:bg-slate-800"
            >

                <img
                    src="<?= htmlspecialchars($logo ?? '') ?>"
                    alt="LALENZ Logo"
                    class="w-11 h-11 object-contain"
                >

            </div>


            <!-- BRAND NAME -->

            <div>

                <div
                    class="font-black
                           text-lg
                           leading-none
                           text-slate-900
                           dark:text-slate-100"
                >
                    <?= htmlspecialchars($systemName ?? '') ?>
                </div>

                <div
                    class="text-xs
                           text-slate-500
                           mt-1
                           dark:text-slate-400"
                >
                    <?= htmlspecialchars($systemTagline ?? '') ?>
                </div>

            </div>

        </a>

        <button
            id="sidebarCollapseToggle"
            type="button"
            data-tippy-content="Collapse sidebar"
            aria-label="Collapse sidebar"
            aria-pressed="false"
            class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-green-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-300 lg:inline-flex"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16v14H4zM9 5v14m5-9 3 2-3 2" />
            </svg>
        </button>

        <!-- MOBILE CLOSE BUTTON -->

        <button
            id="mobileSidebarClose"
            type="button"
            aria-label="Close navigation menu"
            class="lg:hidden
                   w-9 h-9
                   flex items-center justify-center
                   rounded-lg
                   text-slate-400
                   hover:bg-slate-100
                   hover:text-slate-700
                   transition
                   dark:text-slate-300
                   dark:hover:bg-slate-800
                   dark:hover:text-slate-100"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6 18L18 6M6 6l12 12"
                />

            </svg>

        </button>

    </div>


    <!-- ======================================================
         NAVIGATION
    ======================================================= -->

    <nav
        class="flex-1
               p-4
               space-y-1
               overflow-y-auto"
    >
        <?php
        $sidebarFullName = $fullName ?? $username ?? 'User';
        $sidebarUserRole = $user_role ?? $userRole ?? ($user['role'] ?? ($_SESSION['role'] ?? 'Staff'));
        $sidebarRoleLabel = appRoleLabel($pdo, (string) $sidebarUserRole);
        $sidebarPermissionUserId = isset($userId) ? (int) $userId : fetchUser();
        ?>

        <!-- DASHBOARD -->
        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'dashboard.view')): ?>

        <a
            href="/LALENZ_SARISARI_POS/dashboard.php"
            data-tippy-content="Dashboard"
            class="<?= $currentPage === 'dashboard.php'
                ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
            ?>"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M3 13h8V3H3v10zm10 8h8V3h-8v18zM3 21h8v-6H3v6z"
                />

            </svg>

            <span>
                Dashboard
            </span>

        </a>
        <?php endif; ?>


        <!-- PRODUCTS -->
        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'products.view')): ?>

        <a
            href="/LALENZ_SARISARI_POS/products.php"
            data-tippy-content="Products"
            class="<?= $currentPage === 'products.php'
                ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
            ?>"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M4 7h16M4 7l2 13h12l2-13M9 7V5a3 3 0 016 0v2"
                />

            </svg>

            <span>
                Products
            </span>

        </a>
        <?php endif; ?>


        <!-- CATEGORIES -->
        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'categories.view')): ?>

        <a
            href="/LALENZ_SARISARI_POS/categories.php"
            data-tippy-content="Categories"
            class="<?= $currentPage === 'categories.php'
                ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
            ?>"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5 shrink-0"
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

            <span>
                Categories
            </span>

        </a>
        <?php endif; ?>


        <!-- SALES -->
        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'sales.view')): ?>

        <a
            href="/LALENZ_SARISARI_POS/sales.php"
            data-tippy-content="Sales"
            class="<?= $currentPage === 'sales.php'
                ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
            ?>"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M4 19V5m0 14h16M8 16v-5m4 5V7m4 9v-8"
                />

            </svg>

            <span>
                Sales
            </span>

        </a>
        <?php endif; ?>


        <!-- ITEM SUMMARY -->
        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'item_summary.view')): ?>

        <a
            href="/LALENZ_SARISARI_POS/item-summary.php"
            data-tippy-content="Item Summary"
            class="<?= $currentPage === 'item-summary.php'
                ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
            ?>"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2
                    M9 5a2 2 0 002 2h2a2 2 0 002-2
                    M9 5a2 2 0 012-2h2a2 2 0 012 2
                    m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"
                />

            </svg>

            <span>
                Item Summary
            </span>

        </a>
        <?php endif; ?>


        <!-- EXPENSES -->
        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'expenses.view')): ?>

        <a
            href="/LALENZ_SARISARI_POS/expenses.php"
            data-tippy-content="Expenses"
            class="<?= $currentPage === 'expenses.php'
                ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
            ?>"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M12 8v8m-4-4h8M5 5h14v14H5z"
                />
            </svg>

            <span>Expenses</span>

        </a>
        <?php endif; ?>

        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'admin.view')): ?>
            <!-- ADMIN PANEL -->
            <a
                href="/LALENZ_SARISARI_POS/admin.php"
                data-tippy-content="Admin Panel"
                class="<?= $currentPage === 'admin.php'
                    ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                    : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
                ?>"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="w-5 h-5 shrink-0"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l2.2 1.1 2.4-.2 1.1 2.2 2.1 1.2-.5 2.4 1.1 2.1-1.7 1.7-.2 2.4-2.4.5-1.2 2.1-2.4-.5L10 21l-1.7-1.7-2.4-.2-.5-2.4-2.1-1.2.5-2.4L2.7 11l1.7-1.7.2-2.4L7 6.4l1.2-2.1 2.4.5L12 3z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
                <span>Admin Panel</span>
            </a>
            <a
                href="/LALENZ_SARISARI_POS/admin/action-logs.php"
                data-tippy-content="Action Logs"
                class="<?= $currentPage === 'action-logs.php'
                    ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                    : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
                ?>"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
                </svg>
                <span>Action Logs</span>
            </a>
        <?php endif; ?>

        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'users.manage')): ?>
            <a
                href="/LALENZ_SARISARI_POS/admin/users/index.php"
                data-tippy-content="Manage Users"
                class="flex items-center gap-3 rounded-xl px-4 py-3 text-slate-600 transition hover:bg-slate-100 hover:text-green-600 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m6-10a4 4 0 100-8 4 4 0 000 8zm14 10v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" />
                </svg>
                <span>Manage Users</span>
            </a>
        <?php endif; ?>

        <!-- SETTINGS -->
        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'settings.view')): ?>

        <a
            href="/LALENZ_SARISARI_POS/settings.php"
            data-tippy-content="Settings"
            class="<?= $currentPage === 'settings.php'
                ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
            ?>"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M12 8a4 4 0 100 8 4 4 0 000-8zm8 4a8 8 0 01-.12 1.38l1.45 1.13-1.5 2.6-1.75-.7a8 8 0 01-2.4 1.39L15.4 19.7h-3l-.28-1.9a8 8 0 01-2.4-1.39l-1.75.7-1.5-2.6 1.45-1.13a8 8 0 010-2.76L6.47 9.49l1.5-2.6 1.75.7a8 8 0 012.4-1.39L12.4 4.3h3l.28 1.9a8 8 0 012.4 1.39l1.75-.7 1.5 2.6-1.45 1.13A8 8 0 0120 12z"
                />
            </svg>
            <span>Settings</span>
        </a>
        <?php endif; ?>

        <!-- REPORTS -->
        <?php if (hasAppPermission($pdo, $sidebarPermissionUserId, 'reports.view')): ?>

        <a
            href="/LALENZ_SARISARI_POS/reports.php"
            data-tippy-content="Reports"
            class="<?= $currentPage === 'reports.php'
                ? 'flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-bold transition dark:bg-slate-800 dark:text-green-300'
                : 'flex items-center gap-3 px-4 py-3 rounded-xl text-slate-600 hover:bg-slate-100 hover:text-green-600 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-green-400'
            ?>"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M6 3h12a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V5a2 2 0 012-2z"
                />

                <path
                    stroke-linecap="round"
                    stroke-width="1.8"
                    d="M8 8h8M8 12h8M8 16h5"
                />

            </svg>

            <span>
                Reports
            </span>

        </a>
        <?php endif; ?>

    </nav>


    <!-- ======================================================
         LOGOUT
    ======================================================= -->

    <div
        class="p-4
               border-t
               border-slate-100
               shrink-0
               space-y-3"
    >

        <div
            class="flex min-w-0 items-center gap-3 rounded-xl bg-slate-50 px-3 py-3 dark:bg-slate-800"
            data-tippy-content="<?= htmlspecialchars($sidebarFullName . ' · ' . $sidebarRoleLabel, ENT_QUOTES, 'UTF-8') ?>"
        >
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-100 font-black text-green-700 dark:bg-green-900/40 dark:text-green-300">
                <?= htmlspecialchars(strtoupper(substr((string) $sidebarFullName, 0, 1))) ?>
            </div>
            <div class="min-w-0">
                <p class="truncate text-sm font-bold text-slate-800 dark:text-slate-100"><?= htmlspecialchars((string) $sidebarFullName) ?></p>
                <p class="truncate text-xs text-slate-500 dark:text-slate-400"><?= htmlspecialchars($sidebarRoleLabel) ?></p>
            </div>
        </div>

        <a
            href="/LALENZ_SARISARI_POS/api/logout.php"
            data-tippy-content="Logout"
            class="flex items-center gap-3
                   px-4 py-3
                   rounded-xl
                   text-red-600
                   hover:bg-red-100
                   hover:text-red-700
                   transition
                   font-semibold
                   dark:hover:bg-red-900/30
                   dark:hover:text-red-300"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-5 h-5 shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M10 17l5-5-5-5M15 12H3"
                />

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M21 19V5a2 2 0 00-2-2h-5"
                />

            </svg>

            <span>
                Logout
            </span>

        </a>

    </div>

</aside>

<style>
    @import url('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css');

    body.dark {
        background-color: #020817;
        color: #e2e8f0;
    }

    body.dark .bg-slate-50 {
        background-color: #020817 !important;
    }

    body.dark .bg-white {
        background-color: #0f172a !important;
    }

    body.dark .bg-slate-100 {
        background-color: #111827 !important;
    }

    body.dark .bg-green-50,
    body.dark .bg-green-100,
    body.dark .bg-blue-50,
    body.dark .bg-blue-100,
    body.dark .bg-orange-50,
    body.dark .bg-orange-100,
    body.dark .bg-purple-50,
    body.dark .bg-purple-100,
    body.dark .bg-red-50 {
        filter: brightness(1.15);
    }

    body.dark .border-slate-200,
    body.dark .border-slate-100,
    body.dark .border-slate-300 {
        border-color: #334155 !important;
    }

    body.dark .text-slate-900 { color: #f8fafc !important; }
    body.dark .text-slate-800 { color: #e2e8f0 !important; }
    body.dark .text-slate-700 { color: #cbd5e1 !important; }
    body.dark .text-slate-600 { color: #cbd5e1 !important; }
    body.dark .text-slate-500 { color: #94a3b8 !important; }
    body.dark .text-slate-400 { color: #94a3b8 !important; }

    body.dark .shadow-sm,
    body.dark .shadow-lg,
    body.dark .shadow-xl {
        box-shadow: none !important;
    }

    body.dark table {
        border-color: #334155 !important;
    }

    body.dark thead,
    body.dark table thead {
        background-color: #0f172a !important;
    }

    body.dark table thead th,
    body.dark table th,
    body.dark table td {
        color: #e2e8f0 !important;
    }

    body.dark tbody tr:hover,
    body.dark .product-row:hover,
    body.dark .sales-row:hover,
    body.dark .category-row:hover,
    body.dark .hover\:bg-slate-50\/70:hover {
        background-color: rgba(15, 23, 42, 0.9) !important;
    }

    body.dark th:hover,
    body.dark table thead th:hover,
    body.dark .hover\:bg-slate-100:hover {
        background-color: rgba(30, 41, 59, 0.8) !important;
    }

    body.dark .divide-y > :not(:first-child),
    body.dark .divide-y > :not(:first-child) > td,
    body.dark .divide-y > :not(:first-child) > th {
        border-color: rgba(51, 65, 85, 0.9) !important;
    }

    body.dark input,
    body.dark select,
    body.dark textarea,
    body.dark button:not(.view-sale-btn) {
        color: #e2e8f0 !important;
    }

    body.dark input,
    body.dark select,
    body.dark textarea {
        background-color: #0f172a !important;
        border-color: #334155 !important;
    }

    body.dark #mobileSidebarToggle,
    body.dark #mainSidebar,
    body.dark #sidebarBackdrop {
        transition: all 0.25s ease;
    }

    body.dark #mobileSidebarToggle {
        background-color: #0f172a !important;
        border-color: #334155 !important;
        color: #f8fafc !important;
    }

    body.dark #mainSidebar {
        background-color: #0f172a !important;
        border-color: #334155 !important;
    }

    body.dark #mainSidebar .text-slate-600,
    body.dark #mainSidebar .text-slate-500,
    body.dark #mainSidebar .text-slate-700,
    body.dark #mainSidebar .text-slate-900,
    body.dark #mainSidebar .text-slate-400 {
        color: #e2e8f0 !important;
    }

    body.dark #mainSidebar .bg-green-50,
    body.dark #mainSidebar .bg-slate-50,
    body.dark #mainSidebar .hover\:bg-slate-50:hover {
        background-color: #111827 !important;
    }

    body.dark #mainSidebar .border-slate-100 {
        border-color: #334155 !important;
    }

</style>

<link rel="stylesheet" href="https://unpkg.com/tippy.js@6/dist/tippy.css">
<script src="https://unpkg.com/@popperjs/core@2"></script>
<script src="https://unpkg.com/tippy.js@6"></script>

<script>
(function () {
    const themeKey = 'lalenz_theme';
    const body = document.body;
    const sunIconClass = 'fa-solid fa-sun theme-icon';
    const moonIconClass = 'fa-solid fa-moon theme-icon';
    let toggle = null;

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        const root = document.documentElement;

        root.classList.toggle('dark', isDark);
        body.classList.toggle('dark', isDark);

        if (toggle) {
            const icon = toggle.querySelector('.theme-icon');
            icon.className = isDark ? moonIconClass : sunIconClass;
            toggle.setAttribute('aria-pressed', String(isDark));
            toggle.setAttribute('aria-label', `Switch to ${isDark ? 'light' : 'dark'} mode`);
        }
    }

    function placeThemeToggleInHeader() {
        const header = document.querySelector('header');
        if (!header) {
            return;
        }

        const firstChild = header.firstElementChild;
        const headerRow = firstChild && firstChild.classList.contains('justify-between')
            ? firstChild
            : header;
        toggle = document.createElement('button');
        toggle.id = 'themeToggle';
        toggle.type = 'button';
        toggle.className = 'ml-3 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-100 text-slate-700 transition hover:bg-slate-200 hover:text-green-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:hover:text-green-300';
        toggle.innerHTML = '<i class="fa-solid fa-sun theme-icon" aria-hidden="true"></i>';
        toggle.addEventListener('click', function () {
            const nextTheme = body.classList.contains('dark') ? 'light' : 'dark';
            localStorage.setItem(themeKey, nextTheme);
            applyTheme(nextTheme);
        });
        headerRow.appendChild(toggle);
        applyTheme(body.classList.contains('dark') ? 'dark' : 'light');
    }

    const savedTheme = localStorage.getItem(themeKey);
    const preferredDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const initialTheme = savedTheme || (preferredDark ? 'dark' : 'light');
    applyTheme(initialTheme);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', placeThemeToggleInHeader, { once: true });
    } else {
        placeThemeToggleInHeader();
    }
})();
</script>



<!-- ==========================================================
     MOBILE BACKDROP
=========================================================== -->

<div
    id="sidebarBackdrop"
    class="fixed inset-0
           z-40
           bg-slate-900/50
           opacity-0
           invisible
           transition-opacity duration-300
           lg:hidden"
></div>



<!-- ==========================================================
     SIDEBAR SCRIPT
=========================================================== -->

<script>

(function () {

    'use strict';


    /*
    |--------------------------------------------------------------------------
    | ELEMENTS
    |--------------------------------------------------------------------------
    */

    const sidebar =
        document.getElementById('mainSidebar');

    const toggleButton =
        document.getElementById('mobileSidebarToggle');

    const closeButton =
        document.getElementById('mobileSidebarClose');

    const collapseButton =
        document.getElementById('sidebarCollapseToggle');

    const backdrop =
        document.getElementById('sidebarBackdrop');


    /*
    |--------------------------------------------------------------------------
    | SAFETY CHECK
    |--------------------------------------------------------------------------
    */

    if (!sidebar || !toggleButton || !closeButton || !backdrop || !collapseButton) {
        return;
    }

    const collapsedStorageKey = 'lalenz_sidebar_collapsed';
    const tooltipElements = Array.from(
        document.querySelectorAll('#mainSidebar [data-tippy-content]')
    );
    let tooltips = [];

    function syncMainContent() {
        const targetMarginClass = document.body.classList.contains('sidebar-collapsed')
            ? 'lg:ml-20'
            : 'lg:ml-64';
        const previousMarginClass = targetMarginClass === 'lg:ml-20'
            ? 'lg:ml-64'
            : 'lg:ml-20';

        document.querySelectorAll(
            '[class~="lg:ml-64"]:not(.sidebar-tailwind-safelist), ' +
            '[class~="lg:ml-20"]:not(.sidebar-tailwind-safelist)'
        ).forEach(function (content) {
            content.classList.add(
                'transition-all',
                'duration-300',
                'ease-in-out',
                'sidebar-layout-initialized'
            );
            content.classList.remove(previousMarginClass);
            content.classList.add(targetMarginClass);
        });
    }

    function setCollapsed(isCollapsed) {
        document.body.classList.toggle('sidebar-collapsed', isCollapsed);
        sidebar.classList.toggle('w-20', isCollapsed);
        sidebar.classList.toggle('w-64', !isCollapsed);
        sidebar.classList.add('sidebar-layout-initialized');
        syncMainContent();
        collapseButton.setAttribute('aria-pressed', String(isCollapsed));
        collapseButton.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
        collapseButton.setAttribute('data-tippy-content', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
        localStorage.setItem(collapsedStorageKey, isCollapsed ? 'true' : 'false');

        if (tooltips.length) {
            const collapseTooltip = tooltips.find(
                tooltip => tooltip.reference === collapseButton
            );
            if (collapseTooltip) {
                collapseTooltip.setContent(isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
            }
        }
    }

    new MutationObserver(syncMainContent).observe(document.body, {
        childList: true,
        subtree: true,
    });

    const savedCollapsedState = localStorage.getItem(collapsedStorageKey) === 'true';
    setCollapsed(savedCollapsedState);

    collapseButton.addEventListener('click', function () {
        setCollapsed(!document.body.classList.contains('sidebar-collapsed'));
    });

    if (typeof window.tippy === 'function') {
        tooltips = window.tippy(tooltipElements, {
            placement: 'right',
            animation: 'shift-away',
            delay: [250, 0],
            onShow(instance) {
                if (
                    window.innerWidth < 1024 ||
                    !document.body.classList.contains('sidebar-collapsed')
                ) {
                    return false;
                }
                return true;
            },
        });
    }


    /*
    |--------------------------------------------------------------------------
    | OPEN SIDEBAR
    |--------------------------------------------------------------------------
    */

    function openSidebar() {

    sidebar.classList.remove('-translate-x-full');
    sidebar.classList.add('translate-x-0');

    backdrop.classList.remove(
        'opacity-0',
        'invisible'
    );

    backdrop.classList.add(
        'opacity-100',
        'visible'
    );

    toggleButton.setAttribute(
        'aria-expanded',
        'true'
    );

    /*
    |--------------------------------------------------------------------------
    | Hide hamburger button because it is underneath the sidebar
        |--------------------------------------------------------------------------
        */

        toggleButton.classList.add('opacity-0', 'invisible');

        document.body.classList.add(
            'overflow-hidden'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CLOSE SIDEBAR
    |--------------------------------------------------------------------------
    */

    function closeSidebar() {

    sidebar.classList.remove(
        'translate-x-0'
    );

    sidebar.classList.add(
        '-translate-x-full'
    );

    backdrop.classList.remove(
        'opacity-100',
        'visible'
    );

    backdrop.classList.add(
        'opacity-0',
        'invisible'
    );

    toggleButton.setAttribute(
        'aria-expanded',
        'false'
    );

    /*
    |--------------------------------------------------------------------------
    | Show hamburger button again
    |--------------------------------------------------------------------------
    */

    toggleButton.classList.remove(
        'opacity-0',
        'invisible'
        );

        document.body.classList.remove(
            'overflow-hidden'
        );
    }



    /*
    |--------------------------------------------------------------------------
    | TOGGLE
    |--------------------------------------------------------------------------
    */

    function toggleSidebar() {

        const isOpen =
            sidebar.classList.contains(
                'translate-x-0'
            );

        if (isOpen) {
            closeSidebar();
        } else {
            openSidebar();
        }

    }


    /*
    |--------------------------------------------------------------------------
    | EVENTS
    |--------------------------------------------------------------------------
    */

    toggleButton.addEventListener(
        'click',
        toggleSidebar
    );


    closeButton.addEventListener(
        'click',
        closeSidebar
    );


    backdrop.addEventListener(
        'click',
        closeSidebar
    );


    /*
    |--------------------------------------------------------------------------
    | CLOSE WHEN NAVIGATION LINK IS CLICKED
    |--------------------------------------------------------------------------
    */

    sidebar
        .querySelectorAll('nav a')
        .forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    /*
                    | Only close on mobile.
                    */

                    if (window.innerWidth < 1024) {
                        closeSidebar();
                    }

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | ESC KEY
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'keydown',
        function (event) {

            if (event.key === 'Escape') {

                if (window.innerWidth < 1024) {
                    closeSidebar();
                }

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | HANDLE WINDOW RESIZE
    |--------------------------------------------------------------------------
    */

    window.addEventListener(
        'resize',
        function () {

            /*
            | When switching to desktop,
            | restore normal body scrolling.
            */

            if (window.innerWidth >= 1024) {

                document.body.classList.remove(
                    'overflow-hidden'
                );

                backdrop.classList.remove(
                    'opacity-100',
                    'visible'
                );

                backdrop.classList.add(
                    'opacity-0',
                    'invisible'
                );

                toggleButton.setAttribute(
                    'aria-expanded',
                    'false'
                );

                /*
                | Show toggle button again.
                */

                toggleButton.classList.remove(
                    'opacity-0',
                    'invisible'
                );

            }

        }
    );

    function placeToggleInHeader() {
        const header = document.querySelector('header');
        const headerRow = header && header.firstElementChild;
        const titleGroup = headerRow && headerRow.firstElementChild;

        if (!titleGroup) {
            return;
        }

        titleGroup.classList.add(
            'flex',
            'items-center',
            'gap-3',
            'min-w-0'
        );

        toggleButton.classList.add('mr-1');
        titleGroup.insertBefore(toggleButton, titleGroup.firstChild);
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            placeToggleInHeader,
            { once: true }
        );
    } else {
        placeToggleInHeader();
    }



})();

</script>
