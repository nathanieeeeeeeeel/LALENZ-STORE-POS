<!-- ========================================================= NAVBAR ========================================================= -->
<header class="fixed top-0 left-0 right-0 z-50" x-data="{ mobileMenuOpen: false }">
    <nav class="glass border-b border-slate-200/70 bg-white/80 backdrop-blur-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="h-18 min-h-[72px] flex items-center justify-between">
                
                <!-- LEFT: LOGO & HAMBURGER TOGGLE -->
                <div class="flex items-center gap-4">
                    <!-- MOBILE MENU TRIGGER BUTTON -->
                    <button 
                        @click="mobileMenuOpen = !mobileMenuOpen" 
                        class="md:hidden p-2 rounded-xl text-slate-600 hover:text-brand-600 hover:bg-slate-100 transition focus:outline-none"
                        aria-label="Toggle Menu"
                    >
                        <svg x-show="!mobileMenuOpen" xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <svg x-show="mobileMenuOpen" xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="display: none;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <!-- LOGO -->
                    <a href="<?= !empty($user) ? 'dashboard.php' : 'login.php' ?>" class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-2xl bg-brand-600 text-white flex items-center justify-center shadow-lg shadow-green-600/20">
                            <!-- Store icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M5 10l1 10h12l1-10M7 10V7a5 5 0 0110 0v3" />
                                <path stroke-linecap="round" stroke-width="1.8" d="M9 15h6" />
                            </svg>
                        </div>
                        <div>
                            <div class="font-black text-lg leading-none">LALENZ</div>
                            <div class="text-xs text-slate-500 mt-1">Store POS</div>
                        </div>
                    </a>
                </div>

                <!-- DESKTOP MENU -->
                <div class="hidden md:flex items-center gap-8">
                    <a href="#features" class="text-sm font-semibold text-slate-600 hover:text-brand-600 transition">Features</a>
                    <a href="#about" class="text-sm font-semibold text-slate-600 hover:text-brand-600 transition">About</a>
                    <a href="#contact" class="text-sm font-semibold text-slate-600 hover:text-brand-600 transition">Contact</a>
                </div>

                <!-- ACTIONS -->
                <div class="flex items-center gap-3">
                    <?php if (empty($user)): ?>
                        <a href="login.php" class="hidden sm:inline-flex px-4 py-2.5 text-sm font-bold text-slate-700 hover:text-brand-600 transition">
                            Login
                        </a>
                    <?php endif; ?>
                    <a href="dashboard.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 text-white text-sm font-bold shadow-lg shadow-green-600/20 hover:bg-brand-700 transition">
                        Open POS
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>

        <!-- MOBILE DROPDOWN MENU -->
        <div 
            x-show="mobileMenuOpen" 
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            class="md:hidden border-t border-slate-200/70 bg-white px-4 pt-4 pb-6 space-y-3 shadow-xl"
            style="display: none;"
        >
            <a href="#features" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-xl text-base font-semibold text-slate-600 hover:bg-slate-50 hover:text-brand-600 transition">Features</a>
            <a href="#about" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-xl text-base font-semibold text-slate-600 hover:bg-slate-50 hover:text-brand-600 transition">About</a>
            <a href="#contact" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-xl text-base font-semibold text-slate-600 hover:bg-slate-50 hover:text-brand-600 transition">Contact</a>
            
            <?php if (empty($user)): ?>
                <div class="pt-2 border-t border-slate-100">
                    <a href="login.php" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-xl text-base font-bold text-slate-700 hover:bg-slate-50 hover:text-brand-600 transition">Login</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>
</header>