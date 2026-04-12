import { defineStore } from 'pinia';
import api from '@/api/client';

export const useDashboardStore = defineStore('dashboard', {
    state: () => ({
        data: null,
        loading: false,
    }),
    actions: {
        async fetch() {
            this.loading = true;
            try {
                const { data } = await api.get('/dashboard');
                this.data = data;
            } finally {
                this.loading = false;
            }
        },
    },
});
