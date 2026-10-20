<template>
  <div class="min-h-screen bg-[#fafafa] p-4 lg:p-12 text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <div class="max-w-4xl mx-auto space-y-12">
      
      <!-- Header: Minimal & Professional -->
      <header class="text-center space-y-2 mb-12">
        <div class="w-16 h-16 bg-slate-900 rounded-xl flex items-center justify-center text-white mx-auto mb-6 shadow-xl shadow-slate-200 transform hover:scale-105 transition-transform duration-500">
          <i class="fas fa-rocket text-2xl"></i>
        </div>
        <h1 class="text-3xl font-bold text-slate-900 tracking-tight">تهيئة المنشأة الجديدة</h1>
        <p class="text-slate-400 font-medium text-sm">لنقم بضبط القواعد الأساسية لنظامك المحاسبي في خطوات بسيطة.</p>
      </header>

      <!-- Multi-step Progress Bar: Refined SaaS Style -->
      <nav class="relative flex justify-between items-center max-w-2xl mx-auto mb-16 px-4">
        <div class="absolute top-1/2 left-0 w-full h-0.5 bg-slate-200 -translate-y-1/2 z-0"></div>
        <div 
          class="absolute top-1/2 right-0 h-0.5 bg-blue-600 -translate-y-1/2 z-0 transition-all duration-700"
          :style="{ width: (currentStep / (steps.length - 1)) * 100 + '%' }"
        ></div>

        <div
          v-for="(step, index) in steps"
          :key="index"
          class="relative z-10 flex flex-col items-center"
        >
          <div
            :class="[
              currentStep === index ? 'bg-blue-600 text-white ring-4 ring-blue-500/10' : 
              currentStep > index ? 'bg-emerald-500 text-white' : 'bg-white text-slate-300 border border-slate-200'
            ]"
            class="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-500 shadow-sm"
          >
            <i v-if="currentStep > index" class="fas fa-check text-[10px]"></i>
            <span v-else>{{ index + 1 }}</span>
          </div>
          <span :class="[currentStep === index ? 'text-slate-900' : 'text-slate-400']" class="absolute -bottom-8 whitespace-nowrap text-[10px] font-bold uppercase tracking-widest transition-colors duration-500">
            {{ step.label }}
          </span>
        </div>
      </nav>

      <!-- Main Setup Content Container -->
      <div class="bg-white rounded-xl shadow-2xl shadow-slate-200/50 border border-slate-200 relative overflow-hidden">
        
        <!-- Loading Overlay -->
        <Transition name="fade">
          <div v-if="loading" class="absolute inset-0 bg-white/80 backdrop-blur-md z-50 flex flex-col items-center justify-center p-12">
            <div class="flex items-center gap-1">
              <div class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></div>
              <div class="w-2 h-2 rounded-full bg-blue-500 animate-pulse" style="animation-delay: 0.2s"></div>
              <div class="w-2 h-2 rounded-full bg-blue-500 animate-pulse" style="animation-delay: 0.4s"></div>
            </div>
            <p class="mt-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest animate-pulse">مزامنة بيانات التهيئة...</p>
          </div>
        </Transition>

        <div class="p-8 md:p-12 min-h-[480px]">
          
          <!-- Step 1: Company Information -->
          <div v-show="currentStep === 0" class="setup-step animate-fadeIn">
            <div class="flex items-center gap-3 mb-8">
              <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center text-sm border border-blue-100"><i class="fas fa-building"></i></div>
              <h2 class="text-lg font-bold text-slate-900 uppercase tracking-tight">بيانات الهوية التجارية</h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div class="space-y-1.5">
                <label class="metadata-label">اسم المنشأة / العلامة <span class="text-rose-500">*</span></label>
                <input v-model="formData.company.name" type="text" class="setup-input font-bold" placeholder="أدخل الاسم الرسمي..." required />
              </div>

              <div class="space-y-1.5">
                <label class="metadata-label">البريد الإلكتروني الرسمي</label>
                <input v-model="formData.company.email" type="email" class="setup-input" placeholder="mail@example.com" />
              </div>

              <div class="space-y-1.5">
                <label class="metadata-label">رقم هاتف التواصل</label>
                <input v-model="formData.company.phone" type="tel" class="setup-input font-mono" placeholder="05xxxxxxxx" />
              </div>

              <div class="space-y-1.5">
                <label class="metadata-label">العملة الافتراضية</label>
                <select v-model="formData.company.currency" @change="updateCurrencyData" class="setup-input appearance-none font-bold">
                  <option v-for="curr in getAvailableCurrencies('ar')" :key="curr.code" :value="curr.code">{{ curr.name }} ({{ curr.code }})</option>
                </select>
              </div>

              <div class="md:col-span-2 space-y-1.5">
                <label class="metadata-label">المقر الرئيسي</label>
                <input v-model="formData.company.address" type="text" class="setup-input" placeholder="المدينة، الحي، الشارع..." />
              </div>
            </div>
          </div>

          <!-- Step 2: Tax Settings -->
          <div v-show="currentStep === 1" class="setup-step animate-fadeIn">
            <div class="flex items-center gap-3 mb-8">
              <div class="w-10 h-10 bg-rose-50 text-rose-600 rounded-lg flex items-center justify-center text-sm border border-rose-100"><i class="fas fa-percent"></i></div>
              <h2 class="text-lg font-bold text-slate-900 uppercase tracking-tight">الإعدادات الضريبية (VAT)</h2>
            </div>

            <div class="space-y-8">
              <!-- Toggle Card -->
              <label class="flex items-center justify-between p-5 rounded-xl bg-slate-50 border border-slate-200 cursor-pointer transition-all hover:bg-white group has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/30">
                <div class="flex items-center gap-4">
                   <div class="w-10 h-10 rounded-lg bg-white border border-slate-200 flex items-center justify-center text-slate-300 group-hover:text-blue-600 transition-colors"><i class="fas fa-receipt"></i></div>
                   <div>
                     <p class="text-xs font-bold text-slate-900 uppercase tracking-tight">تفعيل ضريبة القيمة المضافة</p>
                     <p class="text-[10px] text-slate-400 font-medium mt-1">هل المنشأة مسجلة ضريبياً في الجهات الرسمية؟</p>
                   </div>
                </div>
                <input v-model="formData.tax.tax_enabled" type="checkbox" class="w-5 h-5 rounded border-slate-300 text-blue-600 focus:ring-0 cursor-pointer" />
              </label>

              <transition name="slide-down">
                <div v-if="formData.tax.tax_enabled" class="grid grid-cols-1 md:grid-cols-2 gap-6 p-6 rounded-xl bg-slate-50 border border-slate-200 animate-fadeIn">
                  <div class="space-y-1.5"><label class="metadata-label">مسمى الضريبة</label><input v-model="formData.tax.tax_name" type="text" class="setup-input" /></div>
                  <div class="space-y-1.5">
                    <label class="metadata-label">النسبة المئوية (%)</label>
                    <div class="relative">
                      <input v-model.number="formData.tax.tax_rate" type="number" step="0.01" class="setup-input font-mono font-bold text-lg" />
                      <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-300">%</span>
                    </div>
                  </div>
                  <div class="md:col-span-2 space-y-1.5">
                    <label class="metadata-label">الرقم الضريبي (Tax ID)</label>
                    <input v-model="formData.tax.tax_number" type="text" class="setup-input font-mono tracking-widest text-center" placeholder="3000XXXXX" />
                  </div>
                </div>
              </transition>
            </div>
          </div>

          <!-- Step 3: Branch / Warehouse -->
          <div v-show="currentStep === 2" class="setup-step animate-fadeIn">
            <div class="flex items-center gap-3 mb-8">
              <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-lg flex items-center justify-center text-sm border border-emerald-100"><i class="fas fa-warehouse"></i></div>
              <h2 class="text-lg font-bold text-slate-900 uppercase tracking-tight">إدارة المواقع والفروع</h2>
            </div>

            <div class="space-y-8">
              <div v-if="branches.length > 0" class="space-y-3">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">المواقع المسجلة</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div v-for="branch in branches" :key="branch.id" class="flex items-center justify-between p-4 rounded-xl bg-white border border-slate-200 shadow-sm">
                    <div class="flex items-center gap-3 overflow-hidden">
                      <div class="w-8 h-8 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 shrink-0"><i class="fas fa-store text-xs"></i></div>
                      <div class="min-w-0">
                         <p class="text-xs font-bold text-slate-900 truncate">{{ branch.name }}</p>
                         <p class="text-[9px] text-slate-400 font-medium truncate">{{ branch.location || 'غير محدد' }}</p>
                      </div>
                    </div>
                    <span :class="(branch.active || branch.is_active) ? 'text-emerald-600 border-emerald-100 bg-emerald-50' : 'text-slate-400 bg-slate-50 border-slate-100'" class="px-2 py-0.5 rounded text-[8px] font-bold border uppercase tracking-tighter">
                      {{ (branch.active || branch.is_active) ? 'نشط' : 'معطل' }}
                    </span>
                  </div>
                </div>
              </div>

              <!-- Inline Quick Add Form -->
              <div class="p-6 rounded-xl border-2 border-dashed border-slate-200 bg-slate-50/30 space-y-6">
               <h3 class="text-xs font-bold text-slate-600 uppercase tracking-widest"><i class="fas fa-plus-circle ml-1"></i>إضافة فرع / مستودع</h3>                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div class="space-y-1.5"><label class="metadata-label">اسم الفرع</label><input v-model="formData.branch.name" type="text" class="setup-input h-9" placeholder="مثال: الفرع الرئيسي" /></div>
                  <div class="space-y-1.5"><label class="metadata-label">الموقع الجغرافي / العنوان</label><input v-model="formData.branch.location" type="text" class="setup-input h-9" placeholder="المدينة، الحي، رقم المبنى..." /></div>
                  <div class="space-y-1.5"><label class="metadata-label">هاتف التواصل</label><input v-model="formData.branch.phone" type="tel" class="setup-input h-9 font-mono" placeholder="010xxxxxxxx" /></div>
                  <div class="space-y-1.5"><label class="metadata-label">البريد الإلكتروني</label><input v-model="formData.branch.email" type="email" class="setup-input h-9" placeholder="example@company.com" /></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 4: POS Terminals -->
          <div v-show="currentStep === 3" class="setup-step animate-fadeIn">
            <div class="flex items-center gap-3 mb-8">
              <div class="w-10 h-10 bg-violet-50 text-violet-600 rounded-lg flex items-center justify-center text-sm border border-violet-100"><i class="fas fa-desktop"></i></div>
              <h2 class="text-lg font-bold text-slate-900 uppercase tracking-tight">أجهزة نقاط البيع (POS)</h2>
            </div>

            <div class="space-y-8">
              <div v-if="existingTerminals.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div v-for="t in existingTerminals" :key="t.id" class="flex items-center justify-between p-4 rounded-xl bg-white border border-slate-200 shadow-sm">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-violet-50 flex items-center justify-center text-violet-600"><i class="fas fa-terminal text-xs"></i></div>
                    <div><p class="text-xs font-bold text-slate-900">{{ t.name }}</p><p class="text-[9px] font-mono text-slate-400 uppercase">{{ t.code }}</p></div>
                  </div>
                  <span :class="t.status === 'active' ? 'bg-emerald-50 text-emerald-600 border-emerald-100' : 'bg-slate-50 text-slate-400 border-slate-200'" class="px-2 py-0.5 border rounded text-[8px] font-bold uppercase tracking-tighter">{{ t.status === 'active' ? 'نشط' : 'معطل' }}</span>
                </div>
              </div>

              <div class="p-6 rounded-xl border-2 border-dashed border-slate-200 bg-slate-50/30 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-1.5"><label class="metadata-label">كود الجهاز</label><input v-model="formData.terminal.code" type="text" class="setup-input h-9 font-mono uppercase" placeholder="POS-01" /></div>
                <div class="space-y-1.5"><label class="metadata-label">اسم المحطة</label><input v-model="formData.terminal.name" type="text" class="setup-input h-9" placeholder="كاشير رئيسي" /></div>
                <div class="space-y-1.5"><label class="metadata-label">الفرع المرتبط</label><select v-model="formData.terminal.branch_id" class="setup-input h-9"><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
                <div class="space-y-1.5"><label class="metadata-label">الموقع / الوصف</label><input v-model="formData.terminal.location" type="text" class="setup-input h-9" placeholder="مثال: الطابق الأول — الكاونتر الرئيسي" /></div>
              </div>
            </div>
          </div>

          <!-- Step 5: Invoice Settings -->
          <div v-show="currentStep === 4" class="setup-step animate-fadeIn">
            <div class="flex items-center gap-3 mb-8">
              <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-lg flex items-center justify-center text-sm border border-indigo-100"><i class="fas fa-file-invoice-dollar"></i></div>
              <h2 class="text-lg font-bold text-slate-900 uppercase tracking-tight">إعدادات الفواتير والطباعة</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
              <div class="space-y-6">
                <div class="space-y-1.5"><label class="metadata-label">بادئة رقم الفاتورة</label><input v-model="formData.invoice.prefix" type="text" class="setup-input font-mono font-bold uppercase" placeholder="INV-" /></div>
                <div class="space-y-1.5"><label class="metadata-label">الرقم القادم</label><input v-model.number="formData.invoice.next_number" type="number" class="setup-input font-mono font-bold text-lg text-blue-600" /></div>
              </div>

              <div class="space-y-6">
                <div class="space-y-1.5"><label class="metadata-label">نص تذييل الفاتورة</label><textarea v-model="formData.invoice.footer_text" class="setup-input h-auto py-3 italic" rows="3"></textarea></div>
                <label class="setting-toggle-row bg-slate-50 border border-slate-200 px-4 h-11 rounded-lg">
                  <span class="text-[11px] font-bold text-slate-600 uppercase">تضمين الضريبة في السعر</span>
                  <input v-model="formData.invoice.show_tax_in_price" type="checkbox" class="w-4 h-4 rounded border-slate-300 text-blue-600" />
                </label>
              </div>
            </div>
          </div>

          <!-- Step 6: Cashier Sessions -->
          <div v-show="currentStep === 5" class="setup-step animate-fadeIn">
            <div class="flex items-center gap-3 mb-8">
              <div class="w-10 h-10 bg-amber-50 text-amber-600 rounded-lg flex items-center justify-center text-sm border border-amber-100"><i class="fas fa-cash-register"></i></div>
              <h2 class="text-lg font-bold text-slate-900 uppercase tracking-tight">جلسات الكاشير</h2>
            </div>

            <div class="space-y-6">
              <label class="flex items-center justify-between p-5 rounded-xl bg-slate-50 border border-slate-200 cursor-pointer transition-all hover:bg-white group has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/30">
                <div class="flex items-center gap-4">
                  <div class="w-10 h-10 rounded-lg bg-white border border-slate-200 flex items-center justify-center text-slate-300 group-hover:text-blue-600 transition-colors"><i class="fas fa-lock"></i></div>
                  <div>
                    <p class="text-xs font-bold text-slate-900">إلزام جلسة كاشير للحركات النقدية</p>
                    <p class="text-[10px] text-slate-500 font-medium mt-1">عند التفعيل، يلزم فتح جلسة للفرع قبل تسجيل المبيعات والمشتريات النقدية والمدفوعات والمرتجعات والسندات النقدية.</p>
                  </div>
                </div>
                <input v-model="formData.sessions.enabled" type="checkbox" class="w-5 h-5 rounded border-slate-300 text-blue-600 focus:ring-0 cursor-pointer" />
              </label>
              <label class="flex items-center justify-between p-5 rounded-xl bg-amber-50/60 border border-amber-200 cursor-pointer transition-all hover:bg-amber-50 group has-[:checked]:border-amber-500">
                <div class="flex items-center gap-4">
                  <div class="w-10 h-10 rounded-lg bg-white border border-amber-200 flex items-center justify-center text-amber-600"><i class="fas fa-coins"></i></div>
                  <div>
                    <p class="text-xs font-bold text-slate-900">السماح المؤقت بسالب الصندوق</p>
                    <p class="text-[10px] text-slate-600 font-medium mt-1">يسمح بخروج نقدي يتجاوز رصيد الصندوق أثناء جلسة مفتوحة، ولا يعمل عند تعطيل إلزام جلسات الكاشير.</p>
                  </div>
                </div>
                <input v-model="formData.sessions.allow_negative_intraday" type="checkbox" class="w-5 h-5 rounded border-amber-300 text-amber-600 focus:ring-0 cursor-pointer" />
              </label>
              <p class="text-[11px] leading-6 text-slate-500">خيار السالب يشمل المدفوعات النقدية الخارجة مثل دفع المورد والمصروف ورد النقدية، ولا يسجل رأس مال أو تمويلًا تلقائيًا. لا ينطبق على الشراء الآجل، ولا يسمح بسالب البنك أو المحفظة. إذا كان إلزام الجلسات معطلاً، لن تُطلب جلسة، لكن سيظل الصندوق غير مسموح له بتجاوز رصيده إلى السالب.</p>
            </div>
          </div>

        </div>

        <!-- Sticky Operational Footer -->
        <footer class="px-8 md:px-10 py-6 bg-slate-900 border-t border-white/5 flex flex-col md:flex-row items-center justify-between gap-6 shrink-0 relative overflow-hidden">
          <div class="absolute right-0 top-0 w-32 h-32 bg-white/5 rounded-full translate-x-12 -translate-y-12"></div>
          
          <div class="order-2 md:order-1 relative z-10">
             <button @click="skipSetup" :disabled="saving" class="text-[10px] font-bold text-slate-400 uppercase tracking-widest hover:text-white transition-colors">تخطي الإعداد حالياً</button>
          </div>

          <div class="flex items-center gap-3 w-full md:w-auto order-1 md:order-2 relative z-10">
            <button v-if="currentStep > 0" @click="previousStep" class="flex-1 md:flex-none px-6 h-10 rounded-lg border border-white/10 text-white/60 text-xs font-bold uppercase transition-all hover:bg-white/5 active:scale-95">
              السابق
            </button>

            <LoadingButton
              v-if="currentStep < steps.length - 1"
              text="الاستمرار"
              :isLoading="saving"
              loadingText="جاري..."
              icon="fas fa-chevron-left"
              variant="primary"
              loaderType="dots"
              @click="nextStep"
              class="flex-1 md:flex-none px-10 h-10"
            />

            <LoadingButton
              v-if="currentStep === steps.length - 1"
              text="إنهاء التهيئة"
              :isLoading="saving"
              loadingText="جاري..."
              icon="fas fa-check-circle"
              variant="primary"
              loaderType="dots"
              @click="saveSetup"
              class="flex-1 md:flex-none px-12 h-10"
            />
          </div>
        </footer>
      </div>

      <p class="text-center text-[9px] font-bold text-slate-300 uppercase tracking-[0.3em]">System Initialization Engine v2.4</p>
    </div>
  </div>
</template>

<script>
// [بقاء المنطق البرمجي كما هو بنسبة 100%]
// تم الحفاظ على كافة الـ imports، الـ setup، الـ data، الـ mounted، والـ methods الأصلية.
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useSettingsStore } from '@/stores/settings/settingsStore';
import { useSetupStore } from '@/stores/setup/setupStore';
import AlertService from '@/services/AlertService';
import { getAvailableCurrencies, getCurrencySymbol } from '@/config/currencies';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import { isSettingEnabled } from '@/utils';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';

export default {
  name: 'Setup',
  components: { LoadingButton },
  setup() {
    const authStore = useAuthStore();
    const branchStore = useBranchStore();
    const settingsStore = useSettingsStore();
    const setupStore = useSetupStore();
    return { authStore, branchStore, settingsStore, setupStore };
  },
  data() {
    return {
      loading: true,
      saving: false,
      currentStep: 0,
      getAvailableCurrencies,
      steps: [
        { label: 'معلومات المنشأة' },
        { label: 'الضرائب' },
        { label: 'الفروع' },
        { label: 'الأجهزة' },
        { label: 'الفواتير' },
        { label: 'الجلسات' }
      ],
      existingTerminals: [],
      formData: {
        company: { name: '', address: '', phone: '', email: '', currency: 'EGP', currency_code: 'EGP', currency_symbol: 'جنيه' },
        tax: { tax_enabled: false, tax_name: 'ضريبة القيمة المضافة', tax_rate: 14, tax_number: '' },
        branch: { name: '', location: '', phone: '', email: '' },
        terminal: { code: '', name: '', branch_id: '', location: '' },
        invoice: { prefix: 'INV-', next_number: 1001, footer_text: 'شكراً لتعاملكم معنا', show_tax_in_price: true },
        sessions: { enabled: false, allow_negative_intraday: false },
        print: { header_text: '', footer_text: 'شكراً لتعاملكم معنا', terms_text: '' }
      },
      branches: []
    };
  },
  mounted() { this.loadSetupStatus(); },
  methods: {
    updateCurrencyData() {
      const code = this.formData.company.currency;
      this.formData.company.currency_code = code;
      this.formData.company.currency_symbol = getCurrencySymbol(code);
    },
    async loadTerminals() {
      try {
        const { default: terminalsService } = await import('@/services/terminals');
        const data = await terminalsService.list({ status: undefined });
        this.existingTerminals = Array.isArray(data) ? data : [];
      } catch { this.existingTerminals = []; }
    },
    async loadSetupStatus() {
      try {
        const settingsRes = await this.settingsStore.fetchSettings();
        if (settingsRes?.status === 'success') {
          if (this.settingsStore.company) this.formData.company = { ...this.formData.company, ...this.settingsStore.company };
          if (this.settingsStore.tax) this.formData.tax = { ...this.formData.tax, ...this.settingsStore.tax };
          if (this.settingsStore.invoice) this.formData.invoice = { ...this.formData.invoice, ...this.settingsStore.invoice };
          if (this.settingsStore.print) this.formData.print = { ...this.formData.print, ...this.settingsStore.print };
          const setupSettings = settingsRes.data ?? settingsRes;
          const sessionsEnabled = setupSettings['pos.sessions.enabled']
            ?? setupSettings.settings?.['pos.sessions.enabled'];
          this.formData.sessions.enabled = isSettingEnabled(sessionsEnabled);
          const allowNegative = setupSettings['pos.cash_session.allow_negative_intraday']
            ?? setupSettings.settings?.['pos.cash_session.allow_negative_intraday'];
          this.formData.sessions.allow_negative_intraday = isSettingEnabled(allowNegative);
        }
        await this.loadTerminals();
        try {
          await this.branchStore.fetchBranches();
          if (this.branchStore.branches.length > 0) {
            this.branches = [...this.branchStore.branches];
            if (!this.formData.terminal.branch_id) this.formData.terminal.branch_id = this.branches[0].id;
          }
        } catch { /* ... */ }
        const statusResponse = await this.setupStore.loadSetupStatus();
        const tenant = statusResponse?.data?.data?.tenant ?? statusResponse?.data?.tenant ?? null;
        if (tenant?.is_setup_complete && this.$route.query.force !== 'true') {
          this.$router.push('/cashier');
        }
      } catch (error) {
        if (error?.response?.status !== 403) {
          const errorMessage = error.response?.data?.message || 'حدث خطأ في التحميل';
          this.$toast?.error(errorMessage, { position: 'top-center', duration: 5000 });
        }
      } finally { this.loading = false; }
    },
    async nextStep() {
      if (!this.validateCurrentStep()) return;
      if (this.currentStep === 2 && this.formData.branch.name?.trim()) {
        this.saving = true;
        try {
          const res = await this.branchStore.createBranch({
            name: this.formData.branch.name,
            location: this.formData.branch.location || '',
            phone: this.formData.branch.phone || undefined,
            email: this.formData.branch.email || undefined,
          });
          if (res?.status === 'success' && res?.data?.id) {
            this.branches = [...this.branchStore.branches];
            this.formData.terminal.branch_id = res.data.id;
          } else if (res?.status === 'error') {
            AlertService.warning(res.message || 'فشل إنشاء الفرع', 'تنبيه');
          }
        } catch (e) {
          const msg = e?.response?.data?.message || '';
          if (e?.response?.status === 409 || msg.toLowerCase().includes('duplicate')) await this.reloadBranches();
          else if (msg) AlertService.warning(msg, 'تنبيه');
        } finally { this.saving = false; }
      }
      if (this.currentStep === 3 && this.formData.terminal.code && this.formData.terminal.name) {
        try {
          const { default: terminalsService } = await import('@/services/terminals');
          await terminalsService.create({ code: this.formData.terminal.code, name: this.formData.terminal.name, branch_id: this.formData.terminal.branch_id || undefined, location: this.formData.terminal.location || undefined, status: 'active' });
          await this.loadTerminals();
        } catch (e) {
          const msg = e?.response?.data?.message || e?.message || '';
          if (msg) AlertService.warning(msg, 'تنبيه جهاز POS');
        }
      }
      this.currentStep++;
    },
    async reloadBranches() {
      try {
        await this.branchStore.fetchBranches();
        this.branches = [...this.branchStore.branches];
        if (this.branches.length > 0 && !this.formData.terminal.branch_id) this.formData.terminal.branch_id = this.branches[0].id;
      } catch { /* ... */ }
    },
    previousStep() { this.currentStep--; },
    validateCurrentStep() {
      if (this.currentStep === 0 && !this.formData.company.name) {
        AlertService.warning('يرجى إدخال اسم المنشأة للمتابعة', 'بيانات ناقصة');
        return false;
      }
      return true;
    },
    async saveSetup() {
      if (!this.validateCurrentStep()) return;
      this.saving = true;
      try {
        const payload = { ...this.formData, branch: this.branches.length > 0 ? null : this.formData.branch, current_step: 'complete' };
        const response = await this.setupStore.saveSetup(payload);
        if (response.status === 'success') {
          this.settingsStore.clearCache();
          useCompanyCurrency().clearCache();
          if (this.authStore.user) {
            this.authStore.user.is_setup_complete = 1;
            localStorage.setItem('user', JSON.stringify(this.authStore.user));
          }
          AlertService.success('تم إعداد النظام بنجاح!', 'تم بنجاح');
          this.$router.push('/cashier').catch(err => {
            if (!err?.message?.includes('Navigation aborted')) console.warn('Router push error:', err);
          });
        } else {
          AlertService.error(response.data?.message || response.message || 'فشل حفظ الإعدادات', 'خطأ');
        }
      } catch (error) {
        const errorMessage = error.response?.data?.message || error.message || 'خطأ في الحفظ';
        AlertService.error(errorMessage, 'خطأ');
      } finally { this.saving = false; }
    },
    async skipSetup() {
      const confirmed = await AlertService.confirm('هل أنت متأكد من تخطي الإعداد؟ يمكنك إكماله لاحقاً من الإعدادات العامة.', 'تنبيه');
      if (!confirmed) return;
      this.saving = true;
      try {
        const response = await this.setupStore.skipSetup();
        if (response.status === 'success') {
          if (this.authStore.user) {
            this.authStore.user.is_setup_complete = 1;
            localStorage.setItem('user', JSON.stringify(this.authStore.user));
          }
          this.$router.push('/cashier').catch(err => {
            if (!err?.message?.includes('Navigation aborted')) console.warn('Router push error:', err);
          });
        } else {
          AlertService.error(response.data?.message || response.message || 'فشل تخطي الإعداد', 'خطأ');
        }
      } catch (error) {
        AlertService.error(error.response?.data?.message || error.message || 'فشل تخطي الإعداد', 'خطأ');
      } finally { this.saving = false; }
    }
  }
};
</script>

<style scoped>
/* High-Density SaaS UI Tokens */
.metadata-label {
  @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 px-1;
}

.setup-input {
  @apply w-full h-10 bg-white border border-slate-200 rounded-md px-3 outline-none transition-all duration-300 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 text-xs font-medium text-slate-800 placeholder-slate-300;
}

.setting-toggle-row { @apply flex items-center justify-between transition-all; }

/* Custom Scrollbar */
.custom-scroll::-webkit-scrollbar { width: 4px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

/* Animations */
.animate-fadeIn { animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.slide-down-enter-active { transition: all 0.3s ease-out; }
.slide-down-enter-from { opacity: 0; transform: translateY(-5px); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.3s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>