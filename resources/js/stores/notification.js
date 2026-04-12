import { defineStore } from 'pinia';
import api from '@/api/client';

export const useNotificationStore = defineStore('notification', {
    state: () => ({
        notifications: [],
        unreadCount: 0,
    }),
    actions: {
        async fetch() {
            const { data } = await api.get('/notifications');
            this.notifications = data.data;
            this.unreadCount = data.unread_count;
            return data;
        },
        async markRead(id) {
            await api.put(`/notifications/${id}/read`);
            const n = this.notifications.find((x) => x.id === id);
            if (n && !n.read_at) {
                n.read_at = new Date().toISOString();
                this.unreadCount = Math.max(0, this.unreadCount - 1);
            }
        },
        async markAllRead() {
            await api.put('/notifications/read-all');
            this.notifications = this.notifications.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }));
            this.unreadCount = 0;
        },
    },
});
