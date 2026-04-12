<script setup>
import { onMounted, reactive, watch, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useWishiStore } from '@/stores/wishi';
import { useAuthStore } from '@/stores/auth';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { formatINR, formatDate, wishiStatusLabels } from '@/utils/format';

const store = useWishiStore();
const auth = useAuthStore();

useBreadcrumbs(() => [{ label: 'WISHIs' }]);

const filters = reactive({
    q: '',
    status: '',
    role: '',
    cycle_type: '',
    sort: 'newest',
});
const page = ref(1);

let debounce;
function refresh() {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        store.fetchAll({ ...filters, page: page.value });
    }, 200);
}

onMounted(() => store.fetchAll({ ...filters, page: page.value }));
watch(filters, () => { page.value = 1; refresh(); }, { deep: true });

function clearAll() {
    filters.q = ''; filters.status = ''; filters.role = ''; filters.cycle_type = ''; filters.sort = 'newest';
    page.value = 1;
}

function gotoPage(n) { page.value = n; store.fetchAll({ ...filters, page: n }); window.scrollTo({ top: 0, behavior: 'smooth' }); }

const statusBadge = {
    draft: 'badge-gray',
    active: 'badge-success',
    completed: 'badge-info',
    cancelled: 'badge-danger',
};

const statusChips = [
    { key: '', label: 'All' },
    { key: 'draft', label: 'Planned', hint: 'draft' },
    { key: 'active', label: 'Active' },
    { key: 'completed', label: 'Completed' },
    { key: 'cancelled', label: 'Cancelled' },
];
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Your WISHIs</h1>
                <p class="text-sm text-gray-500">Search, filter, and jump into any chit fund you're part of.</p>
            </div>
            <RouterLink v-if="auth.user?.is_admin" to="/wishis/create" class="btn-primary">+ New WISHI</RouterLink>
            <span v-else class="text-xs text-gray-500">Only platform admins can create WISHIs</span>
        </div>

        <!-- Search + filters -->
        <div class="surface-padded space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto_auto_auto] gap-3">
                <div class="relative">
                    <input v-model="filters.q" type="search" placeholder="Search by name…" class="form-input pl-9" />
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
                </div>
                <select v-model="filters.role" class="form-input">
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
            <RouterLink v-for="w in store.wishis" :key="w.id" :to="`/wishis/${w.uuid}`"
                class="surface-padded hover:shadow-md hover:border-indigo-300 transition group"
            >
                <div class="flex items-start justify-between mb-3 gap-2">
                    <div class="flex flex-wrap gap-1.5">
                        <span :class="statusBadge[w.status]" class="capitalize">{{ wishiStatusLabels[w.status] }}</span>
                        <span v-if="w.cycle_type === 'random'" class="badge-info">🎲 Random</span>
                        <span v-else-if="w.cycle_type === 'tender'" class="badge-warning">💰 Tender</span>
                        <span v-else class="badge-brand" :title="`${w.auto_cycles_count} random + ${w.tender_cycles_count} tender cycles`">
                            ⚡ Hybrid · {{ w.auto_cycles_count }}R / {{ w.tender_cycles_count }}T
                        </span>
                    </div>
                    <span v-if="w.is_admin" class="badge-brand shrink-0">Admin</span>
                </div>

                <h3 class="font-semibold text-lg text-gray-900 group-hover:text-indigo-600">{{ w.name }}</h3>

                <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                    <div><div class="text-gray-500 text-xs">Monthly</div><div class="font-semibold">{{ formatINR(w.monthly_contribution) }}</div></div>
                    <div><div class="text-gray-500 text-xs">Pool</div><div class="font-semibold">{{ formatINR(w.total_pool) }}</div></div>
                    <div><div class="text-gray-500 text-xs">Members</div><div class="font-semibold">{{ w.active_members_count ?? '—' }} / {{ w.total_members }}</div></div>
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
                    <span v-if="w.cycles_remaining > 0">{{ w.cycles_remaining }} cycle{{ w.cycles_remaining !== 1 ? 's' : '' }} left</span>
                    <span v-else class="text-emerald-600 font-medium">✓ Done</span>
                </div>
            </RouterLink>
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
