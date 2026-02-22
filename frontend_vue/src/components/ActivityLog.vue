<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const apiBaseUrl = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000'

const userName = ref('Utilisateur')
const userRole = ref('')
const logs = ref<any[]>([])
const isLoading = ref(true)
const errorMessage = ref('')
const filterAction = ref('')
const searchQuery = ref('')
const currentPage = ref(1)
const lastPage = ref(1)

// Monthly navigation
const availableMonths = ref<string[]>([])
const selectedMonth = ref('')

const actionTypes = [
  { value: '', label: 'Toutes les actions' },
  { value: 'login', label: 'Connexion' },
  { value: 'logout', label: 'Déconnexion' },
  { value: 'register', label: 'Inscription' },
  { value: 'login_failed', label: 'Échec connexion' },
  { value: 'delete_log', label: 'Suppression log' },
]

const actionMeta: Record<string, { icon: string; color: string; bg: string }> = {
  login:        { icon: '🔑', color: '#10b981', bg: '#ecfdf5' },
  logout:       { icon: '🚪', color: '#6366f1', bg: '#eef2ff' },
  register:     { icon: '🆕', color: '#3b82f6', bg: '#eff6ff' },
  login_failed: { icon: '⚠️', color: '#ef4444', bg: '#fef2f2' },
  delete_log:   { icon: '🗑️', color: '#dc2626', bg: '#fef2f2' },
  default:      { icon: '📋', color: '#64748b', bg: '#f8fafc' },
}

function getActionMeta(action: string) {
  return actionMeta[action] || actionMeta.default
}

function formatTime(dateStr: string) {
  const d = new Date(dateStr)
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function formatDate(dateStr: string) {
  const d = new Date(dateStr)
  return d.toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
}

function formatMonth(monthStr: string) {
  const [year, month] = monthStr.split('-')
  const d = new Date(Number(year), Number(month) - 1, 1)
  return d.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
}

function capitalizeFirst(s: string) {
  return s.charAt(0).toUpperCase() + s.slice(1)
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

// Group logs by date
const groupedLogs = computed(() => {
  const filtered = logs.value.filter((log) => {
    if (searchQuery.value) {
      const q = searchQuery.value.toLowerCase()
      const matchDesc = log.description?.toLowerCase().includes(q)
      const matchUser = log.user?.name?.toLowerCase().includes(q)
      if (!matchDesc && !matchUser) return false
    }
    return true
  })

  const groups: Record<string, any[]> = {}
  for (const log of filtered) {
    const dateKey = new Date(log.created_at).toISOString().split('T')[0]
    if (!groups[dateKey]) groups[dateKey] = []
    groups[dateKey].push(log)
  }
  return groups
})

const sortedDateKeys = computed(() => {
  return Object.keys(groupedLogs.value).sort((a, b) => b.localeCompare(a))
})

const totalLogs = computed(() => {
  return Object.values(groupedLogs.value).reduce((sum, arr) => sum + arr.length, 0)
})

async function fetchMonths() {
  try {
    const token = localStorage.getItem('auth_token')
    const response = await fetch(`${apiBaseUrl}/api/activity-logs/months`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })
    if (response.ok) {
      const data = await response.json()
      availableMonths.value = data || []
      // Default to current month
      const now = new Date()
      const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
      if (!selectedMonth.value) {
        selectedMonth.value = availableMonths.value.includes(currentMonth) ? currentMonth : (availableMonths.value[0] || currentMonth)
      }
    }
  } catch (e) {
    console.error('Failed to fetch months', e)
  }
}

async function fetchLogs(page = 1) {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const token = localStorage.getItem('auth_token')
    const params = new URLSearchParams()
    params.set('page', String(page))
    params.set('per_page', '200')
    if (filterAction.value) params.set('action', filterAction.value)
    if (selectedMonth.value) params.set('month', selectedMonth.value)

    const response = await fetch(`${apiBaseUrl}/api/activity-logs?${params}`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })

    if (!response.ok) throw new Error('Erreur lors du chargement')

    const data = await response.json()
    logs.value = data.data || []
    currentPage.value = data.current_page || 1
    lastPage.value = data.last_page || 1
  } catch (e: any) {
    errorMessage.value = e.message || 'Erreur inconnue'
  } finally {
    isLoading.value = false
  }
}

function selectMonth(month: string) {
  selectedMonth.value = month
  fetchLogs(1)
}

function navigateMonth(direction: number) {
  const idx = availableMonths.value.indexOf(selectedMonth.value)
  // availableMonths is sorted DESC, so +1 = newer, -1 = older
  const newIdx = idx - direction
  if (newIdx >= 0 && newIdx < availableMonths.value.length) {
    selectedMonth.value = availableMonths.value[newIdx]
    fetchLogs(1)
  }
}

const canGoNewer = computed(() => {
  const idx = availableMonths.value.indexOf(selectedMonth.value)
  return idx > 0
})

const canGoOlder = computed(() => {
  const idx = availableMonths.value.indexOf(selectedMonth.value)
  return idx < availableMonths.value.length - 1
})

function changePage(page: number) {
  if (page >= 1 && page <= lastPage.value) {
    fetchLogs(page)
  }
}

function applyFilter() {
  fetchLogs(1)
}

const canDelete = computed(() => {
  return ['super_admin', 'gerant'].includes(userRole.value)
})

// Selection mode
const selectionMode = ref(false)
const selectedIds = ref<Set<number>>(new Set())
const isDeleting = ref(false)

const selectedCount = computed(() => selectedIds.value.size)

const allVisibleSelected = computed(() => {
  const allIds = logs.value.map((l) => l.id)
  return allIds.length > 0 && allIds.every((id) => selectedIds.value.has(id))
})

function toggleSelectionMode() {
  selectionMode.value = !selectionMode.value
  if (!selectionMode.value) selectedIds.value = new Set()
}

function toggleSelect(logId: number) {
  const s = new Set(selectedIds.value)
  if (s.has(logId)) s.delete(logId)
  else s.add(logId)
  selectedIds.value = s
}

function toggleSelectAll() {
  if (allVisibleSelected.value) {
    selectedIds.value = new Set()
  } else {
    selectedIds.value = new Set(logs.value.map((l) => l.id))
  }
}

async function deleteLog(logId: number) {
  if (!confirm('Supprimer ce log ?')) return

  try {
    const token = localStorage.getItem('auth_token')
    const response = await fetch(`${apiBaseUrl}/api/activity-logs/${logId}`, {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })

    if (!response.ok) {
      const data = await response.json()
      alert(data.message || 'Erreur lors de la suppression')
      return
    }

    logs.value = logs.value.filter((l) => l.id !== logId)
    const s = new Set(selectedIds.value)
    s.delete(logId)
    selectedIds.value = s
  } catch (e) {
    alert('Erreur réseau lors de la suppression')
  }
}

async function deleteSelected() {
  const ids = Array.from(selectedIds.value)
  if (ids.length === 0) return
  if (!confirm(`Supprimer ${ids.length} log(s) sélectionné(s) ?`)) return

  isDeleting.value = true
  try {
    const token = localStorage.getItem('auth_token')
    const response = await fetch(`${apiBaseUrl}/api/activity-logs/bulk-delete`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ ids }),
    })

    if (!response.ok) {
      const data = await response.json()
      alert(data.message || 'Erreur lors de la suppression')
      return
    }

    logs.value = logs.value.filter((l) => !selectedIds.value.has(l.id))
    selectedIds.value = new Set()
    selectionMode.value = false
  } catch (e) {
    alert('Erreur réseau lors de la suppression')
  } finally {
    isDeleting.value = false
  }
}

function goBack() {
  router.push('/dashboard')
}

onMounted(async () => {
  const token = localStorage.getItem('auth_token')
  if (!token) {
    router.push('/')
    return
  }
  userRole.value = localStorage.getItem('auth_role') || ''
  userName.value = localStorage.getItem('auth_user_name') || 'Utilisateur'
  await fetchMonths()
  fetchLogs()
})
</script>

<template>
  <div class="activity-page">
    <!-- Header -->
    <header class="page-header">
      <div class="header-left">
        <button class="btn-back" @click="goBack">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="15 18 9 12 15 6" />
          </svg>
        </button>
        <div>
          <h1 class="header-title">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" class="title-icon">
              <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
              <polyline points="14 2 14 8 20 8" />
              <line x1="16" y1="13" x2="8" y2="13" />
              <line x1="16" y1="17" x2="8" y2="17" />
              <polyline points="10 9 9 9 8 9" />
            </svg>
            Journal des activités
          </h1>
          <p class="header-sub">Suivi de toutes les actions importantes</p>
        </div>
      </div>
      <div class="header-right">
        <span class="role-badge">{{ roleBadge }}</span>
      </div>
    </header>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-box">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" class="search-icon">
            <circle cx="11" cy="11" r="8" />
            <line x1="21" y1="21" x2="16.65" y2="16.65" />
          </svg>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Rechercher dans les logs..."
            class="search-input"
          />
        </div>
        <select v-model="filterAction" @change="applyFilter" class="filter-select">
          <option v-for="a in actionTypes" :key="a.value" :value="a.value">{{ a.label }}</option>
        </select>
      </div>
      <div class="toolbar-right">
        <button v-if="canDelete && !selectionMode" class="btn-select-mode" @click="toggleSelectionMode">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="9 11 12 14 22 4" />
            <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" />
          </svg>
          Sélectionner
        </button>
        <button v-if="canDelete && selectionMode" class="btn-cancel-select" @click="toggleSelectionMode">
          Annuler
        </button>
        <span class="log-count">{{ totalLogs }} entrée{{ totalLogs > 1 ? 's' : '' }}</span>
      </div>
    </div>

    <!-- Month Navigator -->
    <div class="month-navigator">
      <button class="month-nav-btn" :disabled="!canGoOlder" @click="navigateMonth(-1)" title="Mois précédent">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="15 18 9 12 15 6" />
        </svg>
      </button>
      <div class="month-tabs">
        <button
          v-for="month in availableMonths"
          :key="month"
          class="month-tab"
          :class="{ active: selectedMonth === month }"
          @click="selectMonth(month)"
        >
          <svg v-if="selectedMonth === month" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" class="month-icon">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
            <line x1="16" y1="2" x2="16" y2="6" />
            <line x1="8" y1="2" x2="8" y2="6" />
            <line x1="3" y1="10" x2="21" y2="10" />
          </svg>
          {{ capitalizeFirst(formatMonth(month)) }}
        </button>
      </div>
      <button class="month-nav-btn" :disabled="!canGoNewer" @click="navigateMonth(1)" title="Mois suivant">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="9 18 15 12 9 6" />
        </svg>
      </button>
    </div>

    <!-- Content -->
    <div class="content">
      <!-- Loading -->
      <div v-if="isLoading" class="state-card">
        <div class="spinner"></div>
        <p>Chargement des activités...</p>
      </div>

      <!-- Error -->
      <div v-else-if="errorMessage" class="state-card state-error">
        <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#ef4444" stroke-width="1.5">
          <circle cx="12" cy="12" r="10" />
          <line x1="15" y1="9" x2="9" y2="15" />
          <line x1="9" y1="9" x2="15" y2="15" />
        </svg>
        <p>{{ errorMessage }}</p>
        <button class="btn-retry" @click="fetchLogs(currentPage)">Réessayer</button>
      </div>

      <!-- Empty -->
      <div v-else-if="sortedDateKeys.length === 0" class="state-card state-empty">
        <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5">
          <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
          <polyline points="14 2 14 8 20 8" />
        </svg>
        <p>Aucune activité enregistrée</p>
      </div>

      <!-- Selection bar -->
      <div v-if="selectionMode && canDelete" class="selection-bar">
        <label class="select-all-toggle" @click="toggleSelectAll">
          <span class="custom-checkbox" :class="{ checked: allVisibleSelected }"></span>
          Tout sélectionner
        </label>
        <span class="selected-count">{{ selectedCount }} sélectionné{{ selectedCount > 1 ? 's' : '' }}</span>
        <button class="btn-delete-selected" :disabled="selectedCount === 0 || isDeleting" @click="deleteSelected">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6" />
            <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2" />
          </svg>
          {{ isDeleting ? 'Suppression...' : `Supprimer (${selectedCount})` }}
        </button>
      </div>

      <!-- Timeline -->
      <div v-if="sortedDateKeys.length > 0 && !isLoading" class="timeline">
        <div v-for="dateKey in sortedDateKeys" :key="dateKey" class="date-group">
          <div class="date-label">
            <span class="date-dot"></span>
            {{ formatDate(dateKey + 'T00:00:00') }}
          </div>
          <div class="log-list">
            <div
              v-for="log in groupedLogs[dateKey]"
              :key="log.id"
              class="log-entry"
              :class="{ 'log-selected': selectionMode && selectedIds.has(log.id) }"
              :style="{ '--accent': getActionMeta(log.action).color, '--accent-bg': getActionMeta(log.action).bg }"
              @click="selectionMode ? toggleSelect(log.id) : null"
            >
              <label v-if="selectionMode && canDelete" class="log-checkbox" @click.stop="toggleSelect(log.id)">
                <span class="custom-checkbox" :class="{ checked: selectedIds.has(log.id) }"></span>
              </label>
              <div class="log-icon">
                {{ getActionMeta(log.action).icon }}
              </div>
              <div class="log-body">
                <div class="log-main">
                  <span class="log-description">{{ log.description }}</span>
                  <span class="log-action-badge" :style="{ background: getActionMeta(log.action).bg, color: getActionMeta(log.action).color }">
                    {{ log.action }}
                  </span>
                  <button v-if="canDelete" class="btn-delete-log" @click.stop="deleteLog(log.id)" title="Supprimer ce log">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                      <polyline points="3 6 5 6 21 6" />
                      <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2" />
                    </svg>
                  </button>
                </div>
                <div class="log-meta">
                  <span class="log-time">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                      <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
                    </svg>
                    {{ formatTime(log.created_at) }}
                  </span>
                  <span v-if="log.user" class="log-user">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2" /><circle cx="12" cy="7" r="4" />
                    </svg>
                    {{ log.user.name }}
                  </span>
                  <span v-if="log.ip_address" class="log-ip">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
                      <rect x="2" y="3" width="20" height="14" rx="2" /><line x1="8" y1="21" x2="16" y2="21" /><line x1="12" y1="17" x2="12" y2="21" />
                    </svg>
                    {{ log.ip_address }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="lastPage > 1 && !isLoading" class="pagination">
        <button class="page-btn" :disabled="currentPage <= 1" @click="changePage(currentPage - 1)">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6" /></svg>
        </button>
        <span class="page-info">Page {{ currentPage }} / {{ lastPage }}</span>
        <button class="page-btn" :disabled="currentPage >= lastPage" @click="changePage(currentPage + 1)">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6" /></svg>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ===== PAGE ===== */
.activity-page {
  min-height: 100vh;
  background: #f1f5f9;
  font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
}

/* ===== HEADER ===== */
.page-header {
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
  gap: 12px;
}

.btn-back {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-back:hover {
  background: #f1f5f9;
  color: #0f172a;
  border-color: #cbd5e1;
}

.header-title {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.title-icon {
  color: #3b82f6;
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

/* ===== TOOLBAR ===== */
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 32px;
  gap: 16px;
  flex-wrap: wrap;
}

.toolbar-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1;
}

.search-box {
  position: relative;
  flex: 1;
  max-width: 360px;
}

.search-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
  pointer-events: none;
}

.search-input {
  width: 100%;
  padding: 10px 14px 10px 40px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
  font-size: 13px;
  color: #0f172a;
  outline: none;
  transition: all 0.2s;
  font-family: inherit;
}
.search-input:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.search-input::placeholder {
  color: #94a3b8;
}

.filter-select {
  padding: 10px 32px 10px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
  font-size: 13px;
  color: #0f172a;
  outline: none;
  cursor: pointer;
  font-family: inherit;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  transition: all 0.2s;
}
.filter-select:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.toolbar-right {
  display: flex;
  align-items: center;
}

.log-count {
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
  background: #fff;
  padding: 8px 16px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
}

/* ===== STATES ===== */
.state-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 64px 32px;
  margin: 0 32px;
  background: #fff;
  border-radius: 16px;
  text-align: center;
  color: #64748b;
  font-size: 15px;
}

.spinner {
  width: 36px;
  height: 36px;
  border: 3px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.btn-retry {
  padding: 8px 20px;
  background: #3b82f6;
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.2s;
  font-family: inherit;
}
.btn-retry:hover {
  background: #2563eb;
}

/* ===== TIMELINE ===== */
.content {
  padding: 0 32px 32px;
}

.timeline {
  position: relative;
}

.date-group {
  margin-bottom: 8px;
}

.date-label {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  font-weight: 600;
  color: #334155;
  text-transform: capitalize;
  padding: 12px 0 8px;
  position: sticky;
  top: 68px;
  background: #f1f5f9;
  z-index: 10;
}

.date-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: linear-gradient(135deg, #3b82f6, #2563eb);
  flex-shrink: 0;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
}

.log-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-left: 20px;
  border-left: 2px solid #e2e8f0;
  margin-left: 4px;
}

.log-entry {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 16px 20px;
  background: #fff;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  transition: all 0.2s;
  position: relative;
}
.log-entry::before {
  content: '';
  position: absolute;
  left: -22px;
  top: 22px;
  width: 10px;
  height: 2px;
  background: #e2e8f0;
}
.log-entry:hover {
  border-color: var(--accent, #3b82f6);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
  transform: translateX(4px);
}

.log-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: var(--accent-bg, #f8fafc);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}

.log-body {
  flex: 1;
  min-width: 0;
}

.log-main {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}

.log-description {
  font-size: 14px;
  font-weight: 500;
  color: #0f172a;
  flex: 1;
}

.log-action-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 20px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  white-space: nowrap;
}

.btn-delete-log {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  border: 1px solid #fecaca;
  background: #fff;
  color: #ef4444;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
  flex-shrink: 0;
  opacity: 0;
}
.log-entry:hover .btn-delete-log {
  opacity: 1;
}
.btn-delete-log:hover {
  background: #fef2f2;
  border-color: #f87171;
  transform: scale(1.1);
}

/* ===== SELECTION MODE ===== */
.btn-select-mode {
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
  font-family: inherit;
}
.btn-select-mode:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
}

.btn-cancel-select {
  padding: 8px 16px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
}
.btn-cancel-select:hover {
  background: #f1f5f9;
}

.selection-bar {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 20px;
  margin-bottom: 16px;
  background: linear-gradient(135deg, #fef2f2, #fff1f2);
  border: 1px solid #fecaca;
  border-radius: 12px;
  animation: slideDown 0.2s ease-out;
}

@keyframes slideDown {
  from { opacity: 0; transform: translateY(-8px); }
  to   { opacity: 1; transform: translateY(0); }
}

.select-all-toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 500;
  color: #334155;
  cursor: pointer;
  user-select: none;
}

.custom-checkbox {
  width: 18px;
  height: 18px;
  border-radius: 5px;
  border: 2px solid #cbd5e1;
  background: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s;
  flex-shrink: 0;
}
.custom-checkbox.checked {
  background: #ef4444;
  border-color: #ef4444;
}
.custom-checkbox.checked::after {
  content: '';
  width: 5px;
  height: 9px;
  border: solid #fff;
  border-width: 0 2px 2px 0;
  transform: rotate(45deg);
  margin-top: -1px;
}

.selected-count {
  font-size: 13px;
  color: #64748b;
  flex: 1;
}

.btn-delete-selected {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 20px;
  background: #ef4444;
  color: #fff;
  border: none;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
}
.btn-delete-selected:hover:not(:disabled) {
  background: #dc2626;
  box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
  transform: translateY(-1px);
}
.btn-delete-selected:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.log-checkbox {
  display: flex;
  align-items: center;
  cursor: pointer;
  flex-shrink: 0;
}

.log-entry.log-selected {
  background: #fef2f2;
  border-color: #fca5a5;
}

.log-meta {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}

.log-time,
.log-user,
.log-ip {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: #64748b;
}

/* ===== PAGINATION ===== */
.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 24px 0;
}

.page-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #334155;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
}
.page-btn:hover:not(:disabled) {
  background: #3b82f6;
  color: #fff;
  border-color: #3b82f6;
}
.page-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.page-info {
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
}

/* ===== MONTH NAVIGATOR ===== */
.month-navigator {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 32px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
}

.month-nav-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
  flex-shrink: 0;
}

.month-nav-btn:hover:not(:disabled) {
  background: #f1f5f9;
  color: #0f172a;
  border-color: #cbd5e1;
}

.month-nav-btn:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}

.month-tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  flex: 1;
  padding: 2px 0;
  scrollbar-width: none;
}

.month-tabs::-webkit-scrollbar {
  display: none;
}

.month-tab {
  padding: 8px 16px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #64748b;
  font-size: 13px;
  font-weight: 500;
  font-family: 'Inter', sans-serif;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 6px;
}

.month-tab:hover {
  background: #e8eef6;
  border-color: #cbd5e1;
  color: #1e40af;
}

.month-tab.active {
  background: linear-gradient(135deg, #3b82f6, #2563eb);
  color: #fff;
  border-color: transparent;
  box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.month-icon {
  flex-shrink: 0;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
  .page-header {
    padding: 14px 16px;
  }
  .toolbar {
    padding: 12px 16px;
    flex-direction: column;
    align-items: stretch;
  }
  .toolbar-left {
    flex-direction: column;
  }
  .search-box {
    max-width: 100%;
  }
  .month-navigator {
    padding: 10px 16px;
  }
  .content {
    padding: 0 16px 24px;
  }
  .log-entry {
    padding: 12px 14px;
  }
  .log-main {
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
  }
}
</style>
