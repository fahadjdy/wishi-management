<script setup>
import { onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useNotificationStore } from '@/stores/notification';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { formatDateTime, relativeTime } from '@/utils/format';
import {
    CurrencyRupeeIcon, TrophyIcon, ClockIcon, UserGroupIcon,
    UserPlusIcon, SparklesIcon, RocketLaunchIcon,
    CheckBadgeIcon, BellIcon, InboxIcon,
} from '@heroicons/vue/24/outline';
import { CheckCircleIcon } from '@heroicons/vue/24/solid';

const store = useNotificationStore();
const router = useRouter();

useBreadcrumbs(() => [{ label: 'Notifications' }]);

onMounted(() => store.fetch());

// Each notification kind maps to a Heroicon + a warm tone (bg/fg pair). All
// icon tiles use the cream-2/sand neutral background — meaning is conveyed by
// the icon shape, not loud colour. Design language: muted icon tiles, warm
// terracotta wash on unread rows.
const ICON_TILE = 'bg-slate-100 text-slate-700';
const kindIcon = {
    payment_reminder:  { icon: CurrencyRupeeIcon,  tone: 'bg-amber-50 text-amber-700' },
    payment_approved:  { icon: CheckCircleIcon,    tone: 'bg-green-50 text-green-700' },
    winner_announced:  { icon: TrophyIcon,         tone: 'bg-amber-50 text-amber-700' },
    tender_window:     { icon: ClockIcon,          tone: 'bg-accent-50 text-accent-700' },
    member_status:     { icon: UserGroupIcon,      tone: ICON_TILE },
    member_joined:     { icon: UserPlusIcon,       tone: 'bg-green-50 text-green-700' },
    wishi_created:     { icon: SparklesIcon,       tone: 'bg-brand-50 text-brand-700' },
    wishi_started:     { icon: RocketLaunchIcon,   tone: 'bg-brand-50 text-brand-700' },
    wishi_full:        { icon: CheckBadgeIcon,     tone: 'bg-brand-50 text-brand-700' },
};

function iconFor(n) {
    return kindIcon[n.data?.kind] || { icon: BellIcon, tone: ICON_TILE };
}

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

const hasAny = computed(() => store.notifications.length > 0);
</script>

<template>
    <div class="space-y-5 max-w-3xl">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="display text-4xl text-slate-900">Notifications<span class="text-brand-600">.</span></h1>
                <p class="text-sm text-slate-500">
                    <span v-if="store.unreadCount > 0">{{ store.unreadCount }} unread</span>
                    <span v-else>You're all caught up.</span>
                </p>
            </div>
            <button v-if="store.unreadCount > 0" @click="store.markAllRead" class="btn-secondary btn-sm">Mark all read</button>
        </div>

        <div v-if="!hasAny" class="surface-padded text-center py-14">
            <div class="w-14 h-14 mx-auto rounded-full bg-slate-100 flex items-center justify-center mb-3">
                <InboxIcon class="w-7 h-7 text-slate-400" aria-hidden="true" />
            </div>
            <div class="font-semibold text-lg text-slate-900">No notifications yet</div>
            <div class="text-sm text-slate-500 mt-1">Payment reminders, winner announcements, and tender alerts will appear here.</div>
        </div>

        <ul v-else class="surface overflow-hidden divide-y" style="--tw-divide-opacity: 1; border-color: #E9DFCC;">
            <li v-for="n in store.notifications" :key="n.id"
                class="px-5 py-4 flex items-start gap-3.5 cursor-pointer transition"
                :class="!n.read_at ? 'bg-brand-50/60 hover:bg-brand-50' : 'hover:bg-slate-50'"
                style="border-bottom: 1px solid #E9DFCC;"
                @click="openNotification(n)"
            >
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" :class="iconFor(n).tone">
                    <component :is="iconFor(n).icon" class="w-5 h-5" aria-hidden="true" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <div class="text-sm truncate" :class="!n.read_at ? 'font-medium text-slate-900' : 'text-slate-700'">{{ n.data?.title || n.type }}</div>
                        <span v-if="!n.read_at" class="w-1.5 h-1.5 bg-brand-500 rounded-full shrink-0" aria-label="Unread"></span>
                        <div class="ml-auto text-xs text-slate-500 shrink-0">{{ relativeTime(n.created_at) }}</div>
                    </div>
                    <p class="text-sm text-slate-600 mt-1 wrap-break-word leading-relaxed">{{ n.data?.message }}</p>
                    <div v-if="targetFor(n)" class="text-xs text-brand-600 mt-2 inline-flex items-center gap-1">
                        Open →
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
