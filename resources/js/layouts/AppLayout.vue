<script setup>
import { onMounted, computed } from 'vue';
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationStore } from '@/stores/notification';
import { useToast } from 'vue-toastification';
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue';
import {
    HomeIcon, RectangleStackIcon, BellIcon, UserCircleIcon,
    ShieldCheckIcon, PlusIcon, ArrowRightOnRectangleIcon, ChevronDownIcon,
} from '@heroicons/vue/24/outline';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import Logo from '@/components/Logo.vue';

const auth = useAuthStore();
const notif = useNotificationStore();
const router = useRouter();
const toast = useToast();

const isAdmin = computed(() => !!auth.user?.is_admin);

// Primary nav — same 4 items live on both the desktop sidebar and the mobile
// bottom bar so the mental model doesn't fracture across breakpoints.
const mainNav = computed(() => ([
    { name: 'Home',          to: '/dashboard',     icon: HomeIcon },
    { name: 'WISHIs',        to: '/wishis',        icon: RectangleStackIcon },
    { name: 'Notifications', to: '/notifications', icon: BellIcon, badge: notif.unreadCount },
    { name: 'Profile',       to: '/profile',       icon: UserCircleIcon },
]));

const initials = computed(() => {
    if (!auth.user?.name) return '';
    return auth.user.name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
});

async function logout() {
    try {
        await auth.logout();
        toast.success('Signed out.');
        router.push({ name: 'login' });
    } catch {
        toast.error('Could not sign out.');
    }
}

onMounted(() => { notif.fetch().catch(() => {}); });
</script>

<template>
    <div class="min-h-screen flex bg-slate-50">
        <!-- ================================================================
             DESKTOP SIDEBAR (≥ lg). Mobile users navigate via the bottom bar.
        ================================================================= -->
        <aside class="hidden lg:flex sticky top-0 h-screen w-64 shrink-0 flex-col bg-slate-900 text-slate-200 border-r border-slate-800">
            <div class="px-5 py-5 border-b border-white/10">
                <Logo variant="horizontal" size="md" mono />
            </div>

            <nav class="flex-1 px-3 py-5 space-y-1 overflow-y-auto" aria-label="Primary">
                <div class="px-2 mb-2 text-[10px] uppercase tracking-widest text-slate-400 font-bold">Main</div>
                <RouterLink
                    v-for="item in mainNav" :key="item.to" :to="item.to"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition text-slate-300 hover:bg-white/5 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                    active-class="!bg-brand-600/20 !text-white"
                >
                    <component :is="item.icon" class="w-5 h-5 shrink-0" aria-hidden="true" />
                    <span class="flex-1 truncate">{{ item.name }}</span>
                    <span v-if="item.badge > 0" class="bg-rose-500 text-white text-[10px] font-bold rounded-full px-1.5 py-0.5 min-w-5 text-center">{{ item.badge }}</span>
                </RouterLink>

                <template v-if="isAdmin">
                    <div class="px-2 mt-6 mb-2 text-[10px] uppercase tracking-widest text-slate-400 font-bold">Admin</div>
                    <RouterLink
                        to="/admin/users"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition text-slate-300 hover:bg-white/5 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        active-class="!bg-brand-600/20 !text-white"
                    >
                        <ShieldCheckIcon class="w-5 h-5 shrink-0" aria-hidden="true" />
                        <span class="flex-1 truncate">Members</span>
                    </RouterLink>
                </template>
            </nav>

            <div v-if="isAdmin" class="px-4 py-4 border-t border-white/10">
                <RouterLink to="/wishis/create" class="btn-primary btn-block">
                    <PlusIcon class="w-4 h-4" aria-hidden="true" />
                    New WISHI
                </RouterLink>
            </div>
            <div v-else class="px-4 py-4 border-t border-white/10 text-center text-[11px] text-slate-400">
                Only platform admins can create WISHIs
            </div>
        </aside>

        <!-- ================================================================
             MAIN COLUMN
        ================================================================= -->
        <div class="flex-1 flex flex-col min-w-0">
            <header class="sticky top-0 z-30 bg-white/90 backdrop-blur border-b border-slate-200">
                <div class="px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <!-- Mobile brand mark — bottom nav handles routing so no hamburger needed -->
                        <div class="lg:hidden shrink-0">
                            <Logo variant="horizontal" size="sm" />
                        </div>
                        <Breadcrumbs class="min-w-0 flex-1 hidden sm:flex" />
                    </div>

                    <div class="flex items-center gap-2">
                        <RouterLink to="/notifications" class="relative p-2 rounded-lg hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500" aria-label="Notifications">
                            <BellIcon class="w-5 h-5 text-slate-700" aria-hidden="true" />
                            <span v-if="notif.unreadCount > 0" class="absolute top-1.5 right-1.5 w-2 h-2 bg-rose-500 rounded-full ring-2 ring-white" aria-hidden="true"></span>
                        </RouterLink>

                        <Menu as="div" class="relative">
                            <MenuButton class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-full hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                <div class="w-9 h-9 rounded-full overflow-hidden bg-linear-to-br from-brand-500 to-brand-700 text-white text-sm font-bold flex items-center justify-center shrink-0">
                                    <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" :alt="auth.user.name" class="w-full h-full object-cover" />
                                    <span v-else>{{ initials }}</span>
                                </div>
                                <div class="hidden sm:block text-left">
                                    <div class="text-sm font-semibold text-slate-900 leading-tight truncate max-w-35">{{ auth.user?.name }}</div>
                                    <div class="text-xs text-slate-500 leading-tight truncate max-w-35">{{ auth.user?.email }}</div>
                                </div>
                                <ChevronDownIcon class="w-4 h-4 text-slate-500 hidden sm:block" aria-hidden="true" />
                            </MenuButton>
                            <transition
                                enter-active-class="transition ease-out duration-150"
                                enter-from-class="opacity-0 -translate-y-1 scale-95"
                                enter-to-class="opacity-100 translate-y-0 scale-100"
                                leave-active-class="transition ease-in duration-100"
                                leave-from-class="opacity-100"
                                leave-to-class="opacity-0"
                            >
                                <MenuItems class="absolute right-0 top-full mt-2 w-60 bg-white rounded-xl shadow-lg border border-slate-200 py-1 z-50 focus:outline-none origin-top-right">
                                    <div class="px-4 py-3 border-b border-slate-100 sm:hidden">
                                        <div class="font-semibold text-sm truncate">{{ auth.user?.name }}</div>
                                        <div class="text-xs text-slate-500 truncate">{{ auth.user?.email }}</div>
                                    </div>
                                    <MenuItem v-slot="{ active }">
                                        <RouterLink to="/profile" :class="[active && 'bg-slate-50']" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700">
                                            <UserCircleIcon class="w-4 h-4" aria-hidden="true" />
                                            Profile &amp; credit
                                        </RouterLink>
                                    </MenuItem>
                                    <MenuItem v-if="isAdmin" v-slot="{ active }">
                                        <RouterLink to="/admin/users" :class="[active && 'bg-slate-50']" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700">
                                            <ShieldCheckIcon class="w-4 h-4" aria-hidden="true" />
                                            Manage members
                                        </RouterLink>
                                    </MenuItem>
                                    <div class="my-1 border-t border-slate-100"></div>
                                    <MenuItem v-slot="{ active }">
                                        <button type="button" @click="logout" :class="[active && 'bg-rose-50']" class="flex items-center gap-2 w-full text-left px-4 py-2 text-sm text-rose-600">
                                            <ArrowRightOnRectangleIcon class="w-4 h-4" aria-hidden="true" />
                                            Sign out
                                        </button>
                                    </MenuItem>
                                </MenuItems>
                            </transition>
                        </Menu>
                    </div>
                </div>
                <Breadcrumbs class="sm:hidden px-4 pb-2" />
            </header>

            <!-- Page content — bottom padding on mobile reserves space for bottom nav -->
            <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6 pb-28 lg:pb-10">
                <RouterView />
            </main>
        </div>

        <!-- ================================================================
             MOBILE BOTTOM NAV (< lg)
        ================================================================= -->
        <nav class="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-slate-200 safe-bottom shadow-[0_-2px_8px_rgba(15,23,42,0.04)]" aria-label="Mobile navigation">
            <div class="relative">
                <div class="grid grid-cols-4">
                    <RouterLink
                        v-for="item in mainNav" :key="item.to" :to="item.to"
                        class="flex flex-col items-center justify-center gap-0.5 py-2.5 text-[11px] font-medium text-slate-500 hover:text-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:rounded-md min-h-14"
                        active-class="!text-brand-700"
                    >
                        <div class="relative">
                            <component :is="item.icon" class="w-6 h-6" aria-hidden="true" />
                            <span v-if="item.badge > 0" class="absolute -top-1 -right-2 bg-rose-500 text-white text-[9px] font-bold rounded-full px-1 min-w-4 h-4 flex items-center justify-center">{{ item.badge }}</span>
                        </div>
                        {{ item.name }}
                    </RouterLink>
                </div>
                <RouterLink v-if="isAdmin" to="/wishis/create"
                    class="absolute -top-7 left-1/2 -translate-x-1/2 w-14 h-14 rounded-full bg-brand-600 text-white shadow-lg shadow-brand-600/40 flex items-center justify-center hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand-500 ring-4 ring-slate-50"
                    aria-label="Create WISHI"
                >
                    <PlusIcon class="w-7 h-7" aria-hidden="true" />
                </RouterLink>
            </div>
        </nav>
    </div>
</template>
