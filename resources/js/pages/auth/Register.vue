<script setup>
import { reactive, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useToast } from 'vue-toastification';

const auth = useAuthStore();
const router = useRouter();
const toast = useToast();

const form = reactive({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
});
const errors = ref({});
const loading = ref(false);

const passwordChecks = {
    length: (v) => v.length >= 10,
    upper: (v) => /[A-Z]/.test(v),
    lower: (v) => /[a-z]/.test(v),
    number: (v) => /[0-9]/.test(v),
    symbol: (v) => /[^A-Za-z0-9]/.test(v),
};

function check(rule) {
    return passwordChecks[rule](form.password);
}

async function submit() {
    errors.value = {};
    loading.value = true;
    try {
        await auth.register(form);
        toast.success('Account created. Welcome!');
        router.push('/dashboard');
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
        } else {
            toast.error(e.response?.data?.message || 'Registration failed.');
        }
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div>
        <h2 class="text-2xl font-bold text-gray-900 mb-1.5">Create your account</h2>
        <p class="text-sm text-gray-500 mb-7">Join WISHI to manage chit funds securely.</p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label class="form-label">Full name</label>
                <input v-model="form.name" type="text" required class="form-input" placeholder="Your name" />
                <p v-if="errors.name" class="form-error">{{ errors.name[0] }}</p>
            </div>
            <div>
                <label class="form-label">Email</label>
                <input v-model="form.email" type="email" required autocomplete="email" class="form-input" placeholder="you@example.com" />
                <p v-if="errors.email" class="form-error">{{ errors.email[0] }}</p>
            </div>
            <div>
                <label class="form-label">Phone <span class="text-gray-400 font-normal">(optional)</span></label>
                <input v-model="form.phone" type="tel" autocomplete="tel" class="form-input" placeholder="+91 98765 43210" />
                <p v-if="errors.phone" class="form-error">{{ errors.phone[0] }}</p>
            </div>
            <div>
                <label class="form-label">Password</label>
                <input v-model="form.password" type="password" required autocomplete="new-password" class="form-input" placeholder="At least 10 chars, mixed case, number, symbol" />
                <p v-if="errors.password" class="form-error">{{ errors.password[0] }}</p>
                <div v-if="form.password" class="mt-2 grid grid-cols-2 gap-1 text-xs">
                    <div :class="check('length') ? 'text-emerald-600' : 'text-gray-400'">✓ 10+ characters</div>
                    <div :class="check('upper') ? 'text-emerald-600' : 'text-gray-400'">✓ Uppercase</div>
                    <div :class="check('lower') ? 'text-emerald-600' : 'text-gray-400'">✓ Lowercase</div>
                    <div :class="check('number') ? 'text-emerald-600' : 'text-gray-400'">✓ Number</div>
                    <div :class="check('symbol') ? 'text-emerald-600' : 'text-gray-400'">✓ Symbol</div>
                </div>
            </div>
            <div>
                <label class="form-label">Confirm password</label>
                <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="form-input" placeholder="Re-enter password" />
            </div>
            <button type="submit" :disabled="loading" class="btn-primary w-full">
                <span v-if="loading">Creating account…</span>
                <span v-else>Create account</span>
            </button>
        </form>

        <div class="mt-6 text-center text-sm text-gray-600">
            Already have an account?
            <RouterLink to="/login" class="font-semibold text-indigo-600 hover:text-indigo-700">Sign in</RouterLink>
        </div>
    </div>
</template>
