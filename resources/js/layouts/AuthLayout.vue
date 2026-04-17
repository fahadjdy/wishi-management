<script setup>
import Logo from '@/components/Logo.vue';
import { ShieldCheckIcon, LockClosedIcon, DocumentCheckIcon } from '@heroicons/vue/24/outline';
</script>

<template>
    <div class="min-h-screen flex bg-linear-to-br from-brand-700 via-brand-800 to-slate-900">
        <!-- Left: brand story panel (desktop only) -->
        <div class="hidden lg:flex flex-col justify-between w-1/2 p-12 text-white relative overflow-hidden">
            <div class="absolute -top-32 -left-32 w-96 h-96 bg-white/5 rounded-full" aria-hidden="true"></div>
            <div class="absolute -bottom-40 -right-20 w-md h-112 bg-white/5 rounded-full" aria-hidden="true"></div>

            <div class="relative z-10">
                <Logo variant="horizontal" size="lg" mono />
            </div>

            <div class="relative z-10 max-w-md">
                <h1 class="text-4xl font-bold mb-4 leading-tight">Run your committee with confidence.</h1>
                <p class="text-brand-50/90 text-lg leading-relaxed">
                    Transparent chit-fund management — verifiable random draws, sealed tender bidding, automatic credit scoring, and a complete audit trail on every action.
                </p>

                <div class="grid grid-cols-3 gap-3 mt-10">
                    <div class="bg-white/10 backdrop-blur rounded-xl p-4 border border-white/10">
                        <DocumentCheckIcon class="w-5 h-5 text-brand-100 mb-2" aria-hidden="true" />
                        <div class="text-base font-bold leading-none">Transparent</div>
                        <div class="text-[11px] text-brand-50/80 mt-1">Every draw verifiable</div>
                    </div>
                    <div class="bg-white/10 backdrop-blur rounded-xl p-4 border border-white/10">
                        <LockClosedIcon class="w-5 h-5 text-brand-100 mb-2" aria-hidden="true" />
                        <div class="text-base font-bold leading-none">Sealed bids</div>
                        <div class="text-[11px] text-brand-50/80 mt-1">Hidden until close</div>
                    </div>
                    <div class="bg-white/10 backdrop-blur rounded-xl p-4 border border-white/10">
                        <ShieldCheckIcon class="w-5 h-5 text-brand-100 mb-2" aria-hidden="true" />
                        <div class="text-base font-bold leading-none">Audit log</div>
                        <div class="text-[11px] text-brand-50/80 mt-1">Every action tracked</div>
                    </div>
                </div>
            </div>

            <div class="relative z-10 text-xs text-brand-100/70">
                © {{ new Date().getFullYear() }} FHD Wishis · Built with security in mind
            </div>
        </div>

        <!-- Right: form -->
        <div class="flex-1 flex items-center justify-center p-6 sm:p-10">
            <div class="w-full max-w-md">
                <div class="lg:hidden mb-8">
                    <Logo variant="horizontal" size="lg" mono />
                </div>
                <div class="bg-white rounded-2xl shadow-xl p-7 sm:p-9">
                    <slot />
                </div>
                <p class="lg:hidden text-center text-xs text-white/70 mt-6">
                    © {{ new Date().getFullYear() }} FHD Wishis · Secure committee management
                </p>
            </div>
        </div>
    </div>
</template>
