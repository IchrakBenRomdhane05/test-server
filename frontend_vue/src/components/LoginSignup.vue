<script setup lang="ts">
import { ref, reactive, computed, nextTick, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

const isSignUp = ref(false)
const showSignInPassword = ref(false)
const showSignUpPassword = ref(false)
const showMfaVerification = ref(false)
const showForgotPassword = ref(false)

const mfaUserId = ref<number | null>(null)
const mfaEmail = ref('')
const isRegistrationOtp = ref(false) // true = registration flow, false = login flow

const roles = [
  { value: 'super_admin', label: 'Super Admin' },
  { value: 'gerant', label: 'Gérant' },
  { value: 'caissier', label: 'Caissier' },
  { value: 'employe', label: 'Employé' },
  { value: 'comptable', label: 'Comptable' },
]

const apiBaseUrl = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000'

const signInForm = reactive({
  email: '',
  password: '',
})

const signUpForm = reactive({
  name: '',
  email: '',
  password: '',
  passwordConfirmation: '',
  role: '',
})

const signInError = ref('')
const signInSuccess = ref('')
const signUpError = ref('')
const mfaError = ref('')
const isSigningIn = ref(false)
const isSigningUp = ref(false)

// Forgot password state
const forgotStep = ref<'email' | 'otp' | 'newpass'>('email')
const forgotEmail = ref('')
const forgotUserId = ref<number | null>(null)
const forgotError = ref('')
const forgotSuccess = ref('')
const isSendingReset = ref(false)
const isResettingPassword = ref(false)
const forgotOtpDigits = ref(['', '', '', '', '', ''])
const forgotOtpRefs = ref<(HTMLInputElement | null)[]>([])
const newPassword = ref('')
const newPasswordConfirm = ref('')
const showNewPassword = ref(false)
const forgotOtpCode = computed(() => forgotOtpDigits.value.join(''))
const forgotTimeLeft = ref(285)
let forgotTimerInterval: ReturnType<typeof setInterval> | null = null
const forgotResendCooldown = ref(0)
let forgotCooldownInterval: ReturnType<typeof setInterval> | null = null

const forgotFormattedTime = computed(() => {
  const min = Math.floor(forgotTimeLeft.value / 60)
  const sec = forgotTimeLeft.value % 60
  return `${min}:${sec.toString().padStart(2, '0')}`
})

function startForgotTimer() {
  forgotTimeLeft.value = 285
  if (forgotTimerInterval) clearInterval(forgotTimerInterval)
  forgotTimerInterval = setInterval(() => {
    if (forgotTimeLeft.value > 0) {
      forgotTimeLeft.value--
    } else {
      if (forgotTimerInterval) clearInterval(forgotTimerInterval)
    }
  }, 1000)
}

function startForgotResendCooldown() {
  forgotResendCooldown.value = 60
  if (forgotCooldownInterval) clearInterval(forgotCooldownInterval)
  forgotCooldownInterval = setInterval(() => {
    forgotResendCooldown.value--
    if (forgotResendCooldown.value <= 0) {
      if (forgotCooldownInterval) clearInterval(forgotCooldownInterval)
    }
  }, 1000)
}

// Lockout state
const isLockedOut = ref(false)
const lockoutSeconds = ref(0)
const attemptsLeft = ref<number | null>(null)
let lockoutInterval: ReturnType<typeof setInterval> | null = null

const lockoutFormatted = computed(() => {
  const min = Math.floor(lockoutSeconds.value / 60)
  const sec = lockoutSeconds.value % 60
  return `${min}:${sec.toString().padStart(2, '0')}`
})

function startLockoutTimer(seconds: number) {
  isLockedOut.value = true
  lockoutSeconds.value = seconds
  if (lockoutInterval) clearInterval(lockoutInterval)
  lockoutInterval = setInterval(() => {
    lockoutSeconds.value--
    if (lockoutSeconds.value <= 0) {
      isLockedOut.value = false
      signInError.value = ''
      if (lockoutInterval) clearInterval(lockoutInterval)
    }
  }, 1000)
}

const isVerifyingMfa = ref(false)
const isResending = ref(false)


// ========= OTP State =========
const otpDigits = ref(['', '', '', '', '', ''])
const otpInputRefs = ref<(HTMLInputElement | null)[]>([])
const timeLeft = ref(300) // 5 minutes
const resendCooldown = ref(0)
let timerInterval: ReturnType<typeof setInterval> | null = null
let cooldownInterval: ReturnType<typeof setInterval> | null = null

const otpCode = computed(() => otpDigits.value.join(''))

const maskedEmail = computed(() => {
  if (!mfaEmail.value) return ''
  const [name, domain] = mfaEmail.value.split('@')
  const masked = name.substring(0, 2) + '***'
  return `${masked}@${domain}`
})

const formattedTime = computed(() => {
  const min = Math.floor(timeLeft.value / 60)
  const sec = timeLeft.value % 60
  return `${min}:${sec.toString().padStart(2, '0')}`
})

function startTimer() {
  timeLeft.value = 285 // 4:45 — slightly less than server's 5min to avoid desync
  if (timerInterval) clearInterval(timerInterval)
  timerInterval = setInterval(() => {
    if (timeLeft.value > 0) {
      timeLeft.value--
    } else {
      if (timerInterval) clearInterval(timerInterval)
    }
  }, 1000)
}

function startResendCooldown() {
  resendCooldown.value = 60
  if (cooldownInterval) clearInterval(cooldownInterval)
  cooldownInterval = setInterval(() => {
    resendCooldown.value--
    if (resendCooldown.value <= 0) {
      if (cooldownInterval) clearInterval(cooldownInterval)
    }
  }, 1000)
}

function setOtpRef(el: any, index: number) {
  otpInputRefs.value[index] = el
}

function handleOtpInput(index: number, event: Event) {
  const input = event.target as HTMLInputElement
  const value = input.value

  // Only allow single digit
  if (!/^\d$/.test(value)) {
    otpDigits.value[index] = ''
    return
  }

  otpDigits.value[index] = value

  // Move to next input
  if (value && index < 5) {
    nextTick(() => {
      otpInputRefs.value[index + 1]?.focus()
    })
  }

  // Auto-submit when all 6 digits entered
  if (otpCode.value.length === 6) {
    handleMfaVerification()
  }
}

function handleOtpKeydown(index: number, event: KeyboardEvent) {
  if (event.key === 'Backspace' && !otpDigits.value[index] && index > 0) {
    nextTick(() => {
      otpInputRefs.value[index - 1]?.focus()
    })
  }
}

function handleOtpPaste(event: ClipboardEvent) {
  event.preventDefault()
  const pasted = event.clipboardData?.getData('text').replace(/\D/g, '').slice(0, 6) || ''

  for (let i = 0; i < 6; i++) {
    otpDigits.value[i] = pasted[i] || ''
  }

  const nextEmpty = otpDigits.value.findIndex(d => !d)
  const focusIndex = nextEmpty === -1 ? 5 : nextEmpty
  nextTick(() => {
    otpInputRefs.value[focusIndex]?.focus()
  })

  if (pasted.length === 6) {
    handleMfaVerification()
  }
}

function resetOtp() {
  otpDigits.value = ['', '', '', '', '', '']
  nextTick(() => {
    otpInputRefs.value[0]?.focus()
  })
}


async function handleSignIn() {
  signInError.value = ''

  signInSuccess.value = ''

  isSigningIn.value = true

  try {
    const response = await fetch(`${apiBaseUrl}/api/auth/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        email: signInForm.email,
        password: signInForm.password,
      }),
    })

    const data = await response.json()

    if (!response.ok) {
      // Check if account is locked
      if (response.status === 429 && data?.locked) {
        startLockoutTimer(data.retry_after || 300)
        signInError.value = ''
        return
      }

      // Track remaining attempts
      if (data?.attempts_left !== undefined) {
        attemptsLeft.value = data.attempts_left
      }

      if (data?.errors) {
        signInError.value = Object.values(data.errors).flat().join(' ')
      } else {
        signInError.value = data?.message || 'Sign in failed.'
      }
      return
    }

    // Successful login clears lockout state
    attemptsLeft.value = null
    isLockedOut.value = false


    // Check if MFA is required
    if (data?.mfa_required) {
      isRegistrationOtp.value = false // This is login flow
      showMfaVerification.value = true
      mfaUserId.value = data.user_id
      mfaEmail.value = data.email || signInForm.email
      signInError.value = ''
      startTimer()
      nextTick(() => {
        otpInputRefs.value[0]?.focus()
      })
      // In development, show the code (remove in production)
      if (data.dev_code) {
        console.log('MFA Code (DEV ONLY):', data.dev_code)
      }
      return
    }


    if (data?.token) {
      localStorage.setItem('auth_token', data.token)
      localStorage.setItem('auth_role', data.role || '')
      localStorage.setItem('auth_user_name', data.user?.name || '')
      router.push('/dashboard')
    }
  } catch (error) {
    console.error(error)
    signInError.value = 'Unable to sign in. Please try again.'
  } finally {
    isSigningIn.value = false
  }
}

//

async function handleMfaVerification() {
  if (otpCode.value.length !== 6 || isVerifyingMfa.value) return

  mfaError.value = ''
  isVerifyingMfa.value = true

  // Choose endpoint based on flow type
  const endpoint = isRegistrationOtp.value
    ? `${apiBaseUrl}/api/auth/verify-registration`
    : `${apiBaseUrl}/api/auth/verify-mfa`

  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        user_id: mfaUserId.value,
        code: otpCode.value,
      }),
    })

    const data = await response.json()

    if (!response.ok) {
      mfaError.value = data?.message || 'Verification failed.'
      resetOtp()
      return
    }

    // Registration flow: redirect to sign-in (don't auto-login)
    if (isRegistrationOtp.value) {
      showMfaVerification.value = false
      isRegistrationOtp.value = false
      mfaUserId.value = null
      mfaEmail.value = ''
      otpDigits.value = ['', '', '', '', '', '']

      // Switch to sign-in panel
      isSignUp.value = false

      // Pre-fill email for convenience
      signInForm.email = data.user?.email || ''
      signInForm.password = ''

      // Show success message
      signInError.value = ''
      signInSuccess.value = 'Inscription réussie ! Veuillez vous connecter.'

      if (timerInterval) clearInterval(timerInterval)
      if (cooldownInterval) clearInterval(cooldownInterval)
      return
    }

    // Login flow: go to dashboard
    if (data?.token) {
      localStorage.setItem('auth_token', data.token)
      localStorage.setItem('auth_role', data.role || '')
      localStorage.setItem('auth_user_name', data.user?.name || '')
      router.push('/dashboard')
    }
  } catch (error) {
    console.error(error)
    mfaError.value = 'Unable to verify code. Please try again.'
    resetOtp()
  } finally {
    isVerifyingMfa.value = false
  }
}

async function resendMfaCode() {
  if (resendCooldown.value > 0 || isResending.value) return

  isResending.value = true
  mfaError.value = ''

  // Choose endpoint based on flow type
  const endpoint = isRegistrationOtp.value
    ? `${apiBaseUrl}/api/auth/resend-registration-otp`
    : `${apiBaseUrl}/api/auth/resend-mfa`

  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        user_id: mfaUserId.value,
      }),
    })

    const data = await response.json()

    if (!response.ok) {
      mfaError.value = data?.message || 'Unable to resend code.'
      return
    }

    // Reset timer and inputs
    startTimer()
    startResendCooldown()
    resetOtp()

    if (data.dev_code) {
      console.log('MFA Code (DEV ONLY):', data.dev_code)
    }
  } catch (error) {
    console.error(error)
    mfaError.value = 'Unable to resend code. Please try again.'
  } finally {
    isResending.value = false
  }
}

function cancelMfa() {
  showMfaVerification.value = false
  mfaUserId.value = null
  mfaEmail.value = ''
  mfaError.value = ''
  otpDigits.value = ['', '', '', '', '', '']

  // Reset form based on flow type
  if (isRegistrationOtp.value) {
    // Reset sign up form
    signUpForm.name = ''
    signUpForm.email = ''
    signUpForm.password = ''
    signUpForm.passwordConfirmation = ''
    signUpForm.role = ''
  } else {
    signInForm.password = ''
  }

  isRegistrationOtp.value = false
  if (timerInterval) clearInterval(timerInterval)
  if (cooldownInterval) clearInterval(cooldownInterval)
}

// ========= Forgot Password =========
function setForgotOtpRef(el: any, index: number) {
  forgotOtpRefs.value[index] = el
}

function handleForgotOtpInput(index: number, event: Event) {
  const input = event.target as HTMLInputElement
  const value = input.value
  if (!/^\d$/.test(value)) {
    forgotOtpDigits.value[index] = ''
    return
  }
  forgotOtpDigits.value[index] = value
  if (value && index < 5) {
    nextTick(() => { forgotOtpRefs.value[index + 1]?.focus() })
  }
  if (forgotOtpCode.value.length === 6) {
    // Don't auto-submit, user still needs to enter new password
  }
}

function handleForgotOtpKeydown(index: number, event: KeyboardEvent) {
  if (event.key === 'Backspace' && !forgotOtpDigits.value[index] && index > 0) {
    nextTick(() => { forgotOtpRefs.value[index - 1]?.focus() })
  }
}

function handleForgotOtpPaste(event: ClipboardEvent) {
  event.preventDefault()
  const pasted = event.clipboardData?.getData('text').replace(/\D/g, '').slice(0, 6) || ''
  for (let i = 0; i < 6; i++) {
    forgotOtpDigits.value[i] = pasted[i] || ''
  }
  const nextEmpty = forgotOtpDigits.value.findIndex(d => !d)
  const focusIndex = nextEmpty === -1 ? 5 : nextEmpty
  nextTick(() => { forgotOtpRefs.value[focusIndex]?.focus() })
}

async function handleForgotSubmitEmail() {
  forgotError.value = ''
  forgotSuccess.value = ''
  isSendingReset.value = true
  try {
    const response = await fetch(`${apiBaseUrl}/api/auth/forgot-password`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ email: forgotEmail.value }),
    })
    const data = await response.json()
    if (!response.ok) {
      forgotError.value = data?.message || 'Erreur.'
      return
    }
    if (data.user_id) {
      forgotUserId.value = data.user_id
      forgotStep.value = 'otp'
      startForgotTimer()
      nextTick(() => { forgotOtpRefs.value[0]?.focus() })
      if (data.dev_code) {
        console.log('Forgot Password OTP (DEV):', data.dev_code)
      }
    } else {
      forgotSuccess.value = data.message
    }
  } catch (e) {
    forgotError.value = 'Erreur réseau. Réessayez.'
  } finally {
    isSendingReset.value = false
  }
}

async function handleResetPassword() {
  forgotError.value = ''
  if (forgotOtpCode.value.length !== 6) {
    forgotError.value = 'Veuillez entrer le code à 6 chiffres.'
    return
  }
  if (newPassword.value.length < 8) {
    forgotError.value = 'Le mot de passe doit contenir au moins 8 caractères.'
    return
  }
  if (newPassword.value !== newPasswordConfirm.value) {
    forgotError.value = 'Les mots de passe ne correspondent pas.'
    return
  }
  isResettingPassword.value = true
  try {
    const response = await fetch(`${apiBaseUrl}/api/auth/reset-password`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        user_id: forgotUserId.value,
        code: forgotOtpCode.value,
        password: newPassword.value,
        password_confirmation: newPasswordConfirm.value,
      }),
    })
    const data = await response.json()
    if (!response.ok) {
      forgotError.value = data?.message || 'Erreur.'
      return
    }
    // Success: go back to sign in
    closeForgotPassword()
    signInSuccess.value = data.message || 'Mot de passe réinitialisé. Connectez-vous.'
    signInForm.email = forgotEmail.value
    signInForm.password = ''
  } catch (e) {
    forgotError.value = 'Erreur réseau. Réessayez.'
  } finally {
    isResettingPassword.value = false
  }
}

async function resendForgotOtp() {
  if (forgotResendCooldown.value > 0 || isResending.value) return
  isResending.value = true
  forgotError.value = ''
  try {
    const response = await fetch(`${apiBaseUrl}/api/auth/resend-forgot-otp`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ user_id: forgotUserId.value }),
    })
    const data = await response.json()
    if (!response.ok) {
      forgotError.value = data?.message || 'Impossible de renvoyer le code.'
      return
    }
    startForgotTimer()
    startForgotResendCooldown()
    forgotOtpDigits.value = ['', '', '', '', '', '']
    nextTick(() => { forgotOtpRefs.value[0]?.focus() })
    if (data.dev_code) console.log('Forgot OTP (DEV):', data.dev_code)
  } catch (e) {
    forgotError.value = 'Erreur réseau.'
  } finally {
    isResending.value = false
  }
}

function openForgotPassword() {
  showForgotPassword.value = true
  forgotStep.value = 'email'
  forgotEmail.value = signInForm.email || ''
  forgotError.value = ''
  forgotSuccess.value = ''
  forgotUserId.value = null
  forgotOtpDigits.value = ['', '', '', '', '', '']
  newPassword.value = ''
  newPasswordConfirm.value = ''
}

function closeForgotPassword() {
  showForgotPassword.value = false
  forgotStep.value = 'email'
  forgotError.value = ''
  forgotSuccess.value = ''
  forgotUserId.value = null
  forgotOtpDigits.value = ['', '', '', '', '', '']
  newPassword.value = ''
  newPasswordConfirm.value = ''
  if (forgotTimerInterval) clearInterval(forgotTimerInterval)
  if (forgotCooldownInterval) clearInterval(forgotCooldownInterval)
}

async function handleSignUp() {
  signUpError.value = ''
  isSigningUp.value = true

  try {
    const response = await fetch(`${apiBaseUrl}/api/auth/register`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        name: signUpForm.name,
        email: signUpForm.email,
        password: signUpForm.password,
        password_confirmation: signUpForm.passwordConfirmation,
        role: signUpForm.role,
      }),
    })

    const data = await response.json()

    if (!response.ok) {
      if (data?.errors) {
        signUpError.value = Object.values(data.errors).flat().join(' ')
      } else {
        signUpError.value = data?.message || 'Sign up failed.'
      }
      return
    }

      // Check if OTP verification is required
    if (data?.mfa_required) {
      isRegistrationOtp.value = true
      showMfaVerification.value = true
      mfaUserId.value = data.user_id
      mfaEmail.value = data.email || signUpForm.email
      signUpError.value = ''
      startTimer()
      nextTick(() => {
        otpInputRefs.value[0]?.focus()
      })
      // In development, show the code (remove in production)
      if (data.dev_code) {
        console.log('Registration OTP Code (DEV ONLY):', data.dev_code)
      }
      return
    }

    if (data?.token) {
      localStorage.setItem('auth_token', data.token)
      localStorage.setItem('auth_role', data.role || '')
      localStorage.setItem('auth_user_name', data.user?.name || '')
      router.push('/dashboard')
    }
  } catch (error) {
    console.error(error)
    signUpError.value = 'Unable to sign up. Please try again.'
  } finally {
    isSigningUp.value = false
  }
}

onUnmounted(() => {
  if (timerInterval) clearInterval(timerInterval)
  if (cooldownInterval) clearInterval(cooldownInterval)
  if (lockoutInterval) clearInterval(lockoutInterval)
  if (forgotTimerInterval) clearInterval(forgotTimerInterval)
  if (forgotCooldownInterval) clearInterval(forgotCooldownInterval)
})
</script>

<template>
  <div class="auth-page">
    <!-- Logo -->
    <div class="logo-wrapper">
      <img src="/logo.png" alt="Sotetel - Smart Enabler" />
    </div>

    <div class="container" :class="{ 'right-panel-active': isSignUp }">

    <!-- Forgot Password Modal -->
    <div v-if="showForgotPassword" class="mfa-modal-overlay">
      <div class="mfa-modal">
        <!-- Step 1: Enter Email -->
        <template v-if="forgotStep === 'email'">
          <div class="mfa-icon">
            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#0066cc" stroke-width="1.5">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0110 0v4" />
              <circle cx="12" cy="16" r="1" fill="#0066cc" />
            </svg>
          </div>
          <div class="mfa-header">
            <h2>Mot de passe oublié</h2>
            <p>Entrez votre adresse email pour recevoir un code de vérification</p>
          </div>
          <form @submit.prevent="handleForgotSubmitEmail">
            <div class="input-group" style="margin-bottom: 16px;">
              <input
                type="email"
                placeholder="Adresse email"
                v-model="forgotEmail"
                required
              />
              <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                <polyline points="22,6 12,13 2,6" />
              </svg>
            </div>
            <p v-if="forgotError" class="form-error mfa-error-msg">{{ forgotError }}</p>
            <p v-if="forgotSuccess" class="form-success">{{ forgotSuccess }}</p>
            <button type="submit" class="btn-submit mfa-verify-btn" :disabled="isSendingReset || !forgotEmail">
              <span v-if="isSendingReset" class="btn-spinner"></span>
              {{ isSendingReset ? 'Envoi...' : 'Envoyer le code' }}
            </button>
            <button type="button" class="mfa-back-btn" @click="closeForgotPassword">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12" />
                <polyline points="12 19 5 12 12 5" />
              </svg>
              Retour à la connexion
            </button>
          </form>
        </template>

        <!-- Step 2: Enter OTP + New Password -->
        <template v-if="forgotStep === 'otp'">
          <div class="mfa-icon">
            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#0066cc" stroke-width="1.5">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
              <path d="M9 12l2 2 4-4" stroke="#0066cc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </div>
          <div class="mfa-header">
            <h2>Réinitialisation</h2>
            <p>Entrez le code envoyé à <strong>{{ forgotEmail }}</strong> puis votre nouveau mot de passe</p>
          </div>
          <form @submit.prevent="handleResetPassword">
            <div class="otp-boxes" style="margin-bottom: 16px;">
              <input
                v-for="(digit, index) in forgotOtpDigits"
                :key="index"
                :ref="(el) => setForgotOtpRef(el, index)"
                :value="forgotOtpDigits[index]"
                type="text"
                inputmode="numeric"
                maxlength="1"
                class="otp-box"
                :class="{ 'otp-box-filled': forgotOtpDigits[index], 'otp-box-error': forgotError }"
                @input="handleForgotOtpInput(index, $event)"
                @keydown="handleForgotOtpKeydown(index, $event)"
                @paste="handleForgotOtpPaste($event)"
              />
            </div>

            <!-- Timer -->
            <div class="mfa-timer" v-if="forgotTimeLeft > 0">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10" />
                <polyline points="12 6 12 12 16 14" />
              </svg>
              Code expire dans <strong>{{ forgotFormattedTime }}</strong>
            </div>
            <div class="mfa-timer mfa-timer-expired" v-else>
              Code expiré. Veuillez renvoyer un nouveau code.
            </div>

            <div class="input-group" style="margin-top: 16px; margin-bottom: 12px;">
              <input
                :type="showNewPassword ? 'text' : 'password'"
                placeholder="Nouveau mot de passe"
                v-model="newPassword"
                required
                minlength="8"
              />
              <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0110 0v4" />
              </svg>
            </div>
            <div class="input-group" style="margin-bottom: 12px;">
              <input
                :type="showNewPassword ? 'text' : 'password'"
                placeholder="Confirmer le mot de passe"
                v-model="newPasswordConfirm"
                required
                minlength="8"
              />
              <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0110 0v4" />
              </svg>
            </div>

            <p v-if="forgotError" class="form-error mfa-error-msg">{{ forgotError }}</p>

            <button
              type="submit"
              class="btn-submit mfa-verify-btn"
              :disabled="isResettingPassword || forgotOtpCode.length !== 6 || !newPassword || !newPasswordConfirm || forgotTimeLeft <= 0"
            >
              <span v-if="isResettingPassword" class="btn-spinner"></span>
              {{ isResettingPassword ? 'Réinitialisation...' : 'Réinitialiser le mot de passe' }}
            </button>

            <!-- Resend -->
            <div class="mfa-resend">
              Pas reçu le code ?
              <button type="button" class="mfa-resend-btn" :disabled="forgotResendCooldown > 0 || isResending" @click="resendForgotOtp">
                {{ isResending ? 'Envoi...' : forgotResendCooldown > 0 ? `Renvoyer (${forgotResendCooldown}s)` : 'Renvoyer le code' }}
              </button>
            </div>

            <button type="button" class="mfa-back-btn" @click="closeForgotPassword">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12" />
                <polyline points="12 19 5 12 12 5" />
              </svg>
              Retour à la connexion
            </button>
          </form>
        </template>
      </div>
    </div>

     <!-- MFA Verification Modal -->
    <div v-if="showMfaVerification" class="mfa-modal-overlay">
      <div class="mfa-modal">
        <!-- Shield Icon -->
        <div class="mfa-icon">
          <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#0066cc" stroke-width="1.5">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
            <path d="M9 12l2 2 4-4" stroke="#0066cc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </div>

        <div class="mfa-header">
          <h2>{{ isRegistrationOtp ? 'Email Verification' : 'Two-Factor Authentication' }}</h2>
          <p>Enter the 6-digit code sent to <strong>{{ maskedEmail }}</strong></p>
        </div>

        <!-- 6 Separate OTP Boxes -->
        <form @submit.prevent="handleMfaVerification">
          <div class="otp-boxes">
            <input
              v-for="(digit, index) in otpDigits"
              :key="index"
              :ref="(el) => setOtpRef(el, index)"
              :value="otpDigits[index]"
              type="text"
              inputmode="numeric"
              maxlength="1"
              class="otp-box"
              :class="{
                'otp-box-filled': otpDigits[index],
                'otp-box-error': mfaError
              }"
              @input="handleOtpInput(index, $event)"
              @keydown="handleOtpKeydown(index, $event)"
              @paste="handleOtpPaste($event)"
            />
          </div>

          <!-- Timer -->
          <div class="mfa-timer" v-if="timeLeft > 0">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10" />
              <polyline points="12 6 12 12 16 14" />
            </svg>
            Code expires in <strong>{{ formattedTime }}</strong>
          </div>
          <div class="mfa-timer mfa-timer-expired" v-else>
            Code expired. Please resend a new code.
          </div>

          <!-- Error -->
          <p v-if="mfaError" class="form-error mfa-error-msg">{{ mfaError }}</p>

          <!-- Verify Button -->
          <button
            type="submit"
            class="btn-submit mfa-verify-btn"
            :disabled="isVerifyingMfa || otpCode.length !== 6 || timeLeft <= 0"
          >
            <span v-if="isVerifyingMfa" class="btn-spinner"></span>
            {{ isVerifyingMfa ? 'Verifying...' : 'Verify Code' }}
          </button>

          <!-- Resend -->
          <div class="mfa-resend">
            Didn't receive the code?
            <button
              type="button"
              class="mfa-resend-btn"
              :disabled="resendCooldown > 0 || isResending"
              @click="resendMfaCode"
            >
              {{ isResending ? 'Sending...' : resendCooldown > 0 ? `Resend (${resendCooldown}s)` : 'Resend code' }}
            </button>
          </div>

          <!-- Cancel -->
          <button type="button" class="mfa-back-btn" @click="cancelMfa">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="19" y1="12" x2="5" y2="12" />
              <polyline points="12 19 5 12 12 5" />
            </svg>
            Back to Sign In
          </button>
        </form>
      </div>
    </div>

      <!-- Sign In Form -->
      <div class="form-container sign-in-container">
        <form @submit.prevent="handleSignIn">
          <h1>Sign In</h1>
          <p class="subtitle">Welcome back! Please enter your details</p>
          <div class="social-container">
            <a href="#" aria-label="Sign in with Google">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" />
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
              </svg>
            </a>
            <a href="#" aria-label="Sign in with Facebook">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
              </svg>
            </a>
            <a href="#" aria-label="Sign in with GitHub">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
              </svg>
            </a>
            <a href="#" aria-label="Sign in with LinkedIn">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" />
              </svg>
            </a>
          </div>
          <div class="divider"><span>or</span></div>
          <div class="input-group">
            <input
              type="email"
              placeholder="Email address"
              v-model="signInForm.email"
              required
            />
            <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
              <polyline points="22,6 12,13 2,6" />
            </svg>
          </div>
          <div class="input-group">
            <input
              :type="showSignInPassword ? 'text' : 'password'"
              placeholder="Password"
              v-model="signInForm.password"
              required
            />
            <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0110 0v4" />
            </svg>
          </div>
          <a href="#" class="forgot-link" @click.prevent="openForgotPassword">Forgot your password?</a>

          <!-- Lockout Banner -->
          <div v-if="isLockedOut" class="lockout-banner">
            <div class="lockout-icon">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0110 0v4" />
                <circle cx="12" cy="16" r="1" fill="currentColor" />
              </svg>
            </div>
            <div class="lockout-text">
              <span class="lockout-title">Compte temporairement bloqué</span>
              <span class="lockout-msg">Trop de tentatives échouées. Réessayez dans</span>
            </div>
            <div class="lockout-timer">{{ lockoutFormatted }}</div>
          </div>

          <!-- Attempts Warning -->
          <div v-if="!isLockedOut && attemptsLeft !== null && attemptsLeft <= 2 && attemptsLeft > 0" class="attempts-warning">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
              <line x1="12" y1="9" x2="12" y2="13" />
              <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
            {{ attemptsLeft }} tentative{{ attemptsLeft > 1 ? 's' : '' }} restante{{ attemptsLeft > 1 ? 's' : '' }} avant le blocage
          </div>

          <p v-if="signInSuccess" class="form-success">{{ signInSuccess }}</p>
          <p v-if="signInError && !isLockedOut" class="form-error">{{ signInError }}</p>
          <button type="submit" class="btn-submit" :disabled="isSigningIn || isLockedOut">
            <template v-if="isLockedOut">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink: 0;">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0110 0v4" />
              </svg>
              Bloqué ({{ lockoutFormatted }})
            </template>
            <template v-else>
              {{ isSigningIn ? 'Signing In...' : 'Sign In' }}
            </template>
          </button>
        </form>
      </div>

      <!-- Sign Up Form -->
      <div class="form-container sign-up-container">
        <form @submit.prevent="handleSignUp">
          <h1>Create Account</h1>
          <p class="subtitle">Start your journey with us today</p>
          <div class="social-container">
            <a href="#" aria-label="Sign up with Google">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" />
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
              </svg>
            </a>
            <a href="#" aria-label="Sign up with Facebook">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
              </svg>
            </a>
            <a href="#" aria-label="Sign up with GitHub">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
              </svg>
            </a>
            <a href="#" aria-label="Sign up with LinkedIn">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" />
              </svg>
            </a>
          </div>
          <div class="divider"><span>or</span></div>
          <div class="input-group">
            <input
              type="text"
              placeholder="Full name"
              v-model="signUpForm.name"
              required
            />
            <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2" />
              <circle cx="12" cy="7" r="4" />
            </svg>
          </div>
          <div class="input-group">
            <input
              type="email"
              placeholder="Email address"
              v-model="signUpForm.email"
              required
            />
            <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
              <polyline points="22,6 12,13 2,6" />
            </svg>
          </div>
          <div class="input-group">
            <input
              :type="showSignUpPassword ? 'text' : 'password'"
              placeholder="Password"
              v-model="signUpForm.password"
              required
            />
            <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0110 0v4" />
            </svg>
          </div>
          <div class="input-group">
            <input
              :type="showSignUpPassword ? 'text' : 'password'"
              placeholder="Confirm password"
              v-model="signUpForm.passwordConfirmation"
              required
            />
            <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0110 0v4" />
            </svg>
          </div>
          <div class="input-group">
            <select v-model="signUpForm.role" required>
              <option value="" disabled selected>Select your role</option>
              <option v-for="role in roles" :key="role.value" :value="role.value">
                {{ role.label }}
              </option>
            </select>
            <svg class="input-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
              <circle cx="8.5" cy="7" r="4" />
              <line x1="20" y1="8" x2="20" y2="14" />
              <line x1="23" y1="11" x2="17" y2="11" />
            </svg>
          </div>
          <p v-if="signUpError" class="form-error">{{ signUpError }}</p>
          <button type="submit" class="btn-submit" style="margin-top: 8px" :disabled="isSigningUp">
            {{ isSigningUp ? 'Signing Up...' : 'Sign Up' }}
          </button>
        </form>
      </div>

      <!-- Overlay -->
      <div class="overlay-container">
        <div class="overlay">
          <div class="overlay-panel overlay-left">
            <div class="decoration decoration-1"></div>
            <div class="decoration decoration-2"></div>
            <h1>Welcome Back!</h1>
            <p>Already have an account? Sign in to continue your journey with us.</p>
            <button class="btn-ghost" @click="isSignUp = false">Sign In</button>
          </div>
          <div class="overlay-panel overlay-right">
            <div class="decoration decoration-1"></div>
            <div class="decoration decoration-3"></div>
            <h1>Hello, Friend!</h1>
            <p>Don't have an account yet? Join us and start your amazing journey.</p>
            <button class="btn-ghost" @click="isSignUp = true">Sign Up</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ======= PAGE LAYOUT ======= */
.auth-page {
  width: 100%;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  overflow-y: auto;
}

/* ======= LOGO ======= */
.logo-wrapper {
  margin-bottom: 24px;
  animation: fadeInDown 0.6s ease-out;
}

.logo-wrapper img {
  height: 56px;
  width: auto;
}

@keyframes fadeInDown {
  from {
    opacity: 0;
    transform: translateY(-20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ======= CONTAINER ======= */
.container {
  position: relative;
  width: 900px;
  max-width: 95vw;
  min-height: 520px;
  background: #ffffff;
  border-radius: 20px;
  box-shadow: 0 20px 60px rgba(0, 50, 100, 0.12), 0 0 0 1px rgba(0, 80, 160, 0.06);
  overflow: hidden;
  animation: fadeInUp 0.8s ease-out;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ======= FORM PANELS ======= */
.form-container {
  position: absolute;
  top: 0;
  height: 100%;
  transition: all 0.65s cubic-bezier(0.68, -0.15, 0.265, 1.15);
}

.sign-in-container {
  left: 0;
  width: 50%;
  z-index: 2;
}

.container.right-panel-active .sign-in-container {
  transform: translateX(100%);
  opacity: 0;
  z-index: 1;
}

.sign-up-container {
  left: 0;
  width: 50%;
  opacity: 0;
  z-index: 1;
}

.container.right-panel-active .sign-up-container {
  transform: translateX(100%);
  opacity: 1;
  z-index: 5;
}

/* ======= FORM STYLING ======= */
form {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  padding: 0 48px;
  text-align: center;
  background: #ffffff;
}

form h1 {
  font-size: 28px;
  font-weight: 700;
  color: #1a2b4a;
  margin-bottom: 8px;
  letter-spacing: -0.5px;
}

form p.subtitle {
  font-size: 13px;
  color: #6b7c93;
  margin-bottom: 24px;
}

/* Social Buttons */
.social-container {
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
}

.social-container a {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #e0e7ef;
  background: #f7f9fc;
  color: #6b7c93;
  text-decoration: none;
  transition: all 0.3s ease;
  cursor: pointer;
}

.social-container a:hover {
  background: #e8eef6;
  color: #0066cc;
  border-color: #b8cce0;
  transform: translateY(-2px);
}

/* Divider */
.divider {
  display: flex;
  align-items: center;
  width: 100%;
  margin-bottom: 18px;
  gap: 12px;
}

.divider::before,
.divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: #e0e7ef;
}

.divider span {
  font-size: 11px;
  color: #98a8b8;
  text-transform: uppercase;
  letter-spacing: 1px;
}

/* Input Fields */
.input-group {
  position: relative;
  width: 100%;
  margin-bottom: 14px;
}

.input-icon {
  position: absolute;
  left: 16px;
  top: 50%;
  transform: translateY(-50%);
  color: #98a8b8;
  transition: color 0.3s ease;
  z-index: 1;
  pointer-events: none;
}

.input-group input {
  width: 100%;
  padding: 14px 16px 14px 44px;
  background: #f4f7fa;
  border: 1px solid #e0e7ef;
  border-radius: 12px;
  color: #1a2b4a;
  font-size: 14px;
  font-family: 'Inter', sans-serif;
  outline: none;
  transition: all 0.3s ease;
}

.form-error {
  width: 100%;
  margin: 6px 0 0;
  font-size: 12px;
  color: #c62828;
  text-align: left;
}


.form-success {
  width: 100%;
  margin: 6px 0 0;
  padding: 10px 14px;
  font-size: 13px;
  color: #2e7d32;
  background: #e8f5e9;
  border-radius: 8px;
  text-align: center;
  border: 1px solid #a5d6a7;
}

.input-group input::placeholder {
  color: #98a8b8;
}

.input-group input:focus {
  border-color: #0066cc;
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
}

.input-group input:focus ~ .input-icon {
  color: #0066cc;
}

/* Select / Role Dropdown */
.input-group select {
  width: 100%;
  padding: 14px 16px 14px 44px;
  background: #f4f7fa;
  border: 1px solid #e0e7ef;
  border-radius: 12px;
  color: #1a2b4a;
  font-size: 14px;
  font-family: 'Inter', sans-serif;
  outline: none;
  transition: all 0.3s ease;
  cursor: pointer;
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2398a8b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 16px center;
}

.input-group select:invalid,
.input-group select option[value=""] {
  color: #98a8b8;
}

.input-group select option {
  color: #1a2b4a;
  background: #ffffff;
  padding: 8px;
}

.input-group select:focus {
  border-color: #0066cc;
  background-color: #ffffff;
  box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%230066cc' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
}

.input-group select:focus ~ .input-icon {
  color: #0066cc;
}

/* Forgot Link */
.forgot-link {
  align-self: flex-end;
  font-size: 12px;
  color: #0066cc;
  text-decoration: none;
  margin-bottom: 20px;
  margin-top: -4px;
  cursor: pointer;
  transition: color 0.3s ease;
}

.forgot-link:hover {
  color: #004999;
}

/* Submit Button */
.btn-submit {
  width: 100%;
  padding: 14px;
  background: linear-gradient(135deg, #0077dd 0%, #004999 100%);
  color: #ffffff;
  border: none;
  border-radius: 12px;
  font-size: 15px;
  font-weight: 600;
  font-family: 'Inter', sans-serif;
  cursor: pointer;
  transition: all 0.3s ease;
  letter-spacing: 0.3px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.btn-submit:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(0, 102, 204, 0.35);
}

.btn-submit:active {
  transform: translateY(0);
}

.btn-submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
}

/* ======= LOCKOUT BANNER ======= */
.lockout-banner {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  background: linear-gradient(135deg, #fef2f2 0%, #fff1f2 100%);
  border: 1px solid #fecaca;
  border-radius: 12px;
  margin-bottom: 12px;
  animation: lockoutSlide 0.4s ease-out;
}

@keyframes lockoutSlide {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.lockout-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: #fee2e2;
  color: #dc2626;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  animation: lockPulse 2s ease-in-out infinite;
}

@keyframes lockPulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.08); }
}

.lockout-text {
  flex: 1;
  display: flex;
  flex-direction: column;
  text-align: left;
  gap: 2px;
}

.lockout-title {
  font-size: 13px;
  font-weight: 700;
  color: #991b1b;
}

.lockout-msg {
  font-size: 11px;
  color: #b91c1c;
}

.lockout-timer {
  font-size: 20px;
  font-weight: 800;
  color: #dc2626;
  font-variant-numeric: tabular-nums;
  font-family: 'Courier New', monospace;
  letter-spacing: 1px;
  background: #fee2e2;
  padding: 6px 12px;
  border-radius: 8px;
  flex-shrink: 0;
}

/* Attempts Warning */
.attempts-warning {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 10px 14px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
  color: #b45309;
  margin-bottom: 8px;
  animation: fadeIn 0.3s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* ======= OVERLAY ======= */
.overlay-container {
  position: absolute;
  top: 0;
  left: 50%;
  width: 50%;
  height: 100%;
  overflow: hidden;
  transition: transform 0.65s cubic-bezier(0.68, -0.15, 0.265, 1.15);
  z-index: 100;
}

.container.right-panel-active .overlay-container {
  transform: translateX(-100%);
}

.overlay {
  position: relative;
  left: -100%;
  height: 100%;
  width: 200%;
  transition: transform 0.65s cubic-bezier(0.68, -0.15, 0.265, 1.15);
  overflow: hidden;
}

.overlay::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: url('/photo1.png');
  background-size: cover;
  background-position: center;
  z-index: 0;
  animation: slowZoom 20s ease-in-out infinite alternate;
}

.overlay::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(
    135deg,
    rgba(0, 60, 130, 0.85) 0%,
    rgba(0, 40, 100, 0.8) 50%,
    rgba(0, 80, 160, 0.75) 100%
  );
  z-index: 1;
}

@keyframes slowZoom {
  0% {
    transform: scale(1) translateX(0);
  }
  50% {
    transform: scale(1.1) translateX(-2%);
  }
  100% {
    transform: scale(1.15) translateY(-3%);
  }
}

.container.right-panel-active .overlay {
  transform: translateX(50%);
}

/* Overlay Panels */
.overlay-panel {
  position: absolute;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 0 48px;
  top: 0;
  height: 100%;
  width: 50%;
  transition: transform 0.65s cubic-bezier(0.68, -0.15, 0.265, 1.15);
  z-index: 2;
}

.overlay-left {
  transform: translateX(-20%);
}

.container.right-panel-active .overlay-left {
  transform: translateX(0);
}

.overlay-right {
  right: 0;
  transform: translateX(0);
}

.container.right-panel-active .overlay-right {
  transform: translateX(20%);
}

.overlay-panel h1 {
  font-size: 30px;
  font-weight: 700;
  margin-bottom: 12px;
  letter-spacing: -0.5px;
  color: #ffffff;
  text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.overlay-panel p {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.85);
  line-height: 1.6;
  margin-bottom: 28px;
  max-width: 280px;
  text-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}

/* Ghost Button */
.btn-ghost {
  padding: 12px 40px;
  background: transparent;
  border: 2px solid rgba(255, 255, 255, 0.5);
  border-radius: 12px;
  color: #ffffff;
  font-size: 14px;
  font-weight: 600;
  font-family: 'Inter', sans-serif;
  cursor: pointer;
  transition: all 0.3s ease;
  letter-spacing: 0.3px;
}

.btn-ghost:hover {
  background: rgba(255, 255, 255, 0.15);
  border-color: rgba(255, 255, 255, 0.8);
  transform: translateY(-2px);
}

/* ======= DECORATIVE ELEMENTS ======= */
.decoration {
  position: absolute;
  border-radius: 50%;
  opacity: 0.1;
  pointer-events: none;
}

.decoration-1 {
  width: 120px;
  height: 120px;
  border: 2px solid #fff;
  top: 30px;
  right: -20px;
}

.decoration-2 {
  width: 80px;
  height: 80px;
  background: #fff;
  bottom: 50px;
  left: -10px;
}

.decoration-3 {
  width: 40px;
  height: 40px;
  background: #fff;
  top: 80px;
  left: 30px;
}


/* ======= MFA MODAL ======= */
.mfa-modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 20, 50, 0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  animation: fadeIn 0.3s ease-out;
  backdrop-filter: blur(8px);
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.mfa-modal {
  background: #ffffff;
  border-radius: 20px;
  padding: 40px 36px;
  width: 90%;
  max-width: 440px;
  box-shadow: 0 20px 60px rgba(0, 50, 100, 0.3);
  animation: slideUp 0.4s ease-out;
}

@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.mfa-icon {
  display: flex;
  justify-content: center;
  margin-bottom: 16px;
}

.mfa-icon svg {
  padding: 14px;
  background: #e8f0fe;
  border-radius: 50%;
  width: 68px;
  height: 68px;
}

.mfa-header {
  text-align: center;
  margin-bottom: 28px;
}

.mfa-header h2 {
  font-size: 22px;
  font-weight: 700;
  color: #1a2b4a;
  margin-bottom: 8px;
  letter-spacing: -0.3px;
}

.mfa-header p {
  font-size: 13px;
  color: #6b7c93;
  margin: 0;
  line-height: 1.5;
}

.mfa-header p strong {
  color: #0066cc;
}

/* OTP 6-Box Input */
.otp-boxes {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-bottom: 16px;
}

.otp-box {
  width: 48px;
  height: 56px;
  border: 2px solid #d8e0ea;
  border-radius: 12px;
  text-align: center;
  font-size: 22px;
  font-weight: 700;
  color: #1a2b4a;
  font-family: 'Courier New', monospace;
  outline: none;
  transition: all 0.2s ease;
  background: #f4f7fa;
  padding: 0;
}

.otp-box:focus {
  border-color: #0066cc;
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.12);
}

.otp-box-filled {
  border-color: #0066cc;
  background: #e8f0fe;
}

.otp-box-error {
  border-color: #c62828;
  animation: shake 0.4s ease;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-4px); }
  40% { transform: translateX(4px); }
  60% { transform: translateX(-4px); }
  80% { transform: translateX(4px); }
}

/* Timer */
.mfa-timer {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 13px;
  color: #6b7c93;
  margin-bottom: 8px;
}

.mfa-timer strong {
  color: #0066cc;
  font-variant-numeric: tabular-nums;
}

.mfa-timer-expired {
  color: #c62828;
}

.mfa-error-msg {
  text-align: center;
  margin-bottom: 8px;
}

.mfa-verify-btn {
  margin-top: 12px;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.btn-spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #ffffff;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
  flex-shrink: 0;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Resend */
.mfa-resend {
  font-size: 13px;
  color: #6b7c93;
  text-align: center;
  margin-bottom: 12px;
}

.mfa-resend-btn {
  color: #0066cc;
  font-weight: 600;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 13px;
  font-family: 'Inter', sans-serif;
  padding: 0;
  margin-left: 4px;
}

.mfa-resend-btn:hover {
  text-decoration: underline;
}

.mfa-resend-btn:disabled {
  color: #98a8b8;
  cursor: not-allowed;
  text-decoration: none;
}

/* Back button */
.mfa-back-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  padding: 10px;
  color: #6b7c93;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 13px;
  font-family: 'Inter', sans-serif;
  transition: color 0.2s ease;
  border-top: 1px solid #e8eef6;
  margin-top: 4px;
  padding-top: 16px;
}

.mfa-back-btn:hover {
  color: #0066cc;
}

/* ======= RESPONSIVE ======= */
@media (max-width: 700px) {
  .logo-wrapper {
    margin-bottom: 16px;
  }

  .logo-wrapper img {
    height: 44px;
  }

  .container {
    min-height: 100vh;
    border-radius: 0;
    width: 100%;
    max-width: 100%;
  }

  .form-container {
    width: 100%;
    height: 65%;
    top: 35%;
  }

  .sign-in-container {
    left: 0;
  }

  .container.right-panel-active .sign-in-container {
    transform: translateY(-100%);
    opacity: 0;
  }

  .sign-up-container {
    left: 0;
  }

  .container.right-panel-active .sign-up-container {
    transform: translateY(0);
    opacity: 1;
  }

  .overlay-container {
    top: 0;
    left: 0;
    width: 100%;
    height: 35%;
  }

  .container.right-panel-active .overlay-container {
    transform: translateY(185%);
  }

  .overlay {
    left: 0;
    top: -100%;
    width: 100%;
    height: 200%;
  }

  .container.right-panel-active .overlay {
    transform: translateY(50%);
  }

  .overlay-panel {
    width: 100%;
    padding: 0 32px;
  }

  .overlay-left {
    transform: translateY(-20%);
  }

  .container.right-panel-active .overlay-left {
    transform: translateY(0);
  }

  .overlay-right {
    bottom: 0;
    top: auto;
    transform: translateY(0);
  }

  .container.right-panel-active .overlay-right {
    transform: translateY(20%);
  }

  form {
    padding: 0 28px;
  }

  .overlay-panel h1 {
    font-size: 24px;
  }

  .overlay-panel p {
    font-size: 13px;
    margin-bottom: 16px;
  }

  .btn-ghost {
    padding: 10px 32px;
    font-size: 13px;
  }

  .mfa-modal {
    padding: 28px 24px;
    width: 92%;
  }

  .mfa-header h2 {
    font-size: 19px;
  }

  .otp-box {
    width: 42px;
    height: 50px;
    font-size: 20px;
  }

  .otp-boxes {
    gap: 8px;
  }
}
</style>
