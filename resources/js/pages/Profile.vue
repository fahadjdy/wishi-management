<script setup>
import { onMounted, computed, reactive, ref } from 'vue';
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

const pwForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const pwErrors = ref({});
const saving = ref(false);

async function changePassword() {
    pwErrors.value = {};
    saving.value = true;
    try {
        await api.put('/me/password', pwForm);
        toast.success('Password updated.');
        pwForm.current_password = '';
        pwForm.password = '';
        pwForm.password_confirmation = '';
    } catch (e) {
        if (e.response?.status === 422) pwErrors.value = e.response.data.errors || {};
        else toast.error(e.response?.data?.message || 'Failed.');
    } finally { saving.value = false; }
}

const scoreOffset = computed(() => {
    const score = credit.score ?? 0;
    const circ = 2 * Math.PI * 60;
    return circ - (score / 100) * circ;
});
const actionLabels = {
    on_time_payment: 'On-time payment', early_payment: 'Early payment',
    late_payment: 'Late payment', missed_payment: 'Missed payment', manual_adjust: 'Manual adjustment',
};
const actionColor = {
    on_time_payment: 'text-emerald-600', early_payment: 'text-emerald-600',
    late_payment: 'text-amber-600', missed_payment: 'text-red-600', manual_adjust: 'text-gray-600',
};
</script>

<template>
    <div class="space-y-5 max-w-4xl">
        <h1 class="text-2xl font-bold">Profile</h1>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <!-- Credit gauge -->
            <div class="surface-padded text-center">
                <h3 class="font-semibold mb-3">Credit Score</h3>
                <div class="relative inline-block">
                    <svg class="-rotate-90" width="160" height="160" viewBox="0 0 140 140">
                        <circle cx="70" cy="70" r="60" stroke="#e5e7eb" stroke-width="12" fill="none" />
                        <circle cx="70" cy="70" r="60" :stroke="credit.score >= 70 ? '#10b981' : credit.score >= 50 ? '#f59e0b' : '#ef4444'" stroke-width="12" fill="none" stroke-linecap="round"
                            :stroke-dasharray="2 * Math.PI * 60" :stroke-dashoffset="scoreOffset" class="transition-all duration-700" />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <div class="text-4xl font-bold">{{ credit.score ?? '—' }}</div>
                        <div class="text-xs text-gray-500">/ 100</div>
                    </div>
                </div>
                <div class="mt-3">
                    <span :class="trustColor[credit.trustLevel]" class="capitalize">{{ credit.trustLevel || '—' }}</span>
                </div>
            </div>

            <!-- Account info (read-only — managed by admin) -->
            <div class="surface-padded md:col-span-2">
                <h3 class="font-semibold mb-3">Account info</h3>
                <p class="text-xs text-gray-500 mb-3">These details are managed by the platform admin. If anything is wrong, contact your admin.</p>
                <dl class="text-sm space-y-2.5">
                    <div class="flex justify-between"><dt class="text-gray-500">Name</dt><dd class="font-medium">{{ auth.user?.name }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Email</dt><dd class="font-medium">{{ auth.user?.email }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd class="font-medium">{{ auth.user?.phone || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Joined</dt><dd class="font-medium">{{ formatDateTime(auth.user?.created_at) }}</dd></div>
                </dl>
            </div>
        </div>

        <!-- Change password (only self-service action) -->
        <form @submit.prevent="changePassword" class="surface-padded max-w-md space-y-4">
            <h3 class="font-semibold">Change password</h3>
            <div>
                <label class="form-label">Current password</label>
                <input v-model="pwForm.current_password" type="password" required autocomplete="current-password" class="form-input" />
                <p v-if="pwErrors.current_password" class="form-error">{{ pwErrors.current_password[0] }}</p>
            </div>
            <div>
                <label class="form-label">New password</label>
                <input v-model="pwForm.password" type="password" required autocomplete="new-password" class="form-input" placeholder="10+ chars, mixed case, number, symbol" />
                <p v-if="pwErrors.password" class="form-error">{{ pwErrors.password[0] }}</p>
            </div>
            <div>
                <label class="form-label">Confirm new password</label>
                <input v-model="pwForm.password_confirmation" type="password" required autocomplete="new-password" class="form-input" />
            </div>
            <button type="submit" :disabled="saving" class="btn-primary">{{ saving ? 'Saving…' : 'Update password' }}</button>
        </form>

        <!-- Credit history -->
        <div class="surface-padded">
            <h3 class="font-semibold mb-3">Credit score history</h3>
            <div v-if="!credit.logs.length" class="text-center py-8 text-gray-400 text-sm">No score changes recorded yet.</div>
            <ul v-else class="divide-y divide-gray-100">
                <li v-for="log in credit.logs" :key="log.id" class="py-3 flex items-center justify-between">
                    <div>
                        <div class="font-medium" :class="actionColor[log.action]">{{ actionLabels[log.action] }}</div>
                        <div class="text-xs text-gray-500">{{ formatDateTime(log.created_at) }}</div>
                    </div>
                    <div class="text-right">
                        <div class="font-bold" :class="log.points > 0 ? 'text-emerald-600' : 'text-red-600'">{{ log.points > 0 ? '+' : '' }}{{ log.points }}</div>
                        <div class="text-xs text-gray-500">{{ log.score_before }} → {{ log.score_after }}</div>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
