<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useAuditStore } from '@/stores/audit';
import { formatDateTime } from '@/utils/format';

const route = useRoute();
const store = useAuditStore();
const page = ref(1);
const expanded = ref(new Set());

const actionColor = {
    wishi_created: 'bg-indigo-500',
    wishi_activated: 'bg-emerald-500',
    wishi_completed: 'bg-emerald-600',
    cycle_opened: 'bg-sky-500',
    winner_selected: 'bg-purple-500',
    surplus_handled: 'bg-amber-500',
    payout_recorded: 'bg-emerald-600',
    deferred_payout_released: 'bg-emerald-500',
    contribution_recorded: 'bg-emerald-400',
    bid_placed: 'bg-cyan-500',
    member_approved: 'bg-indigo-400',
    member_removed: 'bg-red-500',
    member_rejected: 'bg-red-400',
    member_join_requested: 'bg-blue-400',
    settings_updated: 'bg-gray-500',
};

function toggle(id) {
    const set = new Set(expanded.value);
    set.has(id) ? set.delete(id) : set.add(id);
    expanded.value = set;
}

onMounted(() => store.fetch(route.params.uuid));

async function changePage(n) {
    page.value = n;
    await store.fetch(route.params.uuid, n);
}
</script>

<template>
    <div>
        <div v-if="!store.logs.length" class="surface-padded text-center py-12 text-gray-400">No audit entries yet.</div>

        <div v-else class="surface overflow-hidden">
            <ul class="divide-y divide-gray-100">
                <li v-for="log in store.logs" :key="log.id" class="p-4 sm:p-5">
                    <div class="flex gap-3 sm:gap-4">
                        <div class="flex flex-col items-center shrink-0">
                            <div class="w-3 h-3 rounded-full" :class="actionColor[log.action] || 'bg-gray-400'"></div>
                            <div class="flex-1 w-px bg-gray-200 mt-1"></div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-baseline gap-2">
                                <span class="font-semibold capitalize">{{ log.action.replace(/_/g, ' ') }}</span>
                                <span class="text-xs text-gray-500">by {{ log.user?.name || 'system' }}</span>
                                <span class="text-xs text-gray-400 ml-auto">{{ formatDateTime(log.created_at) }}</span>
                            </div>
                            <p v-if="log.description" class="text-sm text-gray-700 mt-1 break-words">{{ log.description }}</p>
                            <button v-if="log.metadata" @click="toggle(log.id)" class="text-xs text-indigo-600 hover:underline mt-1">
                                {{ expanded.has(log.id) ? 'Hide' : 'Show' }} metadata
                            </button>
                            <pre v-if="expanded.has(log.id)" class="mt-2 p-3 bg-gray-50 border border-gray-200 rounded-lg text-xs overflow-x-auto">{{ JSON.stringify(log.metadata, null, 2) }}</pre>
                            <div v-if="log.ip_address" class="text-xs text-gray-400 mt-1">IP: {{ log.ip_address }}</div>
                        </div>
                    </div>
                </li>
            </ul>

            <div v-if="store.meta && store.meta.last_page > 1" class="border-t border-gray-100 px-4 py-3 flex items-center justify-between text-sm">
                <div class="text-gray-500">Page {{ store.meta.current_page }} of {{ store.meta.last_page }}</div>
                <div class="flex gap-2">
                    <button :disabled="page <= 1" @click="changePage(page - 1)" class="btn-secondary btn-sm">Previous</button>
                    <button :disabled="page >= store.meta.last_page" @click="changePage(page + 1)" class="btn-secondary btn-sm">Next</button>
                </div>
            </div>
        </div>
    </div>
</template>
