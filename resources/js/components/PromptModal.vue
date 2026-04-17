<script setup>
import { computed, ref, watch, nextTick } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { PencilSquareIcon } from '@heroicons/vue/24/outline';
import { useUIStore } from '@/stores/ui';

const ui = useUIStore();
const state = computed(() => ui.promptState);
const open = computed(() => !!state.value);

const value = ref('');
const inputEl = ref(null);

watch(open, async (v) => {
    if (!v) return;
    value.value = state.value?.initial || '';
    await nextTick();
    inputEl.value?.focus?.();
    inputEl.value?.select?.();
});

const canSubmit = computed(() => !state.value?.required || value.value.trim().length > 0);

function onCancel() { ui.resolvePrompt(null); }
function onSubmit() {
    if (!canSubmit.value) return;
    ui.resolvePrompt(value.value.trim());
}
</script>

<template>
    <TransitionRoot appear :show="open" as="template">
        <Dialog @close="onCancel" class="relative z-60">
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
                            <div class="w-11 h-11 rounded-full flex items-center justify-center shrink-0 bg-brand-50">
                                <PencilSquareIcon class="w-6 h-6 text-brand-600" aria-hidden="true" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <DialogTitle class="text-lg font-bold text-slate-900">{{ state.title || 'Enter details' }}</DialogTitle>
                                <p v-if="state.message" class="text-sm text-slate-600 mt-1.5 whitespace-pre-line">{{ state.message }}</p>

                                <div class="mt-4">
                                    <label v-if="state.label" class="form-label">{{ state.label }}<span v-if="state.required" class="text-rose-600">&nbsp;*</span></label>
                                    <textarea v-if="state.multiline" ref="inputEl" v-model="value" :placeholder="state.placeholder || ''" class="form-input min-h-24" rows="3" @keydown.ctrl.enter="onSubmit" @keydown.meta.enter="onSubmit"></textarea>
                                    <input v-else ref="inputEl" v-model="value" type="text" :placeholder="state.placeholder || ''" class="form-input" @keyup.enter="onSubmit" />
                                    <p v-if="state.hint" class="form-hint">{{ state.hint }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 justify-end mt-6">
                            <button type="button" @click="onCancel" class="btn-secondary">{{ state.cancelText || 'Cancel' }}</button>
                            <button type="button" @click="onSubmit" :disabled="!canSubmit" class="btn-primary">{{ state.confirmText || 'Submit' }}</button>
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
