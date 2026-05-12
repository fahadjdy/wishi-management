<script setup>
import { RouterLink } from 'vue-router';
</script>

<template>
    <div class="min-h-screen flex flex-col items-center justify-center p-6 paper-tex" style="background-color: #FAF5EC;">
        <div class="display text-9xl text-brand-600 leading-none">404<span class="text-brand-400">.</span></div>
        <h1 class="display text-3xl mt-4 text-slate-900">Page not found</h1>
        <p class="text-slate-500 mt-2">The page you're looking for doesn't exist.</p>
        <RouterLink to="/dashboard" class="btn-primary btn-lg mt-7">Go to Dashboard</RouterLink>
    </div>
</template>
