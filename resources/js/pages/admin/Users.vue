<script setup>
import { onMounted, ref, reactive, computed, watch } from 'vue';
import { useAdminStore } from '@/stores/admin';
import { useAuthStore } from '@/stores/auth';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useToast } from 'vue-toastification';
import { formatDateTime, trustColor } from '@/utils/format';

const store = useAdminStore();
const auth = useAuthStore();
const toast = useToast();

useBreadcrumbs(() => [{ label: 'Admin', to: '/admin' }, { label: 'Members' }]);

const filters = reactive({ q: '', status: '', sort: 'created_at', direction: 'desc' });
const page = ref(1);

const showLockModal = ref(false);
const showCreditModal = ref(false);
const target = ref(null);
const lockForm = reactive({ minutes: 60, reason: '' });
const creditForm = reactive({ points: 0, reason: '' });

// Fetch everything, split client-side (one API trip for 2 tables)
async function load() {
    await store.fetchUsers({ ...filters, page: page.value, per_page: 50 });
}

const admins = computed(() => (store.users || []).filter((u) => u.is_admin));
const members = computed(() => (store.users || []).filter((u) => !u.is_admin));

onMounted(load);
watch(filters, () => { page.value = 1; load(); }, { deep: true });

async function changePage(n) { page.value = n; await load(); window.scrollTo({ top: 0, behavior: 'smooth' }); }

async function toggleAdmin(u) {
    if (!confirm(`${u.is_admin ? 'Revoke' : 'Grant'} platform admin for ${u.name}?`)) return;
    try {
        await store.toggleAdmin(u.id);
        toast.success('Updated.');
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}

function openLock(u) { target.value = u; lockForm.minutes = 60; lockForm.reason = ''; showLockModal.value = true; }
async function lock() {
    try {
        await store.lock(target.value.id, lockForm.minutes, lockForm.reason || null);
        toast.success('User locked.');
        showLockModal.value = false;
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}

async function unlock(u) {
    try {
        await store.unlock(u.id);
        toast.success('User unlocked.');
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}

async function remove(u) {
    if (!confirm(`Soft-delete ${u.name}? They can be restored later.`)) return;
    try {
        await store.remove(u.id);
        toast.success('User deleted.');
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}

async function restore(u) {
    try {
        await store.restore(u.id);
        toast.success('User restored.');
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}

function openCredit(u) { target.value = u; creditForm.points = 0; creditForm.reason = ''; showCreditModal.value = true; }
async function adjustCredit() {
    try {
        await store.adjustCredit(target.value.id, creditForm.points, creditForm.reason);
        toast.success('Credit adjusted.');
        showCreditModal.value = false;
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <h1 class="text-2xl font-bold">Member Management</h1>
            <p class="text-sm text-gray-500">Platform admins and members shown separately. Lock, restore, promote or adjust credit.</p>
        </div>

        <!-- Summary cards -->
        <div v-if="store.summary" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="surface-padded">
                <div class="text-xs text-gray-500">Total users</div>
                <div class="text-2xl font-bold">{{ store.summary.total_users }}</div>
            </div>
            <div class="surface-padded">
                <div class="text-xs text-gray-500">Platform admins</div>
                <div class="text-2xl font-bold text-indigo-600">{{ store.summary.admins }}</div>
            </div>
            <div class="surface-padded">
                <div class="text-xs text-gray-500">Locked</div>
                <div class="text-2xl font-bold text-amber-600">{{ store.summary.locked }}</div>
            </div>
            <div class="surface-padded">
                <div class="text-xs text-gray-500">Deleted</div>
                <div class="text-2xl font-bold text-red-600">{{ store.summary.deleted }}</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="surface-padded">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input v-model="filters.q" type="search" placeholder="Search name, email, phone…" class="form-input" />
                <select v-model="filters.status" class="form-input">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="locked">Locked</option>
                    <option value="deleted">Deleted</option>
                </select>
                <select v-model="filters.sort" class="form-input">
                    <option value="created_at">Sort: newest</option>
                    <option value="last_login_at">Sort: last login</option>
                    <option value="credit_score">Sort: credit score</option>
                    <option value="name">Sort: name</option>
                </select>
            </div>
        </div>

        <!-- Admins Table -->
        <section>
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-lg font-semibold flex items-center gap-2">
                    <span class="w-2 h-2 bg-indigo-600 rounded-full"></span> Platform Admins
                    <span class="badge-brand">{{ admins.length }}</span>
                </h2>
            </div>
            <div class="surface overflow-x-auto">
                <table class="w-full text-sm min-w-[800px]">
                    <thead class="bg-indigo-50 border-b border-indigo-100 text-xs text-indigo-800 uppercase">
                        <tr>
                            <th class="text-left px-4 py-3">Admin</th>
                            <th class="text-left px-4 py-3">Credit</th>
                            <th class="text-left px-4 py-3">WISHIs</th>
                            <th class="text-left px-4 py-3">Last login</th>
                            <th class="text-left px-4 py-3">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="u in admins" :key="u.id" class="hover:bg-gray-50" :class="u.deleted_at ? 'opacity-60' : ''">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white text-xs font-bold flex items-center justify-center shrink-0">
                                        {{ u.name.split(' ').map(p=>p[0]).slice(0,2).join('') }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-medium truncate flex items-center gap-1.5">
                                            {{ u.name }}
                                            <span class="badge-brand">Admin</span>
                                        </div>
                                        <div class="text-xs text-gray-500 truncate">{{ u.email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ u.credit_score }}</span>
                                <span :class="trustColor[u.trust_level]" class="capitalize ml-1.5">{{ u.trust_level }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div>Created: <strong>{{ u.created_wishis_count }}</strong></div>
                                <div>Active: <strong>{{ u.active_memberships_count }}</strong></div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">
                                <div v-if="u.last_login_at">{{ formatDateTime(u.last_login_at) }}</div>
                                <div v-else class="text-gray-400">never</div>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="u.deleted_at" class="badge-danger">Deleted</span>
                                <span v-else-if="u.is_locked" class="badge-warning">Locked</span>
                                <span v-else class="badge-success">Active</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex gap-1.5 justify-end flex-wrap">
                                    <span v-if="u.id === auth.user?.id" class="text-xs text-gray-400 italic">that's you</span>
                                    <template v-else-if="u.deleted_at">
                                        <button @click="restore(u)" class="btn-secondary btn-sm">Restore</button>
                                    </template>
                                    <template v-else>
                                        <button v-if="u.is_locked" @click="unlock(u)" class="btn-success btn-sm">Unlock</button>
                                        <button v-else @click="openLock(u)" class="btn-secondary btn-sm">Lock</button>
                                        <button @click="toggleAdmin(u)" class="btn-secondary btn-sm">Revoke admin</button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!admins.length"><td colspan="6" class="py-8 text-center text-sm text-gray-400">No admins in view.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Members Table -->
        <section>
            <h2 class="text-lg font-semibold flex items-center gap-2 mb-2">
                <span class="w-2 h-2 bg-gray-400 rounded-full"></span> Members
                <span class="badge-gray">{{ members.length }}</span>
            </h2>
            <div class="surface overflow-x-auto">
                <table class="w-full text-sm min-w-[900px]">
                    <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="text-left px-4 py-3">Member</th>
                            <th class="text-left px-4 py-3">Credit</th>
                            <th class="text-left px-4 py-3">WISHIs</th>
                            <th class="text-left px-4 py-3">Last login</th>
                            <th class="text-left px-4 py-3">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="u in members" :key="u.id" class="hover:bg-gray-50" :class="u.deleted_at ? 'opacity-60' : ''">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-slate-500 to-slate-700 text-white text-xs font-bold flex items-center justify-center shrink-0">
                                        {{ u.name.split(' ').map(p=>p[0]).slice(0,2).join('') }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-medium truncate">{{ u.name }}</div>
                                        <div class="text-xs text-gray-500 truncate">{{ u.email }}</div>
                                        <div v-if="u.phone" class="text-xs text-gray-400">{{ u.phone }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ u.credit_score }}</span>
                                    <span :class="trustColor[u.trust_level]" class="capitalize">{{ u.trust_level }}</span>
                                </div>
                                <div v-if="u.missed_payments_count > 0" class="text-[11px] text-red-600 mt-0.5">{{ u.missed_payments_count }} missed</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div>Active: <strong>{{ u.active_memberships_count }}</strong></div>
                                <div v-if="u.won_count > 0" class="text-emerald-600">Won: {{ u.won_count }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">
                                <div v-if="u.last_login_at">{{ formatDateTime(u.last_login_at) }}</div>
                                <div v-else class="text-gray-400">never</div>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="u.deleted_at" class="badge-danger">Deleted</span>
                                <span v-else-if="u.is_locked" class="badge-warning">Locked</span>
                                <span v-else class="badge-success">Active</span>
                                <div v-if="u.failed_login_attempts > 0" class="text-[11px] text-amber-600 mt-0.5">{{ u.failed_login_attempts }} failed</div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex gap-1.5 justify-end flex-wrap">
                                    <template v-if="u.deleted_at">
                                        <button @click="restore(u)" class="btn-secondary btn-sm">Restore</button>
                                    </template>
                                    <template v-else>
                                        <button @click="openCredit(u)" class="btn-ghost btn-sm" title="Adjust credit">±</button>
                                        <button v-if="u.is_locked" @click="unlock(u)" class="btn-success btn-sm">Unlock</button>
                                        <button v-else @click="openLock(u)" class="btn-secondary btn-sm">Lock</button>
                                        <button @click="toggleAdmin(u)" class="btn-secondary btn-sm">Make admin</button>
                                        <button @click="remove(u)" class="btn-danger btn-sm">Delete</button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!members.length"><td colspan="6" class="py-8 text-center text-sm text-gray-400">No members in view.</td></tr>
                    </tbody>
                </table>
                <div v-if="store.meta && store.meta.last_page > 1" class="border-t border-gray-100 px-4 py-3 flex items-center justify-between text-sm">
                    <div class="text-gray-500">Page {{ store.meta.current_page }} of {{ store.meta.last_page }} ({{ store.meta.total }} users)</div>
                    <div class="flex gap-2">
                        <button :disabled="page <= 1" @click="changePage(page - 1)" class="btn-secondary btn-sm">← Prev</button>
                        <button :disabled="page >= store.meta.last_page" @click="changePage(page + 1)" class="btn-secondary btn-sm">Next →</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Lock modal -->
        <div v-if="showLockModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showLockModal = false">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md">
                <h3 class="font-semibold text-lg mb-1">Lock {{ target?.name }}</h3>
                <p class="text-xs text-gray-500 mb-4">User can't log in for the chosen duration.</p>
                <div class="space-y-3">
                    <div>
                        <label class="form-label">Lock duration (minutes)</label>
                        <input v-model.number="lockForm.minutes" type="number" min="5" max="43200" class="form-input" />
                    </div>
                    <div>
                        <label class="form-label">Reason (audit log)</label>
                        <textarea v-model="lockForm.reason" rows="2" class="form-input"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="showLockModal = false" class="btn-secondary">Cancel</button>
                    <button @click="lock" class="btn-danger">Lock account</button>
                </div>
            </div>
        </div>

        <div v-if="showCreditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showCreditModal = false">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md">
                <h3 class="font-semibold text-lg mb-1">Adjust credit for {{ target?.name }}</h3>
                <p class="text-xs text-gray-500 mb-4">Current score: {{ target?.credit_score }} · clamped 0-100.</p>
                <div class="space-y-3">
                    <div>
                        <label class="form-label">Points (+/-)</label>
                        <input v-model.number="creditForm.points" type="number" min="-100" max="100" class="form-input" />
                    </div>
                    <div>
                        <label class="form-label">Reason (required)</label>
                        <textarea v-model="creditForm.reason" rows="2" required class="form-input"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="showCreditModal = false" class="btn-secondary">Cancel</button>
                    <button @click="adjustCredit" class="btn-primary">Apply</button>
                </div>
            </div>
        </div>
    </div>
</template>
