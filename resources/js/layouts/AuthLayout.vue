<script setup>
</script>

<template>
    <div class="min-h-screen flex bg-gradient-to-br from-indigo-600 via-indigo-700 to-purple-700">
        <!-- Left side: Brand -->
        <div class="hidden lg:flex flex-col justify-between w-1/2 p-12 text-white relative overflow-hidden">
            <div class="absolute -top-32 -left-32 w-96 h-96 bg-white/5 rounded-full"></div>
            <div class="absolute -bottom-40 -right-20 w-[28rem] h-[28rem] bg-white/5 rounded-full"></div>

            <div class="relative z-10 flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-white/15 backdrop-blur flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 6v6c0 5 3.5 9.5 8 10 4.5-.5 8-5 8-10V6l-8-4z" /></svg>
                </div>
                <span class="text-2xl font-bold tracking-tight">WISHI</span>
            </div>

            <div class="relative z-10 max-w-md">
                <h1 class="text-4xl font-bold mb-4 leading-tight">Manage your committee with confidence.</h1>
                <p class="text-indigo-100 text-lg leading-relaxed">
                    Digital chit fund management with verifiable random draws, secure tender bidding, automatic credit scoring and an immutable audit trail.
                </p>
                <div class="grid grid-cols-3 gap-4 mt-10">
                    <div class="bg-white/10 backdrop-blur rounded-xl p-4">
                        <div class="text-2xl font-bold">100%</div>
                        <div class="text-xs text-indigo-100">Transparent draws</div>
                    </div>
                    <div class="bg-white/10 backdrop-blur rounded-xl p-4">
                        <div class="text-2xl font-bold">CSPRNG</div>
                        <div class="text-xs text-indigo-100">Verifiable random</div>
                    </div>
                    <div class="bg-white/10 backdrop-blur rounded-xl p-4">
                        <div class="text-2xl font-bold">Audit</div>
                        <div class="text-xs text-indigo-100">Every action logged</div>
                    </div>
                </div>
            </div>

            <div class="relative z-10 text-sm text-indigo-200">
                © {{ new Date().getFullYear() }} WISHI · Built with security in mind
            </div>
        </div>

        <!-- Right side: Form -->
        <div class="flex-1 flex items-center justify-center p-6 sm:p-10">
            <div class="w-full max-w-md">
                <div class="lg:hidden flex items-center gap-3 mb-8 text-white">
                    <div class="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 6v6c0 5 3.5 9.5 8 10 4.5-.5 8-5 8-10V6l-8-4z" /></svg>
                    </div>
                    <span class="text-2xl font-bold">WISHI</span>
                </div>
                <div class="bg-white rounded-2xl shadow-xl p-7 sm:p-9">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
