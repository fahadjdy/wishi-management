<script setup>
import { onMounted, computed, ref, reactive, onUnmounted } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useCycleStore } from '@/stores/cycle';
import { useWishiStore } from '@/stores/wishi';
import { useContributionStore } from '@/stores/contribution';
import { useTenderStore } from '@/stores/tender';
import { useMemberStore } from '@/stores/member';
import { useAuthStore } from '@/stores/auth';
import { useToast } from 'vue-toastification';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { formatINR, formatDate, formatDateTime, cycleStatusLabels } from '@/utils/format';

const route = useRoute();
const cycleStore = useCycleStore();
const wishiStore = useWishiStore();
const contribStore = useContributionStore();
const tenderStore = useTenderStore();
const memberStore = useMemberStore();
const auth = useAuthStore();
const toast = useToast();

const wishi = computed(() => wishiStore.currentWishi);
const cycle = computed(() => cycleStore.currentCycle);
const isAdmin = computed(() => wishi.value?.is_admin);

useBreadcrumbs(() => [
    { label: 'WISHIs', to: '/wishis' },
    { label: wishi.value?.name || 'WISHI', to: wishi.value ? `/wishis/${wishi.value.uuid}` : null },
    { label: cycle.value ? `Cycle #${cycle.value.cycle_number}` : 'Cycle' },
]);
const myMember = computed(() => memberStore.members.find((m) => m.user_id === auth.user?.id));
const myContribution = computed(() => contribStore.contributions.find((c) => c.user_id === auth.user?.id));
const canBid = computed(() => cycle.value?.mode === 'tender' && cycle.value?.is_bidding_open && myMember.value && !myMember.value.has_won);
const myBid = computed(() => tenderStore.tenders.find((t) => t.user_id === auth.user?.id));

// Winner can only be announced on or after the cycle's contribution due date.
const isCycleMature = computed(() => {
    const due = cycle.value?.contribution_due_at;
    if (!due) return true;
    return new Date(due).getTime() <= Date.now();
});
const daysTillMature = computed(() => {
    const due = cycle.value?.contribution_due_at;
    if (!due) return null;
    const ms = new Date(due).getTime() - Date.now();
    return Math.max(0, Math.ceil(ms / 86400000));
});

const bidForm = reactive({ amount: null });
const winnerForm = reactive({ method: 'auto', user_id: null, reason: '' });
const surplusForm = reactive({ action: 'distribute', recipient_id: null, reason: '' });
const payoutForm = reactive({ method: 'bank_transfer', reference: '', notes: '' });
const paymentForm = reactive({ user_id: null, payment_method: 'upi', payment_reference: '', notes: '' });

const showWinnerModal = ref(false);
const showMultiWinnerModal = ref(false);
const showSurplusModal = ref(false);
const showPayoutModal = ref(false);
const showPaymentModal = ref(false);
const loading = ref(false);
const countdown = ref('');
let countdownTimer = null;

// Multi-winner selection state
const selectedBidIds = ref(new Set());
const multiReason = ref('');
const multiAcceptTopup = ref(false);

function toggleBidSelection(tenderId) {
    const next = new Set(selectedBidIds.value);
    next.has(tenderId) ? next.delete(tenderId) : next.add(tenderId);
    selectedBidIds.value = next;
}

const selectedBids = computed(() => tenderStore.tenders.filter((t) => selectedBidIds.value.has(t.id)));
const selectedTotal = computed(() => selectedBids.value.reduce((s, b) => s + Number(b.bid_amount || 0), 0));
const topupRequired = computed(() => Math.max(0, selectedTotal.value - Number(cycle.value?.total_pool || 0)));
const surplusPending = computed(() => Math.max(0, Number(cycle.value?.total_pool || 0) - selectedTotal.value));

async function confirmMultiWinners() {
    if (selectedBidIds.value.size === 0) return toast.warning('Select at least one bid.');
    if (topupRequired.value > 0 && !multiAcceptTopup.value) {
        toast.warning(`You must confirm the admin top-up of ₹${topupRequired.value.toLocaleString('en-IN')}.`);
        return;
    }
    loading.value = true;
    try {
        const response = await cycleStore.$axiosForDetail?.() || null; // placeholder no-op
        const { data } = await (await import('@/api/client')).default.put(
            `/wishis/${route.params.uuid}/cycles/${route.params.cycleId}/select-multi-winners`,
            {
                tender_ids: Array.from(selectedBidIds.value),
                accept_topup: multiAcceptTopup.value,
                reason: multiReason.value || null,
            },
        );
        cycleStore.currentCycle = data.data;
        toast.success(`${selectedBidIds.value.size} winner(s) selected.`);
        showMultiWinnerModal.value = false;
        selectedBidIds.value = new Set();
        multiReason.value = '';
        multiAcceptTopup.value = false;
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to select winners.');
    } finally {
        loading.value = false;
    }
}

const stepperSteps = computed(() => {
    if (!cycle.value) return [];
    const arr = [
        { key: 'contribution_open', label: 'Contributions' },
    ];
    if (cycle.value.mode === 'tender') {
        arr.push({ key: 'bidding_open', label: 'Bidding' });
    }
    arr.push({ key: 'selection_pending', label: 'Selection' });
    arr.push({ key: 'completed', label: 'Payout' });
    return arr;
});

function statusIndex(s) {
    return ['contribution_open', 'bidding_open', 'selection_pending', 'completed'].indexOf(s);
}

const contribStatusBadge = {
    pending: 'badge-warning',
    paid: 'badge-success',
    late: 'badge-warning',
    missed: 'badge-danger',
};

async function load() {
    if (!wishi.value || wishi.value.uuid !== route.params.uuid) await wishiStore.fetch(route.params.uuid);
    await cycleStore.fetch(route.params.uuid, route.params.cycleId);
    await Promise.all([
        contribStore.fetch(route.params.uuid, route.params.cycleId),
        memberStore.fetch(route.params.uuid),
        cycle.value?.mode === 'tender' ? tenderStore.fetch(route.params.uuid, route.params.cycleId) : Promise.resolve(),
    ]);
}

function startCountdown() {
    if (countdownTimer) clearInterval(countdownTimer);
    countdownTimer = setInterval(() => {
        if (!cycle.value?.tender_closes_at) return;
        const diff = new Date(cycle.value.tender_closes_at).getTime() - Date.now();
        if (diff <= 0) {
            countdown.value = 'Closed';
            clearInterval(countdownTimer);
            return;
        }
        const h = Math.floor(diff / 3600000);
        const m = Math.floor((diff % 3600000) / 60000);
        const s = Math.floor((diff % 60000) / 1000);
        countdown.value = `${h}h ${m}m ${s}s`;
    }, 1000);
}

onMounted(async () => {
    await load();
    if (cycle.value?.mode === 'tender' && cycle.value?.is_bidding_open) startCountdown();
});

onUnmounted(() => {
    if (countdownTimer) clearInterval(countdownTimer);
});

async function placeBid() {
    if (!bidForm.amount || bidForm.amount <= 0) return toast.warning('Enter a valid bid.');
    loading.value = true;
    try {
        await tenderStore.place(route.params.uuid, route.params.cycleId, bidForm.amount);
        toast.success('Bid placed.');
        bidForm.amount = null;
        await tenderStore.fetch(route.params.uuid, route.params.cycleId);
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not place bid.');
    } finally {
        loading.value = false;
    }
}

async function recordOwnPayment() {
    loading.value = true;
    try {
        await contribStore.record(route.params.uuid, route.params.cycleId, {
            payment_method: paymentForm.payment_method,
            payment_reference: paymentForm.payment_reference || null,
            notes: paymentForm.notes || null,
        });
        toast.success('Payment recorded.');
        showPaymentModal.value = false;
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to record payment.');
    } finally {
        loading.value = false;
    }
}

async function recordMemberPayment(userId) {
    loading.value = true;
    try {
        await contribStore.record(route.params.uuid, route.params.cycleId, {
            user_id: userId,
            payment_method: 'cash',
        });
        toast.success('Payment recorded.');
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to record payment.');
    } finally {
        loading.value = false;
    }
}

async function undoPayment(contribution) {
    if (!confirm(`Undo this ${Number(contribution.amount).toLocaleString('en-IN')} ₹ payment for ${contribution.user?.name || 'this member'}? The credit-score change will also be reversed.`)) return;
    loading.value = true;
    try {
        await contribStore.revert(route.params.uuid, route.params.cycleId, contribution.id);
        toast.success('Payment reverted.');
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not undo — cycle may already be settled.');
    } finally {
        loading.value = false;
    }
}

async function selectWinner() {
    loading.value = true;
    try {
        const payload = winnerForm.method === 'manual'
            ? { method: 'manual', user_id: winnerForm.user_id, reason: winnerForm.reason || null }
            : { method: 'auto' };
        await cycleStore.selectWinner(route.params.uuid, route.params.cycleId, payload);
        toast.success('Winner selected.');
        showWinnerModal.value = false;
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to select winner.');
    } finally {
        loading.value = false;
    }
}

async function handleSurplus() {
    loading.value = true;
    try {
        await cycleStore.handleSurplus(route.params.uuid, route.params.cycleId, {
            action: surplusForm.action,
            recipient_id: surplusForm.action === 'bonus' ? surplusForm.recipient_id : null,
            reason: surplusForm.reason || null,
        });
        toast.success('Surplus handled.');
        showSurplusModal.value = false;
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed.');
    } finally {
        loading.value = false;
    }
}

async function recordPayout() {
    loading.value = true;
    try {
        await cycleStore.recordPayout(route.params.uuid, route.params.cycleId, payoutForm);
        toast.success('Payout recorded. Cycle completed.');
        showPayoutModal.value = false;
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed.');
    } finally {
        loading.value = false;
    }
}

const eligibleMembers = computed(() =>
    memberStore.members.filter((m) => ['active', 'approved'].includes(m.status) && !m.has_won)
);
</script>

<template>
    <div v-if="!cycle || !wishi" class="text-center py-16 text-gray-400">Loading…</div>
    <div v-else class="space-y-5">
        <RouterLink :to="`/wishis/${wishi.uuid}/cycles`" class="text-sm text-indigo-600 hover:underline">← Back to cycles</RouterLink>

        <!-- Header -->
        <div class="surface-padded">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-2xl font-bold">Cycle #{{ cycle.cycle_number }}</h1>
                        <span class="badge-info capitalize">{{ cycleStatusLabels[cycle.status] }}</span>
                        <span class="badge-gray capitalize">{{ cycle.mode }}</span>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">{{ wishi.name }}</p>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">Pool</div>
                    <div class="text-2xl font-bold">{{ formatINR(cycle.total_pool) }}</div>
                </div>
            </div>

            <!-- Stepper -->
            <div class="flex items-center mt-4">
                <template v-for="(s, i) in stepperSteps" :key="s.key">
                    <div class="flex flex-col items-center text-center min-w-0">
                        <div
                            class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                            :class="statusIndex(cycle.status) >= statusIndex(s.key) ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-500'"
                        >
                            <span v-if="statusIndex(cycle.status) > statusIndex(s.key)">✓</span>
                            <span v-else>{{ i + 1 }}</span>
                        </div>
                        <div class="text-xs mt-1.5 text-gray-600 hidden sm:block">{{ s.label }}</div>
                    </div>
                    <div v-if="i < stepperSteps.length - 1" class="flex-1 h-1 mx-1 sm:mx-2 rounded-full" :class="statusIndex(cycle.status) > statusIndex(s.key) ? 'bg-indigo-600' : 'bg-gray-200'"></div>
                </template>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            <div class="lg:col-span-2 space-y-5">
                <!-- Tender bidding -->
                <div v-if="cycle.mode === 'tender'" class="surface-padded">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-semibold">Tender bidding</h3>
                        <div v-if="cycle.is_bidding_open" class="text-sm text-amber-600 font-medium">⏱ Closes in {{ countdown }}</div>
                        <div v-else-if="cycle.tender_closes_at" class="text-sm text-gray-500">Closed {{ formatDateTime(cycle.tender_closes_at) }}</div>
                    </div>

                    <!-- Member already placed bid → locked (cannot edit) -->
                    <div v-if="myBid" class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-4">
                        <div class="flex items-start gap-3">
                            <div class="text-2xl">🔒</div>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-emerald-900">Your bid is locked</div>
                                <div class="text-sm text-emerald-800 mt-0.5">
                                    You bid <strong>{{ formatINR(myBid.bid_amount) }}</strong>
                                    <span v-if="myBid.placed_at"> on {{ formatDateTime(myBid.placed_at) }}</span>.
                                </div>
                                <div class="text-[11px] text-emerald-700 mt-1">Bids can't be changed once submitted. Results reveal after the window closes.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Member can still place (eligible, no prior bid) -->
                    <div v-else-if="canBid" class="bg-indigo-50 rounded-xl p-4 mb-4">
                        <label class="form-label">Your bid (max {{ formatINR(cycle.total_pool) }})</label>
                        <div class="flex gap-2">
                            <input v-model.number="bidForm.amount" type="number" min="1" :max="cycle.total_pool" class="form-input flex-1" placeholder="Bid amount in ₹" />
                            <button @click="placeBid" :disabled="loading" class="btn-primary">{{ loading ? 'Submitting…' : 'Place bid' }}</button>
                        </div>
                        <p class="text-xs text-amber-700 mt-2 font-medium">⚠ Once placed, your bid is final — it cannot be edited or withdrawn.</p>
                        <p class="text-xs text-gray-500 mt-1">Lowest bid wins (or admin picks multiple). Bids stay hidden from other members until the window closes.</p>
                    </div>

                    <!-- Non-admin view while bidding is still open: only the count is visible -->
                    <div v-if="tenderStore.meta && !tenderStore.meta.window_closed && !isAdmin">
                        <div class="text-center py-6 text-gray-500 text-sm">
                            <div class="font-semibold text-gray-700">{{ tenderStore.meta.bid_count }} bid{{ tenderStore.meta.bid_count !== 1 ? 's' : '' }} placed</div>
                            <div class="text-xs mt-1">Other members' amounts are hidden until the window closes.</div>
                        </div>
                    </div>

                    <!-- Admin view OR post-close: full bid list with names + amounts -->
                    <div v-else>
                        <div v-if="isAdmin && tenderStore.tenders.length" class="mb-3 flex items-center justify-between text-xs text-gray-500">
                            <span>{{ tenderStore.tenders.length }} bid{{ tenderStore.tenders.length !== 1 ? 's' : '' }} received · sorted by amount (low → high)</span>
                            <span v-if="cycle.is_bidding_open" class="text-amber-600 font-medium">Bidding still open</span>
                        </div>
                        <div v-if="!tenderStore.tenders.length" class="text-center py-6 text-gray-400 text-sm">No bids placed yet.</div>
                        <ul v-else class="divide-y divide-gray-100">
                            <li v-for="t in tenderStore.tenders" :key="t.id" class="py-3 flex items-center justify-between gap-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white text-[10px] font-bold flex items-center justify-center shrink-0">
                                        {{ t.user?.name?.split(' ').map(p => p[0]).slice(0,2).join('') || '?' }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-medium truncate flex items-center gap-1.5">
                                            {{ t.user?.name || (t.user_id === auth.user?.id ? 'You' : '—') }}
                                            <span v-if="t.is_winning_bid" class="badge-success">🏆 Winner</span>
                                            <span v-else-if="t.user_id === auth.user?.id" class="badge-info">You</span>
                                        </div>
                                        <div v-if="t.placed_at" class="text-[11px] text-gray-500">Placed {{ formatDateTime(t.placed_at) }}</div>
                                    </div>
                                </div>
                                <div class="font-bold shrink-0">{{ t.bid_amount ? formatINR(t.bid_amount) : '—' }}</div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Contributions -->
                <div class="surface-padded">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold">Contributions</h3>
                        <div v-if="isAdmin" class="text-xs text-gray-500">
                            {{ contribStore.contributions.filter(c => c.is_paid).length }} / {{ contribStore.contributions.length }} paid
                        </div>
                    </div>

                    <!-- Member view: only own contribution, no button to self-mark -->
                    <div v-if="!isAdmin">
                        <div v-if="myContribution && !myContribution.is_paid" class="p-4 bg-amber-50 border border-amber-200 rounded-xl">
                            <div class="font-semibold text-amber-900">Your contribution is due</div>
                            <div class="text-sm text-amber-800 mt-0.5">{{ formatINR(myContribution.amount) }} · Due {{ formatDate(myContribution.due_date) }}</div>
                            <div class="text-[11px] text-amber-700 mt-2">Pay the admin directly. They'll mark your contribution as received — you don't need to do anything on this screen.</div>
                        </div>
                        <div v-else-if="myContribution?.is_paid" class="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm text-emerald-800 flex items-center gap-2">
                            <span>✓</span>
                            <span>
                                Your contribution of {{ formatINR(myContribution.amount) }} was recorded
                                <span v-if="myContribution.paid_at">on {{ formatDate(myContribution.paid_at) }}</span>
                                <span v-if="myContribution.paid_late" class="text-amber-700 font-medium"> (late)</span>.
                            </span>
                        </div>
                        <div v-else class="text-sm text-gray-500 text-center py-4">No contribution record for you in this cycle.</div>
                    </div>

                    <!-- Admin view: full list + Mark paid actions -->
                    <table v-else class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="c in contribStore.contributions" :key="c.id">
                                <td class="py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-gray-100 text-gray-700 text-xs font-bold flex items-center justify-center">
                                            {{ c.user?.name?.split(' ').map(p => p[0]).slice(0,2).join('') }}
                                        </div>
                                        <span class="font-medium">{{ c.user?.name }}</span>
                                    </div>
                                </td>
                                <td class="py-2.5 text-right">{{ formatINR(c.amount) }}</td>
                                <td class="py-2.5 pl-3 text-right">
                                    <span v-if="c.is_paid" :class="c.paid_late ? 'badge-warning' : 'badge-success'" class="capitalize">
                                        {{ c.paid_late ? 'Paid late' : 'Paid' }}
                                    </span>
                                    <span v-else :class="contribStatusBadge[c.status]" class="capitalize">{{ c.status }}</span>
                                </td>
                                <td class="py-2.5 pl-3 text-right">
                                    <button
                                        v-if="!c.is_paid"
                                        @click="recordMemberPayment(c.user_id)"
                                        :disabled="loading"
                                        class="text-xs text-indigo-600 hover:underline disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Mark paid
                                    </button>
                                    <button
                                        v-else-if="!cycle.paid_out_at && (cycle.selection_method === 'organizer_payout' || !cycle.winner_id)"
                                        @click="undoPayment(c)"
                                        :disabled="loading"
                                        class="text-xs text-red-600 hover:underline disabled:opacity-50 disabled:cursor-not-allowed"
                                        title="Reverts the payment and the credit-score change"
                                    >
                                        Undo
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-5">
                <!-- Winner card -->
                <div class="surface-padded" :class="cycle.selection_method === 'organizer_payout' ? 'bg-indigo-50 border-indigo-200' : ''">
                    <h3 class="font-semibold mb-3">
                        <span v-if="cycle.selection_method === 'organizer_payout'" class="text-indigo-900">👑 Organizer Payout</span>
                        <span v-else>{{ cycle.winners_count > 1 ? `Winners (${cycle.winners_count})` : 'Winner' }}</span>
                    </h3>

                    <!-- Organizer cycle (always cycle #1) — admin receives the full pool -->
                    <div v-if="cycle.selection_method === 'organizer_payout'" class="text-center py-3">
                        <div class="text-5xl mb-2">👑</div>
                        <div class="font-bold text-lg text-indigo-900">{{ cycle.winner?.name || 'Admin' }}</div>
                        <div class="text-xs text-indigo-700">Cycle #1 · Organizer payout (cannot be changed)</div>
                        <div class="mt-3 text-xs text-gray-500">Payout</div>
                        <div class="text-2xl font-bold">{{ formatINR(cycle.payout_amount) }}</div>
                        <p class="text-[11px] text-gray-500 mt-2">As per platform rule, the very first cycle of every WISHI goes to the admin. Members contribute but no one bids for cycle #1.</p>
                    </div>

                    <!-- Multi-winner display (tender with >1 winning bids) -->
                    <div v-else-if="cycle.winners_count > 1">
                        <ul class="divide-y divide-gray-100">
                            <li v-for="t in tenderStore.tenders.filter((t) => t.is_winning_bid)" :key="t.id" class="py-2 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-amber-500">🏆</span>
                                    <span class="font-medium truncate">{{ t.user?.name || (t.user_id === auth.user?.id ? 'You' : '—') }}</span>
                                </div>
                                <div class="font-bold text-sm shrink-0">{{ formatINR(t.bid_amount) }}</div>
                            </li>
                        </ul>
                        <div class="mt-3 pt-3 border-t border-gray-100 text-xs space-y-1">
                            <div class="flex justify-between"><span class="text-gray-500">Pool</span><span>{{ formatINR(cycle.total_pool) }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Total payout</span><strong>{{ formatINR(cycle.payout_amount) }}</strong></div>
                            <div v-if="cycle.admin_topup_amount > 0" class="flex justify-between text-amber-700">
                                <span>💰 Admin top-up</span><strong>+{{ formatINR(cycle.admin_topup_amount) }}</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Single winner display -->
                    <div v-else-if="cycle.winner" class="text-center py-3">
                        <div class="text-5xl mb-2">🏆</div>
                        <div class="font-bold text-lg">{{ cycle.winner.name }}</div>
                        <div class="text-sm text-gray-500">via {{ cycle.selection_method?.replace('_', ' ') }}</div>
                        <div class="mt-3 text-xs text-gray-500">Payout</div>
                        <div class="text-2xl font-bold">{{ formatINR(cycle.payout_amount) }}</div>
                        <div v-if="cycle.winning_bid" class="text-xs text-gray-500 mt-1">Winning bid: {{ formatINR(cycle.winning_bid) }}</div>
                        <div v-if="cycle.selection_seed && isAdmin" class="mt-3 p-2 bg-gray-50 rounded text-[10px] text-gray-500 break-all">Seed: {{ cycle.selection_seed }}</div>
                    </div>
                    <div v-else class="text-center py-4 text-gray-400 text-sm">No winner selected yet.</div>

                    <!-- Action buttons when selection needed. Organizer cycle already has
                         winner_id pre-set, so this block never fires for cycle #1.
                         Also gated on the cycle reaching its due date — the admin cannot
                         announce a winner before the cycle matures. -->
                    <div v-if="isAdmin && !cycle.winner && cycle.winners_count === 0 && cycle.selection_method !== 'organizer_payout' && ['contribution_open','bidding_open','selection_pending'].includes(cycle.status)" class="space-y-2 mt-3">
                        <template v-if="isCycleMature">
                            <button @click="showWinnerModal = true" class="btn-primary w-full">
                                Select single winner
                            </button>
                            <button v-if="cycle.mode === 'tender' && tenderStore.tenders.length > 0" @click="showMultiWinnerModal = true" class="btn-secondary w-full">
                                Select multiple winners →
                            </button>
                        </template>
                        <div v-else class="p-3 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-800 text-sm">
                            <div class="font-semibold">⏳ Winner announcement locked</div>
                            <div class="text-xs mt-1">
                                This button will open on <strong>{{ formatDateTime(cycle.contribution_due_at) }}</strong>
                                ({{ daysTillMature }} day{{ daysTillMature !== 1 ? 's' : '' }} left).
                                Winners can only be chosen on or after the cycle's scheduled date.
                            </div>
                            <button class="btn-primary w-full mt-3 opacity-50 cursor-not-allowed" disabled>
                                Select winner (locked)
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Deferred amount (tender mode) -->
                <div v-if="cycle.mode === 'tender' && cycle.deferred_amount > 0" class="surface-padded" :class="cycle.deferred_released_at ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200'">
                    <h3 class="font-semibold mb-2">
                        <span v-if="cycle.deferred_released_at" class="text-emerald-900">✓ Deferred released</span>
                        <span v-else class="text-amber-900">🔒 Deferred to winner</span>
                    </h3>
                    <div class="text-2xl font-bold" :class="cycle.deferred_released_at ? 'text-emerald-700' : 'text-amber-700'">{{ formatINR(cycle.deferred_amount) }}</div>
                    <p v-if="cycle.deferred_released_at" class="text-xs text-emerald-800 mt-1">
                        Paid out to {{ cycle.winner?.name }} on {{ formatDate(cycle.deferred_released_at) }} when the WISHI completed.
                    </p>
                    <p v-else class="text-xs text-amber-800 mt-1">
                        Pool ₹{{ Number(cycle.total_pool).toLocaleString('en-IN') }} − Winning bid ₹{{ Number(cycle.winning_bid).toLocaleString('en-IN') }}.
                        Held for <strong>{{ cycle.winner?.name }}</strong> and released automatically after the final cycle is paid out.
                    </p>
                </div>

                <!-- Surplus (non-tender with actual unhandled surplus) -->
                <div v-if="cycle.mode !== 'tender' && cycle.surplus > 0" class="surface-padded">
                    <h3 class="font-semibold mb-3">Surplus</h3>
                    <div class="text-2xl font-bold text-emerald-600">{{ formatINR(cycle.surplus) }}</div>
                    <div v-if="cycle.surplus_action" class="text-sm text-gray-600 mt-1 capitalize">Action: {{ cycle.surplus_action.replace(/_/g, ' ') }}</div>
                    <button v-if="isAdmin && !cycle.surplus_action" @click="showSurplusModal = true" class="btn-secondary btn-sm w-full mt-3">Handle surplus</button>
                </div>

                <!-- Payout -->
                <div v-if="cycle.winner_id && !cycle.paid_out_at && isAdmin" class="surface-padded">
                    <h3 class="font-semibold mb-3">Record payout</h3>
                    <p class="text-sm text-gray-500 mb-3">Mark the payout as transferred to the winner.</p>
                    <button @click="showPayoutModal = true" class="btn-primary w-full">Record payout</button>
                </div>
                <div v-else-if="cycle.paid_out_at" class="surface-padded bg-emerald-50 border-emerald-200">
                    <div class="text-sm text-emerald-800">✓ Payout completed on {{ formatDate(cycle.paid_out_at) }}</div>
                </div>
            </div>
        </div>

        <!-- Payment Modal -->
        <div v-if="showPaymentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showPaymentModal = false">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md">
                <h3 class="font-semibold text-lg mb-4">Mark contribution as paid</h3>
                <div class="space-y-3">
                    <div>
                        <label class="form-label">Payment method</label>
                        <select v-model="paymentForm.payment_method" class="form-input">
                            <option value="upi">UPI</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Reference / TXN ID</label>
                        <input v-model="paymentForm.payment_reference" type="text" class="form-input" />
                    </div>
                    <div>
                        <label class="form-label">Notes</label>
                        <textarea v-model="paymentForm.notes" class="form-input" rows="2"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="showPaymentModal = false" class="btn-secondary">Cancel</button>
                    <button @click="recordOwnPayment" :disabled="loading" class="btn-primary">Save</button>
                </div>
            </div>
        </div>

        <!-- Winner Modal -->
        <div v-if="showWinnerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showWinnerModal = false">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md">
                <h3 class="font-semibold text-lg mb-4">Select winner</h3>
                <div class="space-y-3">
                    <div>
                        <label class="form-label">Method</label>
                        <select v-model="winnerForm.method" class="form-input">
                            <option value="auto">Automatic ({{ cycle.mode === 'tender' ? 'lowest bid' : 'random draw' }})</option>
                            <option value="manual">Manual override</option>
                        </select>
                    </div>
                    <div v-if="winnerForm.method === 'manual'">
                        <label class="form-label">Choose member</label>
                        <select v-model="winnerForm.user_id" class="form-input">
                            <option :value="null">Select a member…</option>
                            <option v-for="m in eligibleMembers" :key="m.user_id" :value="m.user_id">{{ m.user?.name }} (score: {{ m.user?.credit_score }})</option>
                        </select>
                        <label class="form-label mt-3">Reason (logged in audit)</label>
                        <textarea v-model="winnerForm.reason" class="form-input" rows="2"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="showWinnerModal = false" class="btn-secondary">Cancel</button>
                    <button @click="selectWinner" :disabled="loading" class="btn-primary">Select</button>
                </div>
            </div>
        </div>

        <!-- Multi-Winner Modal -->
        <div v-if="showMultiWinnerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showMultiWinnerModal = false">
            <div class="bg-white rounded-2xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
                <h3 class="font-semibold text-lg mb-1">Select multiple winners</h3>
                <p class="text-xs text-gray-500 mb-4">Tick the bids you want to accept. Any shortfall you cover personally is logged and shown on this cycle.</p>

                <div class="space-y-2">
                    <label v-for="t in tenderStore.tenders" :key="t.id"
                        class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition"
                        :class="selectedBidIds.has(t.id) ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:border-indigo-300'"
                    >
                        <input type="checkbox" :checked="selectedBidIds.has(t.id)" @change="toggleBidSelection(t.id)" class="rounded text-indigo-600" />
                        <div class="flex-1 min-w-0">
                            <div class="font-medium truncate">{{ t.user?.name || 'Member' }}</div>
                            <div class="text-xs text-gray-500">Bid placed {{ formatDateTime(t.placed_at) }}</div>
                        </div>
                        <div class="font-bold">{{ formatINR(t.bid_amount) }}</div>
                    </label>
                </div>

                <div class="mt-4 p-4 rounded-xl" :class="topupRequired > 0 ? 'bg-amber-50 border border-amber-200' : (surplusPending > 0 ? 'bg-sky-50 border border-sky-200' : 'bg-emerald-50 border border-emerald-200')">
                    <dl class="text-sm space-y-1">
                        <div class="flex justify-between"><dt class="text-gray-600">Selected bids</dt><dd class="font-semibold">{{ selectedBidIds.size }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600">Total of selected</dt><dd class="font-bold">{{ formatINR(selectedTotal) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-600">Pool</dt><dd>{{ formatINR(cycle.total_pool) }}</dd></div>
                        <div v-if="topupRequired > 0" class="flex justify-between text-amber-800 pt-1 border-t border-amber-200"><dt class="font-semibold">💰 Admin top-up (your pocket)</dt><dd class="font-bold">{{ formatINR(topupRequired) }}</dd></div>
                        <div v-else-if="surplusPending > 0" class="flex justify-between text-sky-800 pt-1 border-t border-sky-200"><dt class="font-semibold">🔒 Deferred to winners</dt><dd class="font-bold">{{ formatINR(surplusPending) }}</dd></div>
                        <div v-else class="text-emerald-800 pt-1 border-t border-emerald-200 text-center font-medium">✓ Exact match · no top-up, no deferred</div>
                    </dl>
                </div>

                <label v-if="topupRequired > 0" class="flex items-start gap-2 mt-3 p-3 bg-amber-50 rounded-lg cursor-pointer">
                    <input v-model="multiAcceptTopup" type="checkbox" class="rounded text-amber-600 mt-0.5" />
                    <span class="text-xs text-amber-800">
                        I agree to personally cover the ₹{{ Number(topupRequired).toLocaleString('en-IN') }} shortfall. This will be recorded on the cycle and visible in the audit log.
                    </span>
                </label>

                <div>
                    <label class="form-label mt-3">Reason (optional)</label>
                    <textarea v-model="multiReason" rows="2" class="form-input" placeholder="Why these bids were accepted together…"></textarea>
                </div>

                <div class="flex justify-end gap-2 mt-5">
                    <button @click="showMultiWinnerModal = false" class="btn-secondary">Cancel</button>
                    <button @click="confirmMultiWinners" :disabled="loading || selectedBidIds.size === 0" class="btn-primary">
                        {{ loading ? 'Confirming…' : `Confirm ${selectedBidIds.size} winner${selectedBidIds.size !== 1 ? 's' : ''}` }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Surplus Modal -->
        <div v-if="showSurplusModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showSurplusModal = false">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md">
                <h3 class="font-semibold text-lg mb-4">Handle surplus ({{ formatINR(cycle.surplus) }})</h3>
                <div class="space-y-3">
                    <label class="form-label">Action</label>
                    <select v-model="surplusForm.action" class="form-input">
                        <option value="distribute">Distribute equally to members</option>
                        <option value="reserve">Reserve for emergency fund</option>
                        <option value="bonus">Bonus to specific member</option>
                        <option value="admin_adjust">Admin adjustment</option>
                    </select>
                    <div v-if="surplusForm.action === 'bonus'">
                        <label class="form-label">Recipient</label>
                        <select v-model="surplusForm.recipient_id" class="form-input">
                            <option :value="null">Choose member…</option>
                            <option v-for="m in memberStore.members.filter(x => ['active','approved'].includes(x.status))" :key="m.user_id" :value="m.user_id">{{ m.user?.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Reason</label>
                        <textarea v-model="surplusForm.reason" class="form-input" rows="2"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="showSurplusModal = false" class="btn-secondary">Cancel</button>
                    <button @click="handleSurplus" :disabled="loading" class="btn-primary">Confirm</button>
                </div>
            </div>
        </div>

        <!-- Payout Modal -->
        <div v-if="showPayoutModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showPayoutModal = false">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md">
                <h3 class="font-semibold text-lg mb-4">Record payout</h3>
                <div class="space-y-3">
                    <div>
                        <label class="form-label">Method</label>
                        <select v-model="payoutForm.method" class="form-input">
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="upi">UPI</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Reference</label>
                        <input v-model="payoutForm.reference" type="text" class="form-input" />
                    </div>
                    <div>
                        <label class="form-label">Notes</label>
                        <textarea v-model="payoutForm.notes" class="form-input" rows="2"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="showPayoutModal = false" class="btn-secondary">Cancel</button>
                    <button @click="recordPayout" :disabled="loading" class="btn-primary">Record</button>
                </div>
            </div>
        </div>
    </div>
</template>
