import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/auth/Login.vue'),
        meta: { layout: 'auth', guest: true },
    },
    // Self-registration removed — members are created by platform admins.
    // Any old /register bookmark redirects to login.
    { path: '/register', redirect: '/login' },
    {
        path: '/',
        component: () => import('@/layouts/AppLayout.vue'),
        meta: { requiresAuth: true },
        children: [
            { path: '', redirect: '/dashboard' },
            { path: 'dashboard', name: 'dashboard', component: () => import('@/pages/Dashboard.vue') },
            { path: 'wishis', name: 'wishis.index', component: () => import('@/pages/wishis/Index.vue') },
            { path: 'wishis/create', name: 'wishis.create', component: () => import('@/pages/wishis/Create.vue'), meta: { requiresAdmin: true } },

            // WISHI detail — all sub-tabs render the same Show.vue component.
            // Tab shown is controlled by route.meta.tab so deep-linking works.
            { path: 'wishis/:uuid', name: 'wishis.show', component: () => import('@/pages/wishis/Show.vue'), meta: { tab: 'overview' } },
            { path: 'wishis/:uuid/cycles', name: 'wishis.cycles', component: () => import('@/pages/wishis/Show.vue'), meta: { tab: 'cycles' } },
            { path: 'wishis/:uuid/members', name: 'wishis.members', component: () => import('@/pages/wishis/Show.vue'), meta: { tab: 'members', requiresWishiAdmin: true } },
            { path: 'wishis/:uuid/settings', name: 'wishis.settings', component: () => import('@/pages/wishis/Show.vue'), meta: { tab: 'settings', requiresWishiAdmin: true } },
            { path: 'wishis/:uuid/audit-log', name: 'wishis.audit', component: () => import('@/pages/wishis/Show.vue'), meta: { tab: 'audit', requiresWishiAdmin: true } },

            // Cycle drill-down stays its own page.
            { path: 'wishis/:uuid/cycles/:cycleId', name: 'wishis.cycle', component: () => import('@/pages/wishis/CycleDetail.vue') },

            { path: 'profile', name: 'profile', component: () => import('@/pages/Profile.vue') },
            { path: 'notifications', name: 'notifications', component: () => import('@/pages/Notifications.vue') },
            { path: 'admin/users', name: 'admin.users', component: () => import('@/pages/admin/Users.vue'), meta: { requiresAdmin: true } },
            { path: 'admin/users/:id', name: 'admin.users.show', component: () => import('@/pages/admin/UserDetail.vue'), meta: { requiresAdmin: true } },
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'notfound',
        component: () => import('@/pages/NotFound.vue'),
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior() {
        return { top: 0 };
    },
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.initialized) {
        await auth.fetchUser();
    }
    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }
    if (to.meta.guest && auth.isAuthenticated) {
        return { name: 'dashboard' };
    }
    if (to.meta.requiresAdmin && ! auth.user?.is_admin) {
        return { name: 'dashboard' };
    }
});

export default router;
