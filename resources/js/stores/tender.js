import { defineStore } from 'pinia';
import api from '@/api/client';

export const useTenderStore = defineStore('tender', {
    state: () => ({
        tenders: [],
        meta: null,
    }),
    actions: {
        async fetch(wishiUuid, cycleId) {
            const { data } = await api.get(`/wishis/${wishiUuid}/cycles/${cycleId}/tenders`);
            this.tenders = data.data;
            this.meta = data.meta;
            return data;
        },
        async place(wishiUuid, cycleId, bidAmount) {
            const { data } = await api.post(`/wishis/${wishiUuid}/cycles/${cycleId}/tenders`, { bid_amount: bidAmount });
            return data.data;
        },
    },
});
