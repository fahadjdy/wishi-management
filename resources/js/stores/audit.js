import { defineStore } from 'pinia';
import api from '@/api/client';

export const useAuditStore = defineStore('audit', {
    state: () => ({
        logs: [],
        meta: null,
    }),
    actions: {
        async fetch(wishiUuid, page = 1) {
            const { data } = await api.get(`/wishis/${wishiUuid}/audit-logs`, { params: { page } });
            this.logs = data.data;
            this.meta = data.meta;
            return data;
        },
    },
});
