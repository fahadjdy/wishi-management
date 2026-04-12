import { defineStore } from 'pinia';
import api from '@/api/client';

export const useMemberStore = defineStore('member', {
    state: () => ({
        members: [],
    }),
    actions: {
        async fetch(wishiUuid) {
            const { data } = await api.get(`/wishis/${wishiUuid}/members`);
            this.members = data.data;
            return data.data;
        },
        async approve(wishiUuid, memberId) {
            const { data } = await api.put(`/wishis/${wishiUuid}/members/${memberId}/approve`);
            return data.data;
        },
        async reject(wishiUuid, memberId, reason = null) {
            const { data } = await api.put(`/wishis/${wishiUuid}/members/${memberId}/reject`, { reason });
            return data.data;
        },
        async remove(wishiUuid, memberId, reason = null) {
            await api.delete(`/wishis/${wishiUuid}/members/${memberId}`, { data: { reason } });
        },
    },
});
