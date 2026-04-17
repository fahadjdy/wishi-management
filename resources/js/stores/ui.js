import { defineStore } from 'pinia';

/**
 * Global UI store.
 *
 * Holds transient UI state that spans routes — breadcrumbs + app-level modals
 * (confirm / prompt / credentials). Pages call `ui.confirm({...})` which returns
 * a Promise; the root AppLayout mounts the three modal components once and they
 * read their state directly from this store.
 */
export const useUIStore = defineStore('ui', {
    state: () => ({
        breadcrumbs: [],
        // { title, message, meta, confirmText, cancelText, tone, requireTypeText, resolve }
        confirmState: null,
        // { title, label, placeholder, initialValue, multiline, required, confirmText, cancelText, resolve }
        promptState: null,
        // { email, password, title, note, resolve }
        credentialsState: null,
    }),
    actions: {
        setBreadcrumbs(items) { this.breadcrumbs = Array.isArray(items) ? items : []; },
        clearBreadcrumbs() { this.breadcrumbs = []; },

        // --- Confirm ---
        confirm(options) {
            if (this.confirmState?.resolve) this.confirmState.resolve(false);
            return new Promise((resolve) => {
                this.confirmState = { ...options, resolve };
            });
        },
        resolveConfirm(value) {
            const s = this.confirmState;
            this.confirmState = null;
            s?.resolve(value);
        },

        // --- Prompt (text input) ---
        prompt(options) {
            if (this.promptState?.resolve) this.promptState.resolve(null);
            return new Promise((resolve) => {
                this.promptState = { ...options, resolve };
            });
        },
        resolvePrompt(value) {
            const s = this.promptState;
            this.promptState = null;
            s?.resolve(value);
        },

        // --- Credentials ready (replaces password-in-toast) ---
        showCredentials(options) {
            if (this.credentialsState?.resolve) this.credentialsState.resolve();
            return new Promise((resolve) => {
                this.credentialsState = { ...options, resolve };
            });
        },
        resolveCredentials() {
            const s = this.credentialsState;
            this.credentialsState = null;
            s?.resolve();
        },
    },
});
