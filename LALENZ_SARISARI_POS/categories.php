<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';
require_once __DIR__ . '/config/permissions.php';
/* |-------------------------------------------------------------------------- | Authentication |-------------------------------------------------------------------------- */
$userId = fetchUser();
/* |-------------------------------------------------------------------------- | Current User |-------------------------------------------------------------------------- */
$stmt = $pdo->prepare(" SELECT * FROM users WHERE id = ? ");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
requireAppPermission($pdo, $userId, 'categories.view');
$canManageCategories = hasAppPermission($pdo, $userId, 'categories.manage');

// User information
$username = $user['username'] ?? 'User';
$fullName = $user['full_name'] ?? $username;

$userRole = strtolower($user['role'] ?? 'staff');
/* |-------------------------------------------------------------------------- | Current Page |-------------------------------------------------------------------------- */
$currentPage = basename($_SERVER['PHP_SELF']);
/* |-------------------------------------------------------------------------- | Categories |-------------------------------------------------------------------------- */
$stmt = $pdo->query(" SELECT c.id, c.name, COUNT(p.id) AS product_count FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id, c.name ORDER BY c.name ASC ");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
/* |-------------------------------------------------------------------------- | Statistics |-------------------------------------------------------------------------- */

$totalCategories = count($categories); $totalProducts = 0;

foreach ($categories as $category) {
    $totalProducts += (int) $category['product_count'];
}
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
    Categories | <?= $systemName ?>
</title>

<meta
    name="description"
    content="Manage product categories"
>

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<link rel="icon" href="<?= htmlspecialchars($logo) ?>" type="image/x-icon" />

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

    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    ::-webkit-scrollbar-track {
        background: #f8fafc;
    }

    ::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 999px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

</style>

</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<!-- SIDEBAR -->
<?php 
$currentPage = basename($_SERVER['PHP_SELF']);
include "./includes/sidebar.php";
?>

<!-- ========================================================= -->

<!-- MAIN -->

<!-- ========================================================= -->

<main class="lg:ml-64 min-h-screen dark:bg-slate-950">
    
    <!-- HEADER -->
    <header class="flex h-20 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6 lg:px-8 dark:border-slate-700 dark:bg-slate-900">
            <div>
                <h1 class="text-2xl font-black tracking-tight">Categories</h1>
                <p class="text-sm text-slate-500 mt-1">Organize your products by category.</p>
            </div>
    </header>

<!-- CONTENT -->

<div class="p-6 lg:p-8">


    <!-- ================================================= -->
    <!-- STATISTICS -->
    <!-- ================================================= -->

    <div
        class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8"
    >


        <!-- TOTAL CATEGORIES -->

        <div
            class="bg-white rounded-2xl border border-slate-200 p-5"
        >

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-sm font-semibold text-slate-500">
                        Total Categories
                    </p>

                    <p class="mt-2 text-3xl font-black">
                        <?= number_format($totalCategories) ?>
                    </p>

                </div>


                <div
                    class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center"
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

            </div>

        </div>


        <!-- PRODUCTS -->

        <div
            class="bg-white rounded-2xl border border-slate-200 p-5"
        >

            <div class="flex items-center justify-between">

                <div>

                    <p class="text-sm font-semibold text-slate-500">
                        Products Assigned
                    </p>

                    <p class="mt-2 text-3xl font-black text-green-600">
                        <?= number_format($totalProducts) ?>
                    </p>

                </div>


                <div
                    class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center"
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
                            d="M4 7h16M4 7l2 13h12l2-13M9 7V5a3 3 0 016 0v2"
                        />

                    </svg>
                </div>

            </div>

        </div>

    </div>


    <!-- ================================================= -->
    <!-- CATEGORY CARD -->
    <!-- ================================================= -->

    <div
        class="bg-white border border-slate-200 rounded-2xl overflow-hidden"
    >


        <!-- TOOLBAR -->

        <div
            class="p-5 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
        >

            <div>

                <h2 class="text-lg font-black">
                    Product Categories
                </h2>

                <p class="text-sm text-slate-500 mt-1">
                    Manage the categories used by your products.
                </p>

            </div>


            <div class="flex flex-col sm:flex-row gap-3">


                <!-- SEARCH -->

                <div class="relative">

                    <input
                        type="search"
                        id="categorySearch"
                        placeholder="Search categories..."
                        class="w-full sm:w-64 h-11 pl-10 pr-4 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10"
                    >

                    <span
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
                    >
                        🔎
                    </span>

                </div>


                <!-- ADD CATEGORY -->

                <?php if ($canManageCategories): ?>

                    <a
                        href="/LALENZ_SARISARI_POS/category_add.php"
                        class="h-11 px-5 rounded-xl bg-green-600 hover:bg-green-700 text-white font-bold text-sm inline-flex items-center justify-center gap-2 shadow-lg shadow-green-600/20 transition"
                    >

                        <span class="text-lg">
                            +
                        </span>

                        Add Category

                    </a>

                <?php endif; ?>

            </div>

        </div>


        <!-- TABLE -->

        <div class="overflow-x-auto">

            <table class="w-full min-w-[700px]">

                <thead class="bg-slate-50">

                    <tr>

                        <th
                            class="text-left px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500"
                        >
                            Category
                        </th>

                        <th
                            class="text-center px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500"
                        >
                            Products
                        </th>

                        <th
                            class="text-right px-5 py-4 text-xs font-black uppercase tracking-wider text-slate-500"
                        >
                            Actions
                        </th>

                    </tr>

                </thead>


                <tbody
                    id="categoryTable"
                    class="divide-y divide-slate-100"
                >


                    <?php if (empty($categories)): ?>

                        <tr>

                            <td
                                colspan="3"
                                class="px-5 py-16 text-center"
                            >

                                <div class="text-4xl mb-3">
                                    📂
                                </div>

                                <p class="font-bold text-slate-700">
                                    No categories found
                                </p>

                                <p class="text-sm text-slate-400 mt-1">
                                    Add your first category to get started.
                                </p>

                            </td>

                        </tr>

                    <?php else: ?>


                        <?php foreach ($categories as $category): ?>

                            <tr
                                class="category-row hover:bg-slate-50/70 transition"
                                data-name="<?= htmlspecialchars(strtolower($category['name'])) ?>"
                            >


                                <!-- CATEGORY -->

                                <td class="px-5 py-4">

                                    <div class="flex items-center gap-3">

                                        <div
                                            class="w-10 h-10 rounded-xl bg-green-50 text-green-600 flex items-center justify-center"
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

                                            <p class="font-bold text-slate-800">
                                                <?= htmlspecialchars($category['name']) ?>
                                            </p>

                                            <p class="text-xs text-slate-400 mt-1">
                                                Category #<?= (int) $category['id'] ?>
                                            </p>

                                        </div>

                                    </div>

                                </td>


                                <!-- PRODUCT COUNT -->

                                <td class="px-5 py-4 text-center">

                                    <span
                                        class="inline-flex min-w-10 justify-center px-3 py-1 rounded-full bg-slate-100 text-slate-700 text-xs font-bold"
                                    >
                                        <?= number_format((int) $category['product_count']) ?>
                                    </span>

                                </td>


                                <!-- ACTIONS -->

                                <td class="px-5 py-4">

                                    <div
                                        class="flex items-center justify-end gap-2"
                                    >


                                        <?php if ($canManageCategories): ?>

                                            <!-- EDIT -->

                                            <a
                                                href="/LALENZ_SARISARI_POS/category_edit.php?id=<?= (int) $category['id'] ?>"
                                                class="px-3 py-2 rounded-lg text-sm font-semibold text-green-700 bg-green-50 hover:bg-green-100 transition"
                                            >
                                                Edit
                                            </a>

                                        <?php endif; ?>


                                        <?php if ($canManageCategories): ?>

                                            <!-- DELETE -->

                                            <a
                                                href="/LALENZ_SARISARI_POS/category_delete.php?id=<?= (int) $category['id'] ?>"
                                                onclick="return confirm('Delete this category? Products assigned to this category may be affected.');"
                                                class="px-3 py-2 rounded-lg text-sm font-semibold text-red-600 bg-red-50 hover:bg-red-100 transition"
                                            >
                                                Delete
                                            </a>

                                        <?php endif; ?>


                                        <?php if (!$canManageCategories): ?>

                                            <span class="text-xs text-slate-400">
                                                View only
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</main>

<!-- ========================================================= -->

<!-- SEARCH -->

<!-- ========================================================= -->

<script> const searchInput = document.getElementById('categorySearch'); const categoryRows = document.querySelectorAll('.category-row'); searchInput.addEventListener('input', function () { const search = this.value.toLowerCase().trim(); categoryRows.forEach(row => { const name = row.dataset.name || ''; if (name.includes(search)) { row.style.display = ''; } else { row.style.display = 'none'; } }); }); </script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body> </html>