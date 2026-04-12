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

const step = ref(1);
const errors = ref({});
const loading = ref(false);

const form = reactive({
    name: '',
    total_members: 10,
    monthly_contribution: 5000,
    duration_months: 10,
    start_date: new Date(Date.now() + 86400000).toISOString().slice(0, 10),
    auto_join: false,
    require_approval: true,
    winner_selection_mode: 'auto',
    cycle_type: 'random',
    hybrid_pattern: ['random', 'random', 'tender'],
    min_credit_score: null,
    max_active_wishis_per_member: null,
    block_if_missed_payments: false,
    tender_start_time: '10:00',
    tender_end_time: '20:00',
    status: 'draft',
});

const totalPool = computed(() => Number(form.monthly_contribution || 0) * Number(form.total_members || 0));

function addPatternStep(value) {
    if (form.hybrid_pattern.length >= 24) return;
    form.hybrid_pattern.push(value);
}
function removePatternStep(idx) {
    form.hybrid_pattern.splice(idx, 1);
}

const steps = [
    { num: 1, label: 'Basics' },
    { num: 2, label: 'Join rules' },
    { num: 3, label: 'Draw type' },
    { num: 4, label: 'Review' },
];

async function submit() {
    loading.value = true;
    errors.value = {};
    try {
        const payload = { ...form };
        if (payload.cycle_type !== 'hybrid') delete payload.hybrid_pattern;
        if (!payload.min_credit_score) delete payload.min_credit_score;
        if (!payload.max_active_wishis_per_member) delete payload.max_active_wishis_per_member;

        const wishi = await store.create(payload);
        toast.success('WISHI created.');
        router.push(`/wishis/${wishi.uuid}`);
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            toast.error('Please fix the errors below.');
            step.value = 1;
        } else {
            toast.error(e.response?.data?.message || 'Failed to create WISHI.');
        }
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="max-w-3xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-bold">Create a new WISHI</h1>
            <p class="text-sm text-gray-500">A few quick details and you'll be ready to invite members.</p>
        </div>

        <!-- Stepper -->
        <div class="flex items-center gap-2 sm:gap-4 overflow-x-auto pb-2">
            <template v-for="(s, i) in steps" :key="s.num">
                <div class="flex items-center gap-2">
                    <div
                        class="w-9 h-9 rounded-full flex items-center justify-center font-semibold text-sm transition"
                        :class="step === s.num ? 'bg-indigo-600 text-white shadow-md shadow-indigo-300' : step > s.num ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-500'"
                    >
                        <span v-if="step > s.num">✓</span>
                        <span v-else>{{ s.num }}</span>
                    </div>
                    <div class="text-sm font-medium hidden sm:block" :class="step === s.num ? 'text-gray-900' : 'text-gray-500'">{{ s.label }}</div>
                </div>
                <div v-if="i < steps.length - 1" class="flex-1 h-px bg-gray-200 min-w-[20px]"></div>
            </template>
        </div>

        <!-- Step 1: Basics -->
        <div v-if="step === 1" class="surface-padded space-y-4">
            <h2 class="text-lg font-semibold">Basic details</h2>
            <div>
                <label class="form-label">WISHI name</label>
                <input v-model="form.name" type="text" required class="form-input" placeholder="e.g. Mumbai Friends Pool" />
                <p v-if="errors.name" class="form-error">{{ errors.name[0] }}</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Total members</label>
                    <input v-model.number="form.total_members" type="number" min="2" max="100" class="form-input" />
                    <p v-if="errors.total_members" class="form-error">{{ errors.total_members[0] }}</p>
                </div>
                <div>
                    <label class="form-label">Monthly contribution (₹)</label>
                    <input v-model.number="form.monthly_contribution" type="number" min="1" class="form-input" />
                    <p v-if="errors.monthly_contribution" class="form-error">{{ errors.monthly_contribution[0] }}</p>
                </div>
                <div>
                    <label class="form-label">Duration (months)</label>
                    <input v-model.number="form.duration_months" type="number" min="2" max="120" class="form-input" />
                    <p v-if="errors.duration_months" class="form-error">{{ errors.duration_months[0] }}</p>
                </div>
                <div>
                    <label class="form-label">Start date</label>
                    <input v-model="form.start_date" type="date" class="form-input" />
                    <p v-if="errors.start_date" class="form-error">{{ errors.start_date[0] }}</p>
                </div>
            </div>
            <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-4 text-sm">
                <div class="font-semibold text-indigo-900">Pool size: {{ formatINR(totalPool) }}</div>
                <div class="text-indigo-700">Each member contributes {{ formatINR(form.monthly_contribution) }} for {{ form.duration_months }} months.</div>
            </div>
        </div>

        <!-- Step 2: Join rules -->
        <div v-if="step === 2" class="surface-padded space-y-4">
            <h2 class="text-lg font-semibold">Join rules &amp; eligibility</h2>
            <label class="flex items-start gap-3">
                <input v-model="form.require_approval" type="checkbox" class="rounded text-indigo-600 focus:ring-indigo-500 mt-1" />
                <div>
                    <div class="font-medium">Require admin approval for joiners</div>
                    <div class="text-xs text-gray-500">Recommended. New members will be in pending state until you approve.</div>
                </div>
            </label>
            <label class="flex items-start gap-3">
                <input v-model="form.auto_join" type="checkbox" class="rounded text-indigo-600 focus:ring-indigo-500 mt-1" />
                <div>
                    <div class="font-medium">Allow auto-join via shareable link</div>
                    <div class="text-xs text-gray-500">Anyone with the WISHI link can request to join.</div>
                </div>
            </label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Minimum credit score <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input v-model.number="form.min_credit_score" type="number" min="0" max="100" class="form-input" placeholder="e.g. 60" />
                </div>
                <div>
                    <label class="form-label">Max active WISHIs per member <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input v-model.number="form.max_active_wishis_per_member" type="number" min="1" max="50" class="form-input" placeholder="e.g. 3" />
                </div>
            </div>
            <label class="flex items-start gap-3">
                <input v-model="form.block_if_missed_payments" type="checkbox" class="rounded text-indigo-600 focus:ring-indigo-500 mt-1" />
                <div>
                    <div class="font-medium">Block enrolment if member has missed payments elsewhere</div>
                    <div class="text-xs text-gray-500">Adds an extra layer of trust to your pool.</div>
                </div>
            </label>
        </div>

        <!-- Step 3: Draw type -->
        <div v-if="step === 3" class="surface-padded space-y-4">
            <h2 class="text-lg font-semibold">Winner selection</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <label v-for="opt in [{v:'random',t:'Random Draw',d:'Cryptographically random winner each cycle.'},{v:'tender',t:'Tender (Bidding)',d:'Lowest bid wins; surplus distributed.'},{v:'hybrid',t:'Hybrid Pattern',d:'Mix of random and tender on a custom schedule.'}]" :key="opt.v" class="cursor-pointer">
                    <input v-model="form.cycle_type" :value="opt.v" type="radio" class="sr-only peer" />
                    <div class="border-2 rounded-xl p-4 transition peer-checked:border-indigo-600 peer-checked:bg-indigo-50 border-gray-200">
                        <div class="font-semibold">{{ opt.t }}</div>
                        <div class="text-xs text-gray-500 mt-1">{{ opt.d }}</div>
                    </div>
                </label>
            </div>

            <div v-if="form.cycle_type === 'hybrid'" class="space-y-3">
                <label class="form-label">Hybrid pattern (repeats over the WISHI duration)</label>
                <div class="flex flex-wrap gap-2 items-center bg-gray-50 p-3 rounded-lg">
                    <span v-for="(p, idx) in form.hybrid_pattern" :key="idx" class="badge-brand cursor-pointer" @click="removePatternStep(idx)">
                        {{ idx + 1 }}. {{ p }} ✕
                    </span>
                    <button type="button" @click="addPatternStep('random')" class="btn-secondary btn-sm">+ Random</button>
                    <button type="button" @click="addPatternStep('tender')" class="btn-secondary btn-sm">+ Tender</button>
                </div>
                <p class="text-xs text-gray-500">Click a step to remove it. Pattern repeats over {{ form.duration_months }} months.</p>
            </div>

            <div v-if="form.cycle_type !== 'random'" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Tender opens at</label>
                    <input v-model="form.tender_start_time" type="time" class="form-input" />
                </div>
                <div>
                    <label class="form-label">Tender closes at</label>
                    <input v-model="form.tender_end_time" type="time" class="form-input" />
                </div>
            </div>

            <div>
                <label class="form-label">Winner selection mode</label>
                <select v-model="form.winner_selection_mode" class="form-input">
                    <option value="auto">Automatic (system picks)</option>
                    <option value="manual">Manual (admin selects)</option>
                </select>
            </div>
        </div>

        <!-- Step 4: Review -->
        <div v-if="step === 4" class="surface-padded space-y-4">
            <h2 class="text-lg font-semibold">Review &amp; confirm</h2>
            <dl class="divide-y divide-gray-100 text-sm">
                <div class="grid grid-cols-2 py-2.5"><dt class="text-gray-500">Name</dt><dd class="font-semibold">{{ form.name || '—' }}</dd></div>
                <div class="grid grid-cols-2 py-2.5"><dt class="text-gray-500">Pool</dt><dd class="font-semibold">{{ formatINR(totalPool) }} ({{ form.total_members }} × {{ formatINR(form.monthly_contribution) }})</dd></div>
                <div class="grid grid-cols-2 py-2.5"><dt class="text-gray-500">Duration</dt><dd class="font-semibold">{{ form.duration_months }} months from {{ form.start_date }}</dd></div>
                <div class="grid grid-cols-2 py-2.5"><dt class="text-gray-500">Draw type</dt><dd class="font-semibold capitalize">{{ form.cycle_type }} <span v-if="form.cycle_type === 'hybrid'" class="text-gray-500 font-normal">({{ form.hybrid_pattern.join(' → ') }})</span></dd></div>
                <div class="grid grid-cols-2 py-2.5"><dt class="text-gray-500">Selection</dt><dd class="font-semibold capitalize">{{ form.winner_selection_mode }}</dd></div>
                <div class="grid grid-cols-2 py-2.5"><dt class="text-gray-500">Min credit score</dt><dd class="font-semibold">{{ form.min_credit_score || 'None' }}</dd></div>
                <div class="grid grid-cols-2 py-2.5"><dt class="text-gray-500">Approval required</dt><dd class="font-semibold">{{ form.require_approval ? 'Yes' : 'No' }}</dd></div>
            </dl>
            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 text-sm text-indigo-900">
                <div class="font-semibold mb-1">ℹ️ What happens after you create?</div>
                <ul class="text-xs text-indigo-800 space-y-0.5 list-disc pl-5">
                    <li>WISHI is created as a <strong>draft</strong> — it will NOT start immediately.</li>
                    <li>Invite and approve members until all {{ form.total_members }} seats are filled.</li>
                    <li>The "Start WISHI" button unlocks only after every member joins.</li>
                    <li>Starting locks the start date to that day and notifies every member.</li>
                </ul>
            </div>
        </div>

        <!-- Nav buttons -->
        <div class="flex items-center justify-between">
            <button v-if="step > 1" @click="step--" type="button" class="btn-secondary">← Back</button>
            <span v-else></span>
            <button v-if="step < 4" @click="step++" type="button" class="btn-primary">Next →</button>
            <button v-else @click="submit" :disabled="loading" type="button" class="btn-primary">
                <span v-if="loading">Creating…</span>
                <span v-else>Create WISHI</span>
            </button>
        </div>
    </div>
</template>
