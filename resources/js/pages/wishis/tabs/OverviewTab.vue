<script setup>
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useWishiStore } from '@/stores/wishi';
import { useCycleStore } from '@/stores/cycle';
import { useContributionStore } from '@/stores/contribution';
import { useAuthStore } from '@/stores/auth';
import { useToast } from 'vue-toastification';
import { formatINR, formatDate, cycleStatusLabels } from '@/utils/format';

const route = useRoute();
const wishiStore = useWishiStore();
const cycleStore = useCycleStore();
const contribStore = useContributionStore();
const auth = useAuthStore();
const toast = useToast();

const wishi = computed(() => wishiStore.currentWishi);
const isAdmin = computed(() => wishi.value?.is_admin);
const cycles = computed(() => [...(cycleStore.cycles || [])].sort((a, b) => b.cycle_number - a.cycle_number));
const currentCycle = computed(() => cycles.value.find((c) => c.cycle_number === wishi.value?.current_cycle));
const myContribution = computed(() => (contribStore.contributions || []).find((c) => c.user_id === auth.user?.id));

async function advance() {
    if (!confirm('Advance to the next cycle? The current cycle must be completed.')) return;
    try {
        const c = await cycleStore.advance(route.params.uuid);
        toast.success(`Cycle #${c.cycle_number} opened.`);
        await wishiStore.fetch(route.params.uuid);
        await cycleStore.fetchAll(route.params.uuid);
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not advance cycle.');
    }
}

function daysUntil(dateStr) {
    if (!dateStr) return null;
    const d = new Date(dateStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0); d.setHours(0, 0, 0, 0);
    return Math.round((d.getTime() - today.getTime()) / 86400000);
}
function dueLabel(days) {
    if (days === null) return '';
    if (days < 0) return `${Math.abs(days)}d overdue`;
    if (days === 0) return 'Due today';
    if (days === 1) return 'Due tomorrow';
    return `In ${days}d`;
}
function dueColor(days) {
    if (days === null) return 'text-gray-500';
    if (days < 0 || days <= 1) return 'text-red-600';
    if (days <= 5) return 'text-amber-600';
    return 'text-emerald-600';
}
</script>

<template>
    <div v-if="wishi" class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div class="lg:col-span-2 space-y-5">
            <!-- Current cycle card -->
            <div class="surface-padded">
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <h3 class="font-semibold">Current cycle</h3>
                    <button v-if="isAdmin && wishi.status === 'active' && currentCycle?.status === 'completed' && wishi.current_cycle < wishi.duration_months" @click="advance" class="btn-primary btn-sm">
                        Open next cycle
                    </button>
                </div>
                <div v-if="!currentCycle" class="text-sm text-gray-500 py-4 text-center">No active cycle yet.</div>
                <div v-else>
                    <div class="flex items-center gap-2 mb-3 flex-wrap">
                        <span class="text-2xl font-bold">#{{ currentCycle.cycle_number }}</span>
                        <span class="badge-info capitalize">{{ cycleStatusLabels[currentCycle.status] }}</span>
                        <span class="badge-gray capitalize">{{ currentCycle.mode }}</span>
                    </div>
                    <RouterLink :to="`/wishis/${wishi.uuid}/cycles/${currentCycle.id}`" class="btn-secondary btn-sm">Open cycle detail →</RouterLink>
                </div>
            </div>

            <!-- MEMBER: read-only own-contribution info, no buttons -->
            <div v-if="!isAdmin && myContribution && !myContribution.is_paid && currentCycle" class="surface-padded bg-amber-50 border-amber-200">
                <h3 class="font-semibold text-amber-900">💳 Your contribution is due</h3>
                <p class="text-sm text-amber-800 mt-0.5">
                    <strong>{{ formatINR(myContribution.amount) }}</strong>
                    · Cycle #{{ currentCycle.cycle_number }}
                    · Due {{ formatDate(myContribution.due_date) }}
                    <span :class="dueColor(daysUntil(myContribution.due_date))" class="font-medium">· {{ dueLabel(daysUntil(myContribution.due_date)) }}</span>
                </p>
                <p class="text-[11px] text-amber-700 mt-1">Pay your WISHI admin directly. They'll mark it as received once the money reaches them.</p>
            </div>
            <div v-else-if="!isAdmin && myContribution?.is_paid && currentCycle" class="surface-padded bg-emerald-50 border-emerald-200">
                <div class="flex items-center gap-2 text-sm text-emerald-800">
                    <span>✓</span>
                    <span>You paid {{ formatINR(myContribution.amount) }} for cycle #{{ currentCycle.cycle_number }}<span v-if="myContribution.paid_at"> on {{ formatDate(myContribution.paid_at) }}</span><span v-if="myContribution.paid_late" class="text-amber-700"> (late)</span>.</span>
                </div>
            </div>

            <!-- ADMIN: pending-payments summary (view-only; mark-paid lives on CycleDetail) -->
            <div v-if="isAdmin && currentCycle && contribStore.contributions.length" class="surface-padded">
                <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                    <h3 class="font-semibold">
                        Cycle #{{ currentCycle.cycle_number }} payments
                        <span class="text-sm font-normal text-gray-500">
                            · {{ contribStore.contributions.filter((c) => c.is_paid).length }} / {{ contribStore.contributions.length }} paid
                        </span>
                    </h3>
                    <RouterLink :to="`/wishis/${wishi.uuid}/cycles/${currentCycle.id}`" class="text-xs text-indigo-600 hover:underline">Open cycle to record →</RouterLink>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden mb-3">
                    <div class="h-full bg-emerald-500 rounded-full transition-all" :style="{ width: Math.min(100, (contribStore.contributions.filter((c) => c.is_paid).length / contribStore.contributions.length) * 100) + '%' }"></div>
                </div>
            </div>

            <!-- Cycle history -->
            <div class="surface-padded">
                <h3 class="font-semibold mb-4">Cycle history &amp; winners</h3>
                <div v-if="!cycles.length" class="text-sm text-gray-400 py-6 text-center">No cycles yet.</div>
                <div v-else class="space-y-2.5">
                    <RouterLink v-for="c in cycles" :key="c.id" :to="`/wishis/${wishi.uuid}/cycles/${c.id}`"
                        class="flex items-center justify-between py-2.5 px-3 rounded-lg hover:bg-gray-50 border border-transparent hover:border-indigo-200">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <div class="w-10 h-10 rounded-full font-bold flex items-center justify-center text-sm shrink-0"
                                :class="c.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-indigo-100 text-indigo-700'">
                                {{ c.cycle_number }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium flex items-center gap-2 flex-wrap">
                                    Cycle #{{ c.cycle_number }}
                                    <span class="badge-gray capitalize">{{ c.mode }}</span>
                                    <span :class="c.status === 'completed' ? 'badge-success' : 'badge-info'" class="capitalize">{{ cycleStatusLabels[c.status] }}</span>
                                </div>
                                <div v-if="c.selection_method === 'organizer_payout'" class="text-xs text-indigo-700 mt-0.5">
                                    👑 <strong>{{ c.winner?.name || 'Admin' }}</strong> · Organizer payout
                                    <span v-if="c.paid_out_at"> · paid {{ formatDate(c.paid_out_at) }}</span>
                                </div>
                                <div v-else-if="c.winner" class="text-xs text-gray-600 mt-0.5">
                                    🏆 <strong>{{ c.winner.name }}</strong>
                                    <span v-if="c.winners_count > 1"> +{{ c.winners_count - 1 }} more</span>
                                    <span v-if="c.paid_out_at"> · paid {{ formatDate(c.paid_out_at) }}</span>
                                </div>
                                <div v-if="isAdmin && c.deferred_pending" class="text-[11px] text-amber-700 mt-0.5">
                                    🔒 Deferred: {{ formatINR(c.deferred_amount) }} · releases when WISHI ends
                                </div>
                                <div v-else-if="isAdmin && c.deferred_released_at" class="text-[11px] text-emerald-700 mt-0.5">
                                    ✓ Deferred {{ formatINR(c.deferred_amount) }} released on {{ formatDate(c.deferred_released_at) }}
                                </div>
                            </div>
                        </div>
                        <div class="text-right shrink-0 ml-3">
                            <div class="text-sm font-semibold">{{ formatINR(c.payout_amount ?? c.total_pool) }}</div>
                            <div v-if="c.winning_bid" class="text-xs text-gray-500">bid: {{ formatINR(c.winning_bid) }}</div>
                        </div>
                    </RouterLink>
                </div>
            </div>
        </div>

        <div class="space-y-5">
            <div class="surface-padded">
                <h3 class="font-semibold mb-3">Quick info</h3>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between"><dt class="text-gray-500">Admin</dt><dd class="font-medium">{{ wishi.creator?.name || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Members</dt><dd class="font-medium">{{ wishi.active_members_count ?? '—' }} / {{ wishi.total_members }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Monthly</dt><dd class="font-medium">{{ formatINR(wishi.monthly_contribution) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Pool</dt><dd class="font-medium">{{ formatINR(wishi.total_pool) }}</dd></div>
                    <div v-if="isAdmin" class="flex justify-between"><dt class="text-gray-500">Approval</dt><dd class="font-medium">{{ wishi.require_approval ? 'Required' : 'Open' }}</dd></div>
                </dl>
            </div>

            <div v-if="wishi.cycle_type === 'hybrid' && wishi.hybrid_pattern" class="surface-padded">
                <h3 class="font-semibold mb-3">Hybrid pattern</h3>
                <div class="flex flex-wrap gap-1.5">
                    <span v-for="(p, i) in wishi.hybrid_pattern" :key="i" class="badge-brand">{{ i + 1 }}. {{ p }}</span>
                </div>
                <p class="text-xs text-gray-500 mt-2">Over {{ wishi.duration_months }} months: {{ wishi.auto_cycles_count }} random + {{ wishi.tender_cycles_count }} tender cycles.</p>
            </div>

            <div v-if="isAdmin && ((wishi.deferred_pending_total || 0) + (wishi.deferred_released_total || 0)) > 0" class="surface-padded">
                <h3 class="font-semibold mb-3">Deferred payouts</h3>
                <div class="space-y-2 text-sm">
                    <div v-if="wishi.deferred_pending_total > 0" class="flex justify-between">
                        <span class="text-amber-700">🔒 Pending release</span>
                        <strong class="text-amber-700">{{ formatINR(wishi.deferred_pending_total) }}</strong>
                    </div>
                    <div v-if="wishi.deferred_released_total > 0" class="flex justify-between">
                        <span class="text-emerald-700">✓ Released</span>
                        <strong class="text-emerald-700">{{ formatINR(wishi.deferred_released_total) }}</strong>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Tender winners get the difference between the pool and their winning bid once the WISHI is fully complete.</p>
            </div>
        </div>
    </div>
</template>
