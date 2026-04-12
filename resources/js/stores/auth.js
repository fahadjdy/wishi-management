import { defineStore } from 'pinia';
import api, { ensureCsrf } from '@/api/client';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        loading: false,
        initialized: false,
    }),
    getters: {
        isAuthenticated: (state) => !!state.user,
    },
    actions: {
        async fetchUser() {
            try {
                this.loading = true;
                const { data } = await api.get('/me');
                this.user = data.user;
            } catch (e) {
                this.user = null;
            } finally {
                this.loading = false;
                this.initialized = true;
            }
        },
        async login(payload) {
            await ensureCsrf();
            const { data } = await api.post('/login', payload);
            this.user = data.user;
            return data;
        },
        async register(payload) {
            await ensureCsrf();
            const { data } = await api.post('/register', payload);
            this.user = data.user;
            return data;
        },
        async logout() {
            try {
                await api.post('/logout');
            } finally {
                this.user = null;
            }
        },
    },
});
