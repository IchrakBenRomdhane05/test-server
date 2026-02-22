import { createRouter, createWebHistory } from 'vue-router'
import LoginSignup from '../components/LoginSignup.vue'
import Dashboard from '../components/Dashboard.vue'
import ActivityLog from '../components/ActivityLog.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'login',
      component: LoginSignup,
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: Dashboard,
      meta: { requiresAuth: true },
    },
    {
      path: '/activity-log',
      name: 'activity-log',
      component: ActivityLog,
      meta: { requiresAuth: true },
    },
  ],
})

// Navigation guard: redirect to login if not authenticated
router.beforeEach((to, _from, next) => {
  const token = localStorage.getItem('auth_token')
  if (to.meta.requiresAuth && !token) {
    next({ name: 'login' })
  } else if (to.name === 'login' && token) {
    next({ name: 'dashboard' })
  } else {
    next()
  }
})

export default router
