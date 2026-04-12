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

const route = useRoute();
const router = useRouter();
const wishiStore = useWishiStore();
const cycleStore = useCycleStore();
const memberStore = useMemberStore();
const contribStore = useContributionStore();
const toast = useToast();
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
    active: 'badge-success',
    completed: 'badge-info',
    cancelled: 'badge-danger',
};

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
    if (!confirm('Start this WISHI now? Every member will be notified, start date will be today, and cycle #1 will open immediately.')) return;
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

onMounted(() => loadAll(route.params.uuid));

watch(() => route.params.uuid, (newUuid, oldUuid) => {
    if (newUuid && newUuid !== oldUuid) loadAll(newUuid);
});
</script>

<template>
    <div v-if="!wishi" class="text-center py-16 text-gray-400">Loading…</div>
    <div v-else class="space-y-5">
        <!-- Draft / not-full: waiting for members -->
        <div v-if="wishi.status === 'draft' && !wishi.is_full" class="surface-padded bg-amber-50 border-amber-200">
            <div class="flex items-start gap-3 flex-wrap">
                <div class="text-2xl">⏳</div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-amber-900">Waiting for members</h3>
                    <p class="text-sm text-amber-800 mt-0.5">
                        {{ wishi.active_members_count }} / {{ wishi.total_members }} members joined.
                        <strong>{{ wishi.seats_remaining }} more</strong> needed before this WISHI can start.
                        <span v-if="wishi.pending_members_count"> · {{ wishi.pending_members_count }} pending approval.</span>
                    </p>
                    <p class="text-xs text-amber-700 mt-1">Start date isn't fixed yet — it becomes today's date when the admin starts the WISHI.</p>
                </div>
            </div>
        </div>

        <!-- Draft / full: ready for admin to start -->
        <div v-else-if="wishi.status === 'draft' && wishi.can_start && isAdmin" class="surface-padded bg-emerald-50 border-emerald-200">
            <div class="flex items-start gap-3 flex-wrap">
                <div class="text-2xl">✅</div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-emerald-900">Ready to start</h3>
                    <p class="text-sm text-emerald-800 mt-0.5">All {{ wishi.total_members }} members have joined. Start the WISHI to open cycle #1 — every member will be notified.</p>
                </div>
                <button @click="startWishi" :disabled="starting" class="btn-success shrink-0">
                    {{ starting ? 'Starting…' : '🚀 Start WISHI now' }}
                </button>
            </div>
        </div>

        <!-- Draft / full / non-admin: waiting message -->
        <div v-else-if="wishi.status === 'draft' && wishi.can_start && !isAdmin" class="surface-padded bg-indigo-50 border-indigo-200">
            <h3 class="font-semibold text-indigo-900">All members joined</h3>
            <p class="text-sm text-indigo-800 mt-0.5">Waiting for the admin ({{ wishi.creator?.name }}) to start this WISHI.</p>
        </div>

        <!-- Header -->
        <div class="surface-padded">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span :class="statusBadge[wishi.status]" class="capitalize">{{ wishiStatusLabels[wishi.status] }}</span>
                        <span class="badge-brand capitalize">{{ wishi.cycle_type }} draws</span>
                        <span v-if="wishi.cycle_type === 'hybrid'" class="badge-gray">{{ wishi.auto_cycles_count }}A / {{ wishi.tender_cycles_count }}T</span>
                        <span v-if="isAdmin" class="badge-info">You're admin</span>
                    </div>
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900 truncate">{{ wishi.name }}</h1>
                    <p class="text-sm text-gray-500 mt-1">
                        <span v-if="wishi.status === 'draft'">Planned start: {{ formatDate(wishi.start_date) }} (not yet fixed)</span>
                        <span v-else>Started {{ formatDate(wishi.start_date) }}</span>
                        · {{ wishi.total_members }} members
                    </p>
                </div>
                <div class="text-left sm:text-right">
                    <div class="text-sm text-gray-500">Pool size</div>
                    <div class="text-2xl font-bold">{{ formatINR(wishi.total_pool) }}</div>
                    <div class="text-xs text-gray-400">{{ formatINR(wishi.monthly_contribution) }} × {{ wishi.total_members }}</div>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-5">
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500">Opened cycles</div>
                    <div class="text-xl font-bold text-emerald-600">{{ wishi.cycles_completed ?? 0 }} / {{ wishi.duration_months }}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500">Remaining</div>
                    <div class="text-xl font-bold text-amber-600">{{ wishi.cycles_remaining ?? wishi.duration_months }}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500">Active members</div>
                    <div class="text-xl font-bold">{{ wishi.active_members_count ?? '—' }} / {{ wishi.total_members }}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500">Deferred pending</div>
                    <div class="text-xl font-bold text-amber-700">{{ formatINR(wishi.deferred_pending_total || 0) }}</div>
                </div>
            </div>

            <div v-if="wishi.status !== 'draft'" class="mt-4">
                <div class="flex items-center justify-between text-xs text-gray-500 mb-1.5">
                    <span>Cycle progress</span>
                    <span>{{ wishi.current_cycle }} / {{ wishi.duration_months }}</span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-indigo-500 to-purple-500 rounded-full transition-all" :style="{ width: progress + '%' }"></div>
                </div>
            </div>
            <div v-else class="mt-4">
                <div class="flex items-center justify-between text-xs text-gray-500 mb-1.5">
                    <span>Members joined</span>
                    <span>{{ wishi.active_members_count }} / {{ wishi.total_members }}</span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all" :class="wishi.is_full ? 'bg-emerald-500' : 'bg-amber-500'"
                        :style="{ width: Math.min(100, (wishi.active_members_count / wishi.total_members) * 100) + '%' }"></div>
                </div>
            </div>
        </div>

        <!-- Tabs bar -->
        <div class="surface overflow-hidden">
            <nav class="flex overflow-x-auto border-b border-gray-200 bg-gray-50" role="tablist">
                <button
                    v-for="t in tabs" :key="t.key"
                    @click="switchTab(t)"
                    :aria-selected="activeTab === t.key" role="tab" type="button"
                    class="px-4 sm:px-5 py-3 text-sm font-medium whitespace-nowrap transition relative focus:outline-none"
                    :class="activeTab === t.key ? 'text-indigo-700 bg-white' : 'text-gray-500 hover:text-gray-800 hover:bg-gray-100'"
                >
                    {{ t.label }}
                    <span v-if="activeTab === t.key" class="absolute bottom-0 left-0 right-0 h-0.5 bg-indigo-600"></span>
                </button>
            </nav>

            <div class="p-4 sm:p-5 md:p-6">
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
