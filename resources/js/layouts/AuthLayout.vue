<script setup>
import Logo from '@/components/Logo.vue';
import { ShieldCheckIcon, LockClosedIcon, DocumentCheckIcon } from '@heroicons/vue/24/outline';
</script>

<template>
    <div class="min-h-screen flex" style="background-color: #FAF5EC;">
        <!-- Left: brand story panel — ink with terracotta accent (desktop) -->
        <div class="hidden lg:flex flex-col justify-between w-1/2 p-12 relative overflow-hidden paper-tex"
             style="background: #1F1812; color: #FAF5EC;">
            <div class="absolute -top-32 -left-32 w-96 h-96 rounded-full" style="background: rgba(194,90,54,0.10);" aria-hidden="true"></div>
            <div class="absolute -bottom-40 -right-20 w-md h-112 rounded-full" style="background: rgba(45,107,87,0.12);" aria-hidden="true"></div>

            <div class="relative z-10">
                <Logo variant="stacked" size="lg" mono />
            </div>

            <div class="relative z-10 max-w-md">
                <h1 class="display text-5xl mb-4 leading-tight">
                    Run your committee with <span class="italic" style="color:#E29A77;">confidence</span>.
                </h1>
                <p class="text-base leading-relaxed" style="color: rgba(250,245,236,0.78);">
                    Transparent chit-fund management — verifiable random draws, sealed tender bidding, automatic credit scoring, and a complete audit trail on every action.
                </p>

                <div class="grid grid-cols-3 gap-3 mt-10">
                    <div class="rounded-2xl p-4 border" style="background: rgba(250,245,236,0.06); border-color: rgba(250,245,236,0.12);">
                        <DocumentCheckIcon class="w-5 h-5 mb-2" style="color:#E29A77;" aria-hidden="true" />
                        <div class="text-base font-medium leading-none">Transparent</div>
                        <div class="text-[11px] mt-1.5" style="color: rgba(250,245,236,0.65);">Every draw verifiable</div>
                    </div>
                    <div class="rounded-2xl p-4 border" style="background: rgba(250,245,236,0.06); border-color: rgba(250,245,236,0.12);">
                        <LockClosedIcon class="w-5 h-5 mb-2" style="color:#E29A77;" aria-hidden="true" />
                        <div class="text-base font-medium leading-none">Sealed bids</div>
                        <div class="text-[11px] mt-1.5" style="color: rgba(250,245,236,0.65);">Hidden until close</div>
                    </div>
                    <div class="rounded-2xl p-4 border" style="background: rgba(250,245,236,0.06); border-color: rgba(250,245,236,0.12);">
                        <ShieldCheckIcon class="w-5 h-5 mb-2" style="color:#E29A77;" aria-hidden="true" />
                        <div class="text-base font-medium leading-none">Audit log</div>
                        <div class="text-[11px] mt-1.5" style="color: rgba(250,245,236,0.65);">Every action tracked</div>
                    </div>
                </div>
            </div>

            <div class="relative z-10 text-xs" style="color: rgba(250,245,236,0.55);">
                © {{ new Date().getFullYear() }} WISHI · Built with security in mind
            </div>
        </div>

        <!-- Right: form on cream paper -->
        <div class="flex-1 flex items-center justify-center p-6 sm:p-10">
            <div class="w-full max-w-md">
                <div class="lg:hidden mb-8 flex justify-center">
                    <Logo variant="stacked" size="lg" />
                </div>
                <div class="surface p-7 sm:p-9">
                    <slot />
                </div>
                <p class="text-center text-xs text-slate-500 mt-6">
                    © {{ new Date().getFullYear() }} WISHI · Secure committee management
                </p>
            </div>
        </div>
    </div>
</template>
