<script setup>
import { computed, ref, watch, nextTick } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { ExclamationTriangleIcon, InformationCircleIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline';
import { useUIStore } from '@/stores/ui';

const ui = useUIStore();
const state = computed(() => ui.confirmState);
const open = computed(() => !!state.value);

const typed = ref('');
const inputEl = ref(null);

watch(open, async (v) => {
    if (v) {
        typed.value = '';
        await nextTick();
        inputEl.value?.focus?.();
    }
});

const requiresType = computed(() => !!state.value?.requireTypeText);
const typeOK = computed(() => !requiresType.value || typed.value.trim() === state.value.requireTypeText);

const tone = computed(() => state.value?.tone || 'primary');
const visual = computed(() => ({
    primary: { Icon: InformationCircleIcon,  iconColor: 'text-brand-600',  iconBg: 'bg-brand-50',  btnClass: 'btn-primary' },
    danger:  { Icon: ExclamationTriangleIcon, iconColor: 'text-rose-600',  iconBg: 'bg-rose-50',   btnClass: 'btn-danger'  },
    success: { Icon: ShieldCheckIcon,         iconColor: 'text-green-600', iconBg: 'bg-green-50',  btnClass: 'btn-success' },
    warning: { Icon: ExclamationTriangleIcon, iconColor: 'text-amber-600', iconBg: 'bg-amber-50',  btnClass: 'btn-primary' },
}[tone.value] || { Icon: InformationCircleIcon, iconColor: 'text-brand-600', iconBg: 'bg-brand-50', btnClass: 'btn-primary' }));

function onCancel() { ui.resolveConfirm(false); }
function onConfirm() { if (typeOK.value) ui.resolveConfirm(true); }
</script>

<template>
    <TransitionRoot appear :show="open" as="template">
        <Dialog @close="onCancel" class="relative z-[60]">
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
                            <div :class="visual.iconBg" class="w-11 h-11 rounded-full flex items-center justify-center shrink-0">
                                <component :is="visual.Icon" :class="visual.iconColor" class="w-6 h-6" aria-hidden="true" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <DialogTitle class="text-lg font-bold text-slate-900">{{ state.title || 'Confirm action' }}</DialogTitle>
                                <p v-if="state.message" class="text-sm text-slate-600 mt-1.5 whitespace-pre-line">{{ state.message }}</p>

                                <dl v-if="state.meta && Object.keys(state.meta).length" class="mt-3 p-3 rounded-lg bg-slate-50 border border-slate-200 text-xs space-y-1">
                                    <div v-for="(v, k) in state.meta" :key="k" class="flex justify-between gap-4">
                                        <dt class="text-slate-500">{{ k }}</dt>
                                        <dd class="font-medium text-slate-900 text-right">{{ v }}</dd>
                                    </div>
                                </dl>

                                <div v-if="requiresType" class="mt-4">
                                    <label class="text-xs font-medium text-slate-700 block mb-1.5">
                                        Type <code class="px-1 py-0.5 bg-slate-100 rounded text-slate-900 font-mono">{{ state.requireTypeText }}</code> to confirm
                                    </label>
                                    <input ref="inputEl" v-model="typed" type="text" class="form-input font-mono text-sm" autocomplete="off" @keyup.enter="onConfirm" />
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 justify-end mt-6">
                            <button type="button" @click="onCancel" class="btn-secondary">{{ state.cancelText || 'Cancel' }}</button>
                            <button type="button" @click="onConfirm" :disabled="!typeOK" :class="visual.btnClass">{{ state.confirmText || 'Confirm' }}</button>
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
