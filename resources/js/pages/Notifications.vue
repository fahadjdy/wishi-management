<script setup>
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useNotificationStore } from '@/stores/notification';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { formatDateTime, relativeTime } from '@/utils/format';

const store = useNotificationStore();
const router = useRouter();

useBreadcrumbs(() => [{ label: 'Notifications' }]);

onMounted(() => store.fetch());

const kindIcon = {
    payment_reminder: '💰',
    payment_approved: '✅',
    winner_announced: '🎉',
    tender_window: '⏱',
    member_status: '👥',
    member_joined: '🙋',
    wishi_created: '✨',
    wishi_started: '🚀',
    wishi_full: '🎯',
};

function targetFor(n) {
    const d = n?.data || {};
    if (d.wishi_uuid) {
        if (d.cycle_id && d.kind === 'payment_approved') {
            return `/wishis/${d.wishi_uuid}/cycles/${d.cycle_id}`;
        }
        return `/wishis/${d.wishi_uuid}`;
    }
    return null;
}

async function openNotification(n) {
    if (!n.read_at) await store.markRead(n.id);
    const to = targetFor(n);
    if (to) router.push(to);
}
</script>

<template>
    <div class="space-y-5 max-w-3xl">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">Notifications</h1>
                <p class="text-sm text-gray-500">{{ store.unreadCount }} unread</p>
            </div>
            <button v-if="store.unreadCount > 0" @click="store.markAllRead" class="btn-secondary btn-sm">Mark all read</button>
        </div>

        <div v-if="!store.notifications.length" class="surface-padded text-center py-12">
            <div class="text-5xl mb-3">📭</div>
            <div class="font-semibold text-lg">No notifications</div>
            <div class="text-sm text-gray-500">You'll see payment reminders, winner announcements and tender alerts here.</div>
        </div>

        <ul v-else class="space-y-2">
            <li v-for="n in store.notifications" :key="n.id"
                class="surface p-4 flex items-start gap-3 cursor-pointer hover:border-indigo-300"
                :class="!n.read_at ? 'border-indigo-200 bg-indigo-50/30' : ''"
                @click="openNotification(n)">
                <div class="text-2xl">{{ kindIcon[n.data?.kind] || '🔔' }}</div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <div class="font-semibold">{{ n.data?.title || n.type }}</div>
                        <span v-if="!n.read_at" class="w-2 h-2 bg-indigo-500 rounded-full"></span>
                    </div>
                    <p class="text-sm text-gray-600 mt-0.5">{{ n.data?.message }}</p>
                    <div class="text-xs text-indigo-600 mt-1.5" v-if="targetFor(n)">Tap to open →</div>
                    <div class="text-xs text-gray-400 mt-1.5">{{ relativeTime(n.created_at) }} · {{ formatDateTime(n.created_at) }}</div>
                </div>
            </li>
        </ul>
    </div>
</template>
