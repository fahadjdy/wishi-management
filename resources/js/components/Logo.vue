<script setup>
defineProps({
    // 'horizontal' = wordmark + tagline; 'mark' = just the W glyph; 'stacked' = wordmark with tagline below.
    variant: { type: String, default: 'horizontal' },
    size:    { type: String, default: 'md' },   // 'sm' | 'md' | 'lg' | 'xl'
    mono:    { type: Boolean, default: false }, // white-on-dark variant for the sidebar
});

const sizeMap = {
    sm: 'sm',
    md: '',      // default 22px
    lg: 'lg',
    xl: 'xl',
};
</script>

<template>
    <!-- Bare wordmark — used as the W in compact rails -->
    <span v-if="variant === 'mark'"
        class="brandmark"
        :class="[sizeMap[size], mono && 'mono']"
        aria-label="WISHI">
        W<span class="dot">·</span>
    </span>

    <!-- Stacked: wordmark above a small tagline -->
    <span v-else-if="variant === 'stacked'" class="inline-flex flex-col items-start leading-none gap-1" aria-label="WISHI">
        <span class="brandmark" :class="[sizeMap[size], mono && 'mono']">WISHI<span class="dot">.</span></span>
        <span class="text-[10px] uppercase tracking-[0.22em]" :class="mono ? 'text-slate-300/70' : 'text-slate-500'">
            Pool · Cycle · Pay
        </span>
    </span>

    <!-- Default horizontal: full wordmark -->
    <span v-else class="inline-flex items-baseline gap-2" aria-label="WISHI">
        <span class="brandmark" :class="[sizeMap[size], mono && 'mono']">WISHI<span class="dot">.</span></span>
    </span>
</template>
