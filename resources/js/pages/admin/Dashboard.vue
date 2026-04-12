<script setup>
import { onMounted, onBeforeUnmount, ref, watch, nextTick } from 'vue';
import { RouterLink } from 'vue-router';
import { useAdminStore } from '@/stores/admin';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { formatINR, formatDateTime } from '@/utils/format';
import * as am5 from '@amcharts/amcharts5';
import * as am5percent from '@amcharts/amcharts5/percent';
import * as am5xy from '@amcharts/amcharts5/xy';
import am5themes_Animated from '@amcharts/amcharts5/themes/Animated';

const store = useAdminStore();

useBreadcrumbs(() => [{ label: 'Admin', to: '/admin' }, { label: 'Analytics' }]);

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

function buildLine(el, data, categoryField, valueField, color, label = 'count') {
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
        tooltip: am5.Tooltip.new(root, { labelText: '{valueX.formatDate(\"MMM dd\")}: {valueY}' }),
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
    const d = store.dashboard;
    if (!d) return;
    buildPie(pieRoleEl.value, d.users_by_role, 'role', 'count', [0x6366f1, 0x94a3b8]);
    buildPie(pieTypeEl.value, d.wishis_by_type, 'cycle_type', 'count', [0x10b981, 0xf59e0b, 0x8b5cf6]);
    buildBar(barTrustEl.value, d.users_by_trust, 'trust_level', 'count', 0x4f46e5);
    buildLine(lineSignupsEl.value, d.signups_last_30_days, 'date', 'count', 0x6366f1);
    buildLine(areaMoneyEl.value, d.contributions_last_30_days, 'date', 'amount', 0x10b981, 'amount');
}

onMounted(async () => {
    await store.fetchDashboard();
    await renderCharts();
});

watch(() => store.dashboard, () => renderCharts());

onBeforeUnmount(dispose);
</script>

<template>
    <div v-if="store.dashboardLoading && !store.dashboard" class="text-center py-16 text-gray-400">Loading dashboard…</div>
    <div v-else-if="store.dashboard" class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold">Admin Dashboard</h1>
            <p class="text-sm text-gray-500">Platform-wide overview and analytics.</p>
        </div>

        <!-- Overview tiles -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <div class="surface-padded"><div class="text-[11px] uppercase tracking-wide text-gray-500">Users</div><div class="text-2xl font-bold mt-1">{{ store.dashboard.overview.total_users }}</div><div class="text-[11px] text-emerald-600">{{ store.dashboard.overview.active_users }} active</div></div>
            <div class="surface-padded"><div class="text-[11px] uppercase tracking-wide text-gray-500">Admins</div><div class="text-2xl font-bold text-indigo-600 mt-1">{{ store.dashboard.overview.total_admins }}</div><div class="text-[11px] text-amber-600">{{ store.dashboard.overview.locked_users }} locked</div></div>
            <div class="surface-padded"><div class="text-[11px] uppercase tracking-wide text-gray-500">WISHIs</div><div class="text-2xl font-bold mt-1">{{ store.dashboard.overview.active_wishis }} / {{ store.dashboard.overview.total_wishis }}</div><div class="text-[11px] text-gray-500">active / total</div></div>
            <div class="surface-padded"><div class="text-[11px] uppercase tracking-wide text-gray-500">Pool value</div><div class="text-lg font-bold mt-1">{{ formatINR(store.dashboard.overview.total_pool_value) }}</div><div class="text-[11px] text-gray-500">active pools</div></div>
            <div class="surface-padded"><div class="text-[11px] uppercase tracking-wide text-gray-500">Payouts</div><div class="text-lg font-bold mt-1 text-emerald-600">{{ formatINR(store.dashboard.overview.total_payouts) }}</div><div class="text-[11px] text-gray-500">cumulative</div></div>
            <div class="surface-padded"><div class="text-[11px] uppercase tracking-wide text-gray-500">Open tenders</div><div class="text-2xl font-bold text-amber-600 mt-1">{{ store.dashboard.overview.open_tenders }}</div><div class="text-[11px] text-red-600">{{ store.dashboard.overview.missed_contributions }} missed</div></div>
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
                <div v-if="!store.dashboard.top_contributors?.length" class="text-center py-8 text-gray-400 text-sm">No contributions yet.</div>
                <ul v-else class="divide-y divide-gray-100">
                    <li v-for="(c, i) in store.dashboard.top_contributors" :key="c.user_id" class="flex items-center justify-between py-2.5">
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
                <div v-if="!store.dashboard.recent_audit?.length" class="text-center py-8 text-gray-400 text-sm">No audit entries.</div>
                <ul v-else class="space-y-2.5">
                    <li v-for="l in store.dashboard.recent_audit" :key="l.id" class="flex gap-3 text-sm">
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
    </div>
</template>
