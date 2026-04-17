<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useWishiStore } from '@/stores/wishi';
import { useAuthStore } from '@/stores/auth';
import { useBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useToast } from 'vue-toastification';
import { formatINR } from '@/utils/format';
import {
    CheckIcon, ChevronLeftIcon, ChevronRightIcon,
    UserGroupIcon, CurrencyRupeeIcon, CalendarDaysIcon,
    ClockIcon, SparklesIcon, ScaleIcon, ArrowPathIcon,
    ShieldCheckIcon, LinkIcon, PlusIcon, XMarkIcon,
} from '@heroicons/vue/24/outline';
import { CheckCircleIcon } from '@heroicons/vue/24/solid';

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
const currentStep = ref(1);

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
const totalPool = computed(() => Number(form.monthly_contribution || 0) * Number(form.total_members || 0));

const steps = [
    { id: 1, label: 'Basics',   desc: 'Name & members'   },
    { id: 2, label: 'Schedule', desc: 'When to run'      },
    { id: 3, label: 'Rules',    desc: 'How winners pick' },
];

const step1Valid = computed(() =>
    form.name.trim().length >= 2 &&
    form.total_members >= 2 && form.total_members <= 100 &&
    form.monthly_contribution > 0
);
const step2Valid = computed(() =>
    !!form.start_date && !!form.wishi_opening_time &&
    (form.cycle_frequency !== 'custom' || (form.cycle_interval_days >= 1 && form.cycle_interval_days <= 365))
);
const step3Valid = computed(() =>
    ['random', 'hybrid'].includes(form.cycle_type) &&
    (form.cycle_type !== 'hybrid' || form.hybrid_pattern.length > 0)
);

const canAdvance = computed(() => {
    if (currentStep.value === 1) return step1Valid.value;
    if (currentStep.value === 2) return step2Valid.value;
    return step3Valid.value;
});

// Indicator is clickable only for backwards jumps + the current step.
// Forward movement goes through the Next button so the validity gate fires.
function goStep(n) {
    if (n <= currentStep.value) currentStep.value = n;
}
function next() {
    if (! canAdvance.value) return;
    if (currentStep.value < 3) currentStep.value += 1;
    else submit();
}
function back() {
    if (currentStep.value > 1) currentStep.value -= 1;
}

function addPatternStep(value) {
    if (form.hybrid_pattern.length >= 24) return;
    form.hybrid_pattern.push(value);
}
function removePatternStep(idx) {
    form.hybrid_pattern.splice(idx, 1);
}
function togglePatternStep(idx) {
    form.hybrid_pattern[idx] = form.hybrid_pattern[idx] === 'random' ? 'tender' : 'random';
}

const frequencyOptions = [
    { value: 'daily',     label: 'Daily',     hint: 'One cycle per day' },
    { value: 'weekly',    label: 'Weekly',    hint: 'Every 7 days' },
    { value: 'monthly',   label: 'Monthly',   hint: 'Most common' },
    { value: 'quarterly', label: 'Quarterly', hint: 'Every 3 months' },
    { value: 'yearly',    label: 'Yearly',    hint: 'Once a year' },
    { value: 'custom',    label: 'Custom',    hint: 'Every N days' },
];

const cycleTypeOptions = [
    {
        value: 'random', label: 'Random',
        hint: 'Every cycle\'s winner is picked by a verifiable random draw. Simple and fair.',
        icon: ArrowPathIcon,
    },
    {
        value: 'hybrid', label: 'Hybrid',
        hint: 'Mix auto-random and tender cycles — you set the pattern below.',
        icon: ScaleIcon,
    },
];

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
            toast.error('Please fix the errors highlighted below.');
            // Jump to the step where the first error lives so the user sees it.
            const keys = Object.keys(errors.value);
            if (keys.some((k) => ['name', 'total_members', 'monthly_contribution'].includes(k))) currentStep.value = 1;
            else if (keys.some((k) => ['start_date', 'wishi_opening_time', 'cycle_frequency', 'cycle_interval_days'].includes(k))) currentStep.value = 2;
            else currentStep.value = 3;
        } else {
            toast.error(e.response?.data?.message || 'Failed to create WISHI.');
        }
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="max-w-3xl mx-auto">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900">Create a new WISHI</h1>
            <p class="text-sm text-slate-500 mt-1">Three quick steps — you can edit most settings later.</p>
        </div>

        <!-- ============ Step indicator ============ -->
        <ol class="flex items-start gap-0 mb-8" aria-label="Create WISHI progress">
            <li v-for="(s, i) in steps" :key="s.id" class="flex-1 flex items-start">
                <button
                    type="button"
                    @click="goStep(s.id)"
                    :disabled="s.id > currentStep"
                    :aria-current="currentStep === s.id ? 'step' : undefined"
                    class="flex items-start gap-3 text-left w-full focus:outline-none group disabled:cursor-default"
                >
                    <div
                        class="w-9 h-9 rounded-full flex items-center justify-center font-semibold text-sm transition shrink-0"
                        :class="[
                            currentStep === s.id ? 'bg-brand-600 text-white ring-4 ring-brand-100 shadow-sm shadow-brand-600/30'
                          : currentStep > s.id   ? 'bg-brand-600 text-white'
                          :                        'bg-slate-200 text-slate-500'
                        ]"
                    >
                        <CheckIcon v-if="currentStep > s.id" class="w-5 h-5" aria-hidden="true" />
                        <span v-else>{{ s.id }}</span>
                    </div>
                    <div class="mt-1 min-w-0 hidden sm:block">
                        <div class="text-sm font-semibold" :class="currentStep >= s.id ? 'text-slate-900' : 'text-slate-500'">{{ s.label }}</div>
                        <div class="text-xs text-slate-500">{{ s.desc }}</div>
                    </div>
                </button>
                <div
                    v-if="i < steps.length - 1"
                    class="flex-1 h-0.5 mt-4 mx-2 rounded-full"
                    :class="currentStep > s.id ? 'bg-brand-600' : 'bg-slate-200'"
                    aria-hidden="true"
                ></div>
            </li>
        </ol>

        <!-- ============ Step content ============ -->
        <form @submit.prevent="next" class="surface-padded space-y-6 animate-slide-up" :key="currentStep" novalidate>

            <!-- STEP 1: BASICS -->
            <template v-if="currentStep === 1">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                        <UserGroupIcon class="w-5 h-5 text-brand-600" aria-hidden="true" />
                        Basics
                    </h2>
                    <p class="text-sm text-slate-500 mt-1">What's your WISHI called and who's in it?</p>
                </div>

                <div class="space-y-5">
                    <div>
                        <label for="w-name" class="form-label">WISHI name</label>
                        <input id="w-name" v-model="form.name" type="text" autofocus class="form-input" placeholder="e.g. Mumbai Friends Pool" />
                        <p class="form-hint">Members will see this name everywhere.</p>
                        <p v-if="errors.name" class="form-error">{{ errors.name[0] }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="w-size" class="form-label">Total members (including you)</label>
                            <input id="w-size" v-model.number="form.total_members" type="number" min="2" max="100" class="form-input" />
                            <p class="form-hint">You hold seat #1 as organizer. You can invite <strong>{{ memberCapacity }}</strong> member{{ memberCapacity !== 1 ? 's' : '' }}.</p>
                            <p v-if="errors.total_members" class="form-error">{{ errors.total_members[0] }}</p>
                        </div>
                        <div>
                            <label for="w-contrib" class="form-label">Contribution per cycle (₹)</label>
                            <div class="relative">
                                <CurrencyRupeeIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" aria-hidden="true" />
                                <input id="w-contrib" v-model.number="form.monthly_contribution" type="number" min="1" class="form-input pl-9" />
                            </div>
                            <p class="form-hint">Each member pays this amount every cycle.</p>
                            <p v-if="errors.monthly_contribution" class="form-error">{{ errors.monthly_contribution[0] }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl bg-brand-50 border border-brand-200 p-4">
                        <div class="text-[11px] uppercase tracking-wide text-brand-700 font-semibold">Pool per cycle</div>
                        <div class="text-3xl font-bold text-brand-900 mt-1">{{ formatINR(totalPool) }}</div>
                        <p class="text-xs text-brand-800 mt-1.5">
                            {{ form.total_members }} members × {{ formatINR(form.monthly_contribution) }} contribution, over {{ form.total_members }} cycles.
                            Cycle #1 pool is your <strong>organizer payout</strong> — you still contribute every cycle.
                        </p>
                    </div>
                </div>
            </template>

            <!-- STEP 2: SCHEDULE -->
            <template v-else-if="currentStep === 2">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                        <CalendarDaysIcon class="w-5 h-5 text-brand-600" aria-hidden="true" />
                        Schedule
                    </h2>
                    <p class="text-sm text-slate-500 mt-1">When does the WISHI open and how often does a cycle run?</p>
                </div>

                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="w-start" class="form-label">Start date</label>
                            <input id="w-start" v-model="form.start_date" type="date" class="form-input" />
                            <p class="form-hint">Earliest day the WISHI can open (after all seats fill).</p>
                            <p v-if="errors.start_date" class="form-error">{{ errors.start_date[0] }}</p>
                        </div>
                        <div>
                            <label for="w-time" class="form-label">Daily cycle opening time</label>
                            <div class="relative">
                                <ClockIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" aria-hidden="true" />
                                <input id="w-time" v-model="form.wishi_opening_time" type="time" class="form-input pl-9" />
                            </div>
                            <p class="form-hint">Time each cycle (and tender bidding) opens every day.</p>
                            <p v-if="errors.wishi_opening_time" class="form-error">{{ errors.wishi_opening_time[0] }}</p>
                        </div>
                    </div>

                    <div>
                        <div class="form-label">Cycle frequency</div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            <label v-for="opt in frequencyOptions" :key="opt.value" class="cursor-pointer">
                                <input v-model="form.cycle_frequency" type="radio" :value="opt.value" class="sr-only peer" />
                                <div class="border-2 rounded-lg p-3 text-left transition peer-checked:border-brand-600 peer-checked:bg-brand-50 border-slate-200 hover:border-brand-300">
                                    <div class="font-semibold text-sm text-slate-900">{{ opt.label }}</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">{{ opt.hint }}</div>
                                </div>
                            </label>
                        </div>
                        <div v-if="form.cycle_frequency === 'custom'" class="mt-3">
                            <label for="w-interval" class="form-label">Every N days</label>
                            <input id="w-interval" v-model.number="form.cycle_interval_days" type="number" min="1" max="365" class="form-input max-w-48" />
                            <p v-if="errors.cycle_interval_days" class="form-error">{{ errors.cycle_interval_days[0] }}</p>
                        </div>
                    </div>
                </div>
            </template>

            <!-- STEP 3: RULES -->
            <template v-else-if="currentStep === 3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                        <SparklesIcon class="w-5 h-5 text-brand-600" aria-hidden="true" />
                        Rules
                    </h2>
                    <p class="text-sm text-slate-500 mt-1">How winners get picked and who can join.</p>
                </div>

                <div class="space-y-5">
                    <div>
                        <div class="form-label">Winner selection style</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <label v-for="opt in cycleTypeOptions" :key="opt.value" class="cursor-pointer">
                                <input v-model="form.cycle_type" type="radio" :value="opt.value" class="sr-only peer" />
                                <div class="border-2 rounded-lg p-4 transition peer-checked:border-brand-600 peer-checked:bg-brand-50 border-slate-200 hover:border-brand-300 h-full">
                                    <div class="flex items-center gap-2">
                                        <component :is="opt.icon" class="w-5 h-5 text-brand-600" aria-hidden="true" />
                                        <div class="font-semibold text-slate-900">{{ opt.label }}</div>
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-1.5">{{ opt.hint }}</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div v-if="form.cycle_type === 'hybrid'" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-center justify-between gap-2 flex-wrap mb-3">
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Cycle pattern</div>
                                <div class="text-xs text-slate-500">Click a cycle tile to flip between Random and Tender.</div>
                            </div>
                            <div class="text-xs text-slate-600 font-medium">
                                {{ form.hybrid_pattern.filter(p => p === 'random').length }} random ·
                                {{ form.hybrid_pattern.filter(p => p === 'tender').length }} tender
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            <div
                                v-for="(p, idx) in form.hybrid_pattern" :key="idx"
                                class="inline-flex items-center gap-1.5 rounded-lg border-2 pl-2 pr-1 py-1 text-xs font-semibold transition"
                                :class="p === 'random' ? 'border-brand-300 bg-white text-brand-700' : 'border-accent-300 bg-white text-accent-700'"
                            >
                                <button type="button" @click="togglePatternStep(idx)" class="inline-flex items-center gap-1.5 focus:outline-none" :title="`Flip to ${p === 'random' ? 'Tender' : 'Random'}`">
                                    <span class="text-[10px] text-slate-400">#{{ idx + 1 }}</span>
                                    <span>{{ p === 'random' ? 'Random' : 'Tender' }}</span>
                                </button>
                                <button type="button" @click="removePatternStep(idx)" class="w-5 h-5 rounded-full hover:bg-rose-100 text-slate-400 hover:text-rose-600 inline-flex items-center justify-center" :aria-label="`Remove cycle ${idx + 1}`">
                                    <XMarkIcon class="w-3 h-3" aria-hidden="true" />
                                </button>
                            </div>

                            <button type="button" @click="addPatternStep('random')" class="chip chip-default">
                                <PlusIcon class="w-3.5 h-3.5" aria-hidden="true" />
                                Random
                            </button>
                            <button type="button" @click="addPatternStep('tender')" class="chip chip-default">
                                <PlusIcon class="w-3.5 h-3.5" aria-hidden="true" />
                                Tender
                            </button>
                        </div>
                        <p class="text-[11px] text-slate-500 mt-3">The pattern repeats across all {{ form.total_members }} cycles.</p>
                    </div>

                    <div>
                        <div class="form-label">Who picks the winner?</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <label class="cursor-pointer">
                                <input v-model="form.winner_selection_mode" type="radio" value="auto" class="sr-only peer" />
                                <div class="border-2 rounded-lg p-3 transition peer-checked:border-brand-600 peer-checked:bg-brand-50 border-slate-200 hover:border-brand-300">
                                    <div class="font-semibold text-sm text-slate-900">Automatic</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">System picks — verifiable random draw or lowest tender bid.</div>
                                </div>
                            </label>
                            <label class="cursor-pointer">
                                <input v-model="form.winner_selection_mode" type="radio" value="manual" class="sr-only peer" />
                                <div class="border-2 rounded-lg p-3 transition peer-checked:border-brand-600 peer-checked:bg-brand-50 border-slate-200 hover:border-brand-300">
                                    <div class="font-semibold text-sm text-slate-900">Manual</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">Admin chooses from eligible members (reason is logged).</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
                        <div class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                            <ShieldCheckIcon class="w-4 h-4 text-brand-600" aria-hidden="true" />
                            Join rules
                        </div>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input v-model="form.require_approval" type="checkbox" class="rounded text-brand-600 focus:ring-brand-500 mt-0.5" />
                            <div class="min-w-0">
                                <div class="font-medium text-sm">Require admin approval for joiners</div>
                                <div class="text-xs text-slate-500">Requests land in your Dashboard pending-queue. Recommended for new admins.</div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input v-model="form.auto_join" type="checkbox" class="rounded text-brand-600 focus:ring-brand-500 mt-0.5" />
                            <div class="min-w-0">
                                <div class="font-medium text-sm flex items-center gap-1.5">
                                    <LinkIcon class="w-3.5 h-3.5 text-slate-400" aria-hidden="true" />
                                    Allow auto-join via shareable link
                                </div>
                                <div class="text-xs text-slate-500">Anyone with the link skips approval. Use carefully — share privately.</div>
                            </div>
                        </label>
                    </div>

                    <div class="rounded-xl bg-brand-50 border border-brand-200 p-4">
                        <div class="text-[11px] uppercase tracking-wide text-brand-700 font-semibold mb-2">Review</div>
                        <dl class="text-sm text-slate-800 space-y-1">
                            <div class="flex justify-between gap-2"><dt class="text-slate-600">WISHI</dt><dd class="font-semibold text-right truncate">{{ form.name || '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-slate-600">Pool / cycle</dt><dd class="font-semibold">{{ formatINR(totalPool) }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-slate-600">Members</dt><dd class="font-semibold">{{ form.total_members }} (incl. you)</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-slate-600">Frequency</dt><dd class="font-semibold capitalize">{{ form.cycle_frequency }}<span v-if="form.cycle_frequency === 'custom'"> · every {{ form.cycle_interval_days }}d</span></dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-slate-600">Style</dt><dd class="font-semibold capitalize">{{ form.cycle_type }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-slate-600">Winner picked</dt><dd class="font-semibold capitalize">{{ form.winner_selection_mode }}</dd></div>
                        </dl>
                    </div>
                </div>
            </template>

            <!-- ============ Nav ============ -->
            <div class="flex items-center justify-between gap-2 pt-4 border-t border-slate-100">
                <button v-if="currentStep > 1" type="button" @click="back" class="btn-secondary">
                    <ChevronLeftIcon class="w-4 h-4" aria-hidden="true" />
                    Back
                </button>
                <button v-else type="button" @click="router.push('/wishis')" class="btn-ghost">
                    Cancel
                </button>

                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-500 hidden sm:inline">Step {{ currentStep }} of 3</span>
                    <button v-if="currentStep < 3" type="submit" :disabled="! canAdvance" class="btn-primary">
                        Next step
                        <ChevronRightIcon class="w-4 h-4" aria-hidden="true" />
                    </button>
                    <button v-else type="submit" :disabled="loading || ! canAdvance" class="btn-primary">
                        <CheckCircleIcon v-if="!loading" class="w-4 h-4" aria-hidden="true" />
                        {{ loading ? 'Creating…' : 'Create WISHI' }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</template>
