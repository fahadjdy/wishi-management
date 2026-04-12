import { onMounted, onUnmounted, watch } from 'vue';
import { useUIStore } from '@/stores/ui';

/**
 * Declarative breadcrumb hook. Pass a reactive source (ref/computed/getter) that
 * returns the crumb array; the component will keep the store in sync and clear
 * on unmount. Home (/dashboard) is always prefixed by the layout.
 *
 * Example:
 *   useBreadcrumbs(() => [
 *     { label: 'WISHIs', to: '/wishis' },
 *     { label: wishi.value?.name || 'WISHI' },
 *   ]);
 */
export function useBreadcrumbs(getter) {
    const ui = useUIStore();
    const resolve = typeof getter === 'function' ? getter : () => getter;

    onMounted(() => ui.setBreadcrumbs(resolve() || []));
    watch(resolve, (v) => ui.setBreadcrumbs(v || []), { deep: true });
    onUnmounted(() => ui.clearBreadcrumbs());
}
