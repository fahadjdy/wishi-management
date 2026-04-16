<script setup>
import { onMounted, ref, reactive, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAdminStore } from '@/stores/admin';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useToast } from 'vue-toastification';
import { formatDateTime, trustColor } from '@/utils/format';

const store = useAdminStore();
const router = useRouter();
const toast = useToast();

useBreadcrumbs(() => [{ label: 'Admin' }, { label: 'Members' }]);

const filters = reactive({ q: '', status: '', sort: 'created_at', direction: 'desc' });
const page = ref(1);

const showCreateModal = ref(false);
const createForm = reactive({ name: '', email: '', phone: '', password: '', credit_score: 70, is_admin: false });
const createErrors = ref({});
const createLoading = ref(false);

function genPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    let out = '';
    for (let i = 0; i < 10; i++) out += chars[Math.floor(Math.random() * chars.length)];
    createForm.password = out + '!1';
}
function openCreate() {
    createForm.name = ''; createForm.email = ''; createForm.phone = '';
    createForm.password = ''; createForm.credit_score = 70; createForm.is_admin = false;
    createErrors.value = {};
    genPassword();
    showCreateModal.value = true;
}
async function submitCreate() {
    createLoading.value = true;
    createErrors.value = {};
    try {
        await store.createUser(createForm);
        toast.success(`Account created · share these credentials with the member: ${createForm.email} / ${createForm.password}`);
        showCreateModal.value = false;
        await load();
    } catch (e) {
        if (e.response?.status === 422) createErrors.value = e.response.data.errors || {};
        else toast.error(e.response?.data?.message || 'Failed.');
    } finally { createLoading.value = false; }
}

async function load() {
    await store.fetchUsers({ ...filters, page: page.value, per_page: 50 });
}

const members = computed(() => (store.users || []).filter((u) => !u.is_admin));

onMounted(load);
watch(filters, () => { page.value = 1; load(); }, { deep: true });

async function changePage(n) { page.value = n; await load(); window.scrollTo({ top: 0, behavior: 'smooth' }); }

function openDetail(u) { router.push(`/admin/users/${u.id}`); }

async function restore(u) {
    try {
        await store.restore(u.id);
        toast.success('User restored.');
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Member Management</h1>
            <button @click="openCreate" class="btn-primary btn-sm">+ Add member</button>
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

        <!-- Members Table -->
        <section>
            <h2 class="text-lg font-semibold flex items-center gap-2 mb-2">
                <span class="w-2 h-2 bg-gray-400 rounded-full"></span> Members
                <span class="badge-gray">{{ members.length }}</span>
                <span class="ml-auto flex items-center gap-3 text-xs font-normal text-gray-500">
                    <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-red-100 border border-red-200"></span>Late payment</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-emerald-100 border border-emerald-200"></span>Paid in advance</span>
                </span>
            </h2>
            <div class="surface overflow-x-auto">
                <table class="w-full text-sm min-w-[800px]">
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
                        <tr v-for="u in members" :key="u.id"
                            @click="openDetail(u)"
                            class="cursor-pointer"
                            :class="[
                                u.payment_status === 'late' ? 'bg-red-50 hover:bg-red-100' :
                                u.payment_status === 'advance' ? 'bg-emerald-50 hover:bg-emerald-100' :
                                'hover:bg-gray-50',
                                u.deleted_at ? 'opacity-60' : ''
                            ]"
                            :title="u.payment_status === 'late' ? `${u.late_contributions_count} late payment(s) pending` : u.payment_status === 'advance' ? `${u.advance_contributions_count} payment(s) made in advance` : ''">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full overflow-hidden bg-gradient-to-br from-slate-500 to-slate-700 text-white text-xs font-bold flex items-center justify-center shrink-0">
                                        <img v-if="u.avatar_url" :src="u.avatar_url" :alt="u.name" class="w-full h-full object-cover" />
                                        <span v-else>{{ u.name.split(' ').map(p=>p[0]).slice(0,2).join('') }}</span>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-medium truncate">{{ u.name }}</div>
                                        <div class="text-xs text-gray-500 truncate">{{ u.email }}</div>
                                        <div v-if="u.phone" class="text-xs text-gray-400">{{ u.phone }}<span v-if="u.whatsapp_number && u.whatsapp_number !== u.phone"> · WA {{ u.whatsapp_number }}</span></div>
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
                            <td class="px-4 py-3 text-right" @click.stop>
                                <button v-if="u.deleted_at" @click="restore(u)" class="btn-secondary btn-sm">Restore</button>
                                <span v-else class="text-xs text-gray-400">→</span>
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

        <!-- Create member account modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showCreateModal = false">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md max-h-[90vh] overflow-y-auto">
                <h3 class="font-semibold text-lg mb-1">Create a new account</h3>
                <p class="text-xs text-gray-500 mb-4">Members sign up only by admin creation. Share the email + password with them.</p>
                <div class="space-y-3">
                    <div>
                        <label class="form-label">Full name</label>
                        <input v-model="createForm.name" type="text" required class="form-input" />
                        <p v-if="createErrors.name" class="form-error">{{ createErrors.name[0] }}</p>
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input v-model="createForm.email" type="email" required class="form-input" />
                        <p v-if="createErrors.email" class="form-error">{{ createErrors.email[0] }}</p>
                    </div>
                    <div>
                        <label class="form-label">Phone <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input v-model="createForm.phone" type="tel" class="form-input" placeholder="+91…" />
                        <p v-if="createErrors.phone" class="form-error">{{ createErrors.phone[0] }}</p>
                    </div>
                    <div>
                        <label class="form-label">Password</label>
                        <div class="flex gap-2">
                            <input v-model="createForm.password" type="text" required class="form-input flex-1 font-mono text-sm" minlength="8" />
                            <button type="button" @click="genPassword" class="btn-secondary btn-sm">Regen</button>
                        </div>
                        <p v-if="createErrors.password" class="form-error">{{ createErrors.password[0] }}</p>
                        <p class="text-[11px] text-gray-500 mt-1">Shown so admin can share manually. Min 8 chars.</p>
                    </div>
                    <div>
                        <label class="form-label">Starting credit score</label>
                        <input v-model.number="createForm.credit_score" type="number" min="0" max="100" class="form-input" />
                    </div>
                    <label class="flex items-start gap-3">
                        <input v-model="createForm.is_admin" type="checkbox" class="rounded text-indigo-600 mt-1" />
                        <div>
                            <div class="font-medium text-sm">Grant platform admin</div>
                            <div class="text-xs text-gray-500">Platform admins can create WISHIs and manage members.</div>
                        </div>
                    </label>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button @click="showCreateModal = false" class="btn-secondary">Cancel</button>
                    <button @click="submitCreate" :disabled="createLoading" class="btn-primary">
                        {{ createLoading ? 'Creating…' : 'Create account' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
