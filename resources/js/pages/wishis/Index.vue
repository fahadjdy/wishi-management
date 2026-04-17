<script setup>
import { onMounted, reactive, watch, ref, computed } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useWishiStore } from '@/stores/wishi';
import { useAuthStore } from '@/stores/auth';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useToast } from 'vue-toastification';
import { formatINR, formatDate, wishiStatusLabels } from '@/utils/format';
import { useConfirm } from '@/composables/useConfirm';
import {
    PlusIcon, MagnifyingGlassIcon, XMarkIcon, RectangleStackIcon,
    PaperAirplaneIcon, PlayIcon, ChevronLeftIcon, ChevronRightIcon,
    CheckIcon, ArrowRightStartOnRectangleIcon,
} from '@heroicons/vue/24/outline';

const store = useWishiStore();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const toast = useToast();
const { confirm: uiConfirm } = useConfirm();

function openWishi(uuid) {
    router.push(`/wishis/${uuid}`);
}

useBreadcrumbs(() => [{ label: 'WISHIs' }]);

// Scope is always `all` — the server returns the user's own WISHIs + every
// joinable `planned` WISHI, which is the same "one list" the user sees.
// The role/status/cycle_type chips below handle all meaningful filtering.
const filters = reactive({
    q: '',
    status: '',
    role: '',
    scope: 'all',
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

const hasFilters = computed(() =>
    !!filters.q || !!filters.status || !!filters.role || !!filters.cycle_type || filters.sort !== 'newest'
);

function clearAll() {
    filters.q = ''; filters.status = ''; filters.role = '';
    filters.cycle_type = ''; filters.sort = 'newest';
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

async function cancelJoin(w) {
    const pending = w.my_membership_status === 'pending';
    const ok = await uiConfirm({
        title: pending ? 'Cancel join request?' : 'Leave this WISHI?',
        message: pending
            ? 'The admin will be notified that your seat is free again.'
            : `You can only leave ${w.name} while it hasn't started yet.`,
        confirmText: pending ? 'Cancel request' : 'Leave WISHI',
        tone: 'danger',
    });
    if (! ok) return;
    joiningUuid.value = w.uuid;
    try {
        await store.cancelJoin(w.uuid);
        toast.success(pending ? 'Join request cancelled.' : 'You have left the WISHI.');
        refresh();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not cancel.');
    } finally {
        joiningUuid.value = null;
    }
}

async function startWishiFromCard(uuid) {
    const w = store.wishis.find((x) => x.uuid === uuid);
    const ok = await uiConfirm({
        title: 'Start this WISHI now?',
        message: 'After starting, no member can be cancelled and cycle #1 (organizer payout) will open immediately.',
        meta: w ? {
            'WISHI': w.name,
            'Members': `${w.total_members}`,
            'Pool / cycle': formatINR(w.total_pool),
        } : undefined,
        requireTypeText: w?.name,
        confirmText: 'Yes, start WISHI',
        cancelText: 'Not yet',
        tone: 'primary',
    });
    if (! ok) return;
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

function gotoPage(n) {
    page.value = n;
    store.fetchAll({ ...filters, page: n });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

const statusBadge = {
    draft: 'badge-gray',
    planned: 'badge-warning',
    active: 'badge-success',
    completed: 'badge-info',
    cancelled: 'badge-danger',
};

// Left-edge accent strip on each card — signals role at a glance without a badge.
function roleAccent(w) {
    if (w.is_admin) return 'bg-brand-500';
    if (w.is_member) return 'bg-emerald-500';
    if (w.can_join) return 'bg-amber-400';
    return 'bg-slate-200';
}

// Status chip list. "All" always shown; Draft is admin-only; every other
// chip appears only when its bucket has at least one WISHI so empty buckets
// don't clutter the row.
const statusChips = computed(() => {
    const counts = store.counts || {};
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
        <!-- ============ Header ============ -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">WISHIs</h1>
                <p class="text-sm text-slate-500">Yours plus every WISHI accepting new members.</p>
            </div>
            <RouterLink v-if="auth.user?.is_admin" to="/wishis/create" class="btn-primary">
                <PlusIcon class="w-4 h-4" aria-hidden="true" />
                New WISHI
            </RouterLink>
            <span v-else class="text-xs text-slate-500">Only platform admins can create WISHIs</span>
        </div>

        <!-- ============ Search + filters ============ -->
        <div class="surface-padded space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto_auto_auto] gap-3">
                <div class="relative">
                    <MagnifyingGlassIcon class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
                    <input v-model="filters.q" type="search" placeholder="Search by name…" class="form-input pl-9" />
                </div>
                <select v-model="filters.role" class="form-input" aria-label="Filter by role">
                    <option value="">Any role</option>
                    <option value="admin">Admin of</option>
                    <option value="member">Member of</option>
                </select>
                <select v-model="filters.cycle_type" class="form-input" aria-label="Filter by cycle type">
                    <option value="">Any type</option>
                    <option value="random">Random</option>
                    <option value="tender">Tender</option>
                    <option value="hybrid">Hybrid</option>
                </select>
                <select v-model="filters.sort" class="form-input" aria-label="Sort">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="name">Name A → Z</option>
                </select>
            </div>

            <!-- Status chips -->
            <div class="flex flex-wrap gap-1.5 items-center">
                <button
                    v-for="s in statusChips" :key="s.key"
                    @click="filters.status = s.key"
                    class="chip"
                    :class="filters.status === s.key ? 'chip-active' : 'chip-default'"
                >
                    {{ s.label }}
                    <span v-if="store.counts && s.key === ''" class="opacity-70">({{ store.counts.all }})</span>
                    <span v-else-if="store.counts && store.counts[s.key] !== undefined" class="opacity-70">({{ store.counts[s.key] }})</span>
                </button>
                <button v-if="hasFilters" @click="clearAll" class="ml-auto text-xs text-slate-500 hover:text-slate-800 inline-flex items-center gap-1 focus:outline-none focus-visible:underline">
                    <XMarkIcon class="w-3.5 h-3.5" aria-hidden="true" />
                    Clear all
                </button>
            </div>
        </div>

        <!-- ============ Results ============ -->
        <div v-if="store.loading" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            <div v-for="i in 6" :key="i" class="surface-padded">
                <div class="skeleton h-5 w-24 mb-3"></div>
                <div class="skeleton h-6 w-3/4 mb-4"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><div class="skeleton h-3 w-16 mb-1"></div><div class="skeleton h-5 w-20"></div></div>
                    <div><div class="skeleton h-3 w-16 mb-1"></div><div class="skeleton h-5 w-20"></div></div>
                </div>
                <div class="skeleton h-1.5 w-full mt-4 rounded-full"></div>
                <div class="skeleton h-8 w-full mt-4 rounded-lg"></div>
            </div>
        </div>

        <!-- Empty state — two variants (filtered-zero vs true-zero) -->
        <div v-else-if="!store.wishis.length" class="surface-padded text-center py-14">
            <div class="w-14 h-14 mx-auto rounded-full bg-slate-100 flex items-center justify-center mb-3">
                <MagnifyingGlassIcon v-if="hasFilters" class="w-7 h-7 text-slate-400" aria-hidden="true" />
                <RectangleStackIcon v-else class="w-7 h-7 text-slate-400" aria-hidden="true" />
            </div>
            <template v-if="hasFilters">
                <h3 class="font-semibold text-lg text-slate-900">No WISHIs match these filters</h3>
                <p class="text-sm text-slate-500 mt-1">Try clearing the search or status.</p>
                <button @click="clearAll" class="btn-secondary mt-5">
                    <XMarkIcon class="w-4 h-4" aria-hidden="true" />
                    Clear filters
                </button>
            </template>
            <template v-else-if="auth.user?.is_admin">
                <h3 class="font-semibold text-lg text-slate-900">Create your first WISHI</h3>
                <p class="text-sm text-slate-500 mt-1 max-w-sm mx-auto">
                    You organize, members contribute every cycle, one member wins each cycle. Cycle #1 is your organizer payout.
                </p>
                <RouterLink to="/wishis/create" class="btn-primary mt-5 inline-flex">
                    <PlusIcon class="w-4 h-4" aria-hidden="true" />
                    Create your first WISHI
                </RouterLink>
            </template>
            <template v-else>
                <h3 class="font-semibold text-lg text-slate-900">No WISHIs to show yet</h3>
                <p class="text-sm text-slate-500 mt-1">When your platform admin invites you to a WISHI, it'll appear here.</p>
            </template>
        </div>

        <!-- ============ Cards grid ============ -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            <div
                v-for="w in store.wishis" :key="w.id"
                @click="openWishi(w.uuid)"
                class="relative surface overflow-hidden cursor-pointer transition hover:shadow-md hover:border-brand-300 group focus-ring"
                tabindex="0"
                @keyup.enter="openWishi(w.uuid)"
            >
                <!-- Left-edge role accent bar -->
                <div class="absolute top-0 left-0 bottom-0 w-1" :class="roleAccent(w)" aria-hidden="true"></div>

                <!-- "Open to join" ribbon (only on joinable cards) -->
                <span v-if="w.can_join" class="absolute top-3 right-3 badge-brand">New · open to join</span>

                <div class="p-5 pl-6">
                    <!-- Row 1: ONE primary badge (status) + optional role hint -->
                    <div class="flex items-center justify-between gap-2 mb-3 min-h-6">
                        <span :class="statusBadge[w.status]" class="capitalize">{{ wishiStatusLabels[w.status] }}</span>
                        <span v-if="w.is_admin && !w.can_join" class="text-[11px] font-semibold text-brand-700 uppercase tracking-wide">Your WISHI</span>
                        <span v-else-if="w.is_member && !w.can_join" class="text-[11px] font-semibold text-emerald-700 uppercase tracking-wide">
                            {{ w.my_membership_status === 'pending' ? 'Pending' : 'Member' }}
                        </span>
                    </div>

                    <!-- Title -->
                    <h3 class="font-semibold text-lg text-slate-900 group-hover:text-brand-700 transition truncate">{{ w.name }}</h3>

                    <!-- Meta line (secondary info as text, not badges) -->
                    <p class="text-xs text-slate-500 mt-0.5 truncate">
                        <span class="capitalize">{{ w.cycle_type }}</span><span v-if="w.cycle_type === 'hybrid'"> · {{ w.auto_cycles_count }}R / {{ w.tender_cycles_count }}T</span>
                        · {{ w.duration_months }} cycles
                        <span v-if="w.creator_name"> · by {{ w.creator_name }}</span>
                    </p>

                    <!-- Simplified stats -->
                    <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                        <div>
                            <div class="text-[11px] text-slate-500 uppercase tracking-wide">Pool / cycle</div>
                            <div class="font-bold text-slate-900 text-lg leading-tight">{{ formatINR(w.total_pool) }}</div>
                            <div class="text-[11px] text-slate-400">{{ formatINR(w.monthly_contribution) }} × {{ w.total_members }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] text-slate-500 uppercase tracking-wide">Members</div>
                            <div class="font-bold text-slate-900 text-lg leading-tight">
                                {{ w.total_joined ?? ((w.active_members_count ?? 0) + 1) }}<span class="text-slate-400 text-sm font-normal"> / {{ w.total_members }}</span>
                            </div>
                            <div v-if="w.seats_remaining > 0" class="text-[11px] text-amber-600 font-medium">{{ w.seats_remaining }} seat{{ w.seats_remaining !== 1 ? 's' : '' }} open</div>
                            <div v-else-if="w.cycles_remaining > 0" class="text-[11px] text-slate-400">{{ w.cycles_remaining }} cycle{{ w.cycles_remaining !== 1 ? 's' : '' }} left</div>
                            <div v-else class="text-[11px] text-emerald-600 font-medium">Done</div>
                        </div>
                    </div>

                    <!-- Progress bar (only for active/completed/cancelled) -->
                    <div v-if="!['draft','planned'].includes(w.status)" class="mt-4">
                        <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-brand-500 rounded-full transition-all" :style="{ width: Math.min(100, ((w.cycles_completed ?? 0) / w.duration_months) * 100) + '%' }"></div>
                        </div>
                        <div class="flex items-center justify-between mt-1.5 text-[11px] text-slate-500">
                            <span>{{ w.cycles_completed ?? 0 }} / {{ w.duration_months }} cycles</span>
                            <span v-if="w.status !== 'draft'">Started {{ formatDate(w.start_date) }}</span>
                        </div>
                    </div>
                    <div v-else class="mt-4 text-[11px] text-slate-500">Planned from {{ formatDate(w.start_date) }}</div>

                    <!-- Inline alerts -->
                    <div v-if="w.active_tender_cycle" class="mt-3 rounded-lg bg-amber-50 border border-amber-200 p-2.5 text-xs flex items-center justify-between gap-2">
                        <span class="font-semibold text-amber-900">Tender live · Cycle #{{ w.active_tender_cycle.cycle_number }}</span>
                        <span class="text-amber-700">{{ w.active_tender_cycle.bid_count }} bid{{ w.active_tender_cycle.bid_count !== 1 ? 's' : '' }}</span>
                    </div>
                    <div v-if="w.is_admin && w.unhandled_surplus > 0" class="mt-3 rounded-lg bg-emerald-50 border border-emerald-200 p-2.5 text-xs text-emerald-900 font-semibold">
                        Unhandled surplus: {{ formatINR(w.unhandled_surplus) }}
                    </div>

                    <!-- Action button (one contextual primary per card) -->
                    <button
                        v-if="w.can_join"
                        @click.stop="requestJoin(w.uuid)"
                        :disabled="joiningUuid === w.uuid"
                        class="btn-primary btn-sm w-full mt-4"
                    >
                        <PaperAirplaneIcon v-if="joiningUuid !== w.uuid && w.require_approval" class="w-4 h-4" aria-hidden="true" />
                        <CheckIcon v-else-if="joiningUuid !== w.uuid" class="w-4 h-4" aria-hidden="true" />
                        {{ joiningUuid === w.uuid ? 'Sending…' : (w.require_approval ? 'Request to join' : 'Join now') }}
                    </button>
                    <button
                        v-else-if="w.is_member && ['pending','approved'].includes(w.my_membership_status) && ['draft','planned'].includes(w.status) && !w.is_admin"
                        @click.stop="cancelJoin(w)"
                        :disabled="joiningUuid === w.uuid"
                        class="btn-secondary btn-sm w-full mt-4 text-rose-600 border-rose-200 hover:bg-rose-50 hover:border-rose-300"
                    >
                        <ArrowRightStartOnRectangleIcon v-if="joiningUuid !== w.uuid && w.my_membership_status !== 'pending'" class="w-4 h-4" aria-hidden="true" />
                        <XMarkIcon v-else-if="joiningUuid !== w.uuid" class="w-4 h-4" aria-hidden="true" />
                        {{ joiningUuid === w.uuid ? 'Cancelling…' : (w.my_membership_status === 'pending' ? 'Cancel request' : 'Leave WISHI') }}
                    </button>
                    <button
                        v-if="w.is_admin && w.can_start"
                        @click.stop="startWishiFromCard(w.uuid)"
                        :disabled="startingUuid === w.uuid"
                        class="btn-success btn-sm w-full mt-2"
                    >
                        <PlayIcon v-if="startingUuid !== w.uuid" class="w-4 h-4" aria-hidden="true" />
                        {{ startingUuid === w.uuid ? 'Starting…' : 'Start WISHI now' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ============ Pagination ============ -->
        <div v-if="store.meta && store.meta.last_page > 1" class="flex items-center justify-between text-sm">
            <div class="text-slate-500">Page {{ store.meta.current_page }} of {{ store.meta.last_page }} · {{ store.meta.total }} total</div>
            <div class="flex gap-2">
                <button :disabled="page <= 1" @click="gotoPage(page - 1)" class="btn-secondary btn-sm">
                    <ChevronLeftIcon class="w-4 h-4" aria-hidden="true" />
                    Prev
                </button>
                <button :disabled="page >= store.meta.last_page" @click="gotoPage(page + 1)" class="btn-secondary btn-sm">
                    Next
                    <ChevronRightIcon class="w-4 h-4" aria-hidden="true" />
                </button>
            </div>
        </div>
    </div>
</template>
