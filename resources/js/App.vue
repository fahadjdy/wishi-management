<script setup>
import { useRoute } from 'vue-router';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { computed } from 'vue';

const route = useRoute();
const layout = computed(() => route.meta.layout || 'app');
</script>

<template>
    <AuthLayout v-if="layout === 'auth'">
        <router-view />
    </AuthLayout>
    <router-view v-else />
</template>
