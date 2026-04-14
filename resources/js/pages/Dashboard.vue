<script setup>
import { onMounted, computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useDashboardStore } from '@/stores/dashboard';
import { useAuthStore } from '@/stores/auth';
import { useWishiStore } from '@/stores/wishi';
import { useToast } from 'vue-toastification';
import { formatINR, formatDate, trustColor } from '@/utils/format';

const dash = useDashboardStore();
const auth = useAuthStore();
const wishiStore = useWishiStore();
const toast = useToast();
const joiningUuid = ref(null);

onMounted(() => dash.fetch());

const joinableWishis = computed(() => dash.data?.joinable_wishis || []);

async function requestJoin(uuid) {
    joiningUuid.value = uuid;
    try {
        const res = await wishiStore.join(uuid);
        toast.success(res.status === 'approved' ? 'You have joined.' : 'Join request sent to admin.');
        await dash.fetch();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not send join request.');
    } finally {
        joiningUuid.value = null;
    }
}

function daysUntil(dateStr) {
    if (!dateStr) return null;
    const d = new Date(dateStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    d.setHours(0, 0, 0, 0);
    return Math.round((d.getTime() - today.getTime()) / 86400000);
}

const upcomingPayments = computed(() => dash.data?.upcoming_payments || []);

// Late payments only — drives the warning hero card. A payment is "late" when
// its due date has passed (days < 0). Future-due payments never show a red warning.
const latePayments = computed(() => upcomingPayments.value.filter((p) => daysUntil(p.due_date) < 0));

// Sum of all amounts the member has to pay across their joined WISHIs — shown
// as the top-of-dashboard "Next month total" pill so the member always knows
// the full out-of-pocket figure without doing mental math.
const nextMonthTotal = computed(() => upcomingPayments.value.reduce((s, p) => s + Number(p.amount || 0), 0));

const upcomingOpenings = computed(() => dash.data?.upcoming_wishi_openings || []);

function openingLabel(days) {
    if (days === null) return '';
    if (days < 0) return `Overdue to open — ${Math.abs(days)} day${Math.abs(days) !== 1 ? 's' : ''} past start date`;
    if (days === 0) return 'Opens today';
    if (days === 1) return 'Opens tomorrow';
    return `Opens in ${days} days`;
}

function openingClass(days) {
    if (days === null) return 'bg-gray-50 border-gray-200';
    if (days < 0) return 'bg-red-50 border-red-200';
    if (days <= 1) return 'bg-amber-50 border-amber-200';
    return 'bg-indigo-50 border-indigo-200';
}

function openingTextClass(days) {
    if (days === null) return 'text-gray-500';
    if (days < 0) return 'text-red-700';
    if (days <= 1) return 'text-amber-700';
    return 'text-indigo-700';
}

// Product rule: warning color (red) is shown ONLY when a payment is actually
// late — i.e. the due date has passed. Upcoming payments (even "due today"
// or "due tomorrow") stay neutral so the dashboard doesn't cry wolf. See R5.
function urgencyClass(days) {
    if (days === null) return 'text-gray-500';
    if (days < 0) return 'text-red-600';
    return 'text-gray-600';
}

function urgencyLabel(days) {
    if (days === null) return '';
    if (days < 0) return `${Math.abs(days)} day${Math.abs(days) !== 1 ? 's' : ''} late`;
    if (days === 0) return 'Due today';
    if (days === 1) return 'Due tomorrow';
    return `Due in ${days} day${days !== 1 ? 's' : ''}`;
}
</script>

<template>
    <div class="space-y-6">
        <!-- Hero -->
        <div class="bg-gradient-to-br from-indigo-600 to-purple-700 rounded-2xl p-6 sm:p-7 text-white shadow-lg shadow-indigo-500/20">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-indigo-100 text-sm">Welcome back,</p>
                    <h1 class="text-2xl sm:text-3xl font-bold mt-0.5">{{ auth.user?.name }} 👋</h1>
                    <p class="text-indigo-100 text-sm mt-2">Here's a snapshot of all your WISHIs and contributions.</p>
                </div>
                <RouterLink v-if="auth.user?.is_admin" to="/wishis/create" class="bg-white text-indigo-700 hover:bg-indigo-50 font-semibold px-5 py-2.5 rounded-lg text-sm shadow-md">
                    + Create new WISHI
                </RouterLink>
            </div>
        </div>

        <!-- Late-payment warning (only when the member is actually behind) -->
        <div v-if="latePayments.length" class="surface-padded bg-red-50 border-red-200">
            <div class="flex items-start gap-3">
                <div class="text-3xl">⚠️</div>
                <div class="min-w-0 flex-1">
                    <div class="text-xs uppercase tracking-wider font-semibold text-red-700">
                        {{ latePayments.length }} late payment{{ latePayments.length !== 1 ? 's' : '' }}
                    </div>
                    <p class="text-sm text-red-800 mt-1">
                        Please clear these with the WISHI admin as soon as possible — late payments reduce your credit score.
                    </p>
                    <ul class="mt-2 divide-y divide-red-200 text-sm">
                        <li v-for="p in latePayments" :key="p.id" class="py-2 flex items-center justify-between gap-2 flex-wrap">
                            <div class="min-w-0">
                                <RouterLink v-if="p.wishi?.uuid" :to="`/wishis/${p.wishi.uuid}`" class="text-red-900 font-medium hover:underline truncate block">
                                    {{ p.wishi.name }} · Cycle #{{ p.cycle_number }}
                                </RouterLink>
                                <span v-else class="text-red-900 font-medium truncate">WISHI · Cycle #{{ p.cycle_number }}</span>
                                <div class="text-[11px] text-red-700 mt-0.5">Was due on {{ formatDate(p.due_date) }}</div>
                            </div>
                            <span class="text-red-700 font-semibold shrink-0">{{ formatINR(p.amount) }} · {{ urgencyLabel(daysUntil(p.due_date)) }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Next month total (always visible when there are dues) — neutral, informational -->
        <div v-if="upcomingPayments.length" class="surface-padded">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-xs uppercase tracking-wider font-semibold text-gray-500">Next month total</div>
                    <div class="text-2xl sm:text-3xl font-bold text-gray-900 mt-0.5">{{ formatINR(nextMonthTotal) }}</div>
                    <p class="text-xs text-gray-500 mt-1">Across {{ upcomingPayments.length }} WISHI{{ upcomingPayments.length !== 1 ? 's' : '' }} you're a member of.</p>
                </div>
                <div class="text-right text-xs text-gray-500 italic max-w-xs">
                    Pay the WISHI admin directly — cash, UPI or bank transfer. They'll mark each payment as received.
                </div>
            </div>
        </div>

        <!-- Upcoming WISHI openings (admin/creator view) -->
        <div v-if="upcomingOpenings.length" class="space-y-3">
            <RouterLink v-for="w in upcomingOpenings" :key="w.uuid"
                :to="`/wishis/${w.uuid}`"
                class="surface-padded block transition hover:shadow-md"
                :class="openingClass(daysUntil(w.start_date))">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-3 min-w-0 flex-1">
                        <div class="text-3xl">
                            <span v-if="daysUntil(w.start_date) < 0">⚠️</span>
                            <span v-else-if="daysUntil(w.start_date) <= 1">⏰</span>
                            <span v-else>🚀</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-xs uppercase tracking-wider font-semibold" :class="openingTextClass(daysUntil(w.start_date))">
                                Upcoming WISHI opening
                            </div>
                            <div class="text-xl sm:text-2xl font-bold text-gray-900 mt-0.5 truncate">
                                {{ w.name }} will {{ openingLabel(daysUntil(w.start_date)).toLowerCase() }}
                            </div>
                            <div class="flex items-center gap-2 mt-1.5 flex-wrap text-sm">
                                <span :class="openingTextClass(daysUntil(w.start_date))" class="font-semibold">
                                    {{ openingLabel(daysUntil(w.start_date)) }}
                                </span>
                                <span class="text-xs text-gray-500">· {{ formatDate(w.start_date) }}</span>
                            </div>
                            <div class="flex items-center gap-2 mt-2 flex-wrap text-xs text-gray-600">
                                <span class="badge-gray">{{ w.active_members }}/{{ w.total_members }} members</span>
                                <span v-if="w.is_full" class="badge-success">Full — ready to start</span>
                                <span v-else class="badge-warning">{{ w.total_members - w.active_members }} seat{{ w.total_members - w.active_members !== 1 ? 's' : '' }} left</span>
                                <span class="text-gray-500">· {{ formatINR(w.monthly_contribution) }}/month · {{ w.duration_months }} cycles</span>
                            </div>
                        </div>
                    </div>
                </div>
            </RouterLink>
        </div>

        <!-- Discover WISHIs accepting members -->
        <div v-if="joinableWishis.length" class="surface-padded">
            <div class="flex items-center justify-between mb-3 gap-2 flex-wrap">
                <div>
                    <h2 class="text-lg font-semibold">Discover WISHIs</h2>
                    <p class="text-xs text-gray-500">New WISHIs accepting members. Tap to view or request to join.</p>
                </div>
                <RouterLink to="/wishis?scope=discover" class="btn-ghost btn-sm">See all →</RouterLink>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div v-for="w in joinableWishis" :key="w.uuid" class="p-4 rounded-xl border border-gray-200 hover:border-indigo-300 hover:shadow-sm transition flex flex-col gap-2">
                    <div class="flex items-start justify-between gap-2">
                        <RouterLink :to="`/wishis/${w.uuid}`" class="font-semibold text-gray-900 hover:text-indigo-600 truncate">{{ w.name }}</RouterLink>
                        <span v-if="w.status === 'draft'" class="badge-warning">Draft</span>
                        <span v-else class="badge-success">Active</span>
                    </div>
                    <div class="text-xs text-gray-500">by {{ w.creator_name }}</div>
                    <div class="text-sm text-gray-700">
                        <span class="font-semibold">{{ formatINR(w.monthly_contribution) }}</span>/month · {{ w.duration_months }} cycles · <span class="capitalize">{{ w.cycle_type }}</span>
                    </div>
                    <div class="flex items-center gap-1.5 flex-wrap text-xs">
                        <span class="badge-gray">{{ w.active_members }}/{{ w.total_members }} members</span>
                        <span class="badge-info">{{ w.seats_left }} seat{{ w.seats_left !== 1 ? 's' : '' }} left</span>
                        <span v-if="w.start_date" class="text-gray-500">· starts {{ formatDate(w.start_date) }}</span>
                    </div>
                    <div class="flex gap-2 mt-1">
                        <button @click="requestJoin(w.uuid)" :disabled="joiningUuid === w.uuid" class="btn-primary btn-sm flex-1">
                            {{ joiningUuid === w.uuid ? 'Sending…' : (w.require_approval ? 'Request to join' : 'Join now') }}
                        </button>
                        <RouterLink :to="`/wishis/${w.uuid}`" class="btn-secondary btn-sm">View</RouterLink>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="surface-padded">
                <div class="stat-bar bg-indigo-500"></div>
                <div class="text-sm text-gray-500">Active WISHIs</div>
                <div class="text-3xl font-bold mt-1.5">{{ dash.data?.active_wishis_count ?? '—' }}</div>
                <div class="text-xs text-gray-400 mt-2">{{ dash.data?.created_wishis_count ?? 0 }} created by you</div>
            </div>
            <div class="surface-padded">
                <div class="stat-bar bg-emerald-500"></div>
                <div class="text-sm text-gray-500">Total Contributed</div>
                <div class="text-3xl font-bold mt-1.5">{{ dash.data ? formatINR(dash.data.total_contributed) : '—' }}</div>
                <div class="text-xs text-gray-400 mt-2">across all cycles</div>
            </div>
            <div class="surface-padded">
                <div class="stat-bar bg-amber-500"></div>
                <div class="text-sm text-gray-500">Total Won</div>
                <div class="text-3xl font-bold mt-1.5">{{ dash.data ? formatINR(dash.data.total_won) : '—' }}</div>
                <div class="text-xs text-gray-400 mt-2">payouts received</div>
            </div>
            <div class="surface-padded">
                <div class="stat-bar bg-purple-500"></div>
                <div class="text-sm text-gray-500">Credit Score</div>
                <div class="flex items-baseline gap-2 mt-1.5">
                    <div class="text-3xl font-bold">{{ dash.data?.credit_score ?? '—' }}</div>
                    <span v-if="dash.data?.trust_level" class="capitalize" :class="trustColor[dash.data.trust_level]">{{ dash.data.trust_level }}</span>
                </div>
                <div class="text-xs text-gray-400 mt-2">out of 100</div>
            </div>
        </div>

        <!-- Upcoming + Active -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="surface-padded lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold">Upcoming Payments</h2>
                    <span class="badge-brand">{{ dash.data?.upcoming_payments?.length ?? 0 }} due</span>
                </div>
                <div v-if="!dash.data?.upcoming_payments?.length" class="text-center py-10 text-gray-400 text-sm">
                    No upcoming payments — you're all caught up! 🎉
                </div>
                <div v-else class="divide-y divide-gray-100">
                    <div v-for="p in dash.data.upcoming_payments" :key="p.id" class="py-3 flex items-center justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <RouterLink v-if="p.wishi?.uuid" :to="`/wishis/${p.wishi.uuid}`" class="font-medium text-gray-900 hover:text-indigo-600 hover:underline truncate block">
                                {{ p.wishi.name }}
                            </RouterLink>
                            <div v-else class="font-medium text-gray-900 truncate">WISHI</div>
                            <div class="text-xs text-gray-500 flex items-center gap-1.5 flex-wrap mt-0.5">
                                <span>Cycle #{{ p.cycle_number }}</span>
                                <span>·</span>
                                <span>Due {{ formatDate(p.due_date) }}</span>
                                <span>·</span>
                                <span :class="urgencyClass(daysUntil(p.due_date))" class="font-medium">{{ urgencyLabel(daysUntil(p.due_date)) }}</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="font-bold text-gray-900">{{ formatINR(p.amount) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="surface-padded">
                <h2 class="text-lg font-semibold mb-4">Cycle Activity</h2>
                <div class="space-y-3">
                    <div class="p-4 rounded-xl bg-gray-50">
                        <div class="text-sm text-gray-500">Active cycles</div>
                        <div class="text-2xl font-bold mt-0.5">{{ dash.data?.active_cycles_count ?? '—' }}</div>
                    </div>
                    <RouterLink to="/wishis" class="block w-full text-center btn-secondary">View all WISHIs →</RouterLink>
                    <RouterLink to="/profile" class="block w-full text-center btn-ghost">Credit history →</RouterLink>
                </div>
            </div>
        </div>
    </div>
</template>
