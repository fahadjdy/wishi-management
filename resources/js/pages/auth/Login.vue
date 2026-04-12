<script setup>
import { reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useToast } from 'vue-toastification';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const toast = useToast();

const form = reactive({ email: '', password: '', remember: false });
const errors = ref({});
const loading = ref(false);
const showPassword = ref(false);

async function submit() {
    errors.value = {};
    loading.value = true;
    try {
        await auth.login(form);
        toast.success('Welcome back!');
        const redirect = route.query.redirect || '/dashboard';
        router.push(redirect);
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
        } else {
            toast.error(e.response?.data?.message || 'Login failed.');
        }
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div>
        <h2 class="text-2xl font-bold text-gray-900 mb-1.5">Welcome back</h2>
        <p class="text-sm text-gray-500 mb-7">Sign in to manage your WISHIs.</p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label class="form-label">Email</label>
                <input v-model="form.email" type="email" autocomplete="email" required class="form-input" placeholder="you@example.com" />
                <p v-if="errors.email" class="form-error">{{ errors.email[0] }}</p>
            </div>
            <div>
                <label class="form-label">Password</label>
                <div class="relative">
                    <input
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        class="form-input pr-10"
                        placeholder="••••••••"
                    />
                    <button type="button" @click="showPassword = !showPassword" class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded text-gray-500 hover:bg-gray-100">
                        <svg v-if="showPassword" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" /><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" /><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" /><line x1="2" y1="2" x2="22" y2="22" /></svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /></svg>
                    </button>
                </div>
                <p v-if="errors.password" class="form-error">{{ errors.password[0] }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-600 select-none">
                <input v-model="form.remember" type="checkbox" class="rounded text-indigo-600 focus:ring-indigo-500" />
                Remember me on this device
            </label>
            <button type="submit" :disabled="loading" class="btn-primary w-full">
                <span v-if="loading">Signing in…</span>
                <span v-else>Sign in</span>
            </button>
        </form>

        <div class="mt-6 text-center text-sm text-gray-600">
            Don't have an account?
            <RouterLink to="/register" class="font-semibold text-indigo-600 hover:text-indigo-700">Create one</RouterLink>
        </div>

        <div class="mt-6 p-3 bg-indigo-50 border border-indigo-100 rounded-lg text-xs text-indigo-800">
            <div class="font-semibold mb-0.5">Demo credentials</div>
            <div>demo@wishi.test / Demo@1234</div>
        </div>
    </div>
</template>
