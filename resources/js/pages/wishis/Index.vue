<script setup>
import { onMounted, reactive, watch, ref, computed } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useWishiStore } from '@/stores/wishi';
import { useAuthStore } from '@/stores/auth';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useToast } from 'vue-toastification';
import { formatINR, formatDate, wishiStatusLabels } from '@/utils/format';

const store = useWishiStore();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const toast = useToast();

function openWishi(uuid) {
    router.push(`/wishis/${uuid}`);
}

useBreadcrumbs(() => [{ label: 'WISHIs' }]);

const filters = reactive({
    q: '',
    status: '',
    role: '',
    scope: route.query.scope || 'all',
    cycle_type: '',
    sort: 'newest',
});
const page = ref(1);
const joiningUuid = ref(null);
const startingUuid = ref(null);

let debounce;
function refresh() {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        store.fetchAll({ ...filters, page: page.value });
    }, 200);
}

onMounted(() => store.fetchAll({ ...filters, page: page.value }));
watch(filters, () => { page.value = 1; refresh(); }, { deep: true });

// When user switches to Discover, clear filters that don't apply there so the
// query stays semantically clean (role + status are meaningless for discover).
watch(() => filters.scope, (s) => {
    if (s === 'discover') {
        filters.role = '';
        filters.status = '';
    }
});

function clearAll() {
    filters.q = ''; filters.status = ''; filters.role = ''; filters.cycle_type = ''; filters.sort = 'newest';
    page.value = 1;
}

async function requestJoin(uuid) {
    joiningUuid.value = uuid;
    try {
        const res = await store.join(uuid);
        toast.success(res.status === 'approved' ? 'You have joined.' : 'Join request sent to admin.');
        refresh();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not send join request.');
    } finally {
        joiningUuid.value = null;
    }
}

async function startWishiFromCard(uuid) {
    if (! confirm('Start this WISHI now? After starting, no member can be cancelled and cycle #1 (organizer payout) will open.')) return;
    startingUuid.value = uuid;
    try {
        await store.activate(uuid);
        toast.success('WISHI started.');
        refresh();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not start WISHI.');
    } finally {
        startingUuid.value = null;
    }
}

function gotoPage(n) { page.value = n; store.fetchAll({ ...filters, page: n }); window.scrollTo({ top: 0, behavior: 'smooth' }); }

const statusBadge = {
    draft: 'badge-gray',
    planned: 'badge-warning',
    active: 'badge-success',
    completed: 'badge-info',
    cancelled: 'badge-danger',
};

// Chip list adapts to scope + counts:
//  • 'All' is always shown (and clears the filter).
//  • Draft is admin-only and only when there are drafts.
//  • Every other chip is shown only when its count > 0 in the current scope —
//    so empty buckets like "Planned (0)" or "Cancelled (0)" don't clutter the UI.
//  • Discover scope inherently means "planned only", so we hide the chips there.
const statusChips = computed(() => {
    const counts = store.counts || {};
    if (filters.scope === 'discover') {
        return [{ key: '', label: 'All' }];
    }
    const candidates = [
        { key: '', label: 'All', alwaysShow: true },
        { key: 'draft', label: 'Draft', adminOnly: true },
        { key: 'planned', label: 'Planned' },
        { key: 'active', label: 'Active' },
        { key: 'completed', label: 'Completed' },
        { key: 'cancelled', label: 'Cancelled' },
    ];
    return candidates.filter((c) => {
        if (c.alwaysShow) return true;
        if (c.adminOnly && !auth.user?.is_admin) return false;
        return (counts[c.key] || 0) > 0;
    });
});
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">WISHIs</h1>
                <p class="text-sm text-gray-500">Yours plus every WISHI on the platform that's accepting new members.</p>
            </div>
            <RouterLink v-if="auth.user?.is_admin" to="/wishis/create" class="btn-primary">+ New WISHI</RouterLink>
            <span v-else class="text-xs text-gray-500">Only platform admins can create WISHIs</span>
        </div>

        <!-- Scope tabs -->
        <div class="flex gap-1 bg-gray-100 p-1 rounded-lg w-fit">
            <button @click="filters.scope = 'all'" :class="filters.scope === 'all' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600 hover:text-gray-900'" class="px-4 py-1.5 rounded-md text-sm font-medium">All</button>
            <button @click="filters.scope = 'mine'" :class="filters.scope === 'mine' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600 hover:text-gray-900'" class="px-4 py-1.5 rounded-md text-sm font-medium">My WISHIs</button>
            <button @click="filters.scope = 'discover'" :class="filters.scope === 'discover' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600 hover:text-gray-900'" class="px-4 py-1.5 rounded-md text-sm font-medium">
                Discover
                <span v-if="store.counts" class="ml-1 opacity-70 text-xs">({{ store.counts.all }})</span>
            </button>
        </div>

        <!-- Search + filters -->
        <div class="surface-padded space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto_auto_auto] gap-3">
                <div class="relative">
                    <input v-model="filters.q" type="search" placeholder="Search by name…" class="form-input pl-9" />
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
                </div>
                <select v-if="filters.scope !== 'discover'" v-model="filters.role" class="form-input">
                    <option value="">Any role</option>
                    <option value="admin">Admin of</option>
                    <option value="member">Member of</option>
                </select>
                <select v-model="filters.cycle_type" class="form-input">
                    <option value="">Any type</option>
                    <option value="random">Random</option>
                    <option value="tender">Tender</option>
                    <option value="hybrid">Hybrid</option>
                </select>
                <select v-model="filters.sort" class="form-input">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="name">Name A → Z</option>
                </select>
            </div>

            <!-- Status chips -->
            <div class="flex flex-wrap gap-1.5 items-center">
                <button v-for="s in statusChips" :key="s.key"
                    @click="filters.status = s.key"
                    :class="filters.status === s.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:border-indigo-400'"
                    class="px-3 py-1.5 rounded-full border text-xs font-medium transition"
                >
                    {{ s.label }}
                    <span v-if="store.counts && s.key === ''" class="ml-1 opacity-70">({{ store.counts.all }})</span>
                    <span v-else-if="store.counts && store.counts[s.key] !== undefined" class="ml-1 opacity-70">({{ store.counts[s.key] }})</span>
                </button>
                <button v-if="filters.q || filters.status || filters.role || filters.cycle_type || filters.sort !== 'newest'"
                    @click="clearAll"
                    class="ml-auto text-xs text-gray-500 hover:text-gray-800"
                >
                    ✕ Clear all
                </button>
            </div>
        </div>

        <!-- Results -->
        <div v-if="store.loading" class="text-center py-16 text-gray-400">Loading…</div>

        <div v-else-if="!store.wishis.length" class="surface-padded text-center py-16">
            <div class="text-5xl mb-3">🔍</div>
            <h3 class="text-lg font-semibold">No WISHIs match these filters</h3>
            <p class="text-sm text-gray-500 mt-1">Try clearing search or status.</p>
            <button @click="clearAll" class="btn-secondary mt-5">Clear filters</button>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            <div v-for="w in store.wishis" :key="w.id"
                @click="openWishi(w.uuid)"
                class="surface-padded hover:shadow-md hover:border-indigo-300 transition group cursor-pointer"
                :class="w.can_join ? 'border-indigo-200 bg-indigo-50/30' : ''"
            >
                <div class="flex items-start justify-between mb-3 gap-2">
                    <div class="flex flex-wrap gap-1.5">
                        <span :class="statusBadge[w.status]" class="capitalize">{{ wishiStatusLabels[w.status] }}</span>
                        <span v-if="w.cycle_type === 'random'" class="badge-info">🎲 Random</span>
                        <span v-else-if="w.cycle_type === 'tender'" class="badge-warning">💰 Tender</span>
                        <span v-else class="badge-brand" :title="`${w.auto_cycles_count} random + ${w.tender_cycles_count} tender cycles`">
                            ⚡ Hybrid · {{ w.auto_cycles_count }}R / {{ w.tender_cycles_count }}T
                        </span>
                        <span v-if="w.can_join" class="badge-info">✨ New · open to join</span>
                    </div>
                    <span v-if="w.is_admin" class="badge-brand shrink-0">Admin</span>
                    <span v-else-if="w.is_member" class="badge-success shrink-0 capitalize">{{ w.my_membership_status === 'pending' ? 'Pending' : 'Member' }}</span>
                </div>

                <h3 class="font-semibold text-lg text-gray-900 group-hover:text-indigo-600">{{ w.name }}</h3>

                <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                    <div><div class="text-gray-500 text-xs">Monthly</div><div class="font-semibold">{{ formatINR(w.monthly_contribution) }}</div></div>
                    <div><div class="text-gray-500 text-xs">Pool</div><div class="font-semibold">{{ formatINR(w.total_pool) }}</div></div>
                    <div><div class="text-gray-500 text-xs">Members</div><div class="font-semibold">{{ w.total_joined ?? ((w.active_members_count ?? 0) + 1) }} / {{ w.total_members }}</div></div>
                    <div>
                        <div class="text-gray-500 text-xs">Opened cycles</div>
                        <div class="font-semibold"><span class="text-emerald-600">{{ w.cycles_completed ?? 0 }}</span><span class="text-gray-400"> / {{ w.duration_months }}</span></div>
                    </div>
                </div>

                <div class="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-indigo-500 to-purple-500 rounded-full" :style="{ width: Math.min(100, ((w.cycles_completed ?? 0) / w.duration_months) * 100) + '%' }"></div>
                </div>

                <div v-if="w.active_tender_cycle" class="mt-3 rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs">
                    <div class="flex items-center justify-between mb-1">
                        <div class="font-semibold text-amber-900">🔔 Tender live · Cycle #{{ w.active_tender_cycle.cycle_number }}</div>
                        <span class="badge-warning capitalize">{{ w.active_tender_cycle.status.replace('_', ' ') }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-1 text-amber-800">
                        <div>Bids: <strong>{{ w.active_tender_cycle.bid_count }}</strong></div>
                        <div v-if="w.active_tender_cycle.lowest_bid > 0">Lowest: <strong>{{ formatINR(w.active_tender_cycle.lowest_bid) }}</strong></div>
                    </div>
                </div>

                <div v-if="w.is_admin && w.unhandled_surplus > 0" class="mt-3 rounded-lg bg-emerald-50 border border-emerald-200 p-3 text-xs">
                    <div class="font-semibold text-emerald-900">💵 Admin action needed</div>
                    <div class="text-emerald-800 mt-0.5">Unhandled surplus: <strong>{{ formatINR(w.unhandled_surplus) }}</strong></div>
                </div>

                <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                    <span v-if="w.status === 'draft'">Planned from {{ formatDate(w.start_date) }}</span>
                    <span v-else>Started {{ formatDate(w.start_date) }}</span>
                    <span v-if="w.seats_remaining > 0" class="text-amber-600 font-medium">{{ w.seats_remaining }} seat{{ w.seats_remaining !== 1 ? 's' : '' }} left to join</span>
                    <span v-else-if="w.cycles_remaining > 0">{{ w.cycles_remaining }} cycle{{ w.cycles_remaining !== 1 ? 's' : '' }} left</span>
                    <span v-else class="text-emerald-600 font-medium">✓ Done</span>
                </div>
                <button v-if="w.can_join" @click.stop="requestJoin(w.uuid)" :disabled="joiningUuid === w.uuid" class="btn-primary btn-sm w-full mt-3">
                    {{ joiningUuid === w.uuid ? 'Sending…' : (w.require_approval ? '📩 Request to join' : '✅ Join now') }}
                </button>
                <button v-if="w.is_admin && w.can_start" @click.stop="startWishiFromCard(w.uuid)" :disabled="startingUuid === w.uuid" class="btn-success btn-sm w-full mt-3">
                    {{ startingUuid === w.uuid ? 'Starting…' : '🚀 Start WISHI now' }}
                </button>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="store.meta && store.meta.last_page > 1" class="flex items-center justify-between text-sm">
            <div class="text-gray-500">Page {{ store.meta.current_page }} of {{ store.meta.last_page }} · {{ store.meta.total }} total</div>
            <div class="flex gap-2">
                <button :disabled="page <= 1" @click="gotoPage(page - 1)" class="btn-secondary btn-sm">← Prev</button>
                <button :disabled="page >= store.meta.last_page" @click="gotoPage(page + 1)" class="btn-secondary btn-sm">Next →</button>
            </div>
        </div>
    </div>
</template>
