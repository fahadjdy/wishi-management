import { defineStore } from 'pinia';
import api from '@/api/client';

export const useContributionStore = defineStore('contribution', {
    state: () => ({
        contributions: [],
        // Member's full payment timeline for a single WISHI — one row per cycle
        // the member has a contribution in, ordered oldest → newest.
        myHistory: [],
        myHistoryMeta: null,
    }),
    actions: {
        async fetch(wishiUuid, cycleId) {
            const { data } = await api.get(`/wishis/${wishiUuid}/cycles/${cycleId}/contributions`);
            this.contributions = data.data;
            return data.data;
        },
        async fetchMyHistory(wishiUuid) {
            const { data } = await api.get(`/wishis/${wishiUuid}/my-contributions`);
            this.myHistory = data.data;
            this.myHistoryMeta = data.meta;
            return data.data;
        },
        clearMyHistory() {
            this.myHistory = [];
            this.myHistoryMeta = null;
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
        async revert(wishiUuid, cycleId, contributionId) {
            const { data } = await api.delete(`/wishis/${wishiUuid}/cycles/${cycleId}/contributions/${contributionId}/payment`);
            const updated = data.data;
            const idx = this.contributions.findIndex((c) => c.id === updated.id);
            if (idx !== -1) {
                this.contributions.splice(idx, 1, updated);
            }
            return updated;
        },
    },
});
