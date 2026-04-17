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

// Each notification kind maps to a Heroicon + a tone (bg/fg pair). Tone carries
// meaning: money-related = brand teal, wins = amber, alerts = amber, member
// events = slate, system/info = brand. Keeps the list scannable without emoji.
const kindIcon = {
    payment_reminder:  { icon: CurrencyRupeeIcon,  tone: 'bg-amber-50 text-amber-700'       },
    payment_approved:  { icon: CheckCircleIcon,    tone: 'bg-emerald-50 text-emerald-700'   },
    winner_announced:  { icon: TrophyIcon,         tone: 'bg-amber-50 text-amber-700'       },
    tender_window:     { icon: ClockIcon,          tone: 'bg-accent-50 text-accent-700'     },
    member_status:     { icon: UserGroupIcon,      tone: 'bg-slate-100 text-slate-700'      },
    member_joined:     { icon: UserPlusIcon,       tone: 'bg-emerald-50 text-emerald-700'   },
    wishi_created:     { icon: SparklesIcon,       tone: 'bg-brand-50 text-brand-700'       },
    wishi_started:     { icon: RocketLaunchIcon,   tone: 'bg-brand-50 text-brand-700'       },
    wishi_full:        { icon: CheckBadgeIcon,     tone: 'bg-brand-50 text-brand-700'       },
};

function iconFor(n) {
    return kindIcon[n.data?.kind] || { icon: BellIcon, tone: 'bg-slate-100 text-slate-600' };
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
                <h1 class="text-2xl font-bold">Notifications</h1>
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

        <ul v-else class="space-y-2">
            <li v-for="n in store.notifications" :key="n.id"
                class="surface p-4 flex items-start gap-3 cursor-pointer transition hover:border-brand-300 hover:shadow-sm"
                :class="!n.read_at && 'border-brand-200 bg-brand-50/30'"
                @click="openNotification(n)"
            >
                <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0" :class="iconFor(n).tone">
                    <component :is="iconFor(n).icon" class="w-5 h-5" aria-hidden="true" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <div class="font-semibold text-slate-900 truncate">{{ n.data?.title || n.type }}</div>
                        <span v-if="!n.read_at" class="w-2 h-2 bg-brand-500 rounded-full shrink-0" aria-label="Unread"></span>
                    </div>
                    <p class="text-sm text-slate-600 mt-0.5 wrap-break-word">{{ n.data?.message }}</p>
                    <div v-if="targetFor(n)" class="text-xs text-brand-700 font-medium mt-1.5">Tap to open →</div>
                    <div class="text-xs text-slate-400 mt-1.5">{{ relativeTime(n.created_at) }} · {{ formatDateTime(n.created_at) }}</div>
                </div>
            </li>
        </ul>
    </div>
</template>
