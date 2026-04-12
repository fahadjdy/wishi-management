<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useMemberStore } from '@/stores/member';
import { useWishiStore } from '@/stores/wishi';
import { useToast } from 'vue-toastification';
import { formatDate, trustColor, memberStatusLabels } from '@/utils/format';

const route = useRoute();
const store = useMemberStore();
const wishiStore = useWishiStore();
const toast = useToast();

const filter = ref('all');
const expanded = ref(new Set());
const isAdmin = computed(() => wishiStore.currentWishi?.is_admin);
const filtered = computed(() => {
    if (filter.value === 'all') return store.members;
    return store.members.filter((m) => m.status === filter.value);
});

function toggle(id) {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
}

async function approve(id) {
    try {
        await store.approve(route.params.uuid, id);
        toast.success('Member approved.');
        await store.fetch(route.params.uuid);
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}
async function reject(id) {
    if (!confirm('Reject this join request?')) return;
    try {
        await store.reject(route.params.uuid, id);
        toast.info('Request rejected.');
        await store.fetch(route.params.uuid);
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}
async function remove(id) {
    if (!confirm('Remove this member?')) return;
    try {
        await store.remove(route.params.uuid, id);
        toast.success('Member removed.');
        await store.fetch(route.params.uuid);
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}

const statusBadge = {
    pending: 'badge-warning',
    approved: 'badge-success',
    active: 'badge-success',
    removed: 'badge-danger',
    left: 'badge-gray',
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-1 bg-gray-100 p-1 rounded-lg overflow-x-auto">
                <button v-for="f in ['all', 'pending', 'active', 'removed']" :key="f" @click="filter = f"
                    :class="filter === f ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600 hover:text-gray-900'"
                    class="px-3 py-1.5 rounded-md text-xs font-medium capitalize whitespace-nowrap">{{ f }}</button>
            </div>
            <div class="text-xs text-gray-500">{{ filtered.length }} member{{ filtered.length !== 1 ? 's' : '' }}</div>
        </div>

        <!-- Mobile cards -->
        <div class="md:hidden space-y-2">
            <div v-for="m in filtered" :key="m.id" class="surface-padded">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white text-xs font-bold flex items-center justify-center shrink-0">
                        {{ m.user?.name?.split(' ').map(p => p[0]).slice(0,2).join('') }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="font-medium">{{ m.user?.name }}</span>
                            <span :class="statusBadge[m.status]" class="capitalize">{{ memberStatusLabels[m.status] }}</span>
                            <span v-if="m.is_admin" class="badge-brand">Admin</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-1.5 flex-wrap">
                            <span>Credit {{ m.user?.credit_score }}</span>
                            <span :class="trustColor[m.user?.trust_level]" class="capitalize">{{ m.user?.trust_level }}</span>
                            <span v-if="m.has_won" class="badge-success">🏆 Cycle #{{ m.won_in_cycle }}</span>
                        </div>
                        <div v-if="isAdmin" class="flex gap-1.5 mt-2 flex-wrap">
                            <button v-if="m.status === 'pending'" @click="approve(m.id)" class="btn-success btn-sm">Approve</button>
                            <button v-if="m.status === 'pending'" @click="reject(m.id)" class="btn-secondary btn-sm">Reject</button>
                            <button v-if="['approved','active'].includes(m.status) && !m.is_admin" @click="remove(m.id)" class="btn-danger btn-sm">Remove</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop table -->
        <div class="hidden md:block surface overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">Member</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-left px-4 py-3">Credit</th>
                        <th class="text-left px-4 py-3">Won this WISHI</th>
                        <th class="text-left px-4 py-3">Joined</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template v-for="m in filtered" :key="m.id">
                        <tr class="hover:bg-gray-50 cursor-pointer" @click="toggle(m.id)">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white text-xs font-bold flex items-center justify-center shrink-0">
                                        {{ m.user?.name?.split(' ').map(p => p[0]).slice(0,2).join('') }}
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="font-medium">{{ m.user?.name }}</span>
                                            <span v-if="m.user?.active_wishis_count > 1" class="badge-info">{{ m.user.active_wishis_count }} WISHIs</span>
                                            <span v-if="m.user?.wishi_history?.filter(h => h.has_won).length" class="badge-success">{{ m.user.wishi_history.filter(h => h.has_won).length }}× opened</span>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <span v-if="m.is_admin" class="text-indigo-600 mr-2">Admin</span>
                                            <button class="text-indigo-600 hover:underline" @click.stop="toggle(m.id)">
                                                {{ expanded.has(m.id) ? 'Hide' : 'Show' }} history
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3"><span :class="statusBadge[m.status]" class="capitalize">{{ memberStatusLabels[m.status] }}</span></td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ m.user?.credit_score }}</span>
                                    <span :class="trustColor[m.user?.trust_level]" class="capitalize">{{ m.user?.trust_level }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3">{{ m.has_won ? `Cycle #${m.won_in_cycle}` : '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ formatDate(m.joined_at) }}</td>
                            <td class="px-4 py-3 text-right" @click.stop>
                                <div v-if="isAdmin" class="flex gap-2 justify-end flex-wrap">
                                    <button v-if="m.status === 'pending'" @click="approve(m.id)" class="btn-success btn-sm">Approve</button>
                                    <button v-if="m.status === 'pending'" @click="reject(m.id)" class="btn-secondary btn-sm">Reject</button>
                                    <button v-if="['approved','active'].includes(m.status) && !m.is_admin" @click="remove(m.id)" class="btn-danger btn-sm">Remove</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="expanded.has(m.id) && m.user?.wishi_history?.length" class="bg-indigo-50/30">
                            <td colspan="6" class="px-4 py-3">
                                <div class="text-xs font-semibold text-gray-700 mb-2">Active membership across the platform</div>
                                <div class="space-y-1.5">
                                    <div v-for="h in m.user.wishi_history" :key="h.wishi_id" class="flex items-center gap-2 text-sm flex-wrap">
                                        <span class="font-medium">{{ h.wishi_name }}</span>
                                        <span v-if="h.has_won" class="badge-success">🏆 Opened cycle #{{ h.won_in_cycle }}<span v-if="h.won_date"> on {{ formatDate(h.won_date) }}</span></span>
                                        <span v-else class="badge-gray">Yet to win</span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div v-if="!filtered.length" class="py-10 text-center text-sm text-gray-400">No members in this view.</div>
        </div>
    </div>
</template>
