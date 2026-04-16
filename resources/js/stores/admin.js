import { defineStore } from 'pinia';
import api from '@/api/client';

export const useAdminStore = defineStore('admin', {
    state: () => ({
        users: [],
        meta: null,
        summary: null,
        loading: false,
        currentUser: null,
        currentUserDetail: null,
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
            // Detail payload: active WISHIs + pending/paid contributions — used
            // by the member profile modal so the admin can mark-paid / undo
            // without leaving the Members page.
            this.currentUserDetail = {
                active_wishis: data.active_wishis || [],
                pending_contributions: data.pending_contributions || [],
                paid_contributions: data.paid_contributions || [],
                totals: data.totals || null,
            };
            return this.currentUserDetail;
        },
        async createUser(payload) {
            const { data } = await api.post('/admin/users', payload);
            return data.data;
        },
        async updateUser(id, formData) {
            // POST /admin/users/{id} accepts multipart for avatar upload.
            const { data } = await api.post(`/admin/users/${id}`, formData);
            return data.data;
        },
        async resetUserPassword(id, password) {
            const { data } = await api.put(`/admin/users/${id}/password`, { password });
            return data;
        },
        async toggleAdmin(id) {
            const { data } = await api.put(`/admin/users/${id}/toggle-admin`);
            return data.data;
        },
        async markContributionPaid(wishiUuid, cycleId, userId) {
            const { data } = await api.post(`/wishis/${wishiUuid}/cycles/${cycleId}/contributions`, {
                user_id: userId,
                payment_method: 'cash',
            });
            return data.data;
        },
        async revertContribution(wishiUuid, cycleId, contributionId) {
            const { data } = await api.delete(`/wishis/${wishiUuid}/cycles/${cycleId}/contributions/${contributionId}/payment`);
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
