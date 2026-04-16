<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useAdminStore } from '@/stores/admin';
import { useAuthStore } from '@/stores/auth';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useToast } from 'vue-toastification';
import { formatDate, formatDateTime, formatINR, trustColor } from '@/utils/format';

const store = useAdminStore();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const toast = useToast();

const userId = computed(() => Number(route.params.id));
const user = computed(() => store.currentUser);
const detail = computed(() => store.currentUserDetail);
const isSelf = computed(() => user.value?.id === auth.user?.id);

useBreadcrumbs(() => [
    { label: 'Admin' },
    { label: 'Members', to: '/admin/users' },
    { label: user.value?.name || 'Member' },
]);

const loading = ref(true);

async function load() {
    loading.value = true;
    try {
        await store.fetchUser(userId.value);
        resetProfile();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not load member.');
        router.push('/admin/users');
    } finally {
        loading.value = false;
    }
}

watch(userId, load);
onMounted(load);

// ---------- Profile form (name, email, phone, whatsapp, avatar) ----------
const profileForm = reactive({ name: '', email: '', phone: '', whatsapp_number: '' });
const profileErrors = ref({});
const profileSaving = ref(false);
const avatarFile = ref(null);
const avatarPreview = ref(null);
const avatarInput = ref(null);

function resetProfile() {
    profileForm.name = user.value?.name || '';
    profileForm.email = user.value?.email || '';
    profileForm.phone = user.value?.phone || '';
    profileForm.whatsapp_number = user.value?.whatsapp_number || '';
    profileErrors.value = {};
    avatarFile.value = null;
    avatarPreview.value = null;
}

function pickAvatar() { avatarInput.value?.click(); }
function onAvatarChange(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    avatarFile.value = file;
    avatarPreview.value = URL.createObjectURL(file);
}
function clearAvatarPick() { avatarFile.value = null; avatarPreview.value = null; }

async function saveProfile() {
    profileSaving.value = true;
    profileErrors.value = {};
    try {
        const fd = new FormData();
        fd.append('name', profileForm.name || '');
        fd.append('email', profileForm.email || '');
        fd.append('phone', profileForm.phone || '');
        fd.append('whatsapp_number', profileForm.whatsapp_number || '');
        if (avatarFile.value) fd.append('avatar', avatarFile.value);
        await store.updateUser(userId.value, fd);
        toast.success('Member profile updated.');
        await load();
    } catch (e) {
        if (e.response?.status === 422) profileErrors.value = e.response.data.errors || {};
        else toast.error(e.response?.data?.message || 'Failed.');
    } finally { profileSaving.value = false; }
}

async function removeAvatar() {
    if (! confirm("Remove this member's profile photo?")) return;
    try {
        const fd = new FormData();
        fd.append('remove_avatar', '1');
        await store.updateUser(userId.value, fd);
        toast.success('Photo removed.');
        avatarFile.value = null; avatarPreview.value = null;
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}

// ---------- Password reset ----------
const pwForm = reactive({ password: '' });
const pwErrors = ref({});
const pwSaving = ref(false);
const showPw = ref(true);

function genPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    let out = '';
    for (let i = 0; i < 10; i++) out += chars[Math.floor(Math.random() * chars.length)];
    pwForm.password = out + '!1';
}

async function resetPassword() {
    if (! pwForm.password || pwForm.password.length < 8) {
        pwErrors.value = { password: ['Password must be at least 8 characters.'] };
        return;
    }
    if (! confirm(`Reset ${user.value.name}'s password to "${pwForm.password}"? You'll need to share this with them manually.`)) return;
    pwSaving.value = true;
    pwErrors.value = {};
    try {
        await store.resetUserPassword(userId.value, pwForm.password);
        toast.success(`Password reset. Share: ${user.value.email} / ${pwForm.password}`, { timeout: 10000 });
        pwForm.password = '';
    } catch (e) {
        if (e.response?.status === 422) pwErrors.value = e.response.data.errors || {};
        else toast.error(e.response?.data?.message || 'Failed.');
    } finally { pwSaving.value = false; }
}

// ---------- Lock / Unlock ----------
const lockForm = reactive({ minutes: 60, reason: '' });

async function lockUser() {
    if (lockForm.minutes < 5) { toast.error('Minimum 5 minutes.'); return; }
    try {
        await store.lock(userId.value, lockForm.minutes, lockForm.reason || null);
        toast.success('Account locked.');
        lockForm.reason = '';
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}
async function unlockUser() {
    try {
        await store.unlock(userId.value);
        toast.success('Account unlocked.');
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}

// ---------- Toggle admin / Delete / Restore ----------
async function toggleAdmin() {
    if (! confirm(`${user.value.is_admin ? 'Revoke' : 'Grant'} platform admin for ${user.value.name}?`)) return;
    try {
        await store.toggleAdmin(userId.value);
        toast.success('Role updated.');
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}
async function softDelete() {
    if (! confirm(`Soft-delete ${user.value.name}? They can be restored later.`)) return;
    try {
        await store.remove(userId.value);
        toast.success('Member deleted.');
        router.push('/admin/users');
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}
async function restore() {
    try {
        await store.restore(userId.value);
        toast.success('Member restored.');
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
}

// ---------- Credit adjust ----------
const creditForm = reactive({ points: 0, reason: '' });
const creditSaving = ref(false);

async function adjustCredit() {
    if (! creditForm.reason?.trim()) { toast.error('Reason is required.'); return; }
    if (creditForm.points === 0) { toast.error('Points must be non-zero.'); return; }
    creditSaving.value = true;
    try {
        await store.adjustCredit(userId.value, creditForm.points, creditForm.reason);
        toast.success('Credit adjusted.');
        creditForm.points = 0; creditForm.reason = '';
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
    finally { creditSaving.value = false; }
}

// ---------- Contributions inline actions ----------
const busyContributionId = ref(null);

async function markPaid(c) {
    busyContributionId.value = c.id;
    try {
        await store.markContributionPaid(c.wishi_uuid, c.cycle_id, userId.value);
        toast.success(`Marked ${formatINR(c.amount)} paid.`);
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Failed.'); }
    finally { busyContributionId.value = null; }
}
async function undoPaid(c) {
    if (! confirm(`Undo ${formatINR(c.amount)} payment for cycle #${c.cycle_number}? The credit-score change will also be reversed.`)) return;
    busyContributionId.value = c.id;
    try {
        await store.revertContribution(c.wishi_uuid, c.cycle_id, c.id);
        toast.success('Payment reverted.');
        await load();
    } catch (e) { toast.error(e.response?.data?.message || 'Could not undo.'); }
    finally { busyContributionId.value = null; }
}

function daysUntil(dateStr) {
    if (! dateStr) return null;
    const d = new Date(dateStr); d.setHours(0, 0, 0, 0);
    const today = new Date(); today.setHours(0, 0, 0, 0);
    return Math.round((d.getTime() - today.getTime()) / 86400000);
}

const initials = computed(() => (user.value?.name || '').split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase());
</script>

<template>
    <div class="max-w-5xl mx-auto space-y-4">
        <div>
            <RouterLink to="/admin/users" class="text-sm text-indigo-600 hover:underline">← Back to Members</RouterLink>
        </div>

        <div v-if="loading" class="surface-padded text-center text-sm text-gray-400 py-16">Loading member…</div>

        <template v-else-if="user">
            <!-- Hero -->
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="h-16 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500"></div>
                <div class="px-6 pb-5 -mt-10 flex flex-wrap items-end gap-5">
                    <div class="w-20 h-20 rounded-full ring-4 ring-white shadow-md overflow-hidden bg-gradient-to-br from-slate-500 to-slate-700 flex items-center justify-center text-white text-xl font-bold shrink-0">
                        <img v-if="avatarPreview || user.avatar_url" :src="avatarPreview || user.avatar_url" :alt="user.name" class="w-full h-full object-cover" />
                        <span v-else>{{ initials }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-xl sm:text-2xl font-bold text-gray-900 truncate">{{ user.name }}</h1>
                            <span v-if="user.is_admin" class="badge-brand">Admin</span>
                            <span v-if="user.deleted_at" class="badge-danger">Deleted</span>
                            <span v-else-if="user.is_locked" class="badge-warning">Locked</span>
                            <span v-else class="badge-success">Active</span>
                        </div>
                        <div class="text-sm text-gray-500 truncate">{{ user.email }}<span v-if="user.phone"> · {{ user.phone }}</span></div>
                        <div class="text-xs text-gray-400 mt-0.5">
                            Credit <strong>{{ user.credit_score }}</strong> · <span :class="trustColor[user.trust_level]" class="capitalize">{{ user.trust_level }}</span>
                            · Joined {{ formatDateTime(user.created_at) }}
                            <span v-if="user.last_login_at"> · Last login {{ formatDateTime(user.last_login_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile edit -->
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
                <h3 class="font-semibold">Profile details</h3>
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-full overflow-hidden bg-gradient-to-br from-slate-500 to-slate-700 text-white text-sm font-bold flex items-center justify-center shrink-0">
                        <img v-if="avatarPreview || user.avatar_url" :src="avatarPreview || user.avatar_url" :alt="user.name" class="w-full h-full object-cover" />
                        <span v-else>{{ initials }}</span>
                    </div>
                    <div class="space-y-1 text-xs text-gray-500">
                        <div class="flex gap-2">
                            <button type="button" @click="pickAvatar" class="btn-secondary btn-sm">{{ user.avatar_url || avatarPreview ? 'Change photo' : 'Upload photo' }}</button>
                            <button v-if="user.avatar_url && !avatarPreview" type="button" @click="removeAvatar" class="btn-ghost btn-sm text-red-600">Remove</button>
                            <button v-if="avatarPreview" type="button" @click="clearAvatarPick" class="btn-ghost btn-sm">Undo pick</button>
                        </div>
                        <div>JPG / PNG / WebP, up to 2 MB.</div>
                    </div>
                    <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp" @change="onAvatarChange" class="hidden" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Name</label>
                        <input v-model="profileForm.name" type="text" class="form-input" />
                        <p v-if="profileErrors.name" class="form-error">{{ profileErrors.name[0] }}</p>
                    </div>
                    <div>
                        <label class="form-label">Email (login)</label>
                        <input v-model="profileForm.email" type="email" class="form-input" />
                        <p v-if="profileErrors.email" class="form-error">{{ profileErrors.email[0] }}</p>
                    </div>
                    <div>
                        <label class="form-label">Phone</label>
                        <input v-model="profileForm.phone" type="tel" class="form-input" placeholder="+91 9xxxxxxxxx" />
                        <p v-if="profileErrors.phone" class="form-error">{{ profileErrors.phone[0] }}</p>
                    </div>
                    <div>
                        <label class="form-label">WhatsApp number</label>
                        <input v-model="profileForm.whatsapp_number" type="tel" class="form-input" placeholder="+91 9xxxxxxxxx" />
                        <p v-if="profileErrors.whatsapp_number" class="form-error">{{ profileErrors.whatsapp_number[0] }}</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="resetProfile" class="btn-secondary">Reset</button>
                    <button type="button" @click="saveProfile" :disabled="profileSaving" class="btn-primary">{{ profileSaving ? 'Saving…' : 'Save changes' }}</button>
                </div>
            </section>

            <!-- Security: password + lock/unlock -->
            <section class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold">Reset password</h3>
                        <button type="button" @click="showPw = !showPw" class="text-[11px] text-indigo-600 hover:underline">{{ showPw ? 'Hide' : 'Show' }}</button>
                    </div>
                    <p v-if="isSelf" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                        You can't reset your own password here. Use your Profile page.
                    </p>
                    <template v-else>
                        <div class="flex gap-2">
                            <input v-model="pwForm.password" :type="showPw ? 'text' : 'password'" class="form-input flex-1 font-mono text-sm" placeholder="New password (min 8 chars)" minlength="8" />
                            <button type="button" @click="genPassword" class="btn-secondary btn-sm">Regen</button>
                        </div>
                        <p v-if="pwErrors.password" class="form-error">{{ pwErrors.password[0] }}</p>
                        <p class="text-[11px] text-gray-500">Shown so you can share it manually with the member (WhatsApp/SMS). No email reset link is sent.</p>
                        <div class="flex justify-end">
                            <button type="button" @click="resetPassword" :disabled="pwSaving || !pwForm.password" class="btn-primary btn-sm">{{ pwSaving ? 'Resetting…' : 'Reset password' }}</button>
                        </div>
                    </template>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
                    <h3 class="font-semibold">Account lock</h3>
                    <div v-if="user.is_locked" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                        Locked until {{ formatDateTime(user.locked_until) }}.
                    </div>
                    <div v-if="user.failed_login_attempts > 0" class="text-[11px] text-amber-600">{{ user.failed_login_attempts }} recent failed login attempt(s).</div>
                    <template v-if="! isSelf">
                        <div v-if="! user.is_locked" class="space-y-2">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="form-label">Duration (minutes)</label>
                                    <input v-model.number="lockForm.minutes" type="number" min="5" max="43200" class="form-input" />
                                </div>
                                <div>
                                    <label class="form-label">Reason</label>
                                    <input v-model="lockForm.reason" type="text" class="form-input" placeholder="(audit log)" />
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <button type="button" @click="lockUser" class="btn-danger btn-sm">Lock account</button>
                            </div>
                        </div>
                        <div v-else class="flex justify-end">
                            <button type="button" @click="unlockUser" class="btn-success btn-sm">Unlock now</button>
                        </div>
                    </template>
                    <p v-else class="text-xs text-gray-400">You cannot lock your own account.</p>
                </div>
            </section>

            <!-- Role + danger zone + credit adjust -->
            <section class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
                    <h3 class="font-semibold">Role & status</h3>
                    <div class="text-sm text-gray-600">
                        {{ user.is_admin ? 'Platform admin — can create WISHIs and manage members.' : 'Regular member.' }}
                    </div>
                    <div class="flex gap-2 flex-wrap">
                        <button v-if="! isSelf" type="button" @click="toggleAdmin" class="btn-secondary btn-sm">
                            {{ user.is_admin ? 'Revoke admin' : 'Make admin' }}
                        </button>
                        <template v-if="! isSelf">
                            <button v-if="user.deleted_at" type="button" @click="restore" class="btn-success btn-sm">Restore account</button>
                            <button v-else type="button" @click="softDelete" class="btn-danger btn-sm">Delete account</button>
                        </template>
                        <span v-if="isSelf" class="text-xs text-gray-400 italic">You cannot change your own role or delete your own account.</span>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
                    <h3 class="font-semibold">Adjust credit score</h3>
                    <div class="text-xs text-gray-500">Current: <strong>{{ user.credit_score }}</strong> — clamped 0-100. Logged to credit history.</div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="form-label">Points (+/-)</label>
                            <input v-model.number="creditForm.points" type="number" min="-100" max="100" class="form-input" />
                        </div>
                        <div>
                            <label class="form-label">Reason</label>
                            <input v-model="creditForm.reason" type="text" required class="form-input" />
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" @click="adjustCredit" :disabled="creditSaving" class="btn-primary btn-sm">Apply</button>
                    </div>
                </div>
            </section>

            <!-- Active WISHIs + contributions -->
            <section v-if="detail" class="space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="surface-padded">
                        <div class="text-[11px] text-gray-500 uppercase tracking-wide">Active WISHIs</div>
                        <div class="text-2xl font-bold">{{ detail.totals?.active_wishis_count ?? 0 }}</div>
                    </div>
                    <div class="surface-padded">
                        <div class="text-[11px] text-gray-500 uppercase tracking-wide">Pending dues</div>
                        <div class="text-2xl font-bold text-amber-600">{{ formatINR(detail.totals?.pending_dues || 0) }}</div>
                    </div>
                    <div class="surface-padded">
                        <div class="text-[11px] text-gray-500 uppercase tracking-wide">Pending count</div>
                        <div class="text-2xl font-bold">{{ detail.totals?.pending_count ?? 0 }}</div>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
                    <h3 class="font-semibold mb-3">Active WISHIs</h3>
                    <div v-if="!detail.active_wishis.length" class="text-sm text-gray-400 py-3">Not a member of any active WISHI.</div>
                    <ul v-else class="divide-y divide-gray-100">
                        <li v-for="m in detail.active_wishis" :key="m.wishi_uuid" class="py-3 flex items-center justify-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <RouterLink :to="`/wishis/${m.wishi_uuid}`" class="font-medium text-indigo-600 hover:underline truncate block">{{ m.wishi_name }}</RouterLink>
                                <div class="text-xs text-gray-500 mt-0.5 flex flex-wrap gap-x-2">
                                    <span class="capitalize">{{ m.cycle_type }}</span>
                                    <span>· Cycle {{ m.current_cycle }}/{{ m.duration_months }}</span>
                                    <span>· {{ formatINR(m.monthly_contribution) }}/mo</span>
                                    <span v-if="m.token_no">· Token #{{ m.token_no }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <span :class="m.membership_status === 'pending' ? 'badge-warning' : 'badge-success'" class="capitalize">{{ m.membership_status }}</span>
                                <span v-if="m.has_won" class="badge-info">🏆 #{{ m.won_in_cycle }}</span>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
                    <h3 class="font-semibold mb-3">Pending payments</h3>
                    <div v-if="!detail.pending_contributions.length" class="text-sm text-gray-400 py-3">Nothing pending — all caught up.</div>
                    <table v-else class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                            <tr>
                                <th class="text-left px-3 py-2">WISHI</th>
                                <th class="text-left px-3 py-2">Cycle</th>
                                <th class="text-right px-3 py-2">Amount</th>
                                <th class="text-left px-3 py-2">Due</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="c in detail.pending_contributions" :key="c.id">
                                <td class="px-3 py-2 truncate max-w-[200px]">{{ c.wishi_name }}</td>
                                <td class="px-3 py-2">#{{ c.cycle_number }}</td>
                                <td class="px-3 py-2 text-right font-semibold">{{ formatINR(c.amount) }}</td>
                                <td class="px-3 py-2">
                                    <div>{{ formatDate(c.due_date) }}</div>
                                    <div class="text-[11px]" :class="daysUntil(c.due_date) < 0 ? 'text-red-600 font-semibold' : 'text-gray-500'">
                                        <span v-if="daysUntil(c.due_date) < 0">{{ Math.abs(daysUntil(c.due_date)) }} days late</span>
                                        <span v-else-if="daysUntil(c.due_date) === 0">due today</span>
                                        <span v-else>in {{ daysUntil(c.due_date) }} days</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button @click="markPaid(c)" :disabled="busyContributionId === c.id" class="btn-success btn-sm">
                                        {{ busyContributionId === c.id ? '…' : 'Mark paid' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="detail.paid_contributions?.length" class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
                    <h3 class="font-semibold mb-3">Recent payments</h3>
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                            <tr>
                                <th class="text-left px-3 py-2">WISHI</th>
                                <th class="text-left px-3 py-2">Cycle</th>
                                <th class="text-right px-3 py-2">Amount</th>
                                <th class="text-left px-3 py-2">Paid</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="c in detail.paid_contributions" :key="c.id">
                                <td class="px-3 py-2 truncate max-w-[200px]">{{ c.wishi_name }}</td>
                                <td class="px-3 py-2">#{{ c.cycle_number }}</td>
                                <td class="px-3 py-2 text-right font-semibold">{{ formatINR(c.amount) }}</td>
                                <td class="px-3 py-2">
                                    <div>{{ formatDate(c.paid_at) }}</div>
                                    <div v-if="c.status === 'late'" class="text-[11px] text-amber-600">paid late</div>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button v-if="c.can_undo" @click="undoPaid(c)" :disabled="busyContributionId === c.id" class="btn-danger btn-sm">
                                        {{ busyContributionId === c.id ? '…' : 'Undo' }}
                                    </button>
                                    <span v-else class="text-[11px] text-gray-400">locked</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>
