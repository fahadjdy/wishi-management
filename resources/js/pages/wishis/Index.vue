<script setup>
import { onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import { useWishiStore } from '@/stores/wishi';
import { useAuthStore } from '@/stores/auth';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { formatINR, formatDate, wishiStatusLabels } from '@/utils/format';

const store = useWishiStore();
const auth = useAuthStore();

useBreadcrumbs(() => [{ label: 'WISHIs' }]);

onMounted(() => store.fetchAll());

const statusBadge = {
    draft: 'badge-gray',
    active: 'badge-success',
    completed: 'badge-info',
    cancelled: 'badge-danger',
};
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Your WISHIs</h1>
                <p class="text-sm text-gray-500">Manage and track all chit funds you're part of.</p>
            </div>
            <RouterLink v-if="auth.user?.is_admin" to="/wishis/create" class="btn-primary">+ New WISHI</RouterLink>
            <span v-else class="text-xs text-gray-500">Only platform admins can create WISHIs</span>
        </div>

        <div v-if="store.loading" class="text-center py-16 text-gray-400">Loading…</div>

        <div v-else-if="!store.wishis.length" class="surface-padded text-center py-16">
            <div class="text-5xl mb-3">📦</div>
            <h3 class="text-lg font-semibold">No WISHIs yet</h3>
            <p v-if="auth.user?.is_admin" class="text-sm text-gray-500 mt-1">Get started by creating your first chit fund pool.</p>
            <p v-else class="text-sm text-gray-500 mt-1">Wait for an admin to invite you, or browse open WISHIs to join.</p>
            <RouterLink v-if="auth.user?.is_admin" to="/wishis/create" class="btn-primary mt-5 inline-flex">Create your first WISHI</RouterLink>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            <RouterLink
                v-for="w in store.wishis"
                :key="w.id"
                :to="`/wishis/${w.uuid}`"
                class="surface-padded hover:shadow-md hover:border-indigo-300 transition group"
            >
                <div class="flex items-start justify-between mb-3 gap-2">
                    <div class="flex flex-wrap gap-1.5">
                        <span :class="statusBadge[w.status]" class="capitalize">{{ wishiStatusLabels[w.status] }}</span>
                        <span v-if="w.cycle_type === 'random'" class="badge-info">🎲 Random</span>
                        <span v-else-if="w.cycle_type === 'tender'" class="badge-warning">💰 Tender</span>
                        <span v-else class="badge-brand" :title="`${w.auto_cycles_count} auto + ${w.tender_cycles_count} tender cycles`">
                            ⚡ Hybrid · {{ w.auto_cycles_count }}A / {{ w.tender_cycles_count }}T
                        </span>
                    </div>
                    <span v-if="w.is_admin" class="badge-brand shrink-0">Admin</span>
                </div>

                <h3 class="font-semibold text-lg text-gray-900 group-hover:text-indigo-600">{{ w.name }}</h3>

                <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
                    <div>
                        <div class="text-gray-500 text-xs">Monthly</div>
                        <div class="font-semibold">{{ formatINR(w.monthly_contribution) }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500 text-xs">Pool size</div>
                        <div class="font-semibold">{{ formatINR(w.total_pool) }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500 text-xs">Members</div>
                        <div class="font-semibold">{{ w.active_members_count ?? '—' }} / {{ w.total_members }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500 text-xs">Opened cycles</div>
                        <div class="font-semibold">
                            <span class="text-emerald-600">{{ w.cycles_completed ?? 0 }}</span>
                            <span class="text-gray-400"> / {{ w.duration_months }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-indigo-500 to-purple-500 rounded-full" :style="{ width: Math.min(100, ((w.cycles_completed ?? 0) / w.duration_months) * 100) + '%' }"></div>
                </div>

                <!-- Active tender snapshot -->
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

                <!-- Admin receivable/payable indicator (surplus awaiting action) -->
                <div v-if="w.unhandled_surplus > 0 && w.is_admin" class="mt-3 rounded-lg bg-emerald-50 border border-emerald-200 p-3 text-xs">
                    <div class="font-semibold text-emerald-900">💵 Admin action needed</div>
                    <div class="text-emerald-800 mt-0.5">
                        Unhandled surplus: <strong>{{ formatINR(w.unhandled_surplus) }}</strong> — decide distribute / reserve / bonus.
                    </div>
                </div>
                <div v-else-if="w.total_surplus > 0" class="mt-3 text-xs text-gray-500">
                    Total surplus distributed: <strong class="text-emerald-600">{{ formatINR(w.total_surplus) }}</strong>
                </div>

                <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                    <span>Started {{ formatDate(w.start_date) }}</span>
                    <span v-if="w.cycles_remaining > 0">{{ w.cycles_remaining }} cycle{{ w.cycles_remaining !== 1 ? 's' : '' }} left</span>
                    <span v-else class="text-emerald-600 font-medium">✓ Done</span>
                </div>
            </RouterLink>
        </div>
    </div>
</template>
