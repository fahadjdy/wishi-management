<script setup>
import { onMounted, onBeforeUnmount, computed, ref, watch, nextTick } from 'vue';
import { RouterLink } from 'vue-router';
import { useDashboardStore } from '@/stores/dashboard';
import { useAuthStore } from '@/stores/auth';
import { useWishiStore } from '@/stores/wishi';
import { useAdminStore } from '@/stores/admin';
import { useMemberStore } from '@/stores/member';
import { useToast } from 'vue-toastification';
import { formatINR, formatDate, formatDateTime, trustColor } from '@/utils/format';
import * as am5 from '@amcharts/amcharts5';
import * as am5percent from '@amcharts/amcharts5/percent';
import * as am5xy from '@amcharts/amcharts5/xy';
import am5themes_Animated from '@amcharts/amcharts5/themes/Animated';
import { useConfirm } from '@/composables/useConfirm';
import {
    ExclamationTriangleIcon, ClockIcon, RocketLaunchIcon,
    CheckCircleIcon, PlusIcon, EnvelopeIcon, ArrowRightIcon,
} from '@heroicons/vue/24/outline';
import Cover from '@/components/Cover.vue';

const dash = useDashboardStore();
const auth = useAuthStore();
const wishiStore = useWishiStore();
const adminStore = useAdminStore();
const memberStore = useMemberStore();
const toast = useToast();
const { prompt: uiPrompt } = useConfirm();
const joiningUuid = ref(null);
const pendingActionId = ref(null);

const isAdmin = computed(() => !!auth.user?.is_admin);

onMounted(async () => {
    await dash.fetch();
    if (isAdmin.value) {
        await adminStore.fetchDashboard();
        await renderCharts();
    }
});

const joinableWishis = computed(() => dash.data?.joinable_wishis || []);

// Design: top-most joinable WISHI gets the terracotta hero treatment;
// the rest render in a compact 2-col grid below.
const heroJoinable = computed(() => joinableWishis.value[0] || null);
const restJoinable = computed(() => joinableWishis.value.slice(1));

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

const pendingJoinRequests = computed(() => adminStore.dashboard?.pending_join_requests || []);

async function approveRequest(r) {
    pendingActionId.value = r.member_id;
    try {
        await memberStore.approve(r.wishi.uuid, r.member_id);
        toast.success(`Approved · ${r.user.name} is now in ${r.wishi.name}.`);
        await adminStore.fetchDashboard();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Approve failed.');
    } finally { pendingActionId.value = null; }
}

async function rejectRequest(r) {
    const reason = await uiPrompt({
        title: `Reject ${r.user.name}?`,
        message: `Their join request for ${r.wishi.name} will be declined.`,
        label: 'Reason (optional — shown in the audit log)',
        placeholder: 'e.g. credit score too low, already in another WISHI',
        multiline: true,
        confirmText: 'Reject request',
        cancelText: 'Keep request',
    });
    if (reason === null) return;
    pendingActionId.value = r.member_id;
    try {
        await memberStore.reject(r.wishi.uuid, r.member_id, reason || null);
        toast.success('Request rejected.');
        await adminStore.fetchDashboard();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Reject failed.');
    } finally { pendingActionId.value = null; }
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

// Unique WISHI count backing the "Across N WISHI you're a member of" label.
// Derived from the payments list itself — one payment may exist per WISHI per
// cycle, so we dedupe on uuid to avoid inflating the count when multiple
// cycles of the same WISHI are pending.
const upcomingPaymentWishiCount = computed(() => new Set(
    upcomingPayments.value.map((p) => p.wishi?.uuid).filter(Boolean)
).size);

const upcomingOpenings = computed(() => dash.data?.upcoming_wishi_openings || []);

function openingLabel(days) {
    if (days === null) return '';
    if (days < 0) return `Overdue to open — ${Math.abs(days)} day${Math.abs(days) !== 1 ? 's' : ''} past start date`;
    if (days === 0) return 'Opens today';
    if (days === 1) return 'Opens tomorrow';
    return `Opens in ${days} days`;
}

function openingClass(days) {
    if (days === null) return 'bg-white border-slate-200';
    if (days < 0) return 'bg-rose-50 border-rose-200';
    if (days <= 1) return 'bg-amber-50 border-amber-200';
    return 'bg-brand-50 border-brand-200';
}

const todayLabel = computed(() => {
    const d = new Date();
    return d.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' });
});

const firstName = computed(() => (auth.user?.name || '').split(' ')[0] || '');
const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return 'Namaste';
    if (h < 17) return 'Hello';
    return 'Good evening';
});

// Sum lifetime contribution (data already on dash store, fallback safe).
const lifetimeContributed = computed(() => dash.data?.total_contributed || 0);

function openingTextClass(days) {
    if (days === null) return 'text-slate-500';
    if (days < 0) return 'text-rose-700';
    if (days <= 1) return 'text-amber-700';
    return 'text-brand-700';
}

function openingIcon(days) {
    if (days === null) return RocketLaunchIcon;
    if (days < 0) return ExclamationTriangleIcon;
    if (days <= 1) return ClockIcon;
    return RocketLaunchIcon;
}

function openingIconTone(days) {
    if (days === null) return 'bg-slate-100 text-slate-500';
    if (days < 0) return 'bg-rose-100 text-rose-600';
    if (days <= 1) return 'bg-amber-100 text-amber-600';
    return 'bg-brand-100 text-brand-600';
}

// Product rule: warning color (rose) is shown ONLY when a payment is actually
// late — i.e. the due date has passed. Upcoming payments (even "due today"
// or "due tomorrow") stay neutral so the dashboard doesn't cry wolf.
function urgencyClass(days) {
    if (days === null) return 'text-slate-500';
    if (days < 0) return 'text-rose-600';
    return 'text-slate-600';
}

function urgencyLabel(days) {
    if (days === null) return '';
    if (days < 0) return `${Math.abs(days)} day${Math.abs(days) !== 1 ? 's' : ''} late`;
    if (days === 0) return 'Due today';
    if (days === 1) return 'Due tomorrow';
    return `Due in ${days} day${days !== 1 ? 's' : ''}`;
}

// ---- Admin analytics (AMCharts) — only wired up when the viewer is admin ----
const roots = [];
const pieRoleEl = ref(null);
const pieTypeEl = ref(null);
const barTrustEl = ref(null);
const lineSignupsEl = ref(null);
const areaMoneyEl = ref(null);

function dispose() {
    roots.forEach((r) => r.dispose?.());
    roots.length = 0;
}

function buildPie(el, data, categoryField, valueField, colors) {
    const root = am5.Root.new(el);
    root.setThemes([am5themes_Animated.new(root)]);
    const chart = root.container.children.push(am5percent.PieChart.new(root, {
        layout: root.verticalLayout,
        innerRadius: am5.percent(50),
    }));
    const series = chart.series.push(am5percent.PieSeries.new(root, {
        valueField,
        categoryField,
        alignLabels: false,
    }));
    series.labels.template.setAll({ text: '{category}\n{value}', fontSize: 11, fill: am5.color(0x374151) });
    series.slices.template.setAll({ strokeWidth: 2, stroke: am5.color(0xffffff), cornerRadius: 4 });
    series.get('colors').set('colors', colors.map(c => am5.color(c)));
    series.data.setAll(data);
    chart.appear(800, 60);
    roots.push(root);
}

function buildBar(el, data, categoryField, valueField, color) {
    const root = am5.Root.new(el);
    root.setThemes([am5themes_Animated.new(root)]);
    const chart = root.container.children.push(am5xy.XYChart.new(root, {
        panX: false, panY: false,
        paddingLeft: 0, paddingRight: 10,
    }));
    const xAxis = chart.xAxes.push(am5xy.CategoryAxis.new(root, {
        categoryField,
        renderer: am5xy.AxisRendererX.new(root, { minGridDistance: 30 }),
    }));
    xAxis.data.setAll(data);
    const yAxis = chart.yAxes.push(am5xy.ValueAxis.new(root, {
        renderer: am5xy.AxisRendererY.new(root, {}),
    }));
    const series = chart.series.push(am5xy.ColumnSeries.new(root, {
        xAxis, yAxis, valueYField: valueField, categoryXField: categoryField,
        tooltip: am5.Tooltip.new(root, { labelText: '{categoryX}: {valueY}' }),
    }));
    series.columns.template.setAll({
        cornerRadiusTL: 6, cornerRadiusTR: 6,
        strokeOpacity: 0, fillOpacity: 0.9,
    });
    series.columns.template.adapters.add('fill', () => am5.color(color));
    series.columns.template.adapters.add('stroke', () => am5.color(color));
    series.data.setAll(data);
    chart.appear(800, 60);
    series.appear();
    roots.push(root);
}

function buildLine(el, data, categoryField, valueField, color) {
    const root = am5.Root.new(el);
    root.setThemes([am5themes_Animated.new(root)]);
    const chart = root.container.children.push(am5xy.XYChart.new(root, {
        panX: true, panY: true, wheelX: 'panX', wheelY: 'zoomX', paddingLeft: 0,
    }));
    const xAxis = chart.xAxes.push(am5xy.DateAxis.new(root, {
        baseInterval: { timeUnit: 'day', count: 1 },
        renderer: am5xy.AxisRendererX.new(root, { minGridDistance: 50 }),
    }));
    const yAxis = chart.yAxes.push(am5xy.ValueAxis.new(root, {
        renderer: am5xy.AxisRendererY.new(root, {}),
    }));
    const series = chart.series.push(am5xy.LineSeries.new(root, {
        xAxis, yAxis, valueXField: 'date', valueYField: valueField,
        tooltip: am5.Tooltip.new(root, { labelText: '{valueX.formatDate("MMM dd")}: {valueY}' }),
    }));
    series.strokes.template.setAll({ strokeWidth: 3, stroke: am5.color(color) });
    series.fills.template.setAll({ fillOpacity: 0.2, visible: true, fill: am5.color(color) });
    const parsed = data.map(d => ({ date: new Date(d[categoryField]).getTime(), [valueField]: d[valueField] }));
    series.data.setAll(parsed);
    series.bullets.push(() => am5.Bullet.new(root, {
        sprite: am5.Circle.new(root, { radius: 3, fill: am5.color(color), stroke: am5.color(0xffffff), strokeWidth: 1 }),
    }));
    chart.appear(800, 60);
    series.appear();
    roots.push(root);
}

async function renderCharts() {
    dispose();
    await nextTick();
    const d = adminStore.dashboard;
    // Refs only exist after the admin <template> branch has rendered; guard so
    // member views (where these chart containers are absent) don't blow up.
    if (!d || !pieRoleEl.value) return;
    // Warm desi-modern palette: terracotta + deep green + mustard for visual variety.
    buildPie(pieRoleEl.value, d.users_by_role, 'role', 'count', [0xC25A36, 0x7A6E60]);
    buildPie(pieTypeEl.value, d.wishis_by_type, 'cycle_type', 'count', [0xC25A36, 0x2D6B57, 0xD49B3A]);
    buildBar(barTrustEl.value, d.users_by_trust, 'trust_level', 'count', 0xC25A36);
    buildLine(lineSignupsEl.value, d.signups_last_30_days, 'date', 'count', 0xC25A36);
    buildLine(areaMoneyEl.value, d.contributions_last_30_days, 'date', 'amount', 0x2D6B57);
}

watch(() => adminStore.dashboard, () => {
    if (isAdmin.value) renderCharts();
});

onBeforeUnmount(dispose);
</script>

<template>
    <div class="space-y-6">
        <!-- Hero — warm greeting + trust chip (member) or quick-create (admin) -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <div class="text-xs text-slate-500">{{ todayLabel }}</div>
                <h1 class="display text-3xl sm:text-4xl text-slate-900 mt-0.5">
                    {{ greeting }},
                    <span class="italic text-brand-600">{{ firstName }}</span><span class="text-brand-600">.</span>
                </h1>
                <p class="text-sm text-slate-500 mt-2">
                    {{ isAdmin
                        ? 'Platform overview — WISHIs, members and money at a glance.'
                        : "Here's a calm snapshot of your WISHIs and contributions." }}
                </p>
            </div>

            <div v-if="isAdmin" class="flex items-center gap-2">
                <RouterLink to="/wishis/create" class="btn-primary">
                    <PlusIcon class="w-4 h-4" aria-hidden="true" />
                    Create new WISHI
                </RouterLink>
            </div>
            <div v-else-if="dash.data" class="surface px-4 py-3 inline-flex items-center gap-4">
                <div>
                    <div class="text-[10px] uppercase tracking-widest text-slate-500">Trust score</div>
                    <div class="flex items-baseline gap-1 mt-0.5">
                        <span class="display text-3xl leading-none text-green-700">{{ dash.data.credit_score ?? '—' }}</span>
                        <span class="text-[11px] text-slate-500">/100</span>
                    </div>
                </div>
                <span v-if="dash.data?.trust_level" class="badge-success capitalize">{{ dash.data.trust_level }}</span>
            </div>
        </div>

        <!-- ============================ ADMIN VIEW ============================ -->
        <template v-if="isAdmin">
            <!-- Upcoming WISHI openings (admins are creators — they care about these) -->
            <div v-if="upcomingOpenings.length" class="space-y-3">
                <RouterLink v-for="w in upcomingOpenings" :key="w.uuid"
                    :to="`/wishis/${w.uuid}`"
                    class="surface-padded block transition hover:shadow-md"
                    :class="openingClass(daysUntil(w.start_date))">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-start gap-3 min-w-0 flex-1">
                            <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" :class="openingIconTone(daysUntil(w.start_date))">
                                <component :is="openingIcon(daysUntil(w.start_date))" class="w-6 h-6" aria-hidden="true" />
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
                                    <span class="badge-gray">{{ (w.active_members ?? 0) + 1 }}/{{ w.total_members }} members</span>
                                    <span v-if="w.is_full" class="badge-success">Full — ready to start</span>
                                    <span v-else class="badge-warning">{{ (w.member_capacity ?? (w.total_members - 1)) - w.active_members }} seat{{ (w.member_capacity ?? (w.total_members - 1)) - w.active_members !== 1 ? 's' : '' }} left</span>
                                    <span class="text-gray-500">· {{ formatINR(w.monthly_contribution) }}/month · {{ w.duration_months }} cycles</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </RouterLink>
            </div>

            <div v-if="adminStore.dashboardLoading && !adminStore.dashboard" class="text-center py-16 text-gray-400">Loading analytics…</div>
            <template v-else-if="adminStore.dashboard">
                <!-- Pending join requests — member-initiated, awaiting admin approval.
                     Kept at the top of the admin section so queued work is unmissable. -->
                <div v-if="pendingJoinRequests.length" class="rounded-xl border border-amber-200 bg-amber-50/50 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 flex items-center justify-between bg-amber-100/60 border-b border-amber-200">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-amber-500 rounded-full animate-pulse"></span>
                            <h3 class="font-semibold text-sm text-amber-900">Pending join requests</h3>
                            <span class="badge-warning">{{ pendingJoinRequests.length }}</span>
                        </div>
                        <span class="text-[11px] text-amber-700">Approve or reject to continue</span>
                    </div>
                    <ul class="divide-y divide-amber-100">
                        <li v-for="r in pendingJoinRequests" :key="r.member_id" class="px-5 py-3 flex items-center gap-3 flex-wrap">
                            <div class="w-9 h-9 rounded-full overflow-hidden bg-linear-to-br from-slate-500 to-slate-700 text-white text-xs font-bold flex items-center justify-center shrink-0">
                                <img v-if="r.user.avatar_url" :src="r.user.avatar_url" :alt="r.user.name" class="w-full h-full object-cover" />
                                <span v-else>{{ (r.user.name || '').split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase() }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="font-medium text-sm truncate">
                                    {{ r.user.name }}
                                    <span class="text-gray-400 font-normal">wants to join</span>
                                    <RouterLink :to="`/wishis/${r.wishi.uuid}`" class="text-indigo-700 hover:underline">{{ r.wishi.name }}</RouterLink>
                                </div>
                                <div class="text-xs text-gray-500 truncate">
                                    {{ r.user.email }} · Credit {{ r.user.credit_score }}
                                    <span :class="trustColor[r.user.trust_level]" class="capitalize ml-1">{{ r.user.trust_level }}</span>
                                    · {{ formatINR(r.wishi.monthly_contribution) }}/{{ r.wishi.cycle_type === 'weekly' ? 'wk' : 'mo' }} · {{ r.wishi.duration_months }} cycles
                                </div>
                                <div class="text-[11px] text-gray-400">Requested {{ formatDateTime(r.requested_at) }}</div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <button @click="approveRequest(r)" :disabled="pendingActionId === r.member_id" class="btn-success btn-sm">
                                    {{ pendingActionId === r.member_id ? '…' : 'Approve' }}
                                </button>
                                <button @click="rejectRequest(r)" :disabled="pendingActionId === r.member_id" class="btn-danger btn-sm">Reject</button>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Overview tiles -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <div class="stat-tile"><div class="l">Users</div><div class="v mt-2">{{ adminStore.dashboard.overview.total_users }}</div><div class="text-[11px] text-green-700 mt-1">{{ adminStore.dashboard.overview.active_users }} active</div></div>
                    <div class="stat-tile"><div class="l">Admins</div><div class="v mt-2" style="color:#A8472A;">{{ adminStore.dashboard.overview.total_admins }}</div><div class="text-[11px] text-amber-700 mt-1">{{ adminStore.dashboard.overview.locked_users }} locked</div></div>
                    <div class="stat-tile"><div class="l">WISHIs</div><div class="v mt-2">{{ adminStore.dashboard.overview.active_wishis }} / {{ adminStore.dashboard.overview.total_wishis }}</div><div class="text-[11px] text-slate-500 mt-1">active / total</div></div>
                    <div class="stat-tile"><div class="l">Pool value</div><div class="v mt-2" style="font-size: 24px;">{{ formatINR(adminStore.dashboard.overview.total_pool_value) }}</div><div class="text-[11px] text-slate-500 mt-1">active pools</div></div>
                    <div class="stat-tile"><div class="l">Payouts</div><div class="v mt-2" style="font-size: 24px; color:#285F4D;">{{ formatINR(adminStore.dashboard.overview.total_payouts) }}</div><div class="text-[11px] text-slate-500 mt-1">cumulative</div></div>
                    <div class="stat-tile"><div class="l">Open tenders</div><div class="v mt-2" style="color:#B07A2A;">{{ adminStore.dashboard.overview.open_tenders }}</div><div class="text-[11px] text-rose-600 mt-1">{{ adminStore.dashboard.overview.missed_contributions }} missed</div></div>
                </div>

                <!-- Charts row 1 -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                    <div class="surface-padded">
                        <h3 class="font-semibold text-sm mb-3">Users by role</h3>
                        <div ref="pieRoleEl" class="w-full h-72"></div>
                    </div>
                    <div class="surface-padded">
                        <h3 class="font-semibold text-sm mb-3">WISHIs by cycle type</h3>
                        <div ref="pieTypeEl" class="w-full h-72"></div>
                    </div>
                    <div class="surface-padded">
                        <h3 class="font-semibold text-sm mb-3">Users by trust level</h3>
                        <div ref="barTrustEl" class="w-full h-72"></div>
                    </div>
                </div>

                <!-- Charts row 2 -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <div class="surface-padded">
                        <h3 class="font-semibold text-sm mb-3">Sign-ups · last 30 days</h3>
                        <div ref="lineSignupsEl" class="w-full h-72"></div>
                    </div>
                    <div class="surface-padded">
                        <h3 class="font-semibold text-sm mb-3">Contributions collected · last 30 days</h3>
                        <div ref="areaMoneyEl" class="w-full h-72"></div>
                    </div>
                </div>

                <!-- Lists row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <div class="surface-padded">
                        <h3 class="font-semibold text-sm mb-3">Top contributors</h3>
                        <div v-if="!adminStore.dashboard.top_contributors?.length" class="text-center py-8 text-gray-400 text-sm">No contributions yet.</div>
                        <ul v-else class="divide-y divide-gray-100">
                            <li v-for="(c, i) in adminStore.dashboard.top_contributors" :key="c.user_id" class="flex items-center justify-between py-2.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold flex items-center justify-center">{{ i + 1 }}</div>
                                    <div class="font-medium">{{ c.name }}</div>
                                </div>
                                <div class="font-bold text-emerald-600">{{ formatINR(c.total) }}</div>
                            </li>
                        </ul>
                    </div>

                    <div class="surface-padded">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-sm">Recent activity</h3>
                            <RouterLink to="/admin/users" class="text-xs text-indigo-600 hover:underline">Members →</RouterLink>
                        </div>
                        <div v-if="!adminStore.dashboard.recent_audit?.length" class="text-center py-8 text-gray-400 text-sm">No audit entries.</div>
                        <ul v-else class="space-y-2.5">
                            <li v-for="l in adminStore.dashboard.recent_audit" :key="l.id" class="flex gap-3 text-sm">
                                <div class="w-1.5 rounded-full bg-indigo-500 shrink-0"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium capitalize">{{ l.action.replace(/_/g, ' ') }}<span v-if="l.wishi_name" class="text-gray-500 font-normal"> · {{ l.wishi_name }}</span></div>
                                    <div class="text-xs text-gray-500 truncate">{{ l.description }}</div>
                                    <div class="text-[11px] text-gray-400">{{ l.user_name || 'system' }} · {{ formatDateTime(l.created_at) }}</div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </template>
        </template>

        <!-- ============================ MEMBER VIEW ============================ -->
        <template v-else>
            <!-- Late-payment warning (only when the member is actually behind) -->
            <div v-if="latePayments.length" class="surface-padded bg-rose-50 border-rose-200">
                <div class="flex items-start gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0">
                        <ExclamationTriangleIcon class="w-6 h-6" aria-hidden="true" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs uppercase tracking-widest font-medium text-rose-700">
                            {{ latePayments.length }} late payment{{ latePayments.length !== 1 ? 's' : '' }}
                        </div>
                        <p class="text-sm text-rose-800 mt-1">
                            Please clear these with the WISHI admin as soon as possible — late payments reduce your credit score.
                        </p>
                        <ul class="mt-2 divide-y divide-rose-200 text-sm">
                            <li v-for="p in latePayments" :key="p.id" class="py-2 flex items-center justify-between gap-2 flex-wrap">
                                <div class="min-w-0">
                                    <RouterLink v-if="p.wishi?.uuid" :to="`/wishis/${p.wishi.uuid}`" class="text-rose-900 font-medium hover:underline truncate block">
                                        {{ p.wishi.name }} · Cycle #{{ p.cycle_number }}
                                    </RouterLink>
                                    <span v-else class="text-rose-900 font-medium truncate">WISHI · Cycle #{{ p.cycle_number }}</span>
                                    <div class="text-[11px] text-rose-700 mt-0.5">Was due on {{ formatDate(p.due_date) }}</div>
                                </div>
                                <span class="text-rose-700 font-semibold shrink-0">{{ formatINR(p.amount) }} · {{ urgencyLabel(daysUntil(p.due_date)) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Next month total — terracotta paper-tex hero -->
            <div v-if="upcomingPayments.length" class="surface-padded paper-tex relative overflow-hidden"
                 style="background: linear-gradient(140deg, #FBEEE6 0%, #F5DDD0 100%); border-color: #EEBFA4;">
                <div class="absolute -top-10 -right-8 w-44 h-44 rounded-full bg-brand-300/20 pointer-events-none"></div>
                <div class="flex items-start justify-between gap-4 flex-wrap relative">
                    <div>
                        <div class="text-[10px] uppercase tracking-widest text-brand-700/80">Next month total</div>
                        <div class="display text-4xl sm:text-5xl text-slate-900 mt-1">{{ formatINR(nextMonthTotal) }}</div>
                        <p class="text-xs text-slate-700 mt-2">Across {{ upcomingPaymentWishiCount }} WISHI{{ upcomingPaymentWishiCount !== 1 ? 's' : '' }} you're a member of.</p>
                    </div>
                    <div class="text-right text-xs text-slate-700 italic max-w-xs">
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
                            <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0" :class="openingIconTone(daysUntil(w.start_date))">
                                <component :is="openingIcon(daysUntil(w.start_date))" class="w-6 h-6" aria-hidden="true" />
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
                                    <span class="badge-gray">{{ (w.active_members ?? 0) + 1 }}/{{ w.total_members }} members</span>
                                    <span v-if="w.is_full" class="badge-success">Full — ready to start</span>
                                    <span v-else class="badge-warning">{{ (w.member_capacity ?? (w.total_members - 1)) - w.active_members }} seat{{ (w.member_capacity ?? (w.total_members - 1)) - w.active_members !== 1 ? 's' : '' }} left</span>
                                    <span class="text-gray-500">· {{ formatINR(w.monthly_contribution) }}/month · {{ w.duration_months }} cycles</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </RouterLink>
            </div>

            <!-- =============== Hero joinable + Discover strip =============== -->
            <template v-if="heroJoinable">
                <!-- Hero terracotta paper-tex card for the top joinable WISHI -->
                <div class="surface paper-tex relative overflow-hidden p-7 sm:p-8"
                     style="background: linear-gradient(140deg, #FBEEE6 0%, #F5DDD0 100%); border-color: #EEBFA4;">
                    <div class="absolute -top-12 -right-10 w-48 h-48 rounded-full bg-brand-300/20 pointer-events-none"></div>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium" style="background: #fff; color: #843620;">
                        <EnvelopeIcon class="w-3.5 h-3.5" aria-hidden="true" />
                        {{ heroJoinable.require_approval ? 'Open to join' : 'Direct join' }}
                    </span>
                    <h2 class="display text-3xl sm:text-4xl text-slate-900 mt-3 leading-tight">
                        Join <span class="italic">{{ heroJoinable.name }}</span>
                    </h2>
                    <div class="text-sm text-slate-700 mt-1">
                        by <strong class="text-slate-900">{{ heroJoinable.creator_name }}</strong>
                        · {{ (heroJoinable.active_members ?? 0) + 1 }} of {{ heroJoinable.total_members }} seats taken
                        <span v-if="heroJoinable.start_date"> · starts {{ formatDate(heroJoinable.start_date) }}</span>
                    </div>

                    <div class="flex items-center gap-6 mt-5 relative">
                        <div>
                            <div class="text-[10px] uppercase tracking-widest text-slate-600">Monthly</div>
                            <div class="display text-2xl sm:text-3xl text-slate-900 leading-none mt-1">{{ formatINR(heroJoinable.monthly_contribution) }}</div>
                        </div>
                        <div class="w-px h-9 bg-brand-200"></div>
                        <div>
                            <div class="text-[10px] uppercase tracking-widest text-slate-600">Total pool</div>
                            <div class="display text-2xl sm:text-3xl text-slate-900 leading-none mt-1">{{ formatINR(heroJoinable.total_pool ?? (heroJoinable.monthly_contribution * heroJoinable.total_members)) }}</div>
                        </div>
                        <div class="w-px h-9 bg-brand-200"></div>
                        <div>
                            <div class="text-[10px] uppercase tracking-widest text-slate-600">Months</div>
                            <div class="display text-2xl sm:text-3xl text-slate-900 leading-none mt-1">{{ heroJoinable.duration_months }}</div>
                        </div>
                    </div>

                    <div class="flex gap-2 mt-6 relative">
                        <button @click="requestJoin(heroJoinable.uuid)" :disabled="joiningUuid === heroJoinable.uuid" class="btn-primary btn-lg">
                            <CheckCircleIcon v-if="joiningUuid !== heroJoinable.uuid" class="w-4 h-4" aria-hidden="true" />
                            {{ joiningUuid === heroJoinable.uuid ? 'Sending…' : (heroJoinable.require_approval ? 'Request to join' : 'Join now') }}
                        </button>
                        <RouterLink :to="`/wishis/${heroJoinable.uuid}`" class="btn-secondary btn-lg">View details</RouterLink>
                    </div>
                </div>

                <!-- Discover strip -->
                <div v-if="restJoinable.length">
                    <div class="flex items-end justify-between mb-3 gap-2 flex-wrap">
                        <div>
                            <h2 class="section-title">Discover</h2>
                            <p class="section-sub">{{ restJoinable.length }} more WISHI{{ restJoinable.length !== 1 ? 's' : '' }} accepting members</p>
                        </div>
                        <RouterLink to="/wishis" class="text-sm text-brand-600 hover:underline inline-flex items-center gap-1">View all <ArrowRightIcon class="w-3.5 h-3.5" /></RouterLink>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div v-for="w in restJoinable" :key="w.uuid" class="surface surface-hover p-4 flex items-center gap-3.5">
                            <div class="w-14 h-14 rounded-xl overflow-hidden shrink-0">
                                <Cover :name="w.name" :height="56" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <RouterLink :to="`/wishis/${w.uuid}`" class="block text-sm font-medium text-slate-900 hover:text-brand-700 truncate">{{ w.name }}</RouterLink>
                                <div class="text-xs text-slate-500 mt-0.5 truncate">
                                    by {{ w.creator_name }} · <span class="capitalize">{{ w.cycle_type }}</span>
                                    · {{ (w.active_members ?? 0) + 1 }}/{{ w.total_members }} filled
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="display text-lg text-slate-900 leading-none">{{ formatINR(w.monthly_contribution) }}</div>
                                <div class="text-[10px] uppercase tracking-widest text-slate-500 mt-1">per month</div>
                            </div>
                            <button @click="requestJoin(w.uuid)" :disabled="joiningUuid === w.uuid" class="btn-secondary btn-sm shrink-0">
                                {{ joiningUuid === w.uuid ? '…' : (w.require_approval ? 'Request' : 'Join') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Stat cards — warm serif numerals -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <div class="stat-tile">
                    <div class="l">Active WISHIs</div>
                    <div class="v mt-2">{{ dash.data?.active_wishis_count ?? '—' }}</div>
                    <div class="text-xs text-slate-500 mt-2">{{ dash.data?.created_wishis_count ?? 0 }} created by you</div>
                </div>
                <div class="stat-tile">
                    <div class="l">Contributed</div>
                    <div class="v mt-2">{{ dash.data ? formatINR(dash.data.total_contributed) : '—' }}</div>
                    <div class="text-xs text-slate-500 mt-2">across all cycles</div>
                </div>
                <div class="stat-tile">
                    <div class="l">Won so far</div>
                    <div class="v mt-2" style="color: #285F4D;">{{ dash.data ? formatINR(dash.data.total_won) : '—' }}</div>
                    <div class="text-xs text-slate-500 mt-2">payouts received</div>
                </div>
                <div class="stat-tile">
                    <div class="l">Credit score</div>
                    <div class="flex items-baseline gap-2 mt-2">
                        <div class="v">{{ dash.data?.credit_score ?? '—' }}</div>
                        <span v-if="dash.data?.trust_level" class="capitalize text-xs" :class="trustColor[dash.data.trust_level]">{{ dash.data.trust_level }}</span>
                    </div>
                    <div class="text-xs text-slate-500 mt-2">out of 100</div>
                </div>
            </div>

            <!-- Upcoming + Active -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="surface-padded lg:col-span-2">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="section-title">Upcoming payments</h2>
                        <span class="badge-brand">{{ dash.data?.upcoming_payments?.length ?? 0 }} due</span>
                    </div>
                    <div v-if="!dash.data?.upcoming_payments?.length" class="text-center py-10 text-sm">
                        <CheckCircleIcon class="w-10 h-10 mx-auto text-green-600 mb-2" aria-hidden="true" />
                        <div class="font-medium text-slate-700">You're all caught up</div>
                        <div class="text-xs text-slate-500">No upcoming payments right now.</div>
                    </div>
                    <div v-else class="divide-y divide-slate-100">
                        <div v-for="p in dash.data.upcoming_payments" :key="p.id" class="py-3 flex items-center justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <RouterLink v-if="p.wishi?.uuid" :to="`/wishis/${p.wishi.uuid}`" class="font-medium text-slate-900 hover:text-brand-600 hover:underline truncate block">
                                    {{ p.wishi.name }}
                                </RouterLink>
                                <div v-else class="font-medium text-slate-900 truncate">WISHI</div>
                                <div class="text-xs text-slate-500 flex items-center gap-1.5 flex-wrap mt-0.5">
                                    <span>Cycle #{{ p.cycle_number }}</span>
                                    <span>·</span>
                                    <span>Due {{ formatDate(p.due_date) }}</span>
                                    <span>·</span>
                                    <span :class="urgencyClass(daysUntil(p.due_date))" class="font-medium">{{ urgencyLabel(daysUntil(p.due_date)) }}</span>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="display text-xl text-slate-900">{{ formatINR(p.amount) }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="surface-padded">
                    <h2 class="section-title mb-4">Cycle activity</h2>
                    <div class="space-y-3">
                        <div class="p-4 rounded-xl bg-slate-50 border border-slate-100">
                            <div class="text-xs uppercase tracking-widest text-slate-500">Active cycles</div>
                            <div class="display text-3xl text-slate-900 mt-1">{{ dash.data?.active_cycles_count ?? '—' }}</div>
                        </div>
                        <RouterLink to="/wishis" class="btn-secondary btn-block">View all WISHIs →</RouterLink>
                        <RouterLink to="/profile" class="btn-ghost btn-block">Credit history →</RouterLink>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
