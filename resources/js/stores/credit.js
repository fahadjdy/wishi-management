import { defineStore } from 'pinia';
import api from '@/api/client';

export const useCreditStore = defineStore('credit', {
    state: () => ({
        score: null,
        trustLevel: null,
        logs: [],
    }),
    actions: {
        async fetchMe() {
            const { data } = await api.get('/me/credit-score');
            this.score = data.score;
            this.trustLevel = data.trust_level;
            this.logs = data.logs;
            return data;
        },
    },
});
