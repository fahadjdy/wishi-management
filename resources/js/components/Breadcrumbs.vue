<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useUIStore } from '@/stores/ui';

const ui = useUIStore();

// Always lead with "Home". Pages append context via `useBreadcrumbs`.
const items = computed(() => {
    const trail = [{ label: 'Home', to: '/dashboard', icon: true }];
    for (const c of ui.breadcrumbs) trail.push(c);
    return trail;
});
</script>

<template>
    <nav v-if="items.length > 1" class="flex items-center flex-wrap gap-1 text-xs sm:text-sm text-gray-500 min-w-0" aria-label="Breadcrumb">
        <template v-for="(c, i) in items" :key="i">
            <RouterLink
                v-if="c.to && i < items.length - 1"
                :to="c.to"
                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded hover:bg-gray-100 hover:text-gray-800 truncate max-w-[160px] sm:max-w-none"
            >
                <svg v-if="c.icon" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l9-9 9 9" /><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10" /></svg>
                <span class="truncate">{{ c.label }}</span>
            </RouterLink>
            <span
                v-else
                class="inline-flex items-center gap-1 px-1.5 py-0.5 text-gray-900 font-semibold truncate max-w-[220px] sm:max-w-none"
                aria-current="page"
            >
                <svg v-if="c.icon" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l9-9 9 9" /><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10" /></svg>
                <span class="truncate">{{ c.label }}</span>
            </span>
            <svg v-if="i < items.length - 1" xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-gray-300 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
        </template>
    </nav>
</template>
