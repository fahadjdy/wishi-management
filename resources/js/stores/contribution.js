import { defineStore } from 'pinia';
import api from '@/api/client';

export const useContributionStore = defineStore('contribution', {
    state: () => ({
        contributions: [],
    }),
    actions: {
        async fetch(wishiUuid, cycleId) {
            const { data } = await api.get(`/wishis/${wishiUuid}/cycles/${cycleId}/contributions`);
            this.contributions = data.data;
            return data.data;
        },
        async record(wishiUuid, cycleId, payload) {
            const { data } = await api.post(`/wishis/${wishiUuid}/cycles/${cycleId}/contributions`, payload);
            // Patch in-memory list immediately so Mark-paid buttons disappear without a reload flash.
            const updated = data.data;
            const idx = this.contributions.findIndex((c) => c.id === updated.id);
            if (idx !== -1) {
                this.contributions.splice(idx, 1, updated);
            }
            return updated;
        },
    },
});
