<script setup>
import { computed, ref, reactive } from 'vue';
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
const cycles = computed(() => cycleStore.cycles);
const currentCycle = computed(() => cycles.value.find((c) => c.cycle_number === wishi.value?.current_cycle));

const pendingContribs = computed(() =>
    (contribStore.contributions || []).filter((c) => !c.is_paid)
);
const myContribution = computed(() =>
    (contribStore.contributions || []).find((c) => c.user_id === auth.user?.id)
);

const marking = ref(new Set()); // contribution IDs being marked

// Quick-payment modal
const showPayModal = ref(false);
const payForm = reactive({
    contribution: null,
    payment_method: 'upi',
    payment_reference: '',
    notes: '',
});

function openPayModal(contribution) {
    payForm.contribution = contribution;
    payForm.payment_method = 'upi';
    payForm.payment_reference = '';
    payForm.notes = '';
    showPayModal.value = true;
}

async function markPaid(contribution, opts = { openModal: true }) {
    if (!contribution || contribution.is_paid) return;

    // For own contribution we surface a proper modal asking for method + reference.
    // For admin marking another member we default to cash (fast path).
    if (opts.openModal && contribution.user_id === auth.user?.id) {
        openPayModal(contribution);
        return;
    }

    const payload = contribution.user_id === auth.user?.id
        ? { payment_method: payForm.payment_method, payment_reference: payForm.payment_reference || null, notes: payForm.notes || null }
        : { user_id: contribution.user_id, payment_method: 'cash' };

    marking.value = new Set([...marking.value, contribution.id]);
    try {
        await contribStore.record(route.params.uuid, currentCycle.value.id, payload);
        toast.success('Payment recorded.');
        showPayModal.value = false;
        // Refresh wishi so counts update in header
        await wishiStore.fetch(route.params.uuid);
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to record payment.');
    } finally {
        const next = new Set(marking.value);
        next.delete(contribution.id);
        marking.value = next;
    }
}

async function confirmOwnPayment() {
    if (!payForm.contribution) return;
    await markPaid(payForm.contribution, { openModal: false });
}

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

            <!-- My own contribution banner (members) -->
            <div v-if="myContribution && !myContribution.is_paid && currentCycle" class="surface-padded bg-amber-50 border-amber-200">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div class="min-w-0">
                        <h3 class="font-semibold text-amber-900">💳 Your contribution is due</h3>
                        <p class="text-sm text-amber-800 mt-0.5">
                            <strong>{{ formatINR(myContribution.amount) }}</strong>
                            · Cycle #{{ currentCycle.cycle_number }}
                            · Due {{ formatDate(myContribution.due_date) }}
                            <span :class="dueColor(daysUntil(myContribution.due_date))" class="font-medium">· {{ dueLabel(daysUntil(myContribution.due_date)) }}</span>
                        </p>
                    </div>
                    <button
                        @click="markPaid(myContribution)"
                        :disabled="marking.has(myContribution.id)"
                        class="btn-primary btn-sm shrink-0"
                    >
                        {{ marking.has(myContribution.id) ? 'Saving…' : 'Mark as paid' }}
                    </button>
                </div>
            </div>
            <div v-else-if="myContribution && myContribution.is_paid && currentCycle" class="surface-padded bg-emerald-50 border-emerald-200">
                <div class="flex items-center gap-2 text-sm text-emerald-800">
                    <span>✓</span>
                    <span>You paid {{ formatINR(myContribution.amount) }} for cycle #{{ currentCycle.cycle_number }}<span v-if="myContribution.paid_at"> on {{ formatDate(myContribution.paid_at) }}</span><span v-if="myContribution.paid_late" class="text-amber-700"> (late)</span>.</span>
                </div>
            </div>

            <!-- Pending payments summary (admin view) -->
            <div v-if="isAdmin && currentCycle && contribStore.contributions.length" class="surface-padded">
                <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                    <h3 class="font-semibold">
                        Cycle #{{ currentCycle.cycle_number }} payments
                        <span class="text-sm font-normal text-gray-500">
                            · {{ contribStore.contributions.filter((c) => c.is_paid).length }} / {{ contribStore.contributions.length }} paid
                        </span>
                    </h3>
                    <RouterLink :to="`/wishis/${wishi.uuid}/cycles/${currentCycle.id}`" class="text-xs text-indigo-600 hover:underline">Full detail →</RouterLink>
                </div>

                <!-- Progress bar -->
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden mb-3">
                    <div class="h-full bg-emerald-500 rounded-full transition-all" :style="{ width: Math.min(100, (contribStore.contributions.filter((c) => c.is_paid).length / contribStore.contributions.length) * 100) + '%' }"></div>
                </div>

                <!-- Pending list -->
                <div v-if="!pendingContribs.length" class="text-center py-6 text-emerald-700 text-sm">
                    🎉 Everyone has paid for this cycle.
                </div>
                <div v-else class="space-y-1.5">
                    <div class="text-xs font-semibold text-amber-700 uppercase tracking-wide mb-1.5">
                        {{ pendingContribs.length }} member{{ pendingContribs.length !== 1 ? 's' : '' }} still pending
                    </div>
                    <div v-for="c in pendingContribs" :key="c.id"
                        class="flex items-center justify-between gap-2 py-2 px-3 rounded-lg border border-gray-100 hover:border-amber-300 hover:bg-amber-50/40 transition"
                    >
                        <div class="flex items-center gap-2.5 min-w-0 flex-1">
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 text-white text-xs font-bold flex items-center justify-center shrink-0">
                                {{ c.user?.name?.split(' ').map(p => p[0]).slice(0,2).join('') }}
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium truncate">{{ c.user?.name }}</div>
                                <div class="text-xs text-gray-500 flex items-center gap-1 flex-wrap">
                                    <span>{{ formatINR(c.amount) }}</span>
                                    <span>·</span>
                                    <span :class="dueColor(daysUntil(c.due_date))">{{ dueLabel(daysUntil(c.due_date)) }}</span>
                                    <span v-if="c.status === 'late'" class="badge-warning text-[10px]">Late</span>
                                </div>
                            </div>
                        </div>
                        <button
                            @click="markPaid(c, { openModal: false })"
                            :disabled="marking.has(c.id)"
                            class="btn-success btn-sm shrink-0"
                        >
                            {{ marking.has(c.id) ? '…' : 'Mark paid' }}
                        </button>
                    </div>
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
                                <div v-if="c.winner" class="text-xs text-gray-600 mt-0.5">
                                    🏆 <strong>{{ c.winner.name }}</strong>
                                    <span v-if="c.paid_out_at"> · paid {{ formatDate(c.paid_out_at) }}</span>
                                </div>
                                <div v-if="c.deferred_pending" class="text-[11px] text-amber-700 mt-0.5">
                                    🔒 Deferred: {{ formatINR(c.deferred_amount) }} · releases when WISHI ends
                                </div>
                                <div v-else-if="c.deferred_released_at" class="text-[11px] text-emerald-700 mt-0.5">
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
                    <div class="flex justify-between"><dt class="text-gray-500">Created by</dt><dd class="font-medium">{{ wishi.creator?.name || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Selection</dt><dd class="font-medium capitalize">{{ wishi.winner_selection_mode }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Approval</dt><dd class="font-medium">{{ wishi.require_approval ? 'Required' : 'Open' }}</dd></div>
                    <div v-if="wishi.min_credit_score" class="flex justify-between"><dt class="text-gray-500">Min score</dt><dd class="font-medium">{{ wishi.min_credit_score }}</dd></div>
                </dl>
            </div>

            <div v-if="wishi.cycle_type === 'hybrid' && wishi.hybrid_pattern" class="surface-padded">
                <h3 class="font-semibold mb-3">Hybrid pattern</h3>
                <div class="flex flex-wrap gap-1.5">
                    <span v-for="(p, i) in wishi.hybrid_pattern" :key="i" class="badge-brand">{{ i + 1 }}. {{ p }}</span>
                </div>
                <p class="text-xs text-gray-500 mt-2">Repeats over {{ wishi.duration_months }} months: {{ wishi.auto_cycles_count }} auto + {{ wishi.tender_cycles_count }} tender.</p>
            </div>

            <div v-if="(wishi.deferred_pending_total + wishi.deferred_released_total) > 0" class="surface-padded">
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

        <!-- Own-payment modal -->
        <div v-if="showPayModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showPayModal = false">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md">
                <h3 class="font-semibold text-lg mb-1">Mark your contribution paid</h3>
                <p class="text-xs text-gray-500 mb-4">
                    <strong>{{ formatINR(payForm.contribution?.amount) }}</strong> for cycle #{{ currentCycle?.cycle_number }} · Due {{ formatDate(payForm.contribution?.due_date) }}
                </p>
                <div class="space-y-3">
                    <div>
                        <label class="form-label">Payment method</label>
                        <select v-model="payForm.payment_method" class="form-input">
                            <option value="upi">UPI</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Reference / TXN ID</label>
                        <input v-model="payForm.payment_reference" type="text" class="form-input" placeholder="e.g. UPI ref" />
                    </div>
                    <div>
                        <label class="form-label">Notes (optional)</label>
                        <textarea v-model="payForm.notes" rows="2" class="form-input"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="showPayModal = false" class="btn-secondary">Cancel</button>
                    <button @click="confirmOwnPayment" :disabled="payForm.contribution && marking.has(payForm.contribution.id)" class="btn-primary">Save</button>
                </div>
            </div>
        </div>
    </div>
</template>
