<script setup>
import { computed, ref, watch } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { KeyIcon, ClipboardIcon, CheckIcon, EyeIcon, EyeSlashIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline';
import { useUIStore } from '@/stores/ui';
import { useToast } from 'vue-toastification';

/**
 * One-shot "credentials ready" modal — replaces the old pattern of toast-ing
 * a new user's password. A financial app must NEVER flash a credential in a
 * disappearing toast; admins need explicit copy/share affordances.
 */
const ui = useUIStore();
const toast = useToast();

const state = computed(() => ui.credentialsState);
const open = computed(() => !!state.value);

const copied = ref('');
const showPw = ref(false);

watch(open, (v) => {
    if (v) {
        copied.value = '';
        showPw.value = false;
    }
});

async function copyValue(kind, value) {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = kind;
        setTimeout(() => { if (copied.value === kind) copied.value = ''; }, 1800);
    } catch {
        toast.error('Could not copy — please select the text manually.');
    }
}

async function copyBoth() {
    if (!state.value) return;
    const text = `Email: ${state.value.email}\nPassword: ${state.value.password}`;
    try {
        await navigator.clipboard.writeText(text);
        copied.value = 'both';
        setTimeout(() => { if (copied.value === 'both') copied.value = ''; }, 1800);
    } catch {
        toast.error('Could not copy — please select the text manually.');
    }
}

function shareWhatsApp() {
    if (!state.value) return;
    const appName = state.value.appName || 'FHD Wishis';
    const intro = state.value.whatsappIntro || `Your ${appName} login credentials:`;
    const hint = state.value.whatsappHint || 'Please sign in and change your password from Profile after first login.';
    const text = `${intro}\n\nEmail: ${state.value.email}\nPassword: ${state.value.password}\n\n${hint}`;
    const url = `https://wa.me/${state.value.whatsappNumber || ''}?text=${encodeURIComponent(text)}`;
    window.open(url, '_blank', 'noopener');
}

function onDone() { ui.resolveCredentials(); }
</script>

<template>
    <TransitionRoot appear :show="open" as="template">
        <Dialog @close="onDone" class="relative z-60">
            <TransitionChild as="template"
                enter="ease-out duration-200" enter-from="opacity-0" enter-to="opacity-100"
                leave="ease-in duration-150" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" aria-hidden="true" />
            </TransitionChild>

            <div class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto">
                <TransitionChild as="template"
                    enter="ease-out duration-200" enter-from="opacity-0 translate-y-2 scale-95" enter-to="opacity-100 translate-y-0 scale-100"
                    leave="ease-in duration-150" leave-from="opacity-100 scale-100" leave-to="opacity-0 scale-95">
                    <DialogPanel v-if="state" class="w-full max-w-md bg-white rounded-2xl shadow-2xl p-6 my-8">
                        <div class="flex items-start gap-4">
                            <div class="w-11 h-11 rounded-full bg-brand-50 flex items-center justify-center shrink-0">
                                <KeyIcon class="w-6 h-6 text-brand-600" aria-hidden="true" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <DialogTitle class="text-lg font-bold text-slate-900">{{ state.title || 'Credentials ready to share' }}</DialogTitle>
                                <p class="text-sm text-slate-600 mt-1.5">
                                    {{ state.description || 'Copy these and send them to the member securely. The password will not be shown again — you can reset it later from the member profile.' }}
                                </p>

                                <div class="mt-4 space-y-3">
                                    <div>
                                        <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Email</label>
                                        <div class="flex items-center gap-2 mt-1">
                                            <input :value="state.email" readonly class="form-input flex-1 font-mono text-sm bg-slate-50" @focus="$event.target.select()" />
                                            <button @click="copyValue('email', state.email)" type="button" class="btn-secondary btn-sm shrink-0">
                                                <CheckIcon v-if="copied === 'email'" class="w-4 h-4" aria-hidden="true" />
                                                <ClipboardIcon v-else class="w-4 h-4" aria-hidden="true" />
                                                {{ copied === 'email' ? 'Copied' : 'Copy' }}
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Password</label>
                                        <div class="flex items-center gap-2 mt-1">
                                            <input :value="state.password" :type="showPw ? 'text' : 'password'" readonly class="form-input flex-1 font-mono text-sm bg-slate-50" @focus="$event.target.select()" />
                                            <button @click="showPw = !showPw" type="button" class="btn-secondary btn-sm shrink-0" :aria-label="showPw ? 'Hide password' : 'Show password'">
                                                <EyeSlashIcon v-if="showPw" class="w-4 h-4" aria-hidden="true" />
                                                <EyeIcon v-else class="w-4 h-4" aria-hidden="true" />
                                            </button>
                                            <button @click="copyValue('password', state.password)" type="button" class="btn-secondary btn-sm shrink-0">
                                                <CheckIcon v-if="copied === 'password'" class="w-4 h-4" aria-hidden="true" />
                                                <ClipboardIcon v-else class="w-4 h-4" aria-hidden="true" />
                                                {{ copied === 'password' ? 'Copied' : 'Copy' }}
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-900">
                                    <strong>Save somewhere safe.</strong> This password is shown only once. If you lose it, reset it from the member's profile page.
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <button @click="copyBoth" type="button" class="btn-secondary btn-sm">
                                        <CheckIcon v-if="copied === 'both'" class="w-4 h-4" aria-hidden="true" />
                                        <ClipboardIcon v-else class="w-4 h-4" aria-hidden="true" />
                                        {{ copied === 'both' ? 'Copied both' : 'Copy both' }}
                                    </button>
                                    <button @click="shareWhatsApp" type="button" class="btn-secondary btn-sm">
                                        <ChatBubbleLeftRightIcon class="w-4 h-4" aria-hidden="true" />
                                        Share via WhatsApp
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end mt-6">
                            <button type="button" @click="onDone" class="btn-primary">Done — I've saved these</button>
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
