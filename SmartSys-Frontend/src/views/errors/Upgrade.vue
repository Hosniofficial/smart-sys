<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100 text-right" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="loadingPlans" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1200px] mx-auto p-6 lg:p-10 space-y-8 animate-fadeIn">
      
      <!-- Hero Header Section -->
      <header class="text-center space-y-3 max-w-2xl mx-auto pt-4">
        <div class="w-12 h-12 rounded-xl bg-blue-50 border border-blue-100 text-blue-600 flex items-center justify-center mx-auto shadow-sm">
          <i class="fas fa-rocket text-sm"></i>
        </div>
        <h1 class="text-xl md:text-2xl font-bold text-slate-900 tracking-tight">
          الترقية مطلوبة لمتابعة الاستخدام
        </h1>
        <p class="text-xs text-slate-500 font-medium leading-relaxed max-w-lg mx-auto">
          عذراً، لقد انتهت الفترة التجريبية أو الاشتراك الحالي لمنشأتك. يرجى اختيار الخطة المناسبة لاستكمال العمل بكافة الصلاحيات.
        </p>
      </header>

      <!-- Reason Alert (Conditional) -->
      <transition name="slide-down">
        <div v-if="reason" class="bg-amber-50 border border-amber-200 rounded-xl p-4 shadow-sm flex items-center gap-3 max-w-3xl mx-auto">
          <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center text-xs shrink-0">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="space-y-0.5">
            <p class="text-[10px] font-bold text-amber-700 uppercase tracking-widest">تنبيه الاشتراك</p>
            <p class="text-xs font-bold text-amber-900 leading-relaxed">{{ reason }}</p>
          </div>
        </div>
      </transition>

      <!-- Pricing Plans Grid -->
      <section class="grid grid-cols-1 md:grid-cols-3 gap-6 py-2">
        <div 
          v-for="p in plans" 
          :key="p.code" 
          class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm hover:border-blue-300 hover:shadow-md transition-all flex flex-col justify-between group"
        >
          <!-- Plan Content -->
          <div class="space-y-6">
            <div class="flex items-center justify-between">
              <span class="px-2.5 py-0.5 rounded text-[9px] font-bold border border-blue-100 bg-blue-50 text-blue-600 uppercase tracking-wider">
                باقة الأعمال
              </span>
              <div class="w-8 h-8 rounded-lg bg-slate-50 border border-slate-100 text-slate-400 flex items-center justify-center text-xs group-hover:bg-blue-50 group-hover:text-blue-600 transition-colors">
                <i class="fas fa-gem"></i>
              </div>
            </div>

            <div>
              <h3 class="text-lg font-bold text-slate-900 capitalize">{{ p.name }}</h3>
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">
                دورة الفوترة: كل {{ p.billing_cycle_days }} يوم
              </p>
            </div>

            <!-- Price -->
            <div class="flex items-baseline gap-1.5 pt-2 border-t border-slate-50">
              <span class="text-3xl font-bold font-mono tracking-tight text-slate-900">{{ p.price }}</span>
              <span class="text-xs font-bold text-slate-400 uppercase">{{ p.currency }}</span>
            </div>

            <!-- Feature List -->
            <ul class="space-y-3 pt-2 border-t border-slate-50">
              <li v-for="feature in ['كافة ميزات النظام الأساسية', 'دعم فني متكامل 24/7', 'تحديثات دورية مجانية']" :key="feature" class="flex items-center gap-2.5 text-xs font-medium text-slate-600">
                <div class="w-4 h-4 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-[9px] shrink-0">
                  <i class="fas fa-check"></i>
                </div>
                <span>{{ feature }}</span>
              </li>
            </ul>
          </div>

          <!-- Select Action -->
          <div class="pt-8">
            <button 
              @click="selectPlan(p)" 
              class="h-9 w-full bg-slate-900 text-white rounded-md text-xs font-bold shadow-sm hover:bg-blue-600 active:scale-95 transition-all flex items-center justify-center gap-2"
            >
              <span>اشترك الآن</span>
              <i class="fas fa-arrow-left text-[10px]"></i>
            </button>
          </div>
        </div>
      </section>

      <!-- Footer Info -->
      <footer class="text-center py-6 border-t border-slate-200">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest leading-relaxed max-w-lg mx-auto">
          الأسعار الموضحة قد تخضع للتغيير. جميع بوابات التحويل المباشر مؤمنة بالكامل وسيتم تفعيل بوابات الدفع الإلكتروني قريباً.
        </p>
      </footer>
    </div>

    <!-- Payment Selection Modal -->
    <BaseModal :show="showPaymentModal" @close="closePaymentModal" maxWidth="md">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-slate-900 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-credit-card"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">اختر وسيلة الدفع</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">التحويل المالي المباشر</p>
          </div>
        </div>
      </template>

      <div class="space-y-5">
        <!-- Selected Plan Summary -->
        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
          <div class="space-y-0.5">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">الخطة المختارة</p>
            <h4 class="text-xs font-bold text-slate-900">{{ selectedPlan?.name }}</h4>
          </div>
          <div class="text-right">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">المبلغ المستحق</p>
            <span class="text-sm font-bold font-mono tracking-tight text-blue-600">
              {{ selectedPlan?.price }} {{ selectedPlan?.currency }}
            </span>
          </div>
        </div>

        <!-- Payment Methods -->
        <div class="space-y-2.5">
          <!-- Instapay -->
          <button 
            @click="selectPaymentMethod('instapay')" 
            class="w-full p-4 bg-white border border-slate-200 rounded-xl hover:border-emerald-300 hover:bg-emerald-50/20 transition-all flex items-center justify-between group active:scale-[0.99] shadow-sm"
          >
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center text-sm shadow-sm group-hover:bg-emerald-600 group-hover:text-white transition-colors">
                <i class="fas fa-mobile-screen-button"></i>
              </div>
              <div class="text-right">
                <p class="text-xs font-bold text-slate-900">Instapay</p>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">دفع سريع ولحظي عبر التطبيق</p>
              </div>
            </div>
            <i class="fas fa-chevron-left text-[10px] text-slate-300 group-hover:text-emerald-600 transition-colors"></i>
          </button>

          <!-- Vodafone Cash -->
          <button 
            @click="selectPaymentMethod('vodafonecash')" 
            class="w-full p-4 bg-white border border-slate-200 rounded-xl hover:border-rose-300 hover:bg-rose-50/20 transition-all flex items-center justify-between group active:scale-[0.99] shadow-sm"
          >
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-lg bg-rose-50 text-rose-600 border border-rose-100 flex items-center justify-center text-sm shadow-sm group-hover:bg-rose-600 group-hover:text-white transition-colors">
                <i class="fas fa-wallet"></i>
              </div>
              <div class="text-right">
                <p class="text-xs font-bold text-slate-900">Vodafone Cash</p>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">تحويل فوري عبر المحفظة</p>
              </div>
            </div>
            <i class="fas fa-chevron-left text-[10px] text-slate-300 group-hover:text-rose-600 transition-colors"></i>
          </button>
        </div>

        <!-- WhatsApp Support Notice -->
        <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
            <i class="fab fa-whatsapp text-sm"></i>
          </div>
          <p class="text-[11px] font-medium text-slate-600 leading-relaxed">
            سيتم توجيهك مباشرة إلى محادثة WhatsApp مع فريق الدعم لإتمام التحقق وتفعيل الحساب.
          </p>
        </div>
      </div>

      <template #footer>
        <button 
          @click="closePaymentModal" 
          class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors"
        >
          إلغاء
        </button>
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import BaseModal from '@/components/BaseModal.vue'
import { useRoute } from 'vue-router'
import apiClient from '@/config/axios'

// --- State ---
const route = useRoute()
const plans = ref([])
const loadingPlans = ref(false)
const reason = ref('')
const showPaymentModal = ref(false)
const selectedPlan = ref(null)

// Fallback plans if API fails
const fallbackPlans = [
  {
    id: 1,
    code: 'starter',
    name: 'Basic',
    price: '99',
    currency: 'EGP',
    billing_cycle_days: 30,
    is_active: true
  },
  {
    id: 2,
    code: 'professional',
    name: 'Professional',
    price: '299',
    currency: 'EGP',
    billing_cycle_days: 30,
    is_active: true
  },
  {
    id: 3,
    code: 'enterprise',
    name: 'Enterprise',
    price: '599',
    currency: 'EGP',
    billing_cycle_days: 30,
    is_active: true
  }
]

function getQuery(name) {
  try { return route.query[name] || '' } catch { return '' }
}

// Load plans from API with fallback to hardcoded
async function loadPlans() {
  loadingPlans.value = true
  reason.value = decodeURIComponent(getQuery('reason') || '')
  
  try {
    if (import.meta.env.DEV) {
      console.log('[Upgrade Page] Loading plans from API...')
    }
    
    // Try to fetch from /plans endpoint
    const response = await apiClient.get('/plans', {
      meta: { skipLoader: true, suppress402: true }
    })
    
    if (response.data?.status === 'success' && Array.isArray(response.data.data)) {
      // Filter out trial and promotional plans (only paid plans)
      const filteredPlans = response.data.data.filter(p => 
        !p.code.toLowerCase().includes('trial') && 
        !p.code.toLowerCase().includes('promo') &&
        parseFloat(p.price) > 0
      )
      plans.value = filteredPlans.length > 0 ? filteredPlans : response.data.data
      if (import.meta.env.DEV) {
        console.log('[Upgrade Page] Loaded plans from API:', plans.value.length)
      }
      return
    }
  } catch (error) {
    if (import.meta.env.DEV) {
      console.log('[Upgrade Page] API call failed, using fallback plans:', error.message)
    }
  } finally {
    loadingPlans.value = false
  }
  
  // Fallback: use hardcoded plans
  plans.value = fallbackPlans
  if (import.meta.env.DEV) {
    console.log('[Upgrade Page] Using fallback plans')
  }
}

function selectPlan(p) {
  selectedPlan.value = p
  showPaymentModal.value = true
}

function closePaymentModal() {
  showPaymentModal.value = false
  selectedPlan.value = null
}

// Logic: Payment Redirect (STRICTLY PRESERVED)
function selectPaymentMethod(method) {
  const whatsappNumber = '+201062024249'
  const planName = selectedPlan.value?.name || ''
  const planPrice = selectedPlan.value?.price || ''
  const currency = selectedPlan.value?.currency || ''
  
  let message = `مرحباً، أرغب في الاشتراك في خطة ${planName} بسعر ${planPrice} ${currency} عبر ${method === 'instapay' ? 'Instapay' : 'Vodafone Cash'}`
  
  const whatsappUrl = `https://wa.me/${whatsappNumber.replace('+', '')}?text=${encodeURIComponent(message)}`
  window.open(whatsappUrl, '_blank')
  
  if (typeof window !== 'undefined' && typeof window.showToast === 'function') {
    window.showToast('جاري تحويلك إلى WhatsApp لإتمام عملية الدفع', 'info')
  }
  
  closePaymentModal()
}

onMounted(loadPlans)
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

.animate-fadeIn {
  animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.slide-down-enter-active, .slide-down-leave-active {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.slide-down-enter-from, .slide-down-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>