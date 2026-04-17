<script setup>
import { useRoute } from 'vue-router';
import { computed } from 'vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import PromptModal from '@/components/PromptModal.vue';
import CredentialsModal from '@/components/CredentialsModal.vue';

const route = useRoute();
const layout = computed(() => route.meta.layout || 'app');
</script>

<template>
    <AuthLayout v-if="layout === 'auth'">
        <router-view />
    </AuthLayout>
    <router-view v-else />

    <!-- Global, promise-driven modals — one instance each, shown via ui store -->
    <ConfirmModal />
    <PromptModal />
    <CredentialsModal />
</template>
