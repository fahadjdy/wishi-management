import { defineStore } from 'pinia';
import api from '@/api/client';

export const useCycleStore = defineStore('cycle', {
    state: () => ({
        cycles: [],
        currentCycle: null,
        loading: false,
    }),
    actions: {
        async fetchAll(wishiUuid) {
            this.loading = true;
            try {
                const { data } = await api.get(`/wishis/${wishiUuid}/cycles`);
                this.cycles = data.data;
            } finally {
                this.loading = false;
            }
        },
        async fetch(wishiUuid, cycleId) {
            const { data } = await api.get(`/wishis/${wishiUuid}/cycles/${cycleId}`);
            this.currentCycle = data.data;
            return data.data;
        },
        async advance(wishiUuid) {
            const { data } = await api.post(`/wishis/${wishiUuid}/cycles/next`);
            return data.data;
        },
        async selectWinner(wishiUuid, cycleId, payload) {
            const { data } = await api.put(`/wishis/${wishiUuid}/cycles/${cycleId}/select-winner`, payload);
            this.currentCycle = data.data;
            return data.data;
        },
        async handleSurplus(wishiUuid, cycleId, payload) {
            const { data } = await api.put(`/wishis/${wishiUuid}/cycles/${cycleId}/surplus`, payload);
            this.currentCycle = data.data;
            return data.data;
        },
        async recordPayout(wishiUuid, cycleId, payload) {
            const { data } = await api.put(`/wishis/${wishiUuid}/cycles/${cycleId}/payout`, payload);
            return data.data;
        },
    },
});
