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
    wishi_opening_time: '00:00',
    start_date: '',
    status: 'active',
});

const wishi = computed(() => store.currentWishi);

// Start date can only be changed while the WISHI hasn't activated yet.
const canEditStartDate = computed(() => ['draft', 'planned'].includes(wishi.value?.status));
const todayStr = new Date().toISOString().slice(0, 10);

watch(wishi, (w) => {
    if (!w) return;
    Object.assign(form, {
        name: w.name,
        auto_join: w.auto_join,
        require_approval: w.require_approval,
        winner_selection_mode: w.winner_selection_mode,
        wishi_opening_time: (w.wishi_opening_time || '00:00').slice(0, 5),
        start_date: w.start_date || '',
        status: w.status,
    });
}, { immediate: true });

async function save() {
    loading.value = true;
    errors.value = {};
    try {
        const payload = { ...form };
        // Only include start_date in the payload when it's editable AND
        // actually changed — avoids sending a redundant field that the
        // backend would then reject once the WISHI activates.
        if (! canEditStartDate.value || payload.start_date === wishi.value?.start_date) {
            delete payload.start_date;
        }
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
            <p class="text-sm text-amber-700 mt-0.5">Once all {{ wishi.total_members }} members have joined (you hold seat #1 as organizer — {{ wishi.member_capacity ?? (wishi.total_members - 1) }} seats open for invitees), use the <strong>Start WISHI</strong> button at the top of this page to activate. Start date will be locked to that day.</p>
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
            </div>

            <div class="border-t border-gray-100 pt-5 space-y-4">
                <h3 class="font-semibold">Cycle opening</h3>
                <div>
                    <label class="form-label">Winner selection mode</label>
                    <select v-model="form.winner_selection_mode" class="form-input">
                        <option value="auto">Automatic</option>
                        <option value="manual">Manual</option>
                    </select>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Start date</label>
                        <input v-model="form.start_date" type="date" :min="todayStr" :disabled="!canEditStartDate" class="form-input disabled:bg-gray-50 disabled:text-gray-500 disabled:cursor-not-allowed" />
                        <p v-if="canEditStartDate" class="text-[11px] text-gray-500 mt-1">You can reschedule while this WISHI is still in draft / planned. Once you click <strong>Start WISHI</strong>, the start date is locked.</p>
                        <p v-else class="text-[11px] text-gray-400 mt-1">Locked — WISHI has already started on {{ form.start_date }}.</p>
                        <p v-if="errors.start_date" class="form-error">{{ errors.start_date[0] }}</p>
                    </div>
                    <div>
                        <label class="form-label">Wishi opening time</label>
                        <input v-model="form.wishi_opening_time" type="time" class="form-input" />
                        <p class="text-[11px] text-gray-500 mt-1">Time of day each cycle (and tender bidding) opens.</p>
                        <p v-if="errors.wishi_opening_time" class="form-error">{{ errors.wishi_opening_time[0] }}</p>
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
