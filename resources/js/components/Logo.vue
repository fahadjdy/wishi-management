<script setup>
defineProps({
    // 'horizontal' = mark + wordmark side by side; 'mark' = just the W icon; 'stacked' = mark above wordmark.
    variant: { type: String, default: 'horizontal' },
    size:    { type: String, default: 'md' },   // 'sm' | 'md' | 'lg' | 'xl'
    mono:    { type: Boolean, default: false }, // white-on-dark background variant
});

const sizeMap = {
    sm: { mark: 'w-7 h-7',  word: 'text-sm',  sub: 'text-[9px]',  gap: 'gap-2'   },
    md: { mark: 'w-9 h-9',  word: 'text-base', sub: 'text-[10px]', gap: 'gap-2.5' },
    lg: { mark: 'w-12 h-12', word: 'text-xl',  sub: 'text-[11px]', gap: 'gap-3'   },
    xl: { mark: 'w-16 h-16', word: 'text-3xl', sub: 'text-xs',     gap: 'gap-4'   },
};
</script>

<template>
    <span v-if="variant === 'mark'"
        :class="sizeMap[size].mark"
        class="relative inline-flex items-center justify-center rounded-xl shadow-md shrink-0 overflow-hidden"
        :style="mono ? 'background: rgba(255,255,255,0.15); backdrop-filter: blur(6px);' : 'background: linear-gradient(135deg, #14b8a6 0%, #0f766e 100%);'"
        aria-label="FHD Wishis">
        <svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" class="w-3/5 h-3/5 text-white" fill="none" aria-hidden="true">
            <path d="M8 12 L14 28 L20 18 L26 28 L32 12" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="20" cy="33" r="1.5" fill="currentColor" />
        </svg>
    </span>

    <span v-else-if="variant === 'stacked'" class="inline-flex flex-col items-start leading-none" aria-label="FHD Wishis">
        <span :class="[sizeMap[size].sub, mono ? 'text-white/70' : 'text-brand-700']" class="uppercase tracking-[0.22em] font-bold">FHD</span>
        <span :class="[sizeMap[size].word, mono ? 'text-white' : 'text-slate-900']" class="font-extrabold tracking-tight">Wishis</span>
    </span>

    <span v-else :class="sizeMap[size].gap" class="inline-flex items-center" aria-label="FHD Wishis">
        <span :class="sizeMap[size].mark"
            class="relative inline-flex items-center justify-center rounded-xl shadow-md shrink-0 overflow-hidden"
            :style="mono ? 'background: rgba(255,255,255,0.15); backdrop-filter: blur(6px);' : 'background: linear-gradient(135deg, #14b8a6 0%, #0f766e 100%);'">
            <svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" class="w-3/5 h-3/5 text-white" fill="none" aria-hidden="true">
                <path d="M8 12 L14 28 L20 18 L26 28 L32 12" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                <circle cx="20" cy="33" r="1.5" fill="currentColor" />
            </svg>
        </span>
        <span class="flex flex-col items-start leading-none">
            <span :class="[sizeMap[size].sub, mono ? 'text-white/60' : 'text-brand-700/80']" class="uppercase tracking-[0.22em] font-bold">FHD</span>
            <span :class="[sizeMap[size].word, mono ? 'text-white' : 'text-slate-900']" class="font-extrabold tracking-tight">Wishis</span>
        </span>
    </span>
</template>
