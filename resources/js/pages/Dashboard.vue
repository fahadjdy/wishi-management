<script setup>
import { onMounted, computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useDashboardStore } from '@/stores/dashboard';
import { useAuthStore } from '@/stores/auth';
import { formatINR, formatDate, trustColor } from '@/utils/format';

const dash = useDashboardStore();
const auth = useAuthStore();

onMounted(() => dash.fetch());

function daysUntil(dateStr) {
    if (!dateStr) return null;
    const d = new Date(dateStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    d.setHours(0, 0, 0, 0);
    return Math.round((d.getTime() - today.getTime()) / 86400000);
}

const nextPayment = computed(() => {
    const list = dash.data?.upcoming_payments || [];
    return list.length ? list[0] : null;
});

function urgencyClass(days) {
    if (days === null) return 'text-gray-500';
    if (days < 0) return 'text-red-600';
    if (days <= 2) return 'text-red-600';
    if (days <= 5) return 'text-amber-600';
    return 'text-emerald-600';
}

function urgencyLabel(days) {
    if (days === null) return '';
    if (days < 0) return `${Math.abs(days)} day${Math.abs(days) !== 1 ? 's' : ''} overdue`;
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

        <!-- Next payment alert (prominent) -->
        <div v-if="nextPayment" class="surface-padded"
            :class="daysUntil(nextPayment.due_date) <= 2 ? 'bg-red-50 border-red-200' : daysUntil(nextPayment.due_date) <= 5 ? 'bg-amber-50 border-amber-200' : 'bg-emerald-50 border-emerald-200'">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3 min-w-0 flex-1">
                    <div class="text-3xl">
                        <span v-if="daysUntil(nextPayment.due_date) < 0">⚠️</span>
                        <span v-else-if="daysUntil(nextPayment.due_date) <= 2">⏰</span>
                        <span v-else>💳</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs uppercase tracking-wider font-semibold" :class="urgencyClass(daysUntil(nextPayment.due_date))">
                            Next payment
                        </div>
                        <div class="text-xl sm:text-2xl font-bold text-gray-900 mt-0.5">{{ formatINR(nextPayment.amount) }}</div>
                        <div class="text-sm text-gray-700 mt-0.5">
                            {{ nextPayment.wishi?.name || 'WISHI' }} · Cycle #{{ nextPayment.cycle_number }}
                        </div>
                        <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                            <span :class="urgencyClass(daysUntil(nextPayment.due_date))" class="font-semibold text-sm">
                                {{ urgencyLabel(daysUntil(nextPayment.due_date)) }}
                            </span>
                            <span class="text-xs text-gray-500">· {{ formatDate(nextPayment.due_date) }}</span>
                        </div>
                    </div>
                </div>
                <RouterLink v-if="nextPayment.wishi?.id" :to="`/wishis/${nextPayment.wishi.uuid || nextPayment.wishi.id}`" class="btn-primary shrink-0">
                    Pay now →
                </RouterLink>
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
                            <div class="font-medium text-gray-900 truncate">{{ p.wishi?.name || 'WISHI' }}</div>
                            <div class="text-xs text-gray-500 flex items-center gap-1.5 flex-wrap mt-0.5">
                                <span>Cycle #{{ p.cycle_number }}</span>
                                <span>·</span>
                                <span>{{ formatDate(p.due_date) }}</span>
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
