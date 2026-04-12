<script setup>
import { onMounted, ref, computed } from 'vue';
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationStore } from '@/stores/notification';
import { useToast } from 'vue-toastification';
import Breadcrumbs from '@/components/Breadcrumbs.vue';

const auth = useAuthStore();
const notif = useNotificationStore();
const router = useRouter();
const toast = useToast();

const sidebarOpen = ref(false);
const profileOpen = ref(false);

const navItems = computed(() => {
    const items = [
        { name: 'Dashboard', to: '/dashboard', icon: 'grid' },
        { name: 'WISHIs', to: '/wishis', icon: 'layers' },
        { name: 'Notifications', to: '/notifications', icon: 'bell' },
        { name: 'Profile', to: '/profile', icon: 'user' },
    ];
    if (auth.user?.is_admin) {
        items.push({ name: 'Admin · Analytics', to: '/admin', icon: 'chart' });
        items.push({ name: 'Admin · Members', to: '/admin/users', icon: 'shield' });
    }
    return items;
});

const initials = computed(() => {
    if (!auth.user?.name) return '';
    return auth.user.name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
});

async function logout() {
    try {
        await auth.logout();
        toast.success('Logged out.');
        router.push({ name: 'login' });
    } catch {
        toast.error('Logout failed.');
    }
}

onMounted(() => {
    notif.fetch().catch(() => {});
});
</script>

<template>
    <div class="min-h-screen flex bg-gray-50">
        <!-- Sidebar -->
        <aside
            class="fixed lg:sticky top-0 left-0 z-40 h-screen w-64 bg-[#0f1538] text-gray-200 flex flex-col transition-transform duration-200"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        >
            <div class="px-5 py-5 flex items-center gap-2.5 border-b border-white/10">
                <div class="w-9 h-9 rounded-lg bg-indigo-500 flex items-center justify-center shadow-lg shadow-indigo-500/40">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 6v6c0 5 3.5 9.5 8 10 4.5-.5 8-5 8-10V6l-8-4z" /></svg>
                </div>
                <div>
                    <div class="text-white font-bold text-lg leading-none">WISHI</div>
                    <div class="text-[11px] uppercase tracking-wider text-indigo-300">Chit Fund Manager</div>
                </div>
            </div>

            <nav class="flex-1 px-3 py-5 space-y-1 overflow-y-auto">
                <RouterLink
                    v-for="item in navItems"
                    :key="item.to"
                    :to="item.to"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition"
                    active-class="bg-indigo-600 text-white shadow-md shadow-indigo-600/30"
                    :class="{ 'text-gray-300 hover:bg-white/5 hover:text-white': !($route.path.startsWith(item.to) && item.to !== '/dashboard') }"
                    @click="sidebarOpen = false"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <template v-if="item.icon === 'grid'"><rect x="3" y="3" width="7" height="7" rx="1.2" /><rect x="14" y="3" width="7" height="7" rx="1.2" /><rect x="3" y="14" width="7" height="7" rx="1.2" /><rect x="14" y="14" width="7" height="7" rx="1.2" /></template>
                        <template v-else-if="item.icon === 'layers'"><path d="m12 2 9 5-9 5-9-5 9-5z" /><path d="m3 12 9 5 9-5" /><path d="m3 17 9 5 9-5" /></template>
                        <template v-else-if="item.icon === 'bell'"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" /><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" /></template>
                        <template v-else-if="item.icon === 'user'"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></template>
                        <template v-else-if="item.icon === 'shield'"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /></template>
                        <template v-else-if="item.icon === 'chart'"><polyline points="3 3 3 21 21 21" /><path d="M7 15l4-6 4 3 5-7" /></template>
                    </svg>
                    <span class="flex-1">{{ item.name }}</span>
                    <span v-if="item.name === 'Notifications' && notif.unreadCount > 0" class="bg-red-500 text-white text-[10px] font-bold rounded-full px-1.5 py-0.5 min-w-[18px] text-center">
                        {{ notif.unreadCount }}
                    </span>
                </RouterLink>
            </nav>

            <div v-if="auth.user?.is_admin" class="px-4 py-4 border-t border-white/10">
                <RouterLink to="/wishis/create" class="block w-full text-center bg-indigo-500 hover:bg-indigo-400 text-white font-semibold py-2.5 rounded-lg text-sm transition">
                    + New WISHI
                </RouterLink>
            </div>
            <div v-else class="px-4 py-3 border-t border-white/10 text-center text-[11px] text-gray-400">
                Only platform admins can create WISHIs
            </div>
        </aside>

        <!-- Mobile overlay -->
        <div v-if="sidebarOpen" class="fixed inset-0 bg-black/40 z-30 lg:hidden" @click="sidebarOpen = false"></div>

        <!-- Main -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Topbar -->
            <header class="sticky top-0 z-20 bg-white border-b border-gray-200">
                <div class="px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <button class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-gray-100 shrink-0" @click="sidebarOpen = !sidebarOpen">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round" /></svg>
                        </button>
                        <Breadcrumbs class="min-w-0 flex-1" />
                    </div>
                    <div class="flex items-center gap-2">
                        <RouterLink to="/notifications" class="relative p-2 rounded-lg hover:bg-gray-100" title="Notifications">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" /><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" /></svg>
                            <span v-if="notif.unreadCount > 0" class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full ring-2 ring-white"></span>
                        </RouterLink>
                        <div class="relative">
                            <button class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-full hover:bg-gray-100" @click="profileOpen = !profileOpen">
                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white text-sm font-bold flex items-center justify-center">
                                    {{ initials }}
                                </div>
                                <div class="hidden sm:block text-left">
                                    <div class="text-sm font-semibold text-gray-900 leading-tight">{{ auth.user?.name }}</div>
                                    <div class="text-xs text-gray-500 leading-tight">{{ auth.user?.email }}</div>
                                </div>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-500 hidden sm:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9" /></svg>
                            </button>
                            <div v-if="profileOpen" class="absolute right-0 top-full mt-2 w-56 bg-white rounded-xl shadow-lg border border-gray-200 py-1 z-50" @click="profileOpen = false">
                                <RouterLink to="/profile" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Profile & Credit Score</RouterLink>
                                <button @click="logout" class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">Logout</button>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6">
                <RouterView />
            </main>
        </div>
    </div>
</template>
