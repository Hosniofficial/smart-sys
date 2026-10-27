<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <PageLoadingBar :active="profileLoading || passwordLoading" />

    <div class="max-w-6xl mx-auto p-6 lg:p-10 space-y-8 animate-fadeIn">
      
      <!-- Profile Hero Banner -->
      <div class="bg-white border border-slate-200 rounded-xl p-6 sm:p-8 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-6">
        <div class="flex flex-col sm:flex-row items-center gap-5 text-center sm:text-right">
          <div class="w-16 h-16 bg-slate-900 text-white rounded-xl flex items-center justify-center text-xl font-bold font-mono tracking-wider shadow-sm shrink-0">
            {{ userInitials }}
          </div>
          <div class="space-y-1">
            <div class="flex items-center justify-center sm:justify-start gap-2">
              <h1 class="text-lg font-bold text-slate-900">{{ profileForm.name || authUser?.name || 'مستخدم النظام' }}</h1>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-bold border border-blue-100 bg-blue-50 text-blue-600">
                حساب نشط
              </span>
            </div>
            <p class="text-xs text-slate-400 font-medium">
              إدارة بياناتك الشخصية، معلومات الاتصال، وإعدادات أمان الدخول.
            </p>
          </div>
        </div>

        <div class="text-left font-mono text-[11px] font-bold text-slate-400 bg-slate-50 px-3 py-1.5 rounded-md border border-slate-100">
          USER_REF: #{{ authUser?.id || '---' }}
        </div>
      </div>

      <!-- Main Content Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        
        <!-- Main Forms Column -->
        <div class="lg:col-span-8 space-y-6">
          
          <!-- Personal Information Card -->
          <section class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-6">
            <div class="flex items-center gap-3 pb-4 border-b border-slate-100">
              <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs">
                <i class="fas fa-user-gear"></i>
              </div>
              <div>
                <h2 class="text-sm font-bold text-slate-900 uppercase">المعلومات الشخصية</h2>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">تحديث الاسم والبيانات الأساسية</p>
              </div>
            </div>

            <form @submit.prevent="updateProfile" class="space-y-4">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-1.5">
                  <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">
                    الاسم الكامل <span class="text-rose-500">*</span>
                  </label>
                  <div class="relative">
                    <input 
                      v-model.trim="profileForm.name" 
                      type="text" 
                      class="filter-input pr-8" 
                      placeholder="أدخل اسمك الكامل" 
                      autocomplete="name" 
                    />
                    <i class="fas fa-signature absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                  </div>
                </div>

                <div class="space-y-1.5">
                  <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">البريد الإلكتروني</label>
                  <div class="relative">
                    <input 
                      v-model.trim="profileForm.email" 
                      type="email" 
                      class="filter-input pr-8 font-mono" 
                      placeholder="example@domain.com" 
                      autocomplete="email" 
                    />
                    <i class="fas fa-envelope absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                  </div>
                </div>

                <div class="md:col-span-2 space-y-1.5">
                  <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">رقم الهاتف</label>
                  <div class="relative">
                    <input 
                      v-model.trim="profileForm.phone" 
                      type="text" 
                      class="filter-input pr-8 font-mono" 
                      placeholder="05XXXXXXXX" 
                      autocomplete="tel" 
                    />
                    <i class="fas fa-phone absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                  </div>
                </div>
              </div>

              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-4 border-t border-slate-50">
                <LoadingButton
                  type="submit" 
                  text="حفظ التعديلات"
                  loadingText="جارٍ الحفظ..."
                  icon="fas fa-save"
                  :isLoading="profileLoading"
                  class="h-9 rounded-md px-6 text-xs shadow-sm"
                />

                <transition name="slide-down">
                  <div 
                    v-if="profileMessage" 
                    :class="[profileMessageType === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200']" 
                    class="px-3 py-1.5 rounded-md text-[10px] font-bold border inline-flex items-center gap-2"
                  >
                    <i :class="profileMessageType === 'success' ? 'fas fa-check-circle' : 'fas fa-triangle-exclamation'"></i>
                    <span>{{ profileMessage }}</span>
                  </div>
                </transition>
              </div>
            </form>
          </section>

          <!-- Password Security Card -->
          <section class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-6">
            <div class="flex items-center gap-3 pb-4 border-b border-slate-100">
              <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-xs">
                <i class="fas fa-shield-halved"></i>
              </div>
              <div>
                <h2 class="text-sm font-bold text-slate-900 uppercase">أمان الحساب وكلمة المرور</h2>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">تغيير كلمة المرور وتأمين الجلسة</p>
              </div>
            </div>

            <form @submit.prevent="changePassword" class="space-y-4">
              <!-- Hidden username field for accessibility (browser password managers) -->
              <input type="text" :value="authUser?.username" autocomplete="username" class="hidden" readonly />
              
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2 space-y-1.5">
                  <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">
                    كلمة المرور الحالية <span class="text-rose-500">*</span>
                  </label>
                  <div class="relative">
                    <input 
                      v-model="passwordForm.current_password" 
                      type="password" 
                      class="filter-input pr-8 font-mono" 
                      autocomplete="current-password" 
                    />
                    <i class="fas fa-key absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                  </div>
                </div>

                <div class="space-y-1.5">
                  <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">كلمة المرور الجديدة</label>
                  <div class="relative">
                    <input 
                      v-model="passwordForm.new_password" 
                      type="password" 
                      class="filter-input pr-8 font-mono" 
                      autocomplete="new-password" 
                    />
                    <i class="fas fa-lock absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                  </div>
                </div>

                <div class="space-y-1.5">
                  <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">تأكيد كلمة المرور</label>
                  <div class="relative">
                    <input 
                      v-model="passwordForm.confirm_password" 
                      type="password" 
                      class="filter-input pr-8 font-mono" 
                      autocomplete="new-password" 
                    />
                    <i class="fas fa-lock-open absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                  </div>
                </div>
              </div>

              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-4 border-t border-slate-50">
                <LoadingButton
                  type="submit" 
                  text="تحديث كلمة المرور"
                  loadingText="جارٍ التحديث..."
                  icon="fas fa-key"
                  :isLoading="passwordLoading"
                  class="h-9 rounded-md px-6 text-xs shadow-sm"
                />

                <transition name="slide-down">
                  <div 
                    v-if="passwordMessage" 
                    :class="[passwordMessageType === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200']" 
                    class="px-3 py-1.5 rounded-md text-[10px] font-bold border inline-flex items-center gap-2"
                  >
                    <i :class="passwordMessageType === 'success' ? 'fas fa-check-circle' : 'fas fa-triangle-exclamation'"></i>
                    <span>{{ passwordMessage }}</span>
                  </div>
                </transition>
              </div>
            </form>
          </section>
        </div>

        <!-- Sidebar Info -->
        <div class="lg:col-span-4 space-y-6">
          
          <!-- Account Meta Card -->
          <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4">
            <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
              <i class="fas fa-circle-info text-blue-500"></i>
              بيانات الحساب النظامية
            </h3>
            
            <div class="space-y-2">
              <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg border border-slate-100 text-xs">
                <span class="text-[11px] font-bold text-slate-400">اسم المستخدم</span>
                <span class="font-bold font-mono text-slate-900">@{{ authUser?.username || '-' }}</span>
              </div>
              <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg border border-slate-100 text-xs">
                <span class="text-[11px] font-bold text-slate-400">معرّف الحساب (ID)</span>
                <span class="font-bold font-mono text-slate-900">#{{ authUser?.id || '-' }}</span>
              </div>
            </div>
          </div>

          <!-- Security Note -->
          <div class="bg-slate-900 rounded-xl p-5 text-white shadow-sm space-y-3 relative overflow-hidden">
            <div class="flex items-center gap-2 text-blue-400">
              <i class="fas fa-shield-halved text-xs"></i>
              <h3 class="text-[10px] font-bold uppercase tracking-widest">ملاحظة أمنية</h3>
            </div>
            <p class="text-slate-400 text-xs font-medium leading-relaxed">
              عند تغيير البريد الإلكتروني، قد يطلب منك النظام إعادة تسجيل الدخول لتحديث جلسة العمل الخاصة بك ولتأمين حسابك.
            </p>
            <div class="pt-2 border-t border-slate-800 flex items-center gap-2">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              <span class="text-[9px] font-bold text-slate-500 uppercase tracking-widest font-mono">نظام حماية الجلسات نشط</span>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth/authStore'
import { useUserStore } from '@/stores/user/userStore'
import LoadingButton from '@/components/ui/LoadingButton.vue'

// --- State & Stores (Strictly Preserved) ---
const authStore = useAuthStore()
const userStore = useUserStore()

const profileLoading = ref(false)
const profileMessage = ref('')
const profileMessageType = ref('success')

const passwordLoading = ref(false)
const passwordMessage = ref('')
const passwordMessageType = ref('success')

const profileForm = reactive({ name: '', email: '', phone: '' })
const passwordForm = reactive({ current_password: '', new_password: '', confirm_password: '' })

const authUser = computed(() => {
  const fromPinia = authStore?.user || null
  if (fromPinia) return fromPinia
  try {
    const raw = localStorage.getItem('user')
    return raw ? JSON.parse(raw) : null
  } catch (e) {
    return null
  }
})

const userInitials = computed(() => {
  const name = (profileForm.name || authUser.value?.name || authUser.value?.username || '').trim()
  if (!name) return 'U'
  const parts = name.split(' ').filter(Boolean)
  const a = parts[0]?.[0] || ''
  const b = parts[1]?.[0] || ''
  return (a + b).toUpperCase() || (name[0] ? name[0].toUpperCase() : 'U')
})

async function fetchMe() {
  try {
    const me = await userStore.fetchCurrentUserProfile()
    if (!me) return
    profileForm.name = me?.name || profileForm.name
    profileForm.email = me?.email || ''
    profileForm.phone = me?.phone || ''
    const merged = { ...(authStore?.user || authUser.value || {}), ...me }
    localStorage.setItem('user', JSON.stringify(merged))
    if (authStore) authStore.user = merged
  } catch (e) {
    /* ignore */
  }
}

async function updateProfile() {
  profileMessage.value = ''
  profileMessageType.value = 'success'
  if (!profileForm.name) {
    profileMessageType.value = 'error'
    profileMessage.value = 'الاسم الكامل حقل مطلوب'
    return
  }
  profileLoading.value = true
  try {
    const res = await userStore.updateCurrentUserProfile({
      name: profileForm.name,
      email: profileForm.email,
      phone: profileForm.phone
    })
    profileMessage.value = res?.message || 'تم تحديث البيانات بنجاح'
    if (res?.data) {
      const merged = { ...(authUser.value || {}), ...res.data }
      localStorage.setItem('user', JSON.stringify(merged))
      if (authStore) authStore.user = merged
    }
  } catch (e) {
    profileMessageType.value = 'error'
    profileMessage.value = e?.response?.data?.message || 'فشل التحديث'
  } finally {
    profileLoading.value = false
  }
}

async function changePassword() {
  passwordMessage.value = ''
  passwordMessageType.value = 'success'
  if (!passwordForm.current_password || !passwordForm.new_password) {
    passwordMessageType.value = 'error'
    passwordMessage.value = 'يرجى إدخال كلمات المرور'
    return
  }
  if (passwordForm.new_password !== passwordForm.confirm_password) {
    passwordMessageType.value = 'error'
    passwordMessage.value = 'كلمتا المرور غير متطابقتين'
    return
  }
  passwordLoading.value = true
  try {
    const res = await userStore.changeCurrentUserPassword({
      current_password: passwordForm.current_password,
      new_password: passwordForm.new_password
    })
    passwordMessage.value = res?.message || 'تم تغيير كلمة المرور'
    passwordForm.current_password = ''
    passwordForm.new_password = ''
    passwordForm.confirm_password = ''
    window.setTimeout(() => {
      authStore.clearAuthData()
      window.location.replace('/')
    }, 1200)
  } catch (e) {
    passwordMessageType.value = 'error'
    passwordMessage.value = e?.response?.data?.message || 'فشل تغيير كلمة المرور'
  } finally {
    passwordLoading.value = false
  }
}

onMounted(() => {
  const u = authUser.value
  profileForm.name = u?.name || ''
  profileForm.email = u?.email || ''
  profileForm.phone = u?.phone || ''
  fetchMe()
})
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

.slide-down-enter-active, .slide-down-leave-active {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.slide-down-enter-from, .slide-down-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>