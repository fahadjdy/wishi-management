<script setup>
import { ref, computed, reactive, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useWishiStore } from '@/stores/wishi';
import { useToast } from 'vue-toastification';

const route = useRoute();
const store = useWishiStore();
const toast = useToast();
const loading = ref(false);
const errors = ref({});

const form = reactive({
    name: '',
    auto_join: false,
    require_approval: true,
    winner_selection_mode: 'auto',
    min_credit_score: null,
    max_active_wishis_per_member: null,
    tender_start_time: null,
    tender_end_time: null,
    status: 'active',
});

const wishi = computed(() => store.currentWishi);

watch(wishi, (w) => {
    if (!w) return;
    Object.assign(form, {
        name: w.name,
        auto_join: w.auto_join,
        require_approval: w.require_approval,
        winner_selection_mode: w.winner_selection_mode,
        min_credit_score: w.min_credit_score,
        max_active_wishis_per_member: w.max_active_wishis_per_member,
        tender_start_time: w.tender_start_time?.slice(0, 5) || null,
        tender_end_time: w.tender_end_time?.slice(0, 5) || null,
        status: w.status,
    });
}, { immediate: true });

async function save() {
    loading.value = true;
    errors.value = {};
    try {
        const payload = { ...form };
        if (!payload.min_credit_score) payload.min_credit_score = null;
        if (!payload.max_active_wishis_per_member) payload.max_active_wishis_per_member = null;
        await store.update(route.params.uuid, payload);
        toast.success('Settings saved.');
    } catch (e) {
        if (e.response?.status === 422) errors.value = e.response.data.errors || {};
        else toast.error(e.response?.data?.message || 'Failed to save.');
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div v-if="wishi" class="space-y-5 max-w-3xl">
        <div v-if="wishi.status === 'draft'" class="surface-padded bg-amber-50 border-amber-200">
            <h3 class="font-semibold text-amber-900">This WISHI is still a draft</h3>
            <p class="text-sm text-amber-700 mt-0.5">Once all {{ wishi.total_members }} members have joined, use the <strong>Start WISHI</strong> button at the top of this page to activate. Start date will be locked to that day.</p>
        </div>

        <form @submit.prevent="save" class="surface-padded space-y-5">
            <div>
                <label class="form-label">WISHI name</label>
                <input v-model="form.name" type="text" class="form-input" />
                <p v-if="errors.name" class="form-error">{{ errors.name[0] }}</p>
            </div>

            <div class="border-t border-gray-100 pt-5 space-y-4">
                <h3 class="font-semibold">Join rules</h3>
                <label class="flex items-start gap-3">
                    <input v-model="form.require_approval" type="checkbox" class="rounded text-indigo-600 mt-1" />
                    <div><div class="font-medium">Require admin approval</div><div class="text-xs text-gray-500">New joiners stay in pending until approved.</div></div>
                </label>
                <label class="flex items-start gap-3">
                    <input v-model="form.auto_join" type="checkbox" class="rounded text-indigo-600 mt-1" />
                    <div><div class="font-medium">Allow auto-join via shareable link</div></div>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Min credit score</label>
                        <input v-model.number="form.min_credit_score" type="number" min="0" max="100" class="form-input" />
                    </div>
                    <div>
                        <label class="form-label">Max active WISHIs per member</label>
                        <input v-model.number="form.max_active_wishis_per_member" type="number" min="1" class="form-input" />
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-100 pt-5 space-y-4">
                <h3 class="font-semibold">Selection &amp; tender</h3>
                <div>
                    <label class="form-label">Winner selection mode</label>
                    <select v-model="form.winner_selection_mode" class="form-input">
                        <option value="auto">Automatic</option>
                        <option value="manual">Manual</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Tender opens</label>
                        <input v-model="form.tender_start_time" type="time" class="form-input" />
                    </div>
                    <div>
                        <label class="form-label">Tender closes</label>
                        <input v-model="form.tender_end_time" type="time" class="form-input" />
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-100 pt-5">
                <label class="form-label">Status</label>
                <select v-model="form.status" class="form-input">
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <div class="flex justify-end">
                <button type="submit" :disabled="loading" class="btn-primary">{{ loading ? 'Saving…' : 'Save changes' }}</button>
            </div>
        </form>
    </div>
</template>
