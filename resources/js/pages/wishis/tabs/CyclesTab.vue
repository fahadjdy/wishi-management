<script setup>
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useCycleStore } from '@/stores/cycle';
import { formatINR, formatDate, cycleStatusLabels } from '@/utils/format';

const route = useRoute();
const store = useCycleStore();

const cycleStatus = {
    pending: 'badge-gray',
    contribution_open: 'badge-warning',
    bidding_open: 'badge-info',
    selection_pending: 'badge-brand',
    completed: 'badge-success',
};
</script>

<template>
    <div>
        <div v-if="!store.cycles.length" class="surface-padded text-center py-12 text-gray-400">No cycles yet.</div>

        <!-- Mobile cards -->
        <div v-else class="md:hidden space-y-3">
            <RouterLink v-for="c in store.cycles" :key="c.id" :to="`/wishis/${route.params.uuid}/cycles/${c.id}`"
                class="surface-padded block hover:border-indigo-300">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-lg font-bold">#{{ c.cycle_number }}</div>
                    <span :class="cycleStatus[c.status]" class="capitalize">{{ cycleStatusLabels[c.status] }}</span>
                </div>
                <div class="flex items-center justify-between mb-1 text-sm">
                    <span class="capitalize text-gray-500">{{ c.mode }}</span>
                    <span class="font-semibold">{{ formatINR(c.total_pool) }}</span>
                </div>
                <div v-if="c.winner" class="text-xs text-gray-600">🏆 {{ c.winner.name }}<span v-if="c.paid_out_at"> · {{ formatDate(c.paid_out_at) }}</span></div>
                <div v-if="c.deferred_pending" class="text-[11px] text-amber-700 mt-1">🔒 {{ formatINR(c.deferred_amount) }} deferred</div>
                <div v-else-if="c.deferred_released_at" class="text-[11px] text-emerald-700 mt-1">✓ Deferred released {{ formatDate(c.deferred_released_at) }}</div>
            </RouterLink>
        </div>

        <!-- Desktop table -->
        <div v-if="store.cycles.length" class="hidden md:block surface overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">#</th>
                        <th class="text-left px-4 py-3">Mode</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-left px-4 py-3">Pool</th>
                        <th class="text-left px-4 py-3">Winner</th>
                        <th class="text-left px-4 py-3">Payout</th>
                        <th class="text-left px-4 py-3">Deferred</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="c in store.cycles" :key="c.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-bold">#{{ c.cycle_number }}</td>
                        <td class="px-4 py-3 capitalize">{{ c.mode }}</td>
                        <td class="px-4 py-3"><span :class="cycleStatus[c.status]" class="capitalize">{{ cycleStatusLabels[c.status] }}</span></td>
                        <td class="px-4 py-3">{{ formatINR(c.total_pool) }}</td>
                        <td class="px-4 py-3">{{ c.winner?.name || '—' }}</td>
                        <td class="px-4 py-3">{{ c.payout_amount ? formatINR(c.payout_amount) : '—' }}</td>
                        <td class="px-4 py-3">
                            <span v-if="c.deferred_pending" class="text-amber-700" :title="'Releases when WISHI completes'">
                                🔒 {{ formatINR(c.deferred_amount) }}
                            </span>
                            <span v-else-if="c.deferred_released_at" class="text-emerald-700" :title="`Released ${formatDate(c.deferred_released_at)}`">
                                ✓ {{ formatINR(c.deferred_amount) }}
                            </span>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="px-4 py-3 text-right"><RouterLink :to="`/wishis/${route.params.uuid}/cycles/${c.id}`" class="text-indigo-600 hover:underline text-xs whitespace-nowrap">Open →</RouterLink></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
