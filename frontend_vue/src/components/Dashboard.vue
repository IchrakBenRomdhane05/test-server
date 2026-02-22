<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Bar, Doughnut, Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler
)

const router = useRouter()
const apiBaseUrl = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000'

const userName = ref('Utilisateur')
const userRole = ref('')

onMounted(() => {
  const token = localStorage.getItem('auth_token')
  if (!token) {
    router.push('/')
    return
  }
  userRole.value = localStorage.getItem('auth_role') || ''
  userName.value = localStorage.getItem('auth_user_name') || 'Utilisateur'
})

async function logout() {
  const token = localStorage.getItem('auth_token')
  try {
    await fetch(`${apiBaseUrl}/api/auth/logout`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
    })
  } catch (e) {
    // proceed with logout even if API call fails
  }
  localStorage.removeItem('auth_token')
  localStorage.removeItem('auth_role')
  localStorage.removeItem('auth_user_name')
  router.push('/')
}

const roleBadge = computed(() => {
  const map: Record<string, string> = {
    super_admin: 'Super Admin',
    gerant: 'Gérant',
    caissier: 'Caissier',
    employe: 'Employé',
    comptable: 'Comptable',
  }
  return map[userRole.value] || userRole.value
})

// KPI data
const kpis = ref([
  { label: 'Ventes du jour', value: '24 350 DT', icon: 'sale', change: '+12%', up: true },
  { label: 'Nouveaux clients', value: '18', icon: 'clients', change: '+5%', up: true },
  { label: 'Stock faible', value: '7 produits', icon: 'stock', change: '-3', up: false },
])

// Weekly sales chart
const weeklySalesData = ref({
  labels: ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'],
  datasets: [
    {
      label: 'Ventes (DT)',
      data: [3200, 4100, 2800, 5200, 4800, 6100, 3500],
      backgroundColor: 'rgba(59, 130, 246, 0.15)',
      borderColor: '#3b82f6',
      borderWidth: 2,
      fill: true,
      tension: 0.4,
      pointBackgroundColor: '#3b82f6',
      pointRadius: 4,
    },
  ],
})

const weeklySalesOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    title: { display: false },
  },
  scales: {
    y: {
      beginAtZero: true,
      grid: { color: 'rgba(0,0,0,0.04)' },
      ticks: { font: { size: 11 }, color: '#94a3b8' },
    },
    x: {
      grid: { display: false },
      ticks: { font: { size: 11 }, color: '#94a3b8' },
    },
  },
}

// Top products chart
const topProductsData = ref({
  labels: ['Café Premium', 'Thé Vert', 'Jus Orange', 'Eau Minérale', 'Croissant'],
  datasets: [
    {
      label: 'Quantité vendue',
      data: [120, 95, 80, 150, 65],
      backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#06b6d4', '#8b5cf6'],
      borderRadius: 6,
      borderSkipped: false,
    },
  ],
})

const topProductsOptions = {
  responsive: true,
  maintainAspectRatio: false,
  indexAxis: 'y' as const,
  plugins: {
    legend: { display: false },
  },
  scales: {
    x: {
      beginAtZero: true,
      grid: { color: 'rgba(0,0,0,0.04)' },
      ticks: { font: { size: 11 }, color: '#94a3b8' },
    },
    y: {
      grid: { display: false },
      ticks: { font: { size: 12 }, color: '#334155' },
    },
  },
}

// Categories chart
const categoriesData = ref({
  labels: ['Boissons', 'Pâtisserie', 'Snacks', 'Produits laitiers', 'Autres'],
  datasets: [
    {
      data: [35, 20, 18, 15, 12],
      backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
      borderWidth: 0,
      hoverOffset: 8,
    },
  ],
})

const categoriesOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom' as const,
      labels: { padding: 16, usePointStyle: true, pointStyle: 'circle', font: { size: 12 } },
    },
  },
}
</script>

<template>
  <div class="dashboard">
    <!-- Header -->
    <header class="dash-header">
      <div class="header-left">
        <img src="/logo.png" alt="Sotetel" class="header-logo" />
        <div>
          <h1 class="header-title">Dashboard</h1>
          <p class="header-sub">Bienvenue, {{ userName }}</p>
        </div>
      </div>
      <div class="header-right">
        <router-link to="/activity-log" class="btn-nav">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="16" y1="13" x2="8" y2="13" />
            <line x1="16" y1="17" x2="8" y2="17" />
          </svg>
          Journal
        </router-link>
        <span class="role-badge">{{ roleBadge }}</span>
        <button class="btn-logout" @click="logout">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" />
            <polyline points="16 17 21 12 16 7" />
            <line x1="21" y1="12" x2="9" y2="12" />
          </svg>
          Déconnexion
        </button>
      </div>
    </header>

    <!-- KPIs -->
    <section class="kpi-row">
      <div v-for="(kpi, i) in kpis" :key="i" class="kpi-card" :class="`kpi-${kpi.icon}`">
        <div class="kpi-icon-wrap">
          <!-- Ventes icon -->
          <svg v-if="kpi.icon === 'sale'" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6" />
          </svg>
          <!-- Clients icon -->
          <svg v-if="kpi.icon === 'clients'" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" /><circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 00-3-3.87" /><path d="M16 3.13a4 4 0 010 7.75" />
          </svg>
          <!-- Stock icon -->
          <svg v-if="kpi.icon === 'stock'" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" />
            <polyline points="3.27 6.96 12 12.01 20.73 6.96" /><line x1="12" y1="22.08" x2="12" y2="12" />
          </svg>
        </div>
        <div class="kpi-info">
          <span class="kpi-label">{{ kpi.label }}</span>
          <span class="kpi-value">{{ kpi.value }}</span>
        </div>
        <span class="kpi-change" :class="kpi.up ? 'up' : 'down'">{{ kpi.change }}</span>
      </div>
    </section>

    <!-- Charts -->
    <section class="charts-grid">
      <!-- Weekly Sales -->
      <div class="chart-card chart-wide">
        <h2 class="chart-title">Ventes par semaine</h2>
        <div class="chart-wrap">
          <Line :data="weeklySalesData" :options="weeklySalesOptions" />
        </div>
      </div>

      <!-- Top Products -->
      <div class="chart-card">
        <h2 class="chart-title">Top Produits</h2>
        <div class="chart-wrap">
          <Bar :data="topProductsData" :options="topProductsOptions" />
        </div>
      </div>

      <!-- Categories -->
      <div class="chart-card">
        <h2 class="chart-title">Catégories</h2>
        <div class="chart-wrap chart-donut">
          <Doughnut :data="categoriesData" :options="categoriesOptions" />
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
/* ===== LAYOUT ===== */
.dashboard {
  min-height: 100vh;
  background: #f1f5f9;
  padding: 0;
  font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
}

/* ===== HEADER ===== */
.dash-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 32px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  position: sticky;
  top: 0;
  z-index: 50;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.header-logo {
  height: 40px;
  width: auto;
}

.header-title {
  font-size: 22px;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
}

.header-sub {
  font-size: 13px;
  color: #64748b;
  margin: 2px 0 0;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 14px;
}

.role-badge {
  padding: 6px 14px;
  background: linear-gradient(135deg, #3b82f6, #2563eb);
  color: #fff;
  font-size: 12px;
  font-weight: 600;
  border-radius: 20px;
  letter-spacing: 0.3px;
}

.btn-nav {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #334155;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  text-decoration: none;
}
.btn-nav:hover {
  background: #eff6ff;
  color: #2563eb;
  border-color: #bfdbfe;
}

.btn-logout {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-logout:hover {
  background: #fef2f2;
  color: #dc2626;
  border-color: #fecaca;
}

/* ===== KPI CARDS ===== */
.kpi-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
  padding: 28px 32px 0;
}

.kpi-card {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 24px;
  background: #ffffff;
  border-radius: 16px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 0 0 1px rgba(0, 0, 0, 0.02);
  position: relative;
  overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
}
.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
}

.kpi-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 4px;
  height: 100%;
}
.kpi-sale::before { background: #3b82f6; }
.kpi-clients::before { background: #10b981; }
.kpi-stock::before { background: #f59e0b; }

.kpi-icon-wrap {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.kpi-sale .kpi-icon-wrap { background: #eff6ff; color: #3b82f6; }
.kpi-clients .kpi-icon-wrap { background: #ecfdf5; color: #10b981; }
.kpi-stock .kpi-icon-wrap { background: #fffbeb; color: #f59e0b; }

.kpi-info {
  display: flex;
  flex-direction: column;
  flex: 1;
}

.kpi-label {
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
  margin-bottom: 4px;
}

.kpi-value {
  font-size: 24px;
  font-weight: 700;
  color: #0f172a;
}

.kpi-change {
  font-size: 12px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 20px;
  align-self: flex-start;
}
.kpi-change.up { background: #ecfdf5; color: #059669; }
.kpi-change.down { background: #fef2f2; color: #dc2626; }

/* ===== CHARTS ===== */
.charts-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  padding: 24px 32px 32px;
}

.chart-card {
  background: #ffffff;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 0 0 1px rgba(0, 0, 0, 0.02);
}

.chart-wide {
  grid-column: 1 / -1;
}

.chart-title {
  font-size: 16px;
  font-weight: 600;
  color: #0f172a;
  margin: 0 0 16px;
}

.chart-wrap {
  height: 280px;
  position: relative;
}

.chart-donut {
  height: 300px;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 1024px) {
  .kpi-row {
    grid-template-columns: 1fr;
    padding: 20px 16px 0;
  }
  .charts-grid {
    grid-template-columns: 1fr;
    padding: 20px 16px 24px;
  }
  .dash-header {
    padding: 14px 16px;
    flex-wrap: wrap;
    gap: 12px;
  }
}
</style>
