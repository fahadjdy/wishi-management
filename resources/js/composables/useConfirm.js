import { useUIStore } from '@/stores/ui';

/**
 * Promise-based confirm / prompt / credentials modals.
 *
 * Replaces browser-native `confirm()` and `prompt()` with branded dialogs
 * that live in the global AppModals mount (see AppLayout.vue).
 *
 * Usage:
 *   const { confirm, prompt, showCredentials } = useConfirm();
 *   if (!await confirm({ title: 'Start WISHI?', tone: 'primary' })) return;
 *   const reason = await prompt({ title: 'Reject', label: 'Reason (optional)' });
 *   await showCredentials({ email, password });
 */
export function useConfirm() {
    const ui = useUIStore();
    return {
        confirm: (opts) => ui.confirm(opts || {}),
        prompt: (opts) => ui.prompt(opts || {}),
        showCredentials: (opts) => ui.showCredentials(opts || {}),
    };
}
