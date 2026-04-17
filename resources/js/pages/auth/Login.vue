<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useToast } from 'vue-toastification';
import { EyeIcon, EyeSlashIcon, LockClosedIcon } from '@heroicons/vue/24/outline';

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
        toast.success('Welcome back.');
        const redirect = route.query.redirect || '/dashboard';
        router.push(redirect);
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
        } else {
            toast.error(e.response?.data?.message || 'Sign-in failed. Please check your email and password.');
        }
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div>
        <h2 class="text-2xl font-bold text-slate-900 mb-1.5">Welcome back</h2>
        <p class="text-sm text-slate-500 mb-7">Sign in to manage your WISHIs.</p>

        <form @submit.prevent="submit" class="space-y-4" novalidate>
            <div>
                <label for="email" class="form-label">Email</label>
                <input id="email" v-model="form.email" type="email" autocomplete="email" required
                    class="form-input" placeholder="you@example.com"
                    :aria-invalid="!!errors.email" aria-describedby="email-error" />
                <p v-if="errors.email" id="email-error" class="form-error">{{ errors.email[0] }}</p>
            </div>

            <div>
                <label for="password" class="form-label">Password</label>
                <div class="relative">
                    <input
                        id="password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        class="form-input pr-10"
                        placeholder="••••••••"
                        :aria-invalid="!!errors.password" aria-describedby="password-error"
                    />
                    <button
                        type="button"
                        @click="showPassword = !showPassword"
                        class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        :aria-label="showPassword ? 'Hide password' : 'Show password'"
                    >
                        <EyeSlashIcon v-if="showPassword" class="w-4 h-4" aria-hidden="true" />
                        <EyeIcon v-else class="w-4 h-4" aria-hidden="true" />
                    </button>
                </div>
                <p v-if="errors.password" id="password-error" class="form-error">{{ errors.password[0] }}</p>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600 select-none">
                <input v-model="form.remember" type="checkbox" class="rounded text-brand-600 focus:ring-brand-500" />
                Keep me signed in on this device
            </label>

            <button type="submit" :disabled="loading" class="btn-primary btn-block">
                <LockClosedIcon v-if="!loading" class="w-4 h-4" aria-hidden="true" />
                <span v-if="loading">Signing in…</span>
                <span v-else>Sign in securely</span>
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-slate-500">
            Accounts are issued by your platform admin. Contact them if you don't have credentials yet.
        </p>
    </div>
</template>
