<script setup>
import { RouterLink } from 'vue-router';
</script>

<template>
    <div class="min-h-screen flex flex-col items-center justify-center bg-gray-50 p-6">
        <div class="text-7xl font-bold text-indigo-600">404</div>
        <h1 class="text-2xl font-semibold mt-3 text-gray-900">Page not found</h1>
        <p class="text-gray-500 mt-2">The page you're looking for doesn't exist.</p>
        <RouterLink to="/dashboard" class="btn-primary mt-6">Go to Dashboard</RouterLink>
    </div>
</template>
