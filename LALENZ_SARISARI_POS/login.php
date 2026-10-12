<?php

session_start();

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/system.php';

/*
|--------------------------------------------------------------------------
| If already logged in, go to dashboard
|--------------------------------------------------------------------------
*/

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| LOGIN PROCESS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {

        $error = 'Please enter your username and password.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Find User
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    username,
                    email,
                    password,
                    full_name,
                    role,
                    is_active
                FROM users
                WHERE username = :username
                LIMIT 1
            ");

            $stmt->execute([
                ':username' => $username
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            /*
            |--------------------------------------------------------------------------
            | Validate User
            |--------------------------------------------------------------------------
            */

            if (!$user) {

                $error = 'Invalid username or password.';

            } elseif ((int) $user['is_active'] !== 1) {

                $error = 'This user account is inactive.';

            } elseif (!password_verify($password, $user['password'])) {

                $error = 'Invalid username or password.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Successful Login
                |--------------------------------------------------------------------------
                */

                /*
                |--------------------------------------------------------------------------
                | Update Last Login
                |--------------------------------------------------------------------------
                */

                $update = $pdo->prepare("
                    UPDATE users
                    SET last_login = NOW()
                    WHERE id = :id
                ");

                $update->execute([
                    ':id' => $user['id']
                ]);

                if (!empty($_POST['remember'])) {
                    appIssueRememberToken($pdo, (int) $user['id']);
                } else {
                    appRevokeRememberToken($pdo);
                }

                appSetAuthenticatedUser($user);

                /*
                |--------------------------------------------------------------------------
                | Redirect
                |--------------------------------------------------------------------------
                */

                header('Location: dashboard.php');
                exit;
            }

        } catch (PDOException $e) {

            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="select-none" oncontextmenu="return false">
   <head>
      <meta charset="UTF-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1.0" />
      <title>Login | <?= htmlspecialchars($systemName) ?></title>
      <meta name="description" content="Login to <?= htmlspecialchars($systemName) ?>" />
      <link rel="icon" href="<?= htmlspecialchars($logo) ?>" type="image/x-icon" />
      <!-- Tailwind CSS -->
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
                        900: "#14532d",
                     },
                  },
               },
            },
         };
      </script>
      <?php include __DIR__ . '/includes/page_transition.php'; ?>
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
         html {
            scroll-behavior: smooth;
         }
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
      </style>
   </head>
   <body class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-900 dark:text-slate-100">
      <!-- ========================================================= PAGE ========================================================= -->
      <div class="relative min-h-screen flex flex-col">
         <!-- ===================================================== HEADER ===================================================== -->
         <header class="w-full">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-5">
               <div class="flex items-center justify-between">
                  <!-- LOGO -->
                  <a href="login.php" class="flex items-center gap-3">
                     <img src="<?= htmlspecialchars($logo) ?>" alt="Logo" class="w-12 h-12" />
                     <div>
                        <div class="font-black text-lg leading-none">
                           <?= htmlspecialchars($systemName) ?>
                        </div>
                        <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                           <?= htmlspecialchars($systemTagline) ?? '' ?>
                        </div>
                     </div>
                  </a>
               </div>
            </div>
         </header>
         <!-- ===================================================== MAIN ===================================================== -->
         <main
            class="flex-1 flex items-center justify-center px-4 py-6 sm:py-10"
         >
            <div class="w-full max-w-md">
               <!-- ================================================= LOGIN CARD ================================================= -->
               <div
                  class="rounded-3xl border border-slate-200 bg-white shadow-xl shadow-slate-900/10 p-6 sm:p-9 dark:border-slate-700 dark:bg-slate-800"
               >
                  <!-- ICON -->
                  <div class="flex justify-center">
                     <div
                        class="w-16 h-16 rounded-2xl bg-green-100 text-green-700 flex items-center justify-center"
                     >
                        <svg
                           xmlns="http://www.w3.org/2000/svg"
                           class="w-8 h-8"
                           fill="none"
                           viewBox="0 0 24 24"
                           stroke="currentColor"
                        >
                           <path
                              stroke-linecap="round"
                              stroke-linejoin="round"
                              stroke-width="1.8"
                              d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                           />
                           <path
                              stroke-linecap="round"
                              stroke-linejoin="round"
                              stroke-width="1.8"
                              d="M19.5 20a7.5 7.5 0 00-15 0"
                           />
                        </svg>
                     </div>
                  </div>
                  <!-- HEADING -->
                  <div class="mt-6 text-center">
                     <h1 class="text-2xl sm:text-3xl font-black tracking-tight">
                        Welcome back
                     </h1>
                     <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        Sign in to manage your store.
                     </p>
                  </div>
                    <?php if ($error !== ''): ?>

                        <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                            <p class="text-sm font-semibold text-red-700">
                                <?= htmlspecialchars($error) ?>
                            </p>
                        </div>

                    <?php endif; ?>


                    <?php if ($success !== ''): ?>

                        <div class="mt-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3">
                            <p class="text-sm font-semibold text-green-700">
                                <?= htmlspecialchars($success) ?>
                            </p>
                        </div>

                    <?php endif; ?>

                  <!-- ================================================= LOGIN FORM ================================================= -->
                  <form action="login.php" method="POST" class="mt-8 space-y-5">
                     <!-- USERNAME -->
                     <div>
                        <label
                           for="username"
                           class="block text-sm font-bold text-slate-700 dark:text-slate-200 mb-2"
                        >
                           Username
                        </label>
                        <div class="relative">
                           <div
                              class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none"
                           >
                              <svg
                                 xmlns="http://www.w3.org/2000/svg"
                                 class="w-5 h-5 text-slate-400"
                                 fill="none"
                                 viewBox="0 0 24 24"
                                 stroke="currentColor"
                              >
                                 <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0z"
                                 />
                                 <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M4 21a8 8 0 0116 0"
                                 />
                              </svg>
                           </div>
                           <input
                              type="text"
                              id="username"
                              name="username"
                              autocomplete="username"
                              placeholder="Enter your username"
                              required
                              class="w-full py-3.5 pl-12 pr-4 rounded-xl border border-slate-200 bg-white text-sm text-slate-900 placeholder-slate-400 outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                           />
                        </div>
                     </div>
                     <!-- PASSWORD -->
                     <div>
                        <div class="flex items-center justify-between mb-2">
                           <label
                              for="password"
                              class="block text-sm font-bold text-slate-700 dark:text-slate-200"
                           >
                              Password
                           </label>
                           <a
                              href="#"
                              class="text-xs font-semibold text-green-600 hover:text-green-700"
                           >
                              Forgot password?
                           </a>
                        </div>
                        <div class="relative">
                           <div
                              class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none"
                           >
                              <svg
                                 xmlns="http://www.w3.org/2000/svg"
                                 class="w-5 h-5 text-slate-400"
                                 fill="none"
                                 viewBox="0 0 24 24"
                                 stroke="currentColor"
                              >
                                 <rect
                                    x="5"
                                    y="10"
                                    width="14"
                                    height="10"
                                    rx="2"
                                    stroke-width="1.8"
                                 />
                                 <path
                                    stroke-linecap="round"
                                    stroke-width="1.8"
                                    d="M8 10V7a4 4 0 018 0v3"
                                 />
                              </svg>
                           </div>
                           <input
                              type="password"
                              id="password"
                              name="password"
                              autocomplete="current-password"
                              placeholder="Enter your password"
                              required
                              class="w-full py-3.5 pl-12 pr-12 rounded-xl border border-slate-200 bg-white text-sm text-slate-900 placeholder-slate-400 outline-none focus:border-green-500 focus:ring-4 focus:ring-green-500/10 transition dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                           />
                           <!-- SHOW PASSWORD -->
                           <button
                              type="button"
                              id="togglePassword"
                              class="absolute inset-y-0 right-0 px-4 text-slate-400 hover:text-green-600 transition"
                              aria-label="Show password"
                           >
                              <svg
                                 id="eyeOpen"
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
                                    d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"
                                 />
                                 <circle
                                    cx="12"
                                    cy="12"
                                    r="2.5"
                                    stroke-width="1.8"
                                 />
                              </svg>
                              <svg
                                 id="eyeClosed"
                                 xmlns="http://www.w3.org/2000/svg"
                                 class="hidden w-5 h-5"
                                 fill="none"
                                 viewBox="0 0 24 24"
                                 stroke="currentColor"
                              >
                                 <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M3 3l18 18"
                                 />
                                 <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M10.6 10.6a2 2 0 002.8 2.8"
                                 />
                                 <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M9.9 5.1A10.8 10.8 0 0112 5c6 0 9.5 7 9.5 7a17 17 0 01-3.1 3.8"
                                 />
                                 <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.8"
                                    d="M6.1 6.1C3.8 7.7 2.5 12 2.5 12s3.5 7 9.5 7c1.2 0 2.3-.2 3.3-.6"
                                 />
                              </svg>
                           </button>
                        </div>
                     </div>
                     <!-- REMEMBER ME -->
                     <div class="flex items-center">
                        <label
                           class="inline-flex items-center gap-2 cursor-pointer"
                        >
                           <input
                              type="checkbox"
                              name="remember"
                              class="w-4 h-4 rounded border-slate-300 text-green-600 focus:ring-green-500"
                           />
                           <span class="text-sm text-slate-500 dark:text-slate-400">
                              Remember me
                           </span>
                        </label>
                     </div>
                     <!-- LOGIN BUTTON -->
                     <button
                        type="submit"
                        class="w-full h-13 py-3.5 rounded-xl bg-green-600 hover:bg-green-700 active:bg-green-800 text-white font-bold text-sm shadow-lg shadow-green-600/20 hover:shadow-xl transition flex items-center justify-center gap-2"
                     >
                        <span>Sign In</span>
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
                              d="M13 7l5 5m0 0l-5 5m5-5H6"
                           />
                        </svg>
                     </button>
                  </form>
                  <!-- DIVIDER -->
                  <div class="relative my-7">
                     <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-slate-200 dark:border-slate-700"></div>
                     </div>
                     <div class="relative flex justify-center">
                        <span class="px-3 bg-white text-xs text-slate-400 dark:bg-slate-800">
                           LALENZ SARI-SARI POS
                        </span>
                     </div>
                  </div>
                  <!-- DEMO INFO -->
                  <div
                     class="rounded-2xl bg-green-50 border border-green-100 p-4"
                  >
                     <div class="flex gap-3">
                        <div
                           class="flex-shrink-0 w-9 h-9 rounded-xl bg-green-100 text-green-700 flex items-center justify-center"
                        >
                           <svg
                              xmlns="http://www.w3.org/2000/svg"
                              class="w-5 h-5"
                              fill="none"
                              viewBox="0 0 24 24"
                              stroke="currentColor"
                           >
                              <circle
                                 cx="12"
                                 cy="12"
                                 r="9"
                                 stroke-width="1.8"
                              />
                              <path
                                 stroke-linecap="round"
                                 stroke-width="1.8"
                                 d="M12 11v5"
                              />
                              <path
                                 stroke-linecap="round"
                                 stroke-width="2"
                                 d="M12 8h.01"
                              />
                           </svg>
                        </div>
                        <div>
                           <p class="text-sm font-bold text-green-800">
                              Store account
                           </p>
                           <p
                              class="mt-1 text-xs text-green-700 leading-relaxed"
                           >
                              Your store credentials will be used to securely
                              access your POS dashboard.
                           </p>
                        </div>
                     </div>
                  </div>
               </div>
               <!-- ================================================= FOOTER ================================================= -->
               <div class="mt-6 text-center">
                  <p class="text-xs text-slate-500 dark:text-slate-400">
                     © <?= date('Y') ?> LALENZ Sari-Sari POS. All rights
                     reserved.
                  </p>
                  <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                     Built for Filipino small businesses 🇵🇭
                  </p>
               </div>
            </div>
         </main>
      </div>
      <!-- ========================================================= JAVASCRIPT ========================================================= -->
      <?php include __DIR__ . '/includes/footer.php'; ?>
      <script>
         const passwordInput = document.getElementById("password");
         const togglePassword = document.getElementById("togglePassword");
         const eyeOpen = document.getElementById("eyeOpen");
         const eyeClosed = document.getElementById("eyeClosed");
         togglePassword.addEventListener("click", function () {
            const isPassword = passwordInput.type === "password";
            passwordInput.type = isPassword ? "text" : "password";
            eyeOpen.classList.toggle("hidden", isPassword);
            eyeClosed.classList.toggle("hidden", !isPassword);
            togglePassword.setAttribute(
               "aria-label",
               isPassword ? "Hide password" : "Show password",
            );
         });
      </script>
   </body>
</html>
