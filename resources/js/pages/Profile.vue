<script setup>
import { onMounted, computed, reactive, ref, watch } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useCreditStore } from '@/stores/credit';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useToast } from 'vue-toastification';
import api from '@/api/client';
import { formatDateTime, trustColor } from '@/utils/format';

const auth = useAuthStore();
const credit = useCreditStore();
const toast = useToast();

useBreadcrumbs(() => [{ label: 'Profile' }]);
onMounted(() => credit.fetchMe());

const isAdmin = computed(() => !!auth.user?.is_admin);

// ---------- Avatar ----------
const avatarInput = ref(null);
const avatarPreview = ref(null);
const avatarSaving = ref(false);

function pickAvatar() { avatarInput.value?.click(); }

async function onAvatarChange(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    // Instant preview while upload runs.
    avatarPreview.value = URL.createObjectURL(file);
    avatarSaving.value = true;
    try {
        const fd = new FormData();
        fd.append('avatar', file);
        const { data } = await api.post('/me/profile', fd);
        auth.user = data.user;
        toast.success('Profile photo updated.');
    } catch (err) {
        avatarPreview.value = null;
        toast.error(err.response?.data?.message || 'Upload failed.');
    } finally { avatarSaving.value = false; e.target.value = ''; }
}

async function removeAvatar() {
    if (! confirm('Remove your profile photo?')) return;
    avatarSaving.value = true;
    try {
        const fd = new FormData();
        fd.append('remove_avatar', '1');
        const { data } = await api.post('/me/profile', fd);
        auth.user = data.user;
        avatarPreview.value = null;
        toast.success('Profile photo removed.');
    } catch (err) {
        toast.error(err.response?.data?.message || 'Failed.');
    } finally { avatarSaving.value = false; }
}

const initials = computed(() => (auth.user?.name || '')
    .split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase());
const avatarSrc = computed(() => avatarPreview.value || auth.user?.avatar_url);

// ---------- Password ----------
const pwForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const pwErrors = ref({});
const pwSaving = ref(false);
const showPw = ref(false);

async function changePassword() {
    pwErrors.value = {};
    pwSaving.value = true;
    try {
        await api.put('/me/password', pwForm);
        toast.success('Password updated.');
        pwForm.current_password = ''; pwForm.password = ''; pwForm.password_confirmation = '';
    } catch (e) {
        if (e.response?.status === 422) pwErrors.value = e.response.data.errors || {};
        else toast.error(e.response?.data?.message || 'Failed.');
    } finally { pwSaving.value = false; }
}

// ---------- Credit gauge ----------
const scoreOffset = computed(() => {
    const score = credit.score ?? 0;
    const circ = 2 * Math.PI * 54;
    return circ - (score / 100) * circ;
});

// ---------- Contact (admin-only self edit) ----------
const contactForm = reactive({ email: '', phone: '', whatsapp_number: '' });
const contactErrors = ref({});
const contactSaving = ref(false);

function syncContactForm() {
    contactForm.email = auth.user?.email || '';
    contactForm.phone = auth.user?.phone || '';
    contactForm.whatsapp_number = auth.user?.whatsapp_number || '';
}
watch(() => auth.user, syncContactForm, { immediate: true });

const contactDirty = computed(() =>
    contactForm.email !== (auth.user?.email || '') ||
    contactForm.phone !== (auth.user?.phone || '') ||
    contactForm.whatsapp_number !== (auth.user?.whatsapp_number || '')
);

async function saveContact() {
    contactErrors.value = {};
    contactSaving.value = true;
    try {
        const fd = new FormData();
        fd.append('email', contactForm.email);
        fd.append('phone', contactForm.phone ?? '');
        fd.append('whatsapp_number', contactForm.whatsapp_number ?? '');
        const { data } = await api.post('/me/profile', fd);
        auth.user = data.user;
        toast.success('Contact details updated.');
    } catch (e) {
        if (e.response?.status === 422) contactErrors.value = e.response.data.errors || {};
        else toast.error(e.response?.data?.message || 'Failed.');
    } finally { contactSaving.value = false; }
}
</script>

<template>
    <div class="max-w-5xl mx-auto space-y-4">
        <!-- ==========================================================
             HERO BANNER — avatar + name + credit score gauge, all in
             one condensed strip. No scrolling needed for the header.
        =========================================================== -->
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <div class="relative">
                <!-- Decorative gradient band -->
                <div class="h-20 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500"></div>
                <div class="px-6 pb-5 -mt-10 flex flex-wrap items-end gap-5">
                    <!-- Avatar with overlay edit button -->
                    <div class="relative shrink-0 group">
                        <div class="w-24 h-24 rounded-full ring-4 ring-white shadow-md overflow-hidden bg-gradient-to-br from-slate-500 to-slate-700 flex items-center justify-center text-white text-2xl font-bold">
                            <img v-if="avatarSrc" :src="avatarSrc" :alt="auth.user?.name" class="w-full h-full object-cover" />
                            <span v-else>{{ initials }}</span>
                        </div>
                        <button @click="pickAvatar" :disabled="avatarSaving"
                            class="absolute -bottom-1 -right-1 w-8 h-8 rounded-full bg-indigo-600 hover:bg-indigo-700 text-white shadow-md flex items-center justify-center transition"
                            :title="avatarSrc ? 'Change photo' : 'Upload photo'">
                            <svg v-if="!avatarSaving" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                            <svg v-else class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
                        </button>
                        <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp" @change="onAvatarChange" class="hidden" />
                    </div>

                    <!-- Name + email + admin badge -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-xl sm:text-2xl font-bold text-gray-900 truncate">{{ auth.user?.name || '—' }}</h1>
                            <span v-if="auth.user?.is_admin" class="badge-brand">Admin</span>
                            <span :class="trustColor[credit.trustLevel]" class="capitalize">{{ credit.trustLevel || '—' }}</span>
                        </div>
                        <div class="text-sm text-gray-500 truncate">{{ auth.user?.email }}</div>
                        <div class="text-xs text-gray-400 mt-0.5">Joined {{ formatDateTime(auth.user?.created_at) }}</div>
                        <button v-if="avatarSrc" @click="removeAvatar" :disabled="avatarSaving" class="text-[11px] text-red-600 hover:underline mt-1">Remove photo</button>
                    </div>

                    <!-- Credit gauge (compact, on the right) -->
                    <div class="shrink-0 flex items-center gap-3">
                        <div class="relative">
                            <svg class="-rotate-90" width="88" height="88" viewBox="0 0 120 120">
                                <circle cx="60" cy="60" r="54" stroke="#e5e7eb" stroke-width="10" fill="none" />
                                <circle cx="60" cy="60" r="54"
                                    :stroke="credit.score >= 70 ? '#10b981' : credit.score >= 50 ? '#f59e0b' : '#ef4444'"
                                    stroke-width="10" fill="none" stroke-linecap="round"
                                    :stroke-dasharray="2 * Math.PI * 54" :stroke-dashoffset="scoreOffset"
                                    class="transition-all duration-700" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <div class="text-lg font-bold leading-none">{{ credit.score ?? '—' }}</div>
                                <div class="text-[9px] text-gray-400 uppercase tracking-wide">score</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==========================================================
             READ-ONLY contact summary + CHANGE PASSWORD form. Members can
             only edit their photo (via hero above) and password. Name,
             email, phone and WhatsApp are managed by the admin (FLOW §4).
        =========================================================== -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Contact details — editable for admins, read-only for members -->
            <form v-if="isAdmin" @submit.prevent="saveContact" class="rounded-xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">Contact details</h3>
                    <span class="text-[11px] text-gray-400">Admin self-service</span>
                </div>
                <div>
                    <label class="form-label">Name</label>
                    <input :value="auth.user?.name || ''" type="text" disabled class="form-input bg-gray-50 cursor-not-allowed" />
                    <p class="text-[11px] text-gray-400 mt-1">Name edits still go through Admin → Members.</p>
                </div>
                <div>
                    <label class="form-label">Email</label>
                    <input v-model="contactForm.email" type="email" required autocomplete="email" class="form-input" />
                    <p v-if="contactErrors.email" class="form-error">{{ contactErrors.email[0] }}</p>
                </div>
                <div>
                    <label class="form-label">Phone</label>
                    <input v-model="contactForm.phone" type="tel" autocomplete="tel" class="form-input" placeholder="+91 98765 43210" />
                    <p v-if="contactErrors.phone" class="form-error">{{ contactErrors.phone[0] }}</p>
                </div>
                <div>
                    <label class="form-label">WhatsApp</label>
                    <input v-model="contactForm.whatsapp_number" type="tel" class="form-input" placeholder="+91 98765 43210" />
                    <p v-if="contactErrors.whatsapp_number" class="form-error">{{ contactErrors.whatsapp_number[0] }}</p>
                </div>
                <div class="flex justify-end pt-1">
                    <button type="submit" :disabled="contactSaving || !contactDirty" class="btn-primary btn-sm">{{ contactSaving ? 'Saving…' : 'Save contact' }}</button>
                </div>
            </form>

            <div v-else class="rounded-xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">Account details</h3>
                    <span class="text-[11px] text-gray-400">Managed by admin</span>
                </div>
                <dl class="text-sm divide-y divide-gray-100">
                    <div class="py-2 flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Name</dt>
                        <dd class="font-medium text-gray-900 text-right truncate">{{ auth.user?.name || '—' }}</dd>
                    </div>
                    <div class="py-2 flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Email</dt>
                        <dd class="font-medium text-gray-900 text-right truncate">{{ auth.user?.email || '—' }}</dd>
                    </div>
                    <div class="py-2 flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Phone</dt>
                        <dd class="font-medium text-gray-900 text-right truncate">{{ auth.user?.phone || '—' }}</dd>
                    </div>
                    <div class="py-2 flex items-center justify-between gap-3">
                        <dt class="text-gray-500">WhatsApp</dt>
                        <dd class="font-medium text-gray-900 text-right truncate">{{ auth.user?.whatsapp_number || '—' }}</dd>
                    </div>
                </dl>
                <p class="text-[11px] text-gray-400">Need a change? Ask your platform admin to update these from <em>Admin → Members</em>.</p>
            </div>

            <!-- Password -->
            <form @submit.prevent="changePassword" class="rounded-xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">Change password</h3>
                    <button type="button" @click="showPw = !showPw" class="text-[11px] text-indigo-600 hover:underline">
                        {{ showPw ? 'Hide' : 'Show' }} characters
                    </button>
                </div>
                <div>
                    <label class="form-label">Current password</label>
                    <input v-model="pwForm.current_password" :type="showPw ? 'text' : 'password'" required autocomplete="current-password" class="form-input" />
                    <p v-if="pwErrors.current_password" class="form-error">{{ pwErrors.current_password[0] }}</p>
                </div>
                <div>
                    <label class="form-label">New password</label>
                    <input v-model="pwForm.password" :type="showPw ? 'text' : 'password'" required autocomplete="new-password" class="form-input" placeholder="10+ chars, upper / lower / digit / symbol" />
                    <p v-if="pwErrors.password" class="form-error">{{ pwErrors.password[0] }}</p>
                </div>
                <div>
                    <label class="form-label">Confirm new password</label>
                    <input v-model="pwForm.password_confirmation" :type="showPw ? 'text' : 'password'" required autocomplete="new-password" class="form-input" />
                </div>
                <div class="flex justify-end pt-1">
                    <button type="submit" :disabled="pwSaving" class="btn-primary btn-sm">{{ pwSaving ? 'Saving…' : 'Update password' }}</button>
                </div>
            </form>
        </div>

    </div>
</template>
