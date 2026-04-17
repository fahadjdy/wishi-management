<script setup>
import { onMounted, computed, watch, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useWishiStore } from '@/stores/wishi';
import { useCycleStore } from '@/stores/cycle';
import { useMemberStore } from '@/stores/member';
import { useToast } from 'vue-toastification';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useContributionStore } from '@/stores/contribution';
import { formatINR, formatDate, wishiStatusLabels } from '@/utils/format';

import OverviewTab from './tabs/OverviewTab.vue';
import CyclesTab from './tabs/CyclesTab.vue';
import MembersTab from './tabs/MembersTab.vue';
import SettingsTab from './tabs/SettingsTab.vue';
import AuditTab from './tabs/AuditTab.vue';
import { useConfirm } from '@/composables/useConfirm';
import {
    PaperAirplaneIcon, PlayIcon, XMarkIcon,
    DocumentTextIcon, ClockIcon, CheckCircleIcon, PauseCircleIcon,
} from '@heroicons/vue/24/outline';

const route = useRoute();
const router = useRouter();
const wishiStore = useWishiStore();
const cycleStore = useCycleStore();
const memberStore = useMemberStore();
const contribStore = useContributionStore();
const toast = useToast();
const { confirm: uiConfirm } = useConfirm();
const starting = ref(false);

const wishi = computed(() => wishiStore.currentWishi);
const isAdmin = computed(() => wishi.value?.is_admin);

useBreadcrumbs(() => [
    { label: 'WISHIs', to: '/wishis' },
    { label: wishi.value?.name || 'WISHI' },
]);

const ROUTE_TO_TAB = {
    'wishis.show': 'overview',
    'wishis.cycles': 'cycles',
    'wishis.members': 'members',
    'wishis.settings': 'settings',
    'wishis.audit': 'audit',
};
const activeTab = computed(() => ROUTE_TO_TAB[route.name] || 'overview');

const progress = computed(() => {
    if (!wishi.value) return 0;
    return Math.min(100, Math.round((wishi.value.current_cycle / wishi.value.duration_months) * 100));
});

const tabs = computed(() => {
    const base = [
        { key: 'overview', label: 'Overview', route: 'wishis.show' },
        { key: 'cycles', label: 'Cycles', route: 'wishis.cycles' },
    ];
    // Members / Settings / Audit are admin-only. Regular members only see Overview + Cycles.
    if (isAdmin.value) {
        base.push({ key: 'members', label: 'Members', route: 'wishis.members' });
        base.push({ key: 'settings', label: 'Settings', route: 'wishis.settings' });
        base.push({ key: 'audit', label: 'Audit Log', route: 'wishis.audit' });
    }
    return base;
});

const statusBadge = {
    draft: 'badge-gray',
    planned: 'badge-warning',
    active: 'badge-success',
    completed: 'badge-info',
    cancelled: 'badge-danger',
};

const publishing = ref(false);
async function publishWishi() {
    const ok = await uiConfirm({
        title: 'Publish this WISHI?',
        message: 'It will become visible to every platform member in the Discover list, and they can request to join.',
        meta: wishi.value ? {
            'WISHI': wishi.value.name,
            'Contribution': `${formatINR(wishi.value.monthly_contribution)} / cycle`,
            'Seats open': `${wishi.value.member_capacity ?? (wishi.value.total_members - 1)}`,
        } : undefined,
        confirmText: 'Publish WISHI',
        tone: 'primary',
    });
    if (!ok) return;
    publishing.value = true;
    try {
        await wishiStore.publish(route.params.uuid);
        toast.success('WISHI published — now discoverable by members.');
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not publish.');
    } finally {
        publishing.value = false;
    }
}

async function loadAll(uuid) {
    try {
        await wishiStore.fetch(uuid);
        const active = cycleStore.cycles.find((c) => ['contribution_open', 'bidding_open', 'selection_pending'].includes(c.status));
        await Promise.all([
            cycleStore.fetchAll(uuid),
            memberStore.fetch(uuid),
        ]);
        // Preload contributions for the active cycle so Overview tab can show pending count.
        const current = cycleStore.cycles.find((c) => c.cycle_number === wishiStore.currentWishi?.current_cycle);
        if (current) {
            try { await contribStore.fetch(uuid, current.id); } catch {}
        }
    } catch (e) {
        toast.error('Could not load WISHI.');
        router.push('/wishis');
    }
}

function switchTab(tab) {
    if (route.name === tab.route) return;
    router.push({ name: tab.route, params: { uuid: route.params.uuid } });
}

async function startWishi() {
    const ok = await uiConfirm({
        title: 'Start this WISHI now?',
        message: `After starting, no member can leave. Cycle #1 (organizer payout) opens immediately and every member will be notified.`,
        meta: wishi.value ? {
            'WISHI': wishi.value.name,
            'Members': `${wishi.value.total_members} (incl. admin)`,
            'Pool / cycle': formatINR(wishi.value.total_pool),
            'Duration': `${wishi.value.duration_months} cycles`,
        } : undefined,
        requireTypeText: wishi.value?.name,
        confirmText: 'Yes, start WISHI',
        cancelText: 'Not yet',
        tone: 'primary',
    });
    if (!ok) return;
    starting.value = true;
    try {
        await wishiStore.activate(route.params.uuid);
        await cycleStore.fetchAll(route.params.uuid);
        toast.success('WISHI started — first cycle is now open.');
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not start WISHI.');
    } finally {
        starting.value = false;
    }
}

// Member-side: cancel own join request / leave before activation. Only
// shown while wishi is draft/planned and current user has a pending or
// approved membership (but isn't the admin, who has their own flows).
const cancelling = ref(false);
const canCancelOwn = computed(() =>
    wishi.value
    && !wishi.value.is_admin
    && wishi.value.is_member
    && ['pending', 'approved'].includes(wishi.value.my_membership_status)
    && ['draft', 'planned'].includes(wishi.value.status)
);
async function cancelOwnMembership() {
    const pending = wishi.value?.my_membership_status === 'pending';
    const ok = await uiConfirm({
        title: pending ? 'Cancel join request?' : 'Leave this WISHI?',
        message: pending
            ? `Your join request for ${wishi.value?.name} will be withdrawn and the admin notified the seat is free again.`
            : `You can only leave ${wishi.value?.name} while it hasn't started yet. After it starts, your seat is locked.`,
        confirmText: pending ? 'Cancel request' : 'Leave WISHI',
        tone: 'danger',
    });
    if (!ok) return;
    cancelling.value = true;
    try {
        await wishiStore.cancelJoin(route.params.uuid);
        toast.success(pending ? 'Join request cancelled.' : 'You have left the WISHI.');
        router.push('/wishis');
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not cancel.');
    } finally {
        cancelling.value = false;
    }
}

onMounted(() => loadAll(route.params.uuid));

watch(() => route.params.uuid, (newUuid, oldUuid) => {
    if (newUuid && newUuid !== oldUuid) loadAll(newUuid);
});
</script>

<template>
    <div v-if="!wishi" class="text-center py-16 text-gray-400">Loading…</div>
    <div v-else class="space-y-3">
        <!-- =============================================================
             COMPACT HEADER CARD — one card with name + badges + pool +
             inline alert + stats strip + progress bar. Designed to fit in
             one viewport without scrolling.
        ============================================================== -->
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <!-- Top row: name + badges + pool + primary action -->
            <div class="px-5 py-4 flex items-start gap-4 flex-wrap">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5 mb-1.5">
                        <span :class="statusBadge[wishi.status]" class="capitalize">{{ wishiStatusLabels[wishi.status] }}</span>
                        <span class="badge-brand capitalize">{{ wishi.cycle_type }}</span>
                        <span v-if="wishi.cycle_type === 'hybrid'" class="badge-gray">{{ wishi.auto_cycles_count }}A/{{ wishi.tender_cycles_count }}T</span>
                        <span class="badge-info capitalize">
                            {{ wishi.cycle_frequency || 'monthly' }}<span v-if="wishi.cycle_frequency === 'custom' && wishi.cycle_interval_days"> · every {{ wishi.cycle_interval_days }}d</span>
                        </span>
                        <span v-if="isAdmin" class="badge-brand">Admin</span>
                    </div>
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900 leading-tight truncate">{{ wishi.name }}</h1>
                    <p class="text-xs text-gray-500 mt-0.5">
                        <span v-if="wishi.status === 'draft'">Planned start {{ formatDate(wishi.start_date) }}</span>
                        <span v-else>Started {{ formatDate(wishi.start_date) }}</span>
                        · {{ wishi.total_members }} members (incl. admin) · Created by {{ wishi.creator?.name || '—' }}
                    </p>
                </div>

                <div class="flex items-start gap-3 shrink-0">
                    <div class="text-right">
                        <div class="text-[11px] uppercase tracking-wide text-gray-400">Pool / cycle</div>
                        <div class="text-2xl font-bold text-gray-900 leading-none">{{ formatINR(wishi.total_pool) }}</div>
                        <div class="text-[11px] text-gray-400 mt-0.5">
                            {{ formatINR(wishi.monthly_contribution) }} × {{ wishi.total_members }} members
                        </div>
                    </div>
                    <button v-if="wishi.status === 'draft' && isAdmin" @click="publishWishi" :disabled="publishing" class="btn-primary btn-sm self-center">
                        <PaperAirplaneIcon v-if="!publishing" class="w-4 h-4" aria-hidden="true" />
                        {{ publishing ? 'Publishing…' : 'Publish' }}
                    </button>
                    <button v-else-if="wishi.status === 'planned' && wishi.can_start && isAdmin" @click="startWishi" :disabled="starting" class="btn-success btn-sm self-center">
                        <PlayIcon v-if="!starting" class="w-4 h-4" aria-hidden="true" />
                        {{ starting ? 'Starting…' : 'Start WISHI' }}
                    </button>
                    <button v-if="canCancelOwn" @click="cancelOwnMembership" :disabled="cancelling" class="btn-danger btn-sm self-center"
                        :title="wishi.my_membership_status === 'pending' ? 'Cancel your pending join request' : 'Leave this WISHI (only allowed before it starts)'">
                        <XMarkIcon v-if="!cancelling" class="w-4 h-4" aria-hidden="true" />
                        {{ cancelling ? 'Cancelling…' : (wishi.my_membership_status === 'pending' ? 'Cancel request' : 'Leave WISHI') }}
                    </button>
                </div>
            </div>

            <!-- Inline alert strip — one row per state, Heroicon as the visual anchor -->
            <div v-if="wishi.status === 'draft' && isAdmin" class="px-5 py-2 bg-slate-50 border-y border-slate-100 text-xs text-slate-700 flex items-center gap-2">
                <DocumentTextIcon class="w-4 h-4 text-slate-500 shrink-0" aria-hidden="true" />
                <span><strong>Draft</strong> — only you can see this. Publish to let members discover &amp; join.</span>
            </div>
            <div v-else-if="wishi.status === 'planned' && !wishi.is_full" class="px-5 py-2 bg-amber-50 border-y border-amber-100 text-xs text-amber-900 flex items-center gap-2">
                <ClockIcon class="w-4 h-4 text-amber-600 shrink-0" aria-hidden="true" />
                <span>
                    <strong>Waiting for members</strong> — {{ wishi.seats_remaining }} more needed before start.
                    <span v-if="wishi.pending_members_count"> · {{ wishi.pending_members_count }} pending approval.</span>
                    Cannot open before {{ formatDate(wishi.start_date) }}.
                </span>
            </div>
            <div v-else-if="wishi.status === 'planned' && wishi.can_start && isAdmin" class="px-5 py-2 bg-emerald-50 border-y border-emerald-100 text-xs text-emerald-900 flex items-center gap-2">
                <CheckCircleIcon class="w-4 h-4 text-emerald-600 shrink-0" aria-hidden="true" />
                <span><strong>Ready to start</strong> — all {{ wishi.total_members }} seats filled. Click <em>Start WISHI</em> to open cycle #1.</span>
            </div>
            <div v-else-if="wishi.status === 'planned' && wishi.is_full && !isAdmin" class="px-5 py-2 bg-brand-50 border-y border-brand-100 text-xs text-brand-900 flex items-center gap-2">
                <PauseCircleIcon class="w-4 h-4 text-brand-600 shrink-0" aria-hidden="true" />
                <span><strong>All members joined.</strong> Waiting for {{ wishi.creator?.name }} to start this WISHI.</span>
            </div>

            <!-- Stats strip — inline, divided, no card-per-stat -->
            <div class="px-5 py-3 bg-linear-to-b from-gray-50/60 to-white grid grid-cols-2 sm:grid-cols-4 divide-x divide-gray-100">
                <div class="px-3 first:pl-0">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">Cycles opened</div>
                    <div class="text-base font-bold text-emerald-600 mt-0.5">{{ wishi.cycles_completed ?? 0 }}<span class="text-gray-300 font-normal"> / {{ wishi.duration_months }}</span></div>
                </div>
                <div class="px-3">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">Remaining</div>
                    <div class="text-base font-bold text-amber-600 mt-0.5">{{ wishi.cycles_remaining ?? wishi.duration_months }}</div>
                </div>
                <div class="px-3">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">Active members</div>
                    <div class="text-base font-bold text-gray-900 mt-0.5">{{ wishi.total_joined ?? ((wishi.active_members_count ?? 0) + 1) }}<span class="text-gray-300 font-normal"> / {{ wishi.total_members }}</span></div>
                </div>
                <div class="px-3">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">Deferred pending</div>
                    <div class="text-base font-bold mt-0.5" :class="(wishi.deferred_pending_total || 0) > 0 ? 'text-amber-700' : 'text-gray-400'">{{ formatINR(wishi.deferred_pending_total || 0) }}</div>
                </div>
            </div>

            <!-- Slim progress bar (no labels — they're covered by stats above) -->
            <div class="h-1 bg-gray-100">
                <div v-if="wishi.status !== 'draft'" class="h-full bg-linear-to-r from-indigo-500 to-purple-500 transition-all" :style="{ width: progress + '%' }"></div>
                <div v-else class="h-full transition-all" :class="wishi.is_full ? 'bg-emerald-500' : 'bg-amber-500'"
                    :style="{ width: Math.min(100, ((wishi.total_joined ?? (wishi.active_members_count + 1)) / Math.max(1, wishi.total_members)) * 100) + '%' }"></div>
            </div>
        </div>

        <!-- =============================================================
             TABS — flush with header, no top margin
        ============================================================== -->
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <nav class="flex overflow-x-auto border-b border-gray-200 bg-gray-50/60" role="tablist">
                <button
                    v-for="t in tabs" :key="t.key"
                    @click="switchTab(t)"
                    :aria-selected="activeTab === t.key" role="tab" type="button"
                    class="px-4 sm:px-5 py-2.5 text-sm font-medium whitespace-nowrap transition relative focus:outline-none"
                    :class="activeTab === t.key ? 'text-indigo-700 bg-white' : 'text-gray-500 hover:text-gray-800 hover:bg-gray-100'"
                >
                    {{ t.label }}
                    <span v-if="activeTab === t.key" class="absolute bottom-0 left-0 right-0 h-0.5 bg-indigo-600"></span>
                </button>
            </nav>

            <div class="p-4 sm:p-5">
                <OverviewTab v-if="activeTab === 'overview'" />
                <CyclesTab v-else-if="activeTab === 'cycles'" />
                <MembersTab v-else-if="activeTab === 'members'" />
                <SettingsTab v-else-if="activeTab === 'settings' && isAdmin" />
                <AuditTab v-else-if="activeTab === 'audit' && isAdmin" />
                <div v-else class="text-center py-10 text-gray-400 text-sm">This tab isn't available.</div>
            </div>
        </div>
    </div>
</template>
