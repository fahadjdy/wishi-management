import { defineStore } from 'pinia';

export const useUIStore = defineStore('ui', {
    state: () => ({
        breadcrumbs: [],
    }),
    actions: {
        setBreadcrumbs(items) {
            this.breadcrumbs = Array.isArray(items) ? items : [];
        },
        clearBreadcrumbs() {
            this.breadcrumbs = [];
        },
    },
});
