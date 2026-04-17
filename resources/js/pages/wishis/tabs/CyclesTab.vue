<script setup>
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useCycleStore } from '@/stores/cycle';
import { formatINR, formatDate, cycleStatusLabels } from '@/utils/format';
import {
    TrophyIcon, LockClosedIcon, CheckCircleIcon, BanknotesIcon,
    ChevronRightIcon,
} from '@heroicons/vue/24/outline';

const route = useRoute();
const store = useCycleStore();

// Show newest first; client-side sort in case the API ever returns otherwise.
const cyclesDesc = computed(() => [...(store.cycles || [])].sort((a, b) => b.cycle_number - a.cycle_number));

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
        <div v-if="!cyclesDesc.length" class="surface-padded text-center py-12 text-slate-400">No cycles yet.</div>

        <!-- Mobile cards -->
        <div v-else class="md:hidden space-y-3">
            <RouterLink v-for="c in cyclesDesc" :key="c.id" :to="`/wishis/${route.params.uuid}/cycles/${c.id}`"
                class="surface-padded block transition hover:border-brand-300 hover:shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-lg font-bold">#{{ c.cycle_number }}</div>
                    <span :class="cycleStatus[c.status]" class="capitalize">{{ cycleStatusLabels[c.status] }}</span>
                </div>
                <div class="flex items-center justify-between mb-1 text-sm">
                    <span v-if="c.selection_method === 'organizer_payout'" class="badge-brand">Organizer</span>
                    <span v-else class="capitalize text-slate-500">{{ c.mode }}</span>
                    <span class="font-semibold">{{ formatINR(c.total_pool) }}</span>
                </div>
                <div v-if="c.selection_method === 'organizer_payout'" class="text-xs text-brand-700 inline-flex items-center gap-1.5 mt-1">
                    <span class="font-medium">{{ c.winner?.name || 'Admin' }}</span>
                    <span class="text-slate-400">· organizer payout</span>
                </div>
                <div v-else-if="c.winner" class="text-xs text-slate-600 inline-flex items-center gap-1.5 mt-1">
                    <TrophyIcon class="w-3.5 h-3.5 text-amber-600 shrink-0" aria-hidden="true" />
                    <span><span class="font-medium">{{ c.winner.name }}</span><span v-if="c.winners_count > 1"> +{{ c.winners_count - 1 }}</span><span v-if="c.paid_out_at" class="text-slate-400"> · {{ formatDate(c.paid_out_at) }}</span></span>
                </div>
                <div v-if="c.deferred_pending" class="text-[11px] text-amber-700 mt-2 inline-flex items-center gap-1">
                    <LockClosedIcon class="w-3 h-3" aria-hidden="true" />
                    {{ formatINR(c.deferred_amount) }} deferred
                </div>
                <div v-else-if="c.deferred_released_at" class="text-[11px] text-emerald-700 mt-2 inline-flex items-center gap-1">
                    <CheckCircleIcon class="w-3 h-3" aria-hidden="true" />
                    Deferred released {{ formatDate(c.deferred_released_at) }}
                </div>
                <div v-if="c.admin_topup_amount > 0" class="text-[11px] text-amber-700 mt-1 inline-flex items-center gap-1">
                    <BanknotesIcon class="w-3 h-3" aria-hidden="true" />
                    Admin top-up: {{ formatINR(c.admin_topup_amount) }}
                </div>
            </RouterLink>
        </div>

        <!-- Desktop table -->
        <div v-if="cyclesDesc.length" class="hidden md:block surface overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200 text-xs text-slate-500 uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">#</th>
                        <th class="text-left px-4 py-3">Type</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-left px-4 py-3">Pool</th>
                        <th class="text-left px-4 py-3">Winner(s)</th>
                        <th class="text-left px-4 py-3">Payout</th>
                        <th class="text-left px-4 py-3">Deferred / Top-up</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="c in cyclesDesc" :key="c.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-bold">#{{ c.cycle_number }}</td>
                        <td class="px-4 py-3">
                            <span v-if="c.selection_method === 'organizer_payout'" class="badge-brand">Organizer</span>
                            <span v-else class="capitalize">{{ c.mode }}</span>
                        </td>
                        <td class="px-4 py-3"><span :class="cycleStatus[c.status]" class="capitalize">{{ cycleStatusLabels[c.status] }}</span></td>
                        <td class="px-4 py-3">{{ formatINR(c.total_pool) }}</td>
                        <td class="px-4 py-3">
                            <div v-if="c.selection_method === 'organizer_payout'" class="inline-flex items-center gap-1.5 text-brand-700">
                                <span class="font-medium">{{ c.winner?.name || 'Admin' }}</span>
                            </div>
                            <div v-else-if="c.winner" class="inline-flex items-center gap-1.5">
                                <TrophyIcon class="w-4 h-4 text-amber-500 shrink-0" aria-hidden="true" />
                                <span>{{ c.winner.name }}<span v-if="c.winners_count > 1" class="text-xs text-slate-500"> (+{{ c.winners_count - 1 }})</span></span>
                            </div>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="px-4 py-3">{{ c.payout_amount ? formatINR(c.payout_amount) : '—' }}</td>
                        <td class="px-4 py-3">
                            <span v-if="c.deferred_pending" class="text-amber-700 inline-flex items-center gap-1" :title="'Releases when WISHI completes'">
                                <LockClosedIcon class="w-3.5 h-3.5" aria-hidden="true" />
                                {{ formatINR(c.deferred_amount) }}
                            </span>
                            <span v-else-if="c.deferred_released_at" class="text-emerald-700 inline-flex items-center gap-1" :title="`Released ${formatDate(c.deferred_released_at)}`">
                                <CheckCircleIcon class="w-3.5 h-3.5" aria-hidden="true" />
                                {{ formatINR(c.deferred_amount) }}
                            </span>
                            <span v-if="c.admin_topup_amount > 0" class="ml-2 text-amber-700 inline-flex items-center gap-1" :title="'Admin personally topped up'">
                                <BanknotesIcon class="w-3.5 h-3.5" aria-hidden="true" />
                                +{{ formatINR(c.admin_topup_amount) }}
                            </span>
                            <span v-if="!c.deferred_pending && !c.deferred_released_at && c.admin_topup_amount == 0" class="text-slate-400">—</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <RouterLink :to="`/wishis/${route.params.uuid}/cycles/${c.id}`" class="text-brand-700 hover:underline text-xs whitespace-nowrap inline-flex items-center gap-1">
                                Open
                                <ChevronRightIcon class="w-3.5 h-3.5" aria-hidden="true" />
                            </RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
