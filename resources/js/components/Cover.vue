<script setup>
import { computed } from 'vue';

const props = defineProps({
    hue:    { type: String, default: '' },           // 'terracotta' | 'green' | 'mustard' | 'plum' — auto-picks when empty
    name:   { type: String, default: '' },           // used to deterministically pick a hue when hue prop is empty
    height: { type: [Number, String], default: 80 }, // pixels — banner height
});

const PALETTES = {
    terracotta: ['#F5DDD0', '#E29A77', '#C25A36'],
    green:      ['#D6E5DD', '#7BAE96', '#2D6B57'],
    mustard:    ['#FAEFD1', '#E5BE6B', '#A57B1A'],
    plum:       ['#E8DCEC', '#B59AC9', '#6A4C8A'],
};

// Derive a stable hue from the name so the same WISHI always renders the same
// cover even though the design data has no `hue` field server-side.
const HUES = Object.keys(PALETTES);
const pickedHue = computed(() => {
    if (props.hue && PALETTES[props.hue]) return props.hue;
    const s = props.name || '';
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return HUES[h % HUES.length];
});

const palette = computed(() => PALETTES[pickedHue.value]);
const patternId = computed(() => `wave-${pickedHue.value}-${(props.name || '').replace(/\W+/g, '')}`);
</script>

<template>
    <div class="wishi-cover" :style="{ height: typeof height === 'number' ? `${height}px` : height, background: palette[0] }">
        <svg width="100%" height="100%" viewBox="0 0 200 80" preserveAspectRatio="none" aria-hidden="true">
            <defs>
                <pattern :id="patternId" x="0" y="0" width="40" height="40" patternUnits="userSpaceOnUse">
                    <path d="M0 20 Q 10 10 20 20 T 40 20" :stroke="palette[1]" stroke-width="1.5" fill="none" opacity="0.5" />
                </pattern>
            </defs>
            <rect width="200" height="80" :fill="`url(#${patternId})`" />
            <circle cx="170" cy="55" r="30" :fill="palette[2]" opacity="0.18" />
            <circle cx="190" cy="20" r="14" :fill="palette[2]" opacity="0.25" />
        </svg>
    </div>
</template>

<style scoped>
.wishi-cover {
    width: 100%;
    overflow: hidden;
    position: relative;
    border-radius: inherit;
}
</style>
