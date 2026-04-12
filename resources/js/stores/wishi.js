import { defineStore } from 'pinia';
import api from '@/api/client';

export const useWishiStore = defineStore('wishi', {
    state: () => ({
        wishis: [],
        meta: null,
        currentWishi: null,
        loading: false,
    }),
    actions: {
        async fetchAll() {
            this.loading = true;
            try {
                const { data } = await api.get('/wishis');
                this.wishis = data.data;
                this.meta = data.meta;
            } finally {
                this.loading = false;
            }
        },
        async fetch(uuid) {
            this.loading = true;
            try {
                const { data } = await api.get(`/wishis/${uuid}`);
                this.currentWishi = data.data;
                return data.data;
            } finally {
                this.loading = false;
            }
        },
        async create(payload) {
            const { data } = await api.post('/wishis', payload);
            return data.data;
        },
        async update(uuid, payload) {
            const { data } = await api.put(`/wishis/${uuid}`, payload);
            this.currentWishi = data.data;
            return data.data;
        },
        async activate(uuid) {
            const { data } = await api.post(`/wishis/${uuid}/activate`);
            this.currentWishi = data.data;
            const idx = this.wishis.findIndex((w) => w.uuid === uuid);
            if (idx >= 0) this.wishis[idx] = data.data;
            return data.data;
        },
        async join(uuid) {
            const { data } = await api.post(`/wishis/${uuid}/join`);
            return data;
        },
    },
});
