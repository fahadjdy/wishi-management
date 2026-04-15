<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useWishiStore } from '@/stores/wishi';
import { useAuthStore } from '@/stores/auth';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useToast } from 'vue-toastification';
import { formatINR } from '@/utils/format';

const router = useRouter();
const store = useWishiStore();
const auth = useAuthStore();
const toast = useToast();

useBreadcrumbs(() => [
    { label: 'WISHIs', to: '/wishis' },
    { label: 'Create' },
]);

onMounted(() => {
    if (! auth.user?.is_admin) {
        toast.error('Only platform admins can create WISHIs.');
        router.push('/dashboard');
    }
});

const errors = ref({});
const loading = ref(false);

const form = reactive({
    name: '',
    total_members: 10,
    monthly_contribution: 5000,
    cycle_frequency: 'monthly',
    cycle_interval_days: 10,
    start_date: new Date(Date.now() + 86400000).toISOString().slice(0, 10),
    wishi_opening_time: '00:00',
    auto_join: false,
    require_approval: true,
    winner_selection_mode: 'auto',
    cycle_type: 'random',
    hybrid_pattern: ['random', 'random', 'tender'],
});

const memberCapacity = computed(() => Math.max(0, Number(form.total_members || 0) - 1));
// Pool per cycle = monthly × total_members. Admin contributes equally
// (they hold seat #1; receive cycle-#1 organizer payout but pay every cycle).
const totalPool = computed(() => Number(form.monthly_contribution || 0) * Number(form.total_members || 0));

function addPatternStep(value) {
    if (form.hybrid_pattern.length >= 24) return;
    form.hybrid_pattern.push(value);
}
function removePatternStep(idx) {
    form.hybrid_pattern.splice(idx, 1);
}

async function submit() {
    loading.value = true;
    errors.value = {};
    try {
        const payload = { ...form };
        if (payload.cycle_type !== 'hybrid') delete payload.hybrid_pattern;
        if (payload.cycle_frequency !== 'custom') delete payload.cycle_interval_days;

        const wishi = await store.create(payload);
        toast.success('WISHI created.');
        router.push(`/wishis/${wishi.uuid}`);
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            toast.error('Please fix the errors below.');
        } else {
            toast.error(e.response?.data?.message || 'Failed to create WISHI.');
        }
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <form @submit.prevent="submit" class="max-w-2xl mx-auto space-y-5">
        <div>
            <h1 class="text-2xl font-bold">Create a new WISHI</h1>
            <p class="text-sm text-gray-500">One cycle per member — cycle #1 is the organizer payout (admin).</p>
        </div>

        <div class="surface-padded space-y-4">
            <div>
                <label class="form-label">WISHI name</label>
                <input v-model="form.name" type="text" required class="form-input" placeholder="e.g. Mumbai Friends Pool" />
                <p v-if="errors.name" class="form-error">{{ errors.name[0] }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Total size (including you)</label>
                    <input v-model.number="form.total_members" type="number" min="2" max="100" required class="form-input" />
                    <p class="text-[11px] text-gray-500 mt-1">You hold seat #1 as organizer. You'll be able to invite <strong>{{ memberCapacity }}</strong> member{{ memberCapacity !== 1 ? 's' : '' }}.</p>
                    <p v-if="errors.total_members" class="form-error">{{ errors.total_members[0] }}</p>
                </div>
                <div>
                    <label class="form-label">Contribution per cycle (₹)</label>
                    <input v-model.number="form.monthly_contribution" type="number" min="1" required class="form-input" />
                    <p v-if="errors.monthly_contribution" class="form-error">{{ errors.monthly_contribution[0] }}</p>
                </div>
                <div>
                    <label class="form-label">Start date</label>
                    <input v-model="form.start_date" type="date" required class="form-input" />
                    <p v-if="errors.start_date" class="form-error">{{ errors.start_date[0] }}</p>
                </div>
                <div>
                    <label class="form-label">Wishi opening time</label>
                    <input v-model="form.wishi_opening_time" type="time" required class="form-input" />
                    <p class="text-[11px] text-gray-500 mt-1">Time of day each cycle (and tender bidding) opens.</p>
                    <p v-if="errors.wishi_opening_time" class="form-error">{{ errors.wishi_opening_time[0] }}</p>
                </div>
            </div>

            <div>
                <label class="form-label">Cycle frequency</label>
                <select v-model="form.cycle_frequency" class="form-input">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="yearly">Yearly</option>
                    <option value="custom">Custom (every N days)</option>
                </select>
                <div v-if="form.cycle_frequency === 'custom'" class="mt-2">
                    <input v-model.number="form.cycle_interval_days" type="number" min="1" max="365" class="form-input" placeholder="Every N days" />
                </div>
            </div>

            <div>
                <label class="form-label">Winner selection</label>
                <div class="grid grid-cols-2 gap-2">
                    <label v-for="opt in [{v:'random',t:'Random',d:'Every cycle picked at random.'},{v:'hybrid',t:'Hybrid',d:'Custom mix of random + tender cycles.'}]" :key="opt.v" class="cursor-pointer">
                        <input v-model="form.cycle_type" :value="opt.v" type="radio" class="sr-only peer" />
                        <div class="border-2 rounded-lg p-3 text-center transition peer-checked:border-indigo-600 peer-checked:bg-indigo-50 border-gray-200">
                            <div class="font-semibold text-sm">{{ opt.t }}</div>
                            <div class="text-[11px] text-gray-500 mt-0.5">{{ opt.d }}</div>
                        </div>
                    </label>
                </div>
            </div>

            <div v-if="form.cycle_type === 'hybrid'">
                <label class="form-label">Hybrid pattern (repeats)</label>
                <div class="flex flex-wrap gap-2 items-center bg-gray-50 p-2.5 rounded-lg">
                    <span v-for="(p, idx) in form.hybrid_pattern" :key="idx" class="badge-brand cursor-pointer" @click="removePatternStep(idx)">
                        {{ idx + 1 }}. {{ p }} ✕
                    </span>
                    <button type="button" @click="addPatternStep('random')" class="btn-secondary btn-sm">+ Random</button>
                    <button type="button" @click="addPatternStep('tender')" class="btn-secondary btn-sm">+ Tender</button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.require_approval" type="checkbox" class="rounded text-indigo-600 mt-0.5" />
                    <span>Require admin approval for joiners</span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.auto_join" type="checkbox" class="rounded text-indigo-600 mt-0.5" />
                    <span>Allow auto-join via shareable link</span>
                </label>
            </div>

            <div>
                <label class="form-label">Selection mode</label>
                <select v-model="form.winner_selection_mode" class="form-input">
                    <option value="auto">Automatic (system picks)</option>
                    <option value="manual">Manual (admin selects)</option>
                </select>
            </div>

            <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-3 text-sm">
                <div class="font-semibold text-indigo-900">Pool: {{ formatINR(totalPool) }}</div>
                <div class="text-indigo-700 text-xs">{{ form.total_members }} members × {{ formatINR(form.monthly_contribution) }} per cycle, over {{ form.total_members }} cycles. You hold seat #1 — you contribute every cycle and receive the cycle-#1 organizer payout.</div>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" @click="router.push('/wishis')" class="btn-secondary">Cancel</button>
            <button type="submit" :disabled="loading" class="btn-primary">
                <span v-if="loading">Creating…</span>
                <span v-else>Create WISHI</span>
            </button>
        </div>
    </form>
</template>
