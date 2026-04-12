import { defineStore } from 'pinia';
import api from '@/api/client';

export const useAdminStore = defineStore('admin', {
    state: () => ({
        users: [],
        meta: null,
        summary: null,
        loading: false,
        currentUser: null,
        dashboard: null,
        dashboardLoading: false,
    }),
    actions: {
        async fetchDashboard() {
            this.dashboardLoading = true;
            try {
                const { data } = await api.get('/admin/dashboard');
                this.dashboard = data;
                return data;
            } finally {
                this.dashboardLoading = false;
            }
        },
        async fetchUsers(params = {}) {
            this.loading = true;
            try {
                const { data } = await api.get('/admin/users', { params });
                this.users = data.data;
                this.meta = data.meta;
                this.summary = data.summary;
                return data;
            } finally {
                this.loading = false;
            }
        },
        async fetchUser(id) {
            const { data } = await api.get(`/admin/users/${id}`);
            this.currentUser = data.data;
            return data.data;
        },
        async toggleAdmin(id) {
            const { data } = await api.put(`/admin/users/${id}/toggle-admin`);
            return data.data;
        },
        async lock(id, minutes, reason) {
            const { data } = await api.put(`/admin/users/${id}/lock`, { minutes, reason });
            return data.data;
        },
        async unlock(id) {
            const { data } = await api.put(`/admin/users/${id}/unlock`);
            return data.data;
        },
        async remove(id) {
            await api.delete(`/admin/users/${id}`);
        },
        async restore(id) {
            const { data } = await api.post(`/admin/users/${id}/restore`);
            return data.data;
        },
        async adjustCredit(id, points, reason) {
            const { data } = await api.put(`/admin/users/${id}/credit-score`, { points, reason });
            return data.data;
        },
    },
});
