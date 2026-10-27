<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">

    <!-- Global Loading Progress -->
    <PageLoadingBar :active="isLoading || statementLoading" />

    <main class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8 min-w-0">
      <PageHeader
        :breadcrumb="breadcrumb"
        :title="type === 'customers' ? 'تفاصيل العميل' : 'تفاصيل المورد'"
        :description="type === 'customers'
          ? 'بيانات العميل ورصيده وفواتيره وتحصيلاته.'
          : 'بيانات المورد ورصيده وفواتيره ومدفوعاته.'"
        :branches="branches"
        :selected-branch="statementBranchId"
        :has-explicit-selection="hasExplicitBranchSelection"
        @branch-changed="handleBranchChange"
      >
        <template #controls>
          <button
            @click="showFilters = !showFilters"
            :class="[showFilters ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 border-slate-200']"
            class="h-9 px-4 rounded-md border text-xs font-bold transition-all flex items-center gap-2 shadow-sm"
          >
            <i class="fas fa-filter text-[10px]"></i>
            {{ showFilters ? 'إخفاء أدوات التصفية' : 'تصفية النتائج' }}
          </button>
          <RouterLink v-if="type && id" :to="`/contacts/${type}/${id}/statement`"
                      class="h-9 px-4 rounded-md text-xs font-bold text-blue-600 bg-blue-50 border border-blue-100 hover:bg-blue-100 transition-all flex items-center gap-2">
            <i class="fas fa-file-invoice-dollar text-[10px]"></i> كشف الحساب
          </RouterLink>
          <button @click="goBack" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all flex items-center gap-2">
            <i class="fas fa-arrow-right text-[10px]"></i> العودة للقائمة
          </button>
        </template>
      </PageHeader>

      <!-- Loading State Overlay -->
      <div v-if="isLoading" class="py-32 text-center bg-white border border-slate-200 rounded-xl shadow-sm">
        <BaseSpinner :size="40" :color="type === 'customers' ? '#2563eb' : '#4f46e5'" />
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] mt-4 animate-pulse">جاري تحليل ملف البيانات...</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="!contact" class="py-32 text-center bg-white border border-slate-200 rounded-xl shadow-sm">
        <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center mx-auto mb-4">
          <i class="fas fa-user-slash text-2xl"></i>
        </div>
        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-widest">البيانات غير متوفرة</h3>
        <p class="text-xs text-slate-400 mt-1">تأكد من صحة الرابط أو صلاحيات الوصول.</p>
        <button @click="goBack" class="mt-6 text-xs font-bold text-blue-600 hover:underline">العودة للقائمة</button>
      </div>

      <!-- Main Profile Body -->
      <div v-else class="space-y-8 animate-fadeIn">

        <!-- Filters Panel -->
        <transition name="slide-down">
          <section v-if="showFilters" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden animate-fadeIn">
            <div class="p-6 space-y-6">
              <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="space-y-1.5">
                  <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">من تاريخ</label>
                  <div class="relative">
                    <input ref="startDateRef" type="date" v-model="startDate" @change="applyFilter" class="filter-input" style="padding-left: 2rem;" />
                    <i @click="startDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer text-slate-300 hover:text-slate-500 transition-colors text-[10px]"></i>
                  </div>
                </div>
                <div class="space-y-1.5">
                  <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">إلى تاريخ</label>
                  <div class="relative">
                    <input ref="endDateRef" type="date" v-model="endDate" @change="applyFilter" class="filter-input" style="padding-left: 2rem;" />
                    <i @click="endDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer text-slate-300 hover:text-slate-500 transition-colors text-[10px]"></i>
                  </div>
                </div>
                <div class="md:col-span-2 space-y-1.5">
                  <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">اختصارات زمنية</label>
                  <div class="flex flex-wrap gap-2">
                    <button v-for="range in [
                      { id: 'last7', label: 'آخر 7 أيام' },
                      { id: 'thisMonth', label: 'هذا الشهر' },
                      { id: 'prevMonth', label: 'الشهر السابق' },
                      { id: 'thisYear', label: 'هذا العام' },
                      { id: 'allTime', label: 'الكل' }
                    ]" :key="range.id" @click="setQuickRange(range.id)" class="filter-input w-auto px-3 cursor-pointer">{{ range.label }}</button>
                  </div>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end pt-4 border-t border-slate-50">
                <p class="text-[10px] font-bold text-slate-500 md:col-span-2">
                  الفترة المحددة:
                  <span class="text-blue-700 font-mono mx-1">{{ startDate }}</span>
                  <i class="fas fa-arrow-left text-[8px] text-slate-300"></i>
                  <span class="text-blue-700 font-mono mx-1">{{ endDate }}</span>
                </p>
                <LoadingButton
                  text="تحديث النتائج"
                  loadingText="جاري التحديث..."
                  icon="fas fa-sync-alt"
                  :isLoading="isLoading || statementLoading"
                  variant="secondary"
                  class="h-9 w-full rounded-md bg-slate-100 text-slate-600 text-[11px] hover:bg-slate-200 md:col-span-2"
                  @click="applyFilter"
                />
              </div>
            </div>
          </section>
        </transition>

        <!-- Profile Identity Header -->
        <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
          <div class="flex flex-col lg:flex-row">
            <div class="p-5 sm:p-8 flex-grow min-w-0 space-y-6">
              <div class="flex items-center gap-4 sm:gap-6 min-w-0">
                <div :class="[type === 'customers' ? 'bg-blue-50 text-blue-600' : 'bg-indigo-50 text-indigo-600']"
                     class="w-14 h-14 sm:w-20 sm:h-20 rounded-xl flex items-center justify-center text-2xl sm:text-4xl shadow-inner border border-white shrink-0">
                  <i :class="type === 'customers' ? 'fas fa-user' : 'fas fa-truck'"></i>
                </div>
                <div class="min-w-0">
                  <h3 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight break-words">{{ contact.name }}</h3>
                  <div class="flex flex-wrap gap-4 mt-2">
                    <span class="text-[11px] font-bold text-slate-400 flex items-center gap-2"><i class="fas fa-phone-alt text-blue-500 w-3"></i> {{ contact.phone || '—' }}</span>
                    <span class="text-[11px] font-bold text-slate-400 flex items-center gap-2"><i class="fas fa-envelope text-blue-500 w-3"></i> {{ contact.email || '—' }}</span>
                    <span v-if="contact.address" class="text-[11px] font-bold text-slate-400 flex items-center gap-2"><i class="fas fa-map-marker-alt text-blue-500 w-3"></i> {{ contact.address }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Balance Card: Dark Professional Accent -->
            <div v-if="canViewSensitiveData" class="p-5 sm:p-8 bg-slate-900 text-white w-full min-w-0 lg:w-[320px] lg:min-w-[320px] relative flex flex-col justify-center text-center">
              <div class="absolute top-0 left-0 w-32 h-32 bg-white/5 rounded-full -translate-x-12 -translate-y-12"></div>
              <p class="text-[10px] font-bold text-blue-400 uppercase tracking-[0.2em] mb-3 relative z-10">الرصيد الختامي للحساب</p>
              <div class="flex items-baseline justify-center gap-2 relative z-10">
                <span class="text-4xl font-bold tracking-tighter font-mono">{{ formatPrice(Math.abs(contact.balance)) }}</span>
                <span class="text-xs font-bold text-white/30 uppercase">{{ currencySymbol }}</span>
              </div>
              <p :class="[type === 'customers' ? (Number(contact.balance) > 0 ? 'text-rose-400' : 'text-emerald-400') : (Number(contact.balance) > 0 ? 'text-emerald-400' : 'text-rose-400')]"
                 class="mt-4 text-[10px] font-bold uppercase tracking-widest relative z-10">
                <i class="fas fa-info-circle ml-1"></i>
                {{ type === 'customers' ? (contact.balance > 0 ? 'مديونية مستحقة' : 'رصيد دائن') : (contact.balance > 0 ? 'ذمم دائنة (للمورد)' : 'رصيد مدين لنا') }}
              </p>
            </div>
            <div v-else class="p-5 sm:p-8 bg-slate-900 text-white w-full min-w-0 lg:w-[320px] lg:min-w-[320px] flex items-center justify-center">
               <p class="text-xs font-bold text-white/30 uppercase tracking-[0.2em]">🔒 بيانات خاصة</p>
            </div>
          </div>
        </section>

        <!-- Smart Decision Alerts -->
        <section v-if="hasOutstanding || hasCredit" class="grid grid-cols-1 gap-4">
          <div v-if="hasOutstanding" class="bg-white border-r-4 border-r-rose-500 border border-slate-200 rounded-xl p-4 sm:p-6 shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 group">
            <div class="flex items-center gap-4 sm:gap-5 min-w-0">
              <div class="w-12 h-12 bg-rose-50 text-rose-600 rounded-lg flex items-center justify-center shadow-inner shrink-0">
                <i class="fas fa-exclamation-circle text-xl"></i>
              </div>
              <div>
                <p class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-1">{{ type === 'customers' ? 'رصيد مستحق للتحصيل' : 'رصيد مستحق الدفع للمورد' }}</p>
                <p class="text-sm font-medium text-slate-500">يتطلب هذا الحساب إجراءً مالياً فورياً بقيمة <span class="font-bold text-rose-600 font-mono mx-1">{{ formatPrice(outstandingAmount) }}</span> {{ currencySymbol }}</p>
              </div>
            </div>
            <button @click="openPaymentModal" class="h-10 px-6 self-start sm:self-auto shrink-0 bg-slate-900 text-white rounded-lg text-[10px] font-bold uppercase tracking-widest hover:bg-black transition-all shadow-lg shadow-slate-200 active:scale-95">
              {{ type === 'customers' ? 'تحصيل الآن' : 'تسجيل دفع' }}
            </button>
          </div>

          <div v-if="hasCredit" class="bg-white border-r-4 border-r-emerald-500 border border-slate-200 rounded-xl p-4 sm:p-6 shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-4 sm:gap-5 min-w-0">
              <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-lg flex items-center justify-center shadow-inner shrink-0">
                <i class="fas fa-check-circle text-xl"></i>
              </div>
              <div>
                <p class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-1">رصيد دائن متاح للاستخدام</p>
                <p class="text-sm font-medium text-slate-500">يملك الحساب رصيداً فائضاً بقيمة <span class="font-bold text-emerald-600 font-mono mx-1">{{ formatPrice(creditAmount) }}</span> يمكن توظيفه في العمليات القادمة.</p>
              </div>
            </div>
            <button class="h-10 px-6 self-start sm:self-auto shrink-0 bg-emerald-600 text-white rounded-lg text-[10px] font-bold uppercase tracking-widest hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-900/20 active:scale-95">
              {{ type === 'customers' ? 'إصدار فاتورة' : 'أمر شراء جديد' }}
            </button>
          </div>
        </section>

        <!-- KPI Performance Grid -->
        <section v-if="activeTab === 'overview'" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 sm:gap-6">
          <div v-for="kpi in [
            { l: 'إجمالي الفواتير', v: contact.invoices_count ?? invoicesCount, i: 'fa-file-invoice', c: 'text-slate-400', bg: 'bg-slate-50' },
            { l: type === 'customers' ? 'إجمالي المبيعات' : 'إجمالي المشتريات', v: formatPrice(contact.invoices_total ?? invoicesTotal), i: 'fa-receipt', c: 'text-blue-600', bg: 'bg-blue-50' },
            { l: type === 'customers' ? 'التحصيلات المستلمة' : 'المدفوعات للمورد', v: formatPrice(contact.payments_total ?? paymentsTotal), i: 'fa-hand-holding-dollar', c: 'text-emerald-600', bg: 'bg-emerald-50' },
            { l: 'المتبقي المستحق', v: formatPrice(contact.balance ?? remainingTotal), i: 'fa-hourglass-half', c: 'text-rose-600', bg: 'bg-rose-50' }
          ]" :key="kpi.l" class="bg-white border border-slate-200 rounded-xl p-3 sm:p-5 shadow-sm space-y-3 sm:space-y-4 hover:border-slate-300 transition-all min-w-0">
            <div class="flex justify-between items-start">
              <div :class="[kpi.bg, kpi.c]" class="w-9 h-9 rounded-lg flex items-center justify-center text-sm shadow-inner"><i :class="['fas', kpi.i]"></i></div>
            </div>
            <div>
              <p class="text-[9px] font-bold text-slate-400 mb-1">{{ kpi.l }}</p>
              <p :class="[kpi.c]" class="text-base sm:text-lg font-bold font-mono tracking-tighter break-words">{{ kpi.v }}</p>
            </div>
          </div>

          <div class="bg-white border border-slate-200 rounded-xl p-3 sm:p-5 shadow-sm space-y-3 hover:border-slate-300 transition-all min-w-0">
            <div class="flex justify-between items-start">
              <div class="w-9 h-9 rounded-lg flex items-center justify-center text-sm shadow-inner bg-orange-50 text-orange-500"><i class="fas fa-rotate-left"></i></div>
            </div>
            <div>
              <p class="text-[9px] font-bold text-slate-400 mb-1">إجمالي المرتجعات</p>
              <p class="text-base sm:text-lg font-bold font-mono tracking-tighter break-words" :class="returnsTotalValue > 0 ? 'text-orange-500' : 'text-slate-300'">{{ formatPrice(returnsTotalValue) }}</p>
            </div>
            <div class="space-y-1.5 pt-2 border-t border-slate-50">
              <div class="flex justify-between items-center">
                <span class="text-[8px] font-bold text-slate-400">مسترد نقداً</span>
                <span class="text-[9px] font-bold text-rose-500 font-mono">{{ formatPrice(refundsCash) }}</span>
              </div>
              <div v-if="returnsAppliedToInvoices > 0" class="flex justify-between items-center">
                <span class="text-[8px] font-bold text-slate-400">مستخدم لتسوية فواتير</span>
                <span class="text-[9px] font-bold text-amber-500 font-mono">{{ formatPrice(returnsAppliedToInvoices) }}</span>
              </div>
              <div v-if="returnsTotal > 0" class="flex justify-between items-center">
                <span class="text-[8px] font-bold text-slate-400">رصيد دائن متاح</span>
                <span class="text-[9px] font-bold text-teal-600 font-mono">{{ formatPrice(returnsTotal) }}</span>
              </div>
            </div>
          </div>
        </section>

        <!-- Navigation Segmented Tabs -->
        <section class="flex items-center justify-center min-w-0">
          <div class="flex w-full sm:w-fit overflow-x-auto bg-slate-100 p-1 rounded-lg border border-slate-200/50 custom-scroll">
            <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
                    :class="[activeTab === tab.id ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']"
                    class="px-3 sm:px-8 py-2 rounded-md text-[10px] sm:text-[11px] font-bold transition-all uppercase tracking-wider flex items-center gap-2 whitespace-nowrap shrink-0">
              <i :class="[tab.icon, 'text-[10px]']"></i>
              {{ tab.name }}
            </button>
          </div>
        </section>

        <!-- Tab Content Rendering -->
        <div class="min-h-[500px]">

          <!-- ══════════════════════════════════════
               TAB: Overview & Aging
               ══════════════════════════════════════ -->
          <div v-if="activeTab === 'overview'" class="space-y-10 animate-fadeIn">
            
            <!-- Smart Credit Alert: Refined -->
            <transition name="fade">
              <div v-if="overpaymentCredit > 0" class="bg-white border border-slate-200 rounded-xl p-4 sm:p-6 shadow-sm flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6 relative overflow-hidden">
                <div class="absolute left-0 top-0 bottom-0 w-1 bg-emerald-500"></div>
                <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-lg flex items-center justify-center shrink-0 shadow-inner">
                  <i class="fas fa-wallet text-xl"></i>
                </div>
                <div class="min-w-0">
                  <h4 class="text-xs font-bold text-slate-900 tracking-wider">تنبيه الرصيد الدائن</h4>
                  <p class="text-sm font-medium text-slate-500 mt-1">
                    يوجد رصيد متاح في الحساب بقيمة <span class="font-bold text-emerald-600 font-mono mx-1">{{ formatPrice(overpaymentCredit) }}</span>
                    {{ type === 'customers' ? 'لصالح العميل (مقدمات دفع)' : 'لصالحكم لدى المورد' }}.
                  </p>
                </div>
              </div>
            </transition>

            <!-- Aging Analysis: SAP/Oracle Architecture -->
            <div v-if="canViewAgingAnalysis" class="space-y-6">
              <div class="flex items-center gap-3 px-1">
                 <div class="w-1.5 h-6 bg-gradient-to-b from-amber-400 to-rose-500 rounded-full"></div>
                 <h3 class="text-sm font-bold text-slate-900 tracking-widest">تحليل أعمار المديونية</h3>
              </div>

              <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="p-5 sm:p-8 grid grid-cols-1 md:grid-cols-4 gap-5 md:gap-8 md:divide-x md:divide-slate-100 md:divide-x-reverse">
                  
                  <div v-for="bucket in [
                    { l: 'فواتير حالية', v: agingBreakdown.current, s: '0 - لم تستحق', c: 'text-emerald-600', bg: 'bg-emerald-500' },
                    { l: '1 - 30 يوم', v: agingBreakdown._1to30, s: 'تأخير منخفض', c: 'text-amber-500', bg: 'bg-amber-400' },
                    { l: '31 - 60 يوم', v: agingBreakdown._31to60, s: 'تأخير متوسط', c: 'text-orange-500', bg: 'bg-orange-500' },
                    { l: '+60 يوم', v: agingBreakdown._60plus, s: 'تأخير حرج', c: 'text-rose-600', bg: 'bg-rose-600' }
                  ]" :key="bucket.l" class="space-y-4 px-2">
                    <div class="flex justify-between items-end">
                      <div class="space-y-1">
                        <p class="text-[10px] font-bold text-slate-400 uppercase">{{ bucket.l }}</p>
                        <p class="text-lg font-bold font-mono tracking-tighter" :class="bucket.c">{{ formatPrice(bucket.v) }}</p>
                      </div>
                      <span class="text-[9px] font-bold text-slate-300 uppercase">{{ Math.round((bucket.v / Math.max(agingBreakdown.current + agingBreakdown._1to30 + agingBreakdown._31to60 + agingBreakdown._60plus, 1)) * 100) }}%</span>
                    </div>
                    <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                       <div class="h-full rounded-full transition-all duration-1000" :class="bucket.bg" :style="{ width: Math.max((bucket.v / Math.max(agingBreakdown.current + agingBreakdown._1to30 + agingBreakdown._31to60 + agingBreakdown._60plus, 1)) * 100, 2) + '%' }"></div>
                    </div>
                    <p class="text-[8px] font-bold text-slate-400 italic">{{ bucket.s }}</p>
                  </div>
                </div>
              </div>
            </div>

            <div v-else class="bg-white border border-orange-200 rounded-xl p-8 text-center shadow-sm">
              <i class="fas fa-lock text-3xl text-orange-400 mb-3 block"></i>
              <h4 class="text-sm font-bold text-orange-900 mb-1">🔒 بيانات محدودة الوصول</h4>
              <p class="text-xs text-orange-600">ليس لديك صلاحية لعرض تحليل أعمار الديون المستحقة</p>
            </div>
          </div>

          <!-- ══════════════════════════════════════
               TAB: Transactions (Audit Ledger)
               ══════════════════════════════════════ -->
          <div v-else-if="activeTab === 'transactions'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm animate-fadeIn">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
              <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
                <i class="fas fa-list-ol text-blue-500"></i> سجل الحركات المالية التفصيلي
              </h3>
              <div class="flex items-center gap-3">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">حجم الصفحة:</label>
                <select v-model.number="txPageSize" class="h-7 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none bg-white">
                  <option :value="10">10</option><option :value="20">20</option><option :value="50">50</option>
                </select>
              </div>
            </div>

            <div class="overflow-x-auto max-h-[60vh] custom-scroll">
              <table class="w-full text-right border-collapse">
                <thead>
                  <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                    <th class="px-6 py-4">التاريخ</th>
                    <th class="px-4 py-4">نوع الحركة</th>
                    <th class="px-6 py-4">البيان / الوصف</th>
                    <th class="px-4 py-4">المرجع</th>
                    <th class="px-4 py-4">مدين (+)</th>
                    <th class="px-4 py-4">دائن (-)</th>
                    <th class="px-6 py-4 text-left">الرصيد</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 font-medium text-xs">
                  <!-- ✅ RESTORED: حالة التحميل والفراغ -->
                  <tr v-if="isLoading">
                    <td colspan="7" class="py-16 text-center">
                      <BaseSpinner :size="28" :color="type === 'customers' ? '#2563eb' : '#4f46e5'" />
                    </td>
                  </tr>
                  <tr v-else-if="!transactions.length">
                    <td colspan="7" class="py-16 text-center text-slate-300">
                      <i class="fas fa-list-ol text-3xl mb-3 block"></i>
                      <p class="font-bold text-[11px] uppercase">لا توجد حركات مالية مسجلة</p>
                    </td>
                  </tr>
                  <tr v-else v-for="t in transactions" :key="t.id" class="hover:bg-slate-50 transition-colors group">
                    <td class="px-6 py-4 text-[10px] font-mono font-bold text-slate-400 group-hover:text-slate-900">{{ formatDate(t.date) }}</td>
                    <td class="px-4 py-4 font-bold text-slate-700">{{ transactionTypeText(t.type || t.transaction_type) }}</td>
                    <td class="px-6 py-4 text-slate-500 italic max-w-xs truncate" :title="t.description">{{ t.description }}</td>
                    <td class="px-4 py-4 font-mono text-slate-400 text-[10px] uppercase tracking-tighter">{{ t.reference || '-' }}</td>
                    <td class="px-4 py-4 font-bold font-mono tracking-tighter" :class="t.debit > 0 ? 'text-rose-600' : 'text-slate-200'">{{ t.debit > 0 ? formatPrice(t.debit) : '—' }}</td>
                    <td class="px-4 py-4 font-bold font-mono tracking-tighter" :class="t.credit > 0 ? 'text-emerald-600' : 'text-slate-200'">{{ t.credit > 0 ? formatPrice(t.credit) : '—' }}</td>
                    <td class="px-6 py-4 text-sm font-bold font-mono tracking-tighter text-left" :class="t.balance === 0 ? 'text-slate-400' : (t.balance < 0 ? 'text-emerald-700' : 'text-rose-700')">
                      {{ formatPrice(t.balance) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Table Footer Pagination -->
            <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
              <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">إجمالي الحركات: {{ totalRecords }} (الصفحة الحالية: {{ transactions.length }})</span>
              <PaginationControls
                :page="page"
                :total-pages="totalPages || 1"
                show-page-indicator
                @previous="page = Math.max(1, page - 1)"
                @next="page++"
              />
            </div>
          </div>

          <!-- ══════════════════════════════════════
               TAB: Invoices (Document Registry)
               ══════════════════════════════════════ -->
          <div v-else-if="activeTab === 'invoices'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm animate-fadeIn">
            <div class="p-6 bg-slate-50/30 border-b border-slate-100 flex items-center gap-3">
              <span class="w-1.5 h-5 bg-blue-600 rounded-full"></span>
              <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest">سجل الفواتير والمستندات</h3>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-right border-collapse">
                <thead>
                  <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                    <th class="px-6 py-4">تاريخ الإصدار</th>
                    <th class="px-4 py-4">الرقم المرجعي</th>
                    <th class="px-4 py-4 text-center">الحالة</th>
                    <th class="px-4 py-4 text-right">الأصناف</th>
                    <th class="px-4 py-4 text-right">الإجمالي</th>
                    <th class="px-4 py-4 text-right">المدفوع</th>
                    <th class="px-6 py-4 text-right">المتبقي</th>
                    <!-- ✅ RESTORED: عمود القيد المحاسبي -->
                    <th class="px-4 py-4 text-center">قيد محاسبي؟</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 font-medium text-xs">
                  <!-- ✅ RESTORED: حالة التحميل والفراغ -->
                  <tr v-if="isLoading">
                    <td colspan="8" class="py-16 text-center">
                      <BaseSpinner :size="28" :color="type === 'customers' ? '#2563eb' : '#4f46e5'" />
                    </td>
                  </tr>
                  <tr v-else-if="!invoices.length">
                    <td colspan="8" class="py-16 text-center text-slate-300">
                      <i class="fas fa-file-invoice text-3xl mb-3 block"></i>
                      <p class="font-bold text-[11px] uppercase">لا توجد فواتير مسجلة</p>
                    </td>
                  </tr>
                  <tr v-else v-for="inv in sortedInvoices" :key="inv.id" class="hover:bg-slate-50 transition-all group">
                    <td class="px-6 py-4 text-[10px] font-mono text-slate-400">{{ formatDate(inv.invoice_date || inv.created_at) }}</td>
                    <td class="px-4 py-4">
                      <RouterLink :to="invoiceRoute(inv)" class="text-blue-600 font-bold hover:underline">
                        {{ inv.invoice_number || ('#' + inv.id) }}
                      </RouterLink>
                    </td>
                    <td class="px-4 py-4 text-center">
                      <!-- ✅ RESTORED: تلوين كامل لكل حالات الفاتورة بدل ثنائي مدفوعة/غير مدفوعة -->
                      <span :class="['status-badge',
                        (inv.dynamic_status || inv.status) === 'returned' || (inv.dynamic_status || inv.status) === 'closed_by_return' ? 'text-orange-700 border-orange-100 bg-orange-50'
                        : (inv.dynamic_status || inv.status) === 'paid'                                          ? 'text-emerald-700 border-emerald-100 bg-emerald-50'
                        : (inv.dynamic_status || inv.status) === 'settled_by_return'                             ? 'text-teal-700 border-teal-100 bg-teal-50'
                        : (inv.dynamic_status || inv.status) === 'settled_by_credit'                             ? 'text-cyan-700 border-cyan-100 bg-cyan-50'
                        : ((inv.dynamic_status || inv.status) === 'partial' || (inv.dynamic_status || inv.status) === 'partially_paid' || (inv.dynamic_status || inv.status) === 'pending_payment') ? 'text-amber-700 border-amber-100 bg-amber-50'
                        : ((inv.dynamic_status || inv.status) === 'rejected' || (inv.dynamic_status || inv.status) === 'canceled' || (inv.dynamic_status || inv.status) === 'cancelled') ? 'text-red-700 border-red-100 bg-red-50'
                        : 'text-yellow-700 border-yellow-100 bg-yellow-50']" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                        {{ inv.status_label || statusText(inv.dynamic_status || inv.status) }}
                      </span>
                    </td>
                    <td class="px-4 py-4 text-right font-mono text-slate-400">{{ inv.items_count || 0 }}</td>
                    <td class="px-4 py-4 text-right font-bold text-slate-900 font-mono">{{ formatPriceEn((Number(inv.net_total_amount ?? inv.total_amount ?? 0) + Number(inv.tax_amount ?? 0))) }}</td>
                    <td class="px-4 py-4 text-right font-bold text-emerald-600 font-mono">{{ formatPriceEn(inv.actual_paid_amount ?? inv.paid_amount ?? 0) }}</td>
                    <td class="px-6 py-4 text-right font-bold font-mono" :class="getInvoiceRemaining(inv) > 0 ? 'text-rose-600' : 'text-slate-200'">{{ formatPriceEn(getInvoiceRemaining(inv)) }}</td>
                    <!-- ✅ RESTORED: مؤشر وجود قيد محاسبي -->
                    <td class="px-4 py-4 text-center">
                      <span v-if="inv.has_journal" class="inline-flex items-center justify-center w-6 h-6 bg-emerald-100 text-emerald-600 rounded-lg text-[10px]" title="قيد محاسبي موجود">✓</span>
                      <span v-else class="text-slate-300 font-bold">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ══════════════════════════════════════
               TAB: Payments (Financial Ledger)
               ══════════════════════════════════════ -->
          <div v-else-if="activeTab === 'payments'" class="grid grid-cols-1 gap-6 animate-fadeIn">
            
            <!-- Receipts/Payments List -->
            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
              <div class="p-4 sm:p-6 bg-slate-50/50 border-b border-slate-100 flex flex-wrap items-center gap-3">
                <span class="w-1.5 h-5 bg-emerald-500 rounded-full"></span>
                <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest">{{ type === 'customers' ? 'سجل المقبوضات النقدية' : 'سجل الدفعات للمورد' }}</h3>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                  <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                      <th class="px-6 py-4">التاريخ</th>
                      <th class="px-4 py-4">المرجع / المستند</th>
                      <th class="px-4 py-4">طريقة الدفع</th>
                      <th class="px-6 py-4 text-left">المبلغ</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-50 font-medium text-xs text-slate-700">
                    <!-- ✅ RESTORED: حالة التحميل -->
                    <tr v-if="isLoading">
                      <td colspan="4" class="py-12 text-center"><BaseSpinner :size="24" :color="type === 'customers' ? '#2563eb' : '#4f46e5'" /></td>
                    </tr>
                    <tr v-else-if="!receiptPayments.length">
                      <td colspan="4" class="py-12 text-center text-slate-300 font-bold uppercase text-[10px]">لا توجد دفعات مسجلة</td>
                    </tr>
                    <tr v-else v-for="p in sortedReceiptPayments" :key="p.id" class="hover:bg-slate-50 transition-all font-bold">
                      <td class="px-6 py-4 text-[10px] font-mono text-slate-400">{{ formatDateEn(p.payment_date || p.date) }}</td>
                      <td class="px-4 py-4">
                        <p class="text-slate-800 leading-none">{{ p.invoice_number ? `فاتورة ${p.invoice_number}` : (p.source_label || p.reference_label || paymentRef(p)) }}</p>
                        <p v-if="p.invoice_number" class="text-[9px] text-blue-600 mt-1">{{ paymentSourceLabel(p) }}</p>
                        <p class="text-[9px] text-slate-400 mt-1 uppercase tracking-tighter">رقم العملية: {{ p.id }}</p>
                      </td>
                      <td class="px-4 py-4">
                        <span class="px-2 py-1 bg-slate-100 rounded text-[9px] font-bold text-slate-500 border border-slate-200 uppercase">{{ p.payment_method_name || 'نقدي' }}</span>
                      </td>
                      <td class="px-6 py-4 text-left font-bold font-mono tracking-tighter text-sm text-emerald-600">+ {{ formatPriceEn(p.amount) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Refund/Debit Ledger -->
            <div v-if="refundPayments.length" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
              <div class="p-6 bg-rose-50/30 border-b border-rose-100 flex items-center gap-3">
                <span class="w-1.5 h-5 bg-rose-400 rounded-full"></span>
                <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest">مدفوعات المرتجعات (صرف)</h3>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                  <!-- ✅ RESTORED: رأس الجدول (كان مفقوداً بالكامل) -->
                  <thead>
                    <tr class="bg-rose-50/50 border-b border-rose-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                      <th class="px-6 py-4">التاريخ</th>
                      <th class="px-4 py-4">المرجع / المستند</th>
                      <th class="px-4 py-4">طريقة الدفع</th>
                      <th class="px-6 py-4 text-left">المبلغ المصروف</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-50 font-medium text-xs text-slate-700">
                    <tr v-for="p in sortedRefundPayments" :key="p.id" class="hover:bg-slate-50 transition-all font-bold">
                      <td class="px-6 py-4 text-[10px] font-mono text-slate-400 w-1/4">{{ formatDateEn(p.payment_date || p.date) }}</td>
                      <!-- ✅ RESTORED: تفضيل reference_label القادم من الـ API قبل الاشتقاق اليدوي -->
                      <td class="px-4 py-4 w-1/4">{{ p.reference_label || paymentRef(p) }}</td>
                      <td class="px-4 py-4 w-1/4"><span class="px-2 py-1 bg-rose-50 rounded text-[9px] font-bold text-rose-500 border border-rose-100 uppercase">{{ p.payment_method_name || 'نقدي' }}</span></td>
                      <td class="px-6 py-4 text-left font-bold font-mono tracking-tighter text-sm text-rose-500 w-1/4">- {{ formatPriceEn(p.amount) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

          </div>
        </div>
      </div>
    </main>

    <!-- Payment / Collection Modal — Teleported to body to escape Layout stacking context -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="showPaymentModal"
          class="fixed inset-0 z-[300] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md"
          @click.self="showPaymentModal = false"
          @keydown.esc="showPaymentModal = false"
        >
          <div class="bg-white w-full max-w-md rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn" dir="rtl">
            <!-- Header -->
            <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
              <div>
                <h3 class="text-sm font-bold text-slate-900">{{ type === 'customers' ? 'تحصيل دفعة من' : 'تسجيل دفعة إلى' }}</h3>
                <p class="text-[10px] text-slate-400 mt-0.5 font-medium">{{ contact?.name }}</p>
              </div>
              <button type="button" @click="showPaymentModal = false"
                      class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-900 hover:bg-slate-100 transition-all flex items-center justify-center">
                <i class="fas fa-times text-xs"></i>
              </button>
            </div>

            <!-- Form -->
            <form @submit.prevent="submitPayment" class="px-8 py-6 space-y-4">
              <p v-if="pendingPayment" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[10px] font-semibold text-amber-800">
                توجد دفعة لم تُحسم نتيجتها. أعد المحاولة بالبيانات نفسها قبل بدء دفعة أخرى.
              </p>
              <!-- المبلغ -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">
                  المبلغ <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="paymentData.amount"
                  :disabled="!!pendingPayment"
                  type="number" step="0.01" min="0.01"
                  class="h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all font-mono"
                  placeholder="0.00" required
                />
                <button
                  v-if="outstandingAmount > 0"
                  type="button"
                  @click="paymentData.amount = outstandingAmount"
                  :disabled="!!pendingPayment"
                  class="text-[9px] font-bold text-blue-600 hover:underline px-1"
                >
                  تحصيل الرصيد كاملاً ({{ formatPrice(outstandingAmount) }})
                </button>
              </div>

              <!-- تاريخ الدفع -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">
                  تاريخ الدفع <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="paymentData.payment_date"
                  :disabled="!!pendingPayment"
                  type="date"
                  class="h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all font-mono"
                  required
                />
              </div>

              <!-- طريقة الدفع -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">
                  طريقة الدفع <span class="text-rose-500">*</span>
                </label>
                <select
                  v-model="paymentData.payment_method_id"
                  :disabled="!!pendingPayment"
                  class="h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all appearance-none"
                  required
                >
                  <option value="">اختر طريقة الدفع</option>
                  <option v-for="m in collectiblePaymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
              </div>

              <!-- الفرع -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">
                  الفرع <span class="text-rose-500">*</span>
                </label>
                <select
                  v-model="paymentData.branch_id"
                  :disabled="!!pendingPayment || !canSelectBranches"
                  class="h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all appearance-none"
                  required
                >
                  <option value="">اختر الفرع</option>
                  <option v-for="w in paymentBranches" :key="w.id" :value="w.id">{{ w.name || w.branch_name }}</option>
                </select>
              </div>

              <!-- ملاحظات -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">ملاحظات</label>
                <textarea
                  v-model="paymentData.notes"
                  :disabled="!!pendingPayment"
                  rows="2"
                  class="w-full bg-white border border-slate-200 rounded-md px-3 py-2 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all italic resize-none"
                  placeholder="مرجع التحويل، رقم الشيك..."
                ></textarea>
              </div>

              <!-- أزرار -->
              <div class="pt-4 border-t border-slate-100 flex gap-3">
                <button
                  type="button"
                  @click="showPaymentModal = false"
                  class="flex-1 h-10 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors rounded-md hover:bg-slate-50"
                >
                  إلغاء
                </button>
                <LoadingButton
                  type="submit"
                  :text="pendingPayment ? 'إعادة المحاولة بنفس البيانات' : (type === 'customers' ? 'تأكيد التحصيل' : 'تأكيد السداد')"
                  :isLoading="paymentLoading"
                  loadingText="جاري..."
                  :variant="type === 'customers' ? 'success' : 'indigo'"
                  class="flex-[2] h-10"
                />
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from '@/composables/useToast';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useStatementData } from '@/composables/useStatementData';
import { useStatementRBAC } from '@/composables/useStatementRBAC';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { usePendingIdempotentRequest } from '@/composables/usePendingIdempotentRequest';
import { getLocalDateISO, getImageUrl } from '@/utils';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';

// --- Core ---
const route = useRoute();
const router = useRouter();
const { showToast } = useToast();
const { fetchSettings, currencySymbol } = useCompanyCurrency();

const type = ref(route.params.type); // 'customers' | 'suppliers'
const id = ref(Number(route.params.id));
const breadcrumb = computed(() => ({
  parent: {
    label: type.value === 'customers' ? 'العملاء' : 'الموردون',
    path: type.value === 'customers' ? '/customers' : '/suppliers'
  },
  current: { label: contact.value?.name || (type.value === 'customers' ? 'تفاصيل العميل' : 'تفاصيل المورد') }
}));

// --- Composables ---
const {
  fetchStatementData,
  loading: statementLoading,
  error: statementError,
  data: statementData,
  totals,
  invoices,
  payments,
  transactions,
  returns,
  agingAnalysis,
  availableCredit,
  alertStatus,
  page,           // ← جديد: pagination
  perPage,        // ← جديد: pagination
  totalRecords,   // ← جديد: pagination
  totalPages,     // ← جديد: pagination
  currencySymbol: _currencySymbol,
  formatCurrencyLocale
} = useStatementData();

// --- RBAC Composable ---
const {
  canViewStatement,
  canExport,
  canViewSensitiveData,
  canViewAgingAnalysis,
  logAccess,
  logDenial
} = useStatementRBAC(type.value);

// --- Stores ---
const customerStore = useCustomerStore();
const supplierStore = useSupplierStore();
const pendingCustomerPayment = usePendingIdempotentRequest('customer-payment');
const pendingSupplierPayment = usePendingIdempotentRequest('supplier-payment');
const paymentStore = usePaymentStore();
const branchStore = useBranchStore();
const branchIsolation = useBranchIsolation();

// --- State ---
const isLoading = ref(false);
const contact = ref(null);
const activeTab = ref('overview');
const showFilters = ref(false);

// --- Payment Modal State ---
const showPaymentModal = ref(false);
const paymentLoading = ref(false);
const paymentData = ref({ amount: null, payment_date: null, payment_method_id: null, branch_id: null, notes: '' });
const pendingPayment = computed(() =>
  type.value === 'customers' ? pendingCustomerPayment.pending.value : pendingSupplierPayment.pending.value
);
const paymentMethods = computed(() => paymentStore.paymentMethods);
const collectiblePaymentMethods = computed(() =>
  paymentMethods.value.filter(method => String(method.kind || '').toLowerCase() !== 'credit')
);
const branches = computed(() => branchStore.branches);
const canSelectBranches = branchIsolation.isExempt;
const hasExplicitBranchSelection = computed(() =>
    Boolean(branchStore.selectedBranchId) || localStorage.getItem('selectedBranchId') === 'all'
);
const statementBranchId = computed(() => canSelectBranches.value
    ? branchStore.selectedBranchId
    : branchIsolation.currentBranchId.value);
const statementBranchParams = computed(() => statementBranchId.value
    ? { branch_id: Number(statementBranchId.value) }
    : {});
const paymentBranches = computed(() => {
    if (canSelectBranches.value) return branches.value;
    try {
        const requiredBranchId = branchIsolation.getRequiredBranchId();
        return branches.value.filter(branch => String(branch.id) === requiredBranchId);
    } catch {
        return [];
    }
});

// ─── Filter State ───────────────────────────────────────────────────────────
const today = new Date();
const yyyy = today.getFullYear();
const mm = String(today.getMonth() + 1).padStart(2, '0');
const dd = String(today.getDate()).padStart(2, '0');
const startOfMonth = `${yyyy}-${mm}-01`;
const startOfYear = `${yyyy}-01-01`;
const todayString = `${yyyy}-${mm}-${dd}`;

// ✅ IMPROVED: Use current year start as default
// This balances between:
// - Including all relevant current-year invoices (for aging analysis)
// - Not loading excessive historical data (better performance)
// Users can manually adjust if needed
const startDate = ref(startOfYear);  // ← Current year start (e.g., 2026-01-01)
const endDate = ref(todayString);
const startDateRef = ref(null);
const endDateRef = ref(null);
const previousFilter = ref({ startDate: startOfYear, endDate: todayString });  // ← fix double fetch

// Tabs definition (computed so names react to type changes)
const tabs = computed(() => [
  { id: 'overview',      name: 'نظرة عامة',                                     icon: 'fas fa-chart-pie' },
  { id: 'transactions',  name: 'الحركات المالية',                                icon: 'fas fa-exchange-alt' },
  { id: 'invoices',      name: type.value === 'customers' ? 'الفواتير' : 'فواتير الشراء', icon: 'fas fa-file-invoice' },
  { id: 'payments',      name: type.value === 'customers' ? 'القبض والتحصيل' : 'المدفوعات للمورد', icon: 'fas fa-wallet' },
]);

// Pagination for transactions (if needed)
const txPage = ref(1);
const txPageSize = ref(20);

// ─── Computed: Overview Totals (from unified composable) ────────────────────

const invoicesCount = computed(() => totals.value.invoicesCount);
const invoicesTotal = computed(() => totals.value.invoicesTotal);
const paymentsTotal = computed(() => totals.value.paymentsTotal);
const returnsTotal = computed(() => totals.value.returnsTotal);
const remainingTotal = computed(() => totals.value.remainingBalance);

// Debug: Watch statement data changes
watch(statementData, (newData) => {
  if (newData) {
    console.log('[ContactDetails] Statement data loaded:', {
      hasSalesOnly: !!newData.sales_only,
      hasReferences: !!newData.references,
      hasTransactions: !!newData.transactions,
      keys: Object.keys(newData),
      salesOnlyCount: newData.sales_only?.items?.length || 0,
      closingBalance: newData.closing_balance
    });
  }
}, { deep: true });

const returnsTotalValue = computed(() => {
  return returns.value.reduce((sum, r) => sum + Number(r.total_amount || 0), 0);
});

const refundsCash = computed(() => {
  return returns.value
    .filter(r => ['refund', 'return_payment'].includes(String(r.type).toLowerCase()))
    .reduce((sum, r) => sum + Number(r.total_amount || 0), 0);
});

const returnsAppliedToInvoices = computed(() => {
  const total = returnsTotalValue.value;
  const cash = refundsCash.value;
  const available = availableCredit.value;
  return Math.max(0, total - cash - available);
});

const overpaymentCredit = computed(() => availableCredit.value);

// ─── Computed: Smart Alerts ──────────────────────────────────────────────

const hasOutstanding = computed(() => {
  const bal = Number(contact.value?.balance ?? 0);
  return type.value === 'customers' ? bal > 0 : bal < 0;
});

const outstandingAmount = computed(() => {
  const bal = Number(contact.value?.balance ?? 0);
  if (type.value === 'customers') {
    return bal > 0 ? Math.abs(bal) : 0;
  } else {
    return bal < 0 ? Math.abs(bal) : 0;
  }
});

const hasCredit = computed(() => {
  const bal = Number(contact.value?.balance ?? 0);
  return type.value === 'customers' ? bal < 0 : bal > 0;
});

const creditAmount = computed(() => {
  const bal = Number(contact.value?.balance ?? 0);
  if (type.value === 'customers') {
    return bal < 0 ? Math.abs(bal) : 0;
  } else {
    return bal > 0 ? Math.abs(bal) : 0;
  }
});

// ─── Computed: Aging Analysis (from unified composable) ──────────────────

const agingBreakdown = computed(() => {
  const analysis = agingAnalysis.value || {};
  const result = {
    current: Number(analysis.current || 0),
    '_1to30': Number(analysis._1to30 || 0),
    '_31to60': Number(analysis._31to60 || 0),
    '_60plus': Number(analysis._60plus || 0)
  };
  console.log('[ContactDetails] Aging Analysis:', { analysis, result, invoicesCount: invoices.value.length });
  return result;
});

// Expose computed properties for template
watch([invoices, payments], () => {}, { deep: true });

// ─── Formatting Helpers ───────────────────────────────────────────────────

const formatDate    = (v) => v ? new Date(v).toLocaleDateString('en-GB') : '-';
const formatDateEn  = (v) => v ? new Date(v).toLocaleDateString('en-GB') : '-';
const formatPrice   = (v) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v || 0));
const formatPriceEn = (v) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v || 0));
const formatNumber  = (v) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(v) || 0);

const statusText = (s) => {
    const map = {
        'paid': 'مدفوعة',
        'settled': 'مسددة',
        'unpaid': 'غير مدفوعة',
        'partial': 'مدفوعة جزئياً',
        'pending_payment': 'آجل',
        'returned': 'مرتجعة',
        'settled_by_return': 'مسددة',
        'closed_by_return': 'مرتجعة',
        'settled_by_credit': 'مسوّاة بمرتجع',
        'settled_mixed': 'مسوّاة نقدي/إشعار دائن',
        'partially_paid': 'مدفوعة جزئياً',
        'rejected': 'مرفوضة',
        'canceled': 'ملغاة',
        'cancelled': 'ملغاة'
    };
    const v = String(s || '').toLowerCase();
    return map[v] || 'غير مدفوعة';
};

const transactionTypeText = (type) => {
    const map = {
        'sale': 'فاتورة بيع',
        'receipt': 'سند قبض',
        'cash_voucher': 'سند نقدي',
        'purchase': 'فاتورة شراء',
        'purchase_return': 'مرتجع شراء',
        'sales_return': 'مرتجع بيع',
        'refund': 'استرجاع',
        'payment': 'سند دفع',
        'journal': 'قيد محاسبي',
        'opening_balance': 'رصيد افتتاحي'
    };
    const v = String(type || '').toLowerCase().trim();
    return map[v] || v;
};

const getInvoiceRemaining = (inv) => {
  try {
    // Prefer explicit remaining_balance if provided by API
    if (inv?.remaining_balance != null && String(inv.remaining_balance).trim() !== '') {
      return Number(inv.remaining_balance) || 0;
    }

    // Fallback to outstanding if provided by statement API
    if (inv?.outstanding != null && String(inv.outstanding).trim() !== '') {
      return Number(inv.outstanding) || 0;
    }

    // Final fallback: compute from totals and paid fields
    const total = Number(inv?.net_total_amount ?? inv?.total_amount ?? inv?.grand_total ?? 0);
    const paid = Number(inv?.actual_paid_amount ?? inv?.paid ?? inv?.paid_amount ?? inv?.amount_paid ?? 0);
    return Math.max(0, total - paid);
  } catch {
    return 0;
  }
};

const invoiceRoute = (inv) => ({
    path: type.value === 'customers'
        ? `/sales/history?id=${inv.id}`
        : `/purchases/history?id=${inv.id}`
});

// Separate receipts from refunds
const receiptPayments = computed(() => payments.value.filter(p => p.type !== 'return_payment'));
const refundPayments  = computed(() => payments.value.filter(p => p.type === 'return_payment'));

// Sort data by date (oldest first) - fixed to match AccountStatement behavior
const sortedInvoices = computed(() => {
    if (!Array.isArray(invoices.value)) return [];
    return [...invoices.value].sort((a, b) => new Date(a?.date || a?.invoice_date || a?.created_at || 0).getTime() - new Date(b?.date || b?.invoice_date || b?.created_at || 0).getTime());
});

const sortedReceiptPayments = computed(() => {
    if (!Array.isArray(receiptPayments.value)) return [];
    return [...receiptPayments.value].sort((a, b) => new Date(a?.payment_date || a?.date || a?.created_at || 0).getTime() - new Date(b?.payment_date || b?.date || b?.created_at || 0).getTime());
});

const sortedRefundPayments = computed(() => {
    if (!Array.isArray(refundPayments.value)) return [];
    return [...refundPayments.value].sort((a, b) => new Date(a?.payment_date || a?.date || a?.created_at || 0).getTime() - new Date(b?.payment_date || b?.date || b?.created_at || 0).getTime());
});

// Full paymentRef with all reference-resolution strategies (from old file)
const paymentRef = (p) => {
    // 0) return_payment → صرف مرتجع label
    if (p?.type === 'return_payment') {
        const rid  = p?.return_id ?? p?.return_sale_id ?? p?.return?.id ?? p?.sales_return_id;
        const sid  = p?.sale_id ?? p?.invoice_id;
        if (rid)  return `صرف مرتجع #${rid}`;
        if (sid)  return `صرف مرتجع فاتورة #${sid}`;
        return `صرف مرتجع #${p?.id ?? '-'}`;
    }

    // 1) Explicit link fields
    const sid  = p?.sale_id ?? p?.invoice_id ?? p?.sale?.id;
    const prid = p?.purchase_id ?? p?.purchase?.id;
    const rid  = p?.return_id ?? p?.return_sale_id ?? p?.return?.id ?? p?.sales_return_id ?? p?.purchase_return_id;
    const cvId = p?.cash_voucher_id ?? p?.voucher_id ?? p?.cash_voucher?.id;

    if (sid)  return `فاتورة بيع #${sid}`;
    if (prid) return `فاتورة شراء #${prid}`;
    if (rid) {
        if (p?.purchase_id || /purchase/i.test(p?.reference || '')) return `مرتجع شراء #${rid}`;
        return `مرتجع بيع #${rid}`;
    }
    if (cvId) return `سند قبض #${cvId}`;

    // 2) Explicit numbers
    if (p?.receipt_number)  return `سند قبض #${p.receipt_number}`;
    if (p?.invoice_number)  return `فاتورة بيع #${p.invoice_number}`;
    if (p?.sale_number)     return `فاتورة بيع #${p.sale_number}`;
    if (p?.purchase_number) return `فاتورة شراء #${p.purchase_number}`;

    // 3) Parse raw reference prefix#id
    const raw = p?.reference || p?.reference_number || '';
    if (raw) {
        const [pref, num] = String(raw).split('#');
        const n   = num || raw;
        const key = String(pref || '').toLowerCase();
        if (key === 'sale' || key === 'sales')                               return `فاتورة بيع #${n}`;
        if (key === 'purchase' || key === 'purchases')                       return `فاتورة شراء #${n}`;
        if (key === 'purchase_return' || key === 'return_purchase')          return `مرتجع شراء #${n}`;
        if (key === 'return' || key === 'sales_return' || key === 'return_sale') return `مرتجع بيع #${n}`;
        if (key === 'cash_voucher')                                          return `سند قبض #${n}`;
        if (key === 'receipt')                                               return `سند قبض #${n}`;
        if (key === 'payment')                                               return `سند دفع #${n}`;
        return `#${n}`;
    }

    // 4) Last resort by id
    if (p?.id) return type.value === 'customers' ? `سند قبض #${p.id}` : `سند دفع #${p.id}`;
    return '-';
};

const paymentSourceLabel = (payment) => {
    if (payment?.source_label) return payment.source_label;
    if (payment?.source_payment_type === 'return_payment' || payment?.return_id) return 'استرداد مرتجع';
    if (payment?.source_payment_type === 'voucher') return 'سند قبض';
    if (payment?.source_payment_type === 'sale' || payment?.sale_id) return 'تحصيل مرتبط بفاتورة';
    if (payment?.source_payment_type === 'customer' || payment?.source_payment_type === 'debt_payment') return 'تحصيل من حساب العميل';
    if (payment?.type === 'return_payment' || payment?.return_id) return 'استرداد مرتجع';
    if (payment?.type === 'voucher' || payment?.type === 'cash_voucher') return 'سند قبض';
    if (payment?.type === 'sale' || payment?.sale_id) return 'تحصيل مرتبط بفاتورة';
    if (payment?.type === 'customer' || payment?.type === 'debt_payment') return 'تحصيل من حساب العميل';
    return type.value === 'customers' ? 'تحصيل من العميل' : 'دفعة إلى المورد';
};

// Build flexible params covering all backend key variants
const contactParams = computed(() => {
    const pid = id.value;
    return type.value === 'customers'
        ? { contact_id: pid, customer_id: pid, party_id: pid }
        : { contact_id: pid, supplier_id: pid, party_id: pid };
});

// ─── Payload Extraction Helper ────────────────────────────────────────────

const extractContactPayload = (payload) => {
    if (!payload) return null;
    if (payload.id)          return payload;
    if (payload.customer?.id) return payload.customer;
    if (payload.supplier?.id) return payload.supplier;
    if (payload.data)        return extractContactPayload(payload.data);
    return null;
};

// ─── API: Fetch Contact (3-tier failover) ─────────────────────────────────

const fetchContact = async () => {
    isLoading.value = true;
    contact.value = null;

    // Use appropriate store based on contact type
    try {
        if (type.value === 'customers') {
            const response = await customerStore.fetchCustomers({ id: id.value });
            if (response.status === 'success') {
              const customer = customerStore.getCustomerById(id.value);
              if (customer) { 
                contact.value = customer; 
                isLoading.value = false; 
                return; 
              }
            }
        } else if (type.value === 'suppliers') {
            await supplierStore.fetchSuppliers({ id: id.value });
            const supplier = supplierStore.getSupplierById(id.value);
            if (supplier) { 
                contact.value = supplier; 
                isLoading.value = false; 
                return; 
            }
        }
    } catch (eStore) {
        console.error('store fetch failed', eStore);
        showToast('Failed to load contact data', 'error');
    }

    if (!contact.value) {
        showToast('لا توجد بيانات لهذه الجهة. تأكد من صحة الرابط أو الصلاحيات.', 'error');
    }
    isLoading.value = false;
};

// ─── Filter Functions ────────────────────────────────────────────────────────

const applyFilter = async () => {
    if (!startDate.value || !endDate.value) {
        showToast('يجب تحديد فترة زمنية صحيحة', 'warning');
        return;
    }
    
    const result = await fetchStatementData(type.value, id.value, {
        start_date: startDate.value,
        end_date: endDate.value,
        include_references: 1,
        status: 'any',
        ...statementBranchParams.value,
    });

    if (result.status !== 'success') {
        handleStatementError(result);
    }
};

const handleBranchChange = async (branchId) => {
    if (!canSelectBranches.value) return;
    branchStore.setSelectedBranch(branchId);
    page.value = 1;
    const result = await fetchStatementData(type.value, id.value, {
        start_date: startDate.value,
        end_date: endDate.value,
        include_references: 1,
        status: 'any',
        ...statementBranchParams.value,
    }, { force: true });
    if (result.status !== 'success') {
        handleStatementError(result);
    }
};

const setQuickRange = async (kind) => {
    const d = new Date();
    if (kind === 'last7') {
        const s = new Date(d);
        s.setDate(d.getDate() - 6);
        startDate.value = getLocalDateISO(s);
        endDate.value = getLocalDateISO(d);
    } else if (kind === 'thisMonth') {
        // ✅ FIX: استخدم اليوم الحالي بدل آخر يوم من الشهر لتجنب التواريخ المستقبلية
        startDate.value = getLocalDateISO(new Date(d.getFullYear(), d.getMonth(), 1));
        endDate.value = getLocalDateISO(d);  // ← استخدم اليوم الحالي بدل آخر يوم من الشهر
    } else if (kind === 'prevMonth') {
        startDate.value = getLocalDateISO(new Date(d.getFullYear(), d.getMonth() - 1, 1));
        endDate.value = getLocalDateISO(new Date(d.getFullYear(), d.getMonth(), 0));
    } else if (kind === 'thisYear') {
        // ✅ FIX: استخدم اليوم الحالي بدل 31 ديسمبر لتجنب التواريخ المستقبلية
        startDate.value = getLocalDateISO(new Date(d.getFullYear(), 0, 1));
        endDate.value = getLocalDateISO(d);  // ← استخدم اليوم الحالي بدل نهاية السنة
    } else if (kind === 'allTime') {
        startDate.value = '2020-01-01';
        endDate.value = getLocalDateISO(d);
    }
    await applyFilter();
};

// ─── Error Handling ───────────────────────────────────────────────────────

/**
 * معالجة الأخطاء من جلب البيانات
 * يوفر تجربة مستخدم أفضل بناءً على نوع الخطأ
 */
const handleStatementError = (result) => {
    if (!result.error) return;

    const { type, message, retryable, showRetry } = result.error;

    if (type === 'session_expired') {
        showToast('انتهت جلستك. يرجى تسجيل الدخول من جديد.', 'error');
        setTimeout(() => router.push('/login'), 1500);
        return;
    }

    if (type === 'permission_denied') {
        showToast('ليس لديك صلاحية لعرض هذه البيانات.', 'error');
        return;
    }

    if (type === 'not_found') {
        showToast('البيانات المطلوبة غير موجودة.', 'error');
        return;
    }

    showToast(message || 'فشل تحميل البيانات', 'error');
};

// ─── Navigation ───────────────────────────────────────────────────────────

const goBack = () => {
    try {
        if (type.value === 'customers') { router.push({ name: 'CustomersManagement' }); return; }
        if (type.value === 'suppliers') { router.push({ name: 'SuppliersManagement' }); return; }
    } catch (_) { /* ignore, fallback below */ }
    router.back();
};

// ─── Payment Collection ────────────────────────────────────────────────────

const openPaymentModal = () => {
    try {
        const requiredBranchId = canSelectBranches.value
            ? branchStore.selectedBranchId
            : branchIsolation.getRequiredBranchId();
        if (!requiredBranchId && !canSelectBranches.value) {
            showToast('لم يتم تعيين فرع لحسابك.', 'error');
            return;
        }
        const requestStore = type.value === 'customers' ? pendingCustomerPayment : pendingSupplierPayment;
        const pending = requestStore.restore(id.value);
        if (type.value === 'suppliers' && pending?.payload?.purchases?.length) {
            showToast('توجد دفعة مخصصة لفواتير. أعد المحاولة من صفحة إدارة الموردين.', 'error');
            return;
        }
        paymentData.value = pending?.payload
            ? { ...pending.payload }
            : {
                amount: outstandingAmount.value > 0 ? outstandingAmount.value : null,
                payment_date: getLocalDateISO(new Date()),
                payment_method_id: null,
                branch_id: branchStore.selectedBranchId || null,
                notes: ''
            };
        if (!canSelectBranches.value) {
            paymentData.value.branch_id = requiredBranchId;
        }
        showPaymentModal.value = true;
    } catch (error) {
        showToast(error.message || 'تعذر استعادة عملية الدفع السابقة.', 'error');
    }
};

const submitPayment = async () => {
    if (!paymentData.value.amount || paymentData.value.amount <= 0) {
        showToast('ادخل مبلغ صحيح', 'error');
        return;
    }
    if (!paymentData.value.payment_method_id) {
        showToast('اختر طريقة الدفع', 'error');
        return;
    }
    if (!paymentData.value.payment_date) {
        showToast('الرجاء تحديد تاريخ الدفع', 'error');
        return;
    }
    if (!paymentData.value.branch_id) {
        showToast('اختر فرعاً لتسجيل الدفعة', 'error');
        return;
    }
    if (!canSelectBranches.value) {
        try {
            paymentData.value.branch_id = branchIsolation.getRequiredBranchId();
        } catch (error) {
            showToast(error.message || 'لم يتم تعيين فرع لحسابك.', 'error');
            return;
        }
    }

    paymentLoading.value = true;
    try {
        const requestStore = type.value === 'customers' ? pendingCustomerPayment : pendingSupplierPayment;
        const payload = type.value === 'customers'
            ? {
                amount: Number(paymentData.value.amount),
                payment_date: paymentData.value.payment_date,
                payment_method_id: Number(paymentData.value.payment_method_id),
                branch_id: paymentData.value.branch_id ? Number(paymentData.value.branch_id) : undefined,
                notes: paymentData.value.notes || '',
            }
            : {
                supplier_id: Number(id.value),
                amount: Number(paymentData.value.amount),
                payment_date: paymentData.value.payment_date,
                payment_method_id: Number(paymentData.value.payment_method_id),
                branch_id: paymentData.value.branch_id ? String(paymentData.value.branch_id) : undefined,
                notes: paymentData.value.notes || '',
            };
        const pending = requestStore.prepare(id.value, payload);
        const res = type.value === 'customers'
            ? await customerStore.recordCustomerPayment(id.value, pending.payload, pending.key)
            : await supplierStore.paySupplierDebt(pending.payload, pending.key);
        if (res.status === 'success') {
            requestStore.clear(id.value);
            showToast('تم التحصيل بنجاح', 'success');
            showPaymentModal.value = false;
            // Refresh statement data to reflect updated balance
            await fetchStatementData(type.value, id.value, {
                start_date: startDate.value,
                end_date: endDate.value,
                include_references: 1,
                status: 'any',
                ...statementBranchParams.value,
            }, { force: true });
            if (type.value === 'customers') {
                const refreshedCustomer = await customerStore.fetchCustomers({
                    id: id.value,
                    force: true,
                });
                if (refreshedCustomer.status === 'success' && refreshedCustomer.data) {
                    contact.value = { ...contact.value, ...refreshedCustomer.data };
                } else {
                    showToast('تم تسجيل الدفعة، لكن تعذر تحديث الرصيد. أعد تحميل بيانات العميل.', 'warning');
                }
            }
        } else {
            if (res.outcomeUnknown) {
                showToast('تعذر تأكيد نتيجة الدفعة. أعد المحاولة بالبيانات نفسها.', 'error');
            } else {
                requestStore.clear(id.value);
                showToast(res.message || 'فشل التسجيل', 'error');
            }
        }
    } catch (e) {
        showToast(e.response?.data?.message || e.message || 'فشل التسجيل', 'error');
    } finally {
        paymentLoading.value = false;
    }
};

// ─── Lifecycle ────────────────────────────────────────────────────────────

onMounted(async () => {
    // Check RBAC: User must have permission to view statements
    if (!canViewStatement.value) {
        showToast('ليس لديك صلاحية لعرض كشوفات الحسابات', 'error');
        logDenial('view_statement', type.value, id.value, 'insufficient_permission');
        router.back();
        return;
    }

    // Log access for audit trail
    logAccess('view_statement', type.value, id.value);

    // Prefill from sessionStorage for instant UI
    try {
        const raw = sessionStorage.getItem('selectedContact');
        if (raw) { const cached = JSON.parse(raw); if (cached?.id) contact.value = cached; }
    } catch (_) {}

    // Load all data: settings and contact info first, then all unified data from statement API
    await Promise.all([fetchSettings(), fetchContact(), paymentStore.fetchPaymentMethods().catch(() => {}), branchStore.fetchBranches().catch(() => {})]);
    
    // Single unified call replaces fetchInvoices + fetchPayments + fetchTransactions
    const result = await fetchStatementData(type.value, id.value, {
        start_date: startDate.value,
        end_date: endDate.value,
        include_references: 1,
        status: 'any',
        ...statementBranchParams.value,
    });

    if (result.status !== 'success') {
        handleStatementError(result);
    }
});

// Re-fetch everything when route params change (navigation between contacts)
watch(() => route.params, async (nv) => {
    type.value = nv.type;
    id.value = Number(nv.id);
    await fetchContact();
    
    const result = await fetchStatementData(type.value, id.value, {
        start_date: startDate.value,
        end_date: endDate.value,
        include_references: 1,
        status: 'any',
        ...statementBranchParams.value,
    });

    if (result.status !== 'success') {
        handleStatementError(result);
    }
});

// ─── Combined Watcher: Pagination + Filter ─────────────────────────────────
// مراقبة موحدة لتجنب Double Fetch
// عند تغيير الصفحة → Fetch بدون reset
// عند تغيير التصفية → Reset الصفحة ثم Fetch

watch([page, perPage, startDate, endDate], async () => {
    // اكتشف هل تغيّر الفلتر
    const filterChanged = 
        previousFilter.value.startDate !== startDate.value || 
        previousFilter.value.endDate !== endDate.value;
    
    if (filterChanged) {
        page.value = 1;  // Reset to page 1 only on filter change
        previousFilter.value = { startDate: startDate.value, endDate: endDate.value };
    }
    
    // Fetch once with all parameters
    const result = await fetchStatementData(type.value, id.value, {
        start_date: startDate.value,
        end_date: endDate.value,
        include_references: 1,
        status: 'any',
        page: page.value,
        per_page: perPage.value,
        ...statementBranchParams.value,
    });

    if (result.status !== 'success') {
        handleStatementError(result);
    }
}, { deep: true });

// Reload the relevant data when tab changes
watch(activeTab, async (tab) => {
    // For tab changes, we don't need to refetch as all data is already loaded from fetchStatementData
    // But if needed for specific tabs, you can conditionally refetch here
});
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v3 {
  @apply h-11 w-full bg-white border border-slate-200 rounded-xl px-3 text-xs font-semibold text-slate-700 hover:border-slate-300 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.metadata-label { @apply text-slate-500 tracking-wide mb-1; }

.quick-range-pill {
  @apply px-3.5 py-2 rounded-lg bg-white border border-slate-200 text-[10px] font-bold text-slate-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 transition-all;
}

.status-badge { @apply px-2 py-0.5 rounded text-[9px] font-bold border inline-flex items-center justify-center; }

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-10px); }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>