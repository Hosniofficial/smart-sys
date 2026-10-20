<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Global Loading Progress -->
    <div v-if="isLoadingData" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-50">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <main class="max-w-[1600px] mx-auto p-6 lg:p-8 space-y-8">
      <PageHeader
        :breadcrumb="{ parent: { label: 'الرئيسية', path: '/' }, current: 'لوحة تحكم الكاشير' }"
        :title="`أهلًا، ${cashierName} 👋`"
        :description="`تابع مبيعاتك، وحركة النقدية وحالة الوردية من مكان واحد.`"
        :branches="[]"
      >
        <template #controls>
          <div class="flex w-full flex-col items-stretch gap-2 md:w-auto md:items-end">
            <div class="flex flex-wrap items-center gap-2">
              <div v-if="canSelectBranches && branches.length" class="relative">
                <select
                  v-model="adminSelectedBranch"
                  @change="handleAdminBranchChange"
                  class="h-9 w-full rounded-lg border border-slate-200 bg-white pr-9 pl-4 text-xs font-bold text-slate-700 outline-none transition-all hover:border-slate-300 focus:ring-2 focus:ring-blue-500/10 sm:min-w-[160px]"
                >
                  <option :value="null" disabled>اختر الفرع</option>
                  <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
                <i class="fas fa-warehouse absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]"></i>
              </div>
              <span class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-[10px] font-bold text-slate-500 whitespace-nowrap" dir="rtl">
                <i class="fas fa-calendar-day text-slate-400"></i>
                {{ currentDate }}
                <span class="text-slate-300">•</span>
                <span class="font-mono text-slate-700">{{ currentTime }}</span>
              </span>
              <button
                @click="handleRefresh"
                :disabled="isLoadingData"
                aria-label="تحديث لوحة التحكم"
                title="تحديث لوحة التحكم"
                class="h-9 w-9 shrink-0 flex items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-400 shadow-sm transition-colors hover:text-blue-600 disabled:cursor-wait disabled:opacity-60"
              >
                <i class="fas fa-sync-alt text-xs" :class="{ 'animate-spin': isLoadingData }"></i>
              </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-[11px] font-bold text-slate-600 whitespace-nowrap">
                <i class="fas fa-clock text-slate-400"></i>
                <span>الوردية</span>
                <span v-if="currentShift?.id" class="font-mono text-slate-900">#{{ currentShift.id }}</span>
                <span v-else class="text-slate-500">غير محددة</span>
              </span>
              <span
                class="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-[11px] font-bold whitespace-nowrap"
                :class="activeSessionId ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700'"
                role="status"
                :aria-label="`حالة الجلسة: ${sessionStatus.text}`"
              >
                <i :class="sessionStatus.icon"></i>
                {{ sessionStatus.text }}
              </span>
              <button
                v-if="!activeSessionId"
                @click="triggerOpenSession"
                class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 text-xs font-bold text-white shadow-sm transition-colors hover:bg-emerald-700"
              >
                <i class="fas fa-play text-[10px]"></i>
                فتح الجلسة
              </button>
              <button
                v-else
                @click="attemptEndShift"
                class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 text-xs font-bold text-white shadow-sm transition-colors hover:bg-rose-700"
              >
                <i class="fas fa-power-off text-[10px]"></i>
                إنهاء الجلسة
              </button>
            </div>
          </div>
        </template>
      </PageHeader>
      
      <!-- KPI Grid -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <template v-if="isLoadingData && !hasLoadedOnce">
          <div v-for="n in 4" :key="n" class="h-28 bg-white border border-slate-200 rounded-xl animate-pulse"></div>
        </template>
        <template v-else>
          <div v-for="card in summaryCards" :key="card.id" class="group bg-white border border-slate-200 p-6 rounded-xl hover:border-blue-500/30 transition-all">
            <div class="flex justify-between items-start mb-3">
              <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ card.title }}</span>
              <div :class="[card.iconBg, card.iconColor, 'w-8 h-8 rounded-lg flex items-center justify-center text-xs opacity-80 group-hover:opacity-100 transition-opacity']">
                <i :class="card.icon"></i>
              </div>
            </div>
            <div class="flex items-baseline gap-2">
              <h3 :class="[card.valueColor, 'text-xl font-bold tracking-tight']">{{ formatPrice(card.value) }}</h3>
              <span v-if="card.tooltip" class="text-[9px] font-bold text-slate-300 uppercase">{{ card.tooltip }}</span>
            </div>
          </div>
        </template>
      </section>

      <!-- Cash Reconciliation: Stripe-inspired High Density Card -->
      <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
          <h2 class="text-sm font-bold flex items-center gap-2">
            <i class="fas fa-vault text-slate-400"></i>
            تسوية المدفوعات والسيولة
          </h2>
          <div class="flex items-center gap-4 text-[10px] font-bold text-slate-400 uppercase tracking-tighter">
            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-500"></span> نقدي</span>
            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-cyan-400"></span> إلكتروني</span>
          </div>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2">
          <!-- Physical Cash Container -->
          <div class="p-8 border-l border-slate-100 flex flex-col items-center justify-center text-center space-y-2">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">النقدية الفعلية بالخزينة</span>
            <div class="text-4xl font-bold text-slate-900 tracking-tighter">
              {{ formatPrice(dashboardStats.cashControl?.cashSales || cashDrawerTotal) }}
            </div>
            <span class="text-[10px] font-medium text-slate-400 italic">تشمل المبيعات النقدية والعهد الافتتاحية فقط</span>
          </div>

          <!-- Electronic Breakdown -->
          <div class="p-8 bg-slate-50/30">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
              <div v-for="(val, key) in { 'تحويل بنكي': 'bank', 'البطاقات': 'card', 'المحفظة': 'wallet', 'آجل': 'credit' }" :key="key" class="space-y-1">
                <p class="text-[10px] font-bold text-slate-400 uppercase">{{ key }}</p>
                <p class="text-lg font-bold text-slate-800">{{ formatPrice(dashboardStats.electronicSettlements?.[val] || 0) }}</p>
              </div>
            </div>
            <div class="pt-4 border-t border-slate-200 flex justify-between items-center">
              <span class="text-[11px] font-bold text-slate-500 uppercase tracking-widest">إجمالي التسويات الإلكترونية</span>
              <span class="text-xl font-bold text-blue-600">{{ formatPrice(dashboardStats.electronicSettlements?.total || 0) }}</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Main Operational Area -->
      <div class="grid grid-cols-1 items-stretch gap-8 lg:grid-cols-12">
        
        <!-- Left: Quick Actions -->
        <div class="h-[420px] sm:h-[460px] lg:col-span-4">
          <!-- Actions -->
          <div class="h-full bg-white border border-slate-200 rounded-2xl p-6 flex flex-col shadow-sm">
            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2">
              <i class="fas fa-bolt text-amber-400"></i> اختصارات العمليات
            </h3>
            <div class="grid flex-1 grid-cols-1 content-start gap-3">
              <button
                v-for="action in displayedQuickActions"
                :key="action.id"
                @click="handleQuickAction(action)"
                :disabled="action.id === 'refresh' && isLoadingData"
                :class="action.primary
                  ? 'border-blue-600 bg-blue-600 text-white shadow-md shadow-blue-100 hover:bg-blue-700'
                  : 'border-slate-200 bg-white text-slate-700 hover:border-blue-200 hover:bg-blue-50/50'"
                class="group flex w-full items-center justify-between gap-3 rounded-xl border px-4 py-3 text-right transition-all disabled:cursor-wait disabled:opacity-60"
              >
                <div class="flex min-w-0 items-center gap-3">
                  <span
                    :class="action.primary ? 'bg-white/15 text-white' : 'bg-slate-50 text-slate-400 group-hover:bg-blue-50 group-hover:text-blue-600'"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg transition-colors"
                  >
                    <i :class="[action.icon, action.id === 'refresh' && isLoadingData ? 'animate-spin' : '']" class="text-sm"></i>
                  </span>
                  <span class="min-w-0">
                    <span class="block truncate text-xs font-bold">{{ action.title }}</span>
                    <span :class="action.primary ? 'text-blue-100' : 'text-slate-400'" class="mt-1 block truncate text-[10px] font-medium">
                      {{ action.description }}
                    </span>
                  </span>
                </div>
                <i class="fas fa-chevron-left shrink-0 text-[10px] opacity-30 transition-opacity group-hover:opacity-70"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- Right: Sales Chart -->
        <div class="h-[420px] sm:h-[460px] lg:col-span-8">
          <!-- Main Sales Chart -->
          <div class="h-full bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm hover:shadow-md hover:shadow-slate-200/50 transition-shadow flex flex-col">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between shrink-0">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                  <i class="fas fa-chart-line"></i>
                </div>
                <div>
                  <h3 class="text-sm font-bold text-slate-900">منحنى المبيعات اللحظي</h3>
                  <p class="text-[11px] text-slate-400 mt-1">إجمالي المبيعات موزع على ساعات اليوم</p>
                </div>
              </div>
              <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 px-2.5 py-1 rounded-full">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                اليوم
              </span>
            </div>
            <div class="px-5 py-6 flex-1 min-h-0">
              <canvas ref="salesChart"></canvas>
            </div>
          </div>
        </div>
      </div>

      <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 items-stretch">
        <!-- Activity Feed -->
        <div class="h-[360px] bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm flex flex-col">
          <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-xs font-bold text-slate-800">النشاطات الأخيرة</h3>
            <span class="text-[10px] bg-slate-100 px-2 py-0.5 rounded font-bold text-slate-500">{{ recentActivities.length }}</span>
          </div>
          <div class="flex-1 overflow-y-auto custom-scroll divide-y divide-slate-50">
            <div v-for="activity in recentActivities" :key="activity.id" @click="handleActivityClick(activity)"
              class="p-4 hover:bg-slate-50 transition-colors cursor-pointer group flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div :class="[activity.colorClass, 'w-8 h-8 rounded-lg flex items-center justify-center text-[10px] bg-opacity-10']">
                  <i :class="['fas', activity.icon]"></i>
                </div>
                <div>
                  <p class="text-xs font-bold text-slate-800">{{ activity.description }}</p>
                  <p class="text-[9px] text-slate-400 font-medium" :title="activity.fullTime">{{ activity.time }}</p>
                </div>
              </div>
              <span :class="[activity.amount > 0 ? 'text-emerald-600' : 'text-rose-600']" class="text-xs font-bold font-mono">
                {{ formatPrice(activity.amount) }}
              </span>
            </div>
            <div v-if="!recentActivities.length" class="py-12 text-center text-slate-300">
              <p class="text-[10px] font-bold uppercase tracking-widest">لا توجد عمليات</p>
            </div>
          </div>
        </div>

        <div class="h-[360px] bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm flex flex-col">
          <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center"><i class="fas fa-chart-pie text-xs"></i></div>
            <div>
              <h3 class="text-xs font-bold text-slate-800">توزيع مبيعات الأصناف</h3>
              <p class="text-[10px] text-slate-400 mt-1">حسب الكمية المباعة</p>
            </div>
          </div>
          <div class="relative flex-1 min-h-0 px-4 py-5">
            <canvas ref="topProductsChart"></canvas>
            <div
              v-if="topProductsTotal > 0"
              class="pointer-events-none absolute left-1/2 top-[44%] flex aspect-square w-24 -translate-x-1/2 -translate-y-1/2 flex-col items-center justify-center rounded-full bg-white text-center"
            >
              <span class="text-[9px] font-medium text-slate-400">إجمالي الكمية</span>
              <span class="text-sm font-bold text-slate-900">{{ compactNumberFormat.format(topProductsTotal) }}</span>
            </div>
          </div>
        </div>

        <div class="h-[360px] bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm flex flex-col">
          <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center"><i class="fas fa-trophy text-xs"></i></div>
            <div>
              <h3 class="text-xs font-bold text-slate-800">الأصناف الأكثر طلباً</h3>
              <p class="text-[10px] text-slate-400 mt-1">أفضل المنتجات أداءً اليوم</p>
            </div>
          </div>
          <div class="flex-1 overflow-y-auto custom-scroll p-5 space-y-3">
            <div v-for="(product, index) in topProducts" :key="product.id" class="flex items-center justify-between group">
              <div class="flex items-center gap-3 min-w-0">
                <span class="text-[10px] font-black text-slate-300 w-4 shrink-0">{{ index + 1 }}</span>
                <div class="min-w-0">
                  <p class="text-xs font-bold text-slate-800 truncate max-w-[140px]">{{ product.name }}</p>
                  <p class="text-[10px] text-slate-400 font-medium">{{ formatNumber(product.quantity) }} {{ product.unit }}</p>
                </div>
              </div>
              <p class="text-xs font-bold text-slate-700 shrink-0">{{ formatPrice(product.totalSales) }}</p>
            </div>
            <div v-if="!topProducts.length" class="py-10 text-center text-slate-300 italic text-[10px]">
              لا توجد مبيعات مسجلة اليوم
            </div>
          </div>
        </div>
      </section>
    </main>

    <!-- Modals: Redesigned as Minimal Overlays -->
    <div v-if="openSessionModal || shiftState === 'ending'" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
      
      <!-- Open Session Modal -->
      <div v-if="openSessionModal" ref="openSessionModalRef" class="bg-white w-full max-w-md rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn" role="dialog" aria-modal="true" aria-labelledby="open-session-title" @keydown.esc="openSessionModal = false" tabindex="-1">
        <div class="p-6 border-b border-slate-100 bg-slate-50">
          <h3 id="open-session-title" class="text-base font-bold text-slate-900">بدء جلسة عمل جديدة</h3>
          <p class="text-[10px] text-slate-500 font-medium uppercase mt-1">يرجى تأكيد العهدة الافتتاحية للموقع</p>
        </div>
        <div class="p-6 space-y-5">
          <div v-if="authStore.isAdmin">
            <label class="text-[10px] font-bold text-slate-400 uppercase mb-2 block">الفرع المختار للعمل</label>
            <div class="h-10 border border-slate-200 rounded-lg px-3 flex items-center bg-blue-50 text-sm font-bold text-blue-700">
              {{ branches.find(b => b.id === adminSelectedBranch)?.name || 'لم يتم التحديد' }}
            </div>
          </div>
          <div v-if="terminals && terminals.length > 0">
            <label class="text-[10px] font-bold text-slate-400 uppercase mb-2 block">جهاز نقطة البيع</label>
            <select v-model="selectedTerminalId" class="w-full h-10 border border-slate-200 rounded-lg px-3 text-sm">
              <option :value="null" disabled>-- اختر جهازاً --</option>
              <option v-for="t in terminals" :key="t.id" :value="t.id">{{ t.code }} - {{ t.name }}</option>
            </select>
            <p v-if="!selectedTerminalId" class="text-[9px] text-rose-500 mt-1">⚠️ يجب اختيار جهاز</p>
          </div>
          <div v-else class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
            <div class="flex items-start gap-3">
              <i class="fas fa-exclamation-triangle text-amber-600 mt-0.5"></i>
              <div class="flex-1">
                <p class="text-xs font-bold text-amber-900">لا توجد أجهزة نقاط بيع</p>
                <p class="text-[10px] text-amber-700 mt-1">يرجى إضافة جهاز نقطة بيع (Terminal) للفرع من صفحة الإعدادات أولاً.</p>
              </div>
            </div>
          </div>
          <div>
            <label class="text-[10px] font-bold text-slate-400 uppercase mb-2 block">المبلغ النقدي الافتتاحي (العهدة)</label>
            <div class="relative">
              <input v-model.number="openingCashAmount" type="number" class="w-full h-12 border border-slate-200 rounded-lg px-4 text-xl font-bold text-center text-blue-600 focus:border-blue-500 outline-none transition-all" />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-300">{{ formatCurrencyLocale(0, 0).replace('0', '').trim() }}</span>
            </div>
          </div>
        </div>
        <div class="p-6 bg-slate-50 flex gap-3">
          <button @click="openSessionModal = false" class="flex-1 h-10 text-xs font-bold text-slate-500 hover:text-slate-700 transition-colors">إلغاء</button>
          <button @click="confirmOpenSession" :disabled="isOpeningSession || !selectedTerminalId" class="flex-[2] h-10 bg-blue-600 text-white rounded-lg text-xs font-bold shadow-sm hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
            {{ isOpeningSession ? 'جارٍ الفتح...' : 'تأكيد وبدء العمل' }}
          </button>
        </div>
      </div>

      <!-- End Shift/Session Modal -->
      <div v-if="shiftState === 'ending'" ref="endShiftModalRef" class="bg-white w-full max-w-lg rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn" role="dialog" aria-modal="true" aria-labelledby="end-shift-title" @keydown.esc="shiftState = 'active'" tabindex="-1">
        <div class="p-6 border-b border-slate-100 bg-slate-50">
          <h3 id="end-shift-title" class="text-base font-bold text-slate-900">إغلاق الجلسة وتصفية الخزينة</h3>
        </div>
        <div class="p-6 space-y-6">
          <div class="grid grid-cols-2 gap-4">
            <div v-for="item in [{l:'المبيعات', v:dashboardStats.sessionData?.total_sales, c:'text-emerald-600'}, {l:'المرتجعات', v:dashboardStats.totalReturns, c:'text-rose-600'}, {l:'الرصيد الافتتاحي', v:dashboardStats.openingBalance, c:'text-slate-600'}]" :key="item.l" class="p-3 bg-slate-50 rounded-lg">
              <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">{{ item.l }}</p>
              <p :class="[item.c, 'text-sm font-bold']">{{ formatPrice(item.v) }}</p>
            </div>
            <div class="p-3 bg-slate-900 rounded-lg text-center">
              <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">المتوقع بالخزينة</p>
              <p class="text-sm font-bold text-blue-400">{{ formatPrice(dashboardStats.sessionData?.expected_cash || expectedInDrawer) }}</p>
            </div>
          </div>
          
          <div class="space-y-4">
            <label class="text-[10px] font-bold text-slate-400 uppercase block text-center">المبلغ الفعلي الموجود حالياً بالخزينة</label>
            <input v-model.number="closingCashInput" type="number" class="w-full h-14 border-2 border-slate-100 rounded-xl px-4 text-2xl font-bold text-center focus:border-blue-500 outline-none transition-all" />
            
            <div v-if="closingCashInput !== null" :class="[cashDifference === 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700']" class="p-3 rounded-lg text-center text-xs font-bold">
              الفرق: {{ formatPrice(cashDifference) }}
            </div>
          </div>

          <div v-if="closingCashInput !== null && Math.abs(cashDifference) > 0.01" class="space-y-3 border-t border-slate-200 pt-4">
            <label class="text-xs font-bold text-slate-600 block">سبب العجز / الزيادة</label>
            <select v-model="selectedVarianceReason" @change="handleVarianceReasonChange" class="w-full h-10 px-3 border-2 border-slate-100 rounded-lg text-xs font-bold focus:border-blue-500 outline-none">
              <option v-for="reason in varianceReasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
            </select>
            <textarea v-if="selectedVarianceReason === 'other'" v-model="varianceReason" rows="2" class="w-full px-3 py-2 border-2 border-slate-100 rounded-lg text-xs font-bold focus:border-blue-500 outline-none resize-none" placeholder="يرجى كتابة التوضيح هنا..." />
          </div>
        </div>
        <div class="p-6 bg-slate-50 flex gap-3">
          <button @click="shiftState = 'active'" class="flex-1 h-10 text-xs font-bold text-slate-500">تراجع</button>
          <button @click="confirmEndShift" class="flex-[2] h-10 bg-rose-600 text-white rounded-lg text-xs font-bold hover:bg-rose-700 shadow-sm transition-all">إغلاق وتصفية</button>
        </div>
      </div>

    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from '@/composables/useToast';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useTerminalStore } from '@/stores/terminal/terminalStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useSessionStore } from '@/stores/session/sessionStore';
import { useShiftStore } from '@/stores/shift/shiftStore';
import { useAnalyticsStore } from '@/stores/analytics/analyticsStore';
import PageHeader from '@/components/PageHeader.vue';
import Chart from 'chart.js/auto';
import { Filler } from 'chart.js';
import { localDateRangeToUTC, getLocalDateISO } from '@/utils';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useRequiredBranch } from '@/composables/useRequiredBranch';

Chart.register(Filler);

// ─── Composables ──────────────────────────────────────────────────────────────
const router = useRouter();
const { showToast } = useToast();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const authStore = useAuthStore();
const branchStore = useBranchStore();
const terminalStore = useTerminalStore();
const branchIsolation = useBranchIsolation();
const { ensureBranchSelected, setRequiredBranch } = useRequiredBranch();
const canSelectBranches = branchIsolation.isExempt;
const paymentStore = usePaymentStore();
const sessionStore = useSessionStore();
const shiftStore = useShiftStore();
const analyticsStore = useAnalyticsStore();

// ─── State ────────────────────────────────────────────────────────────────────
const cashierName = ref('مستخدم');
const currentTime = ref('');
const currentDate = ref('');
const branches = computed(() => branchStore.branches);

const effectiveBranchId = computed(() => {
  return canSelectBranches.value
    ? branchStore.selectedBranchId  // للأدمن: الفرع المختار من القائمة العلوية
    : branchIsolation.currentBranchId.value;  // للكاشير: فرعه الثابت
});

const terminals = computed(() => {
  try {
    const branchId = effectiveBranchId.value;
    if (!branchId) {
      return [];
    }
    
    const termsComputed = terminalStore.getTerminalsForBranch(branchId);
    const terms = termsComputed?.value || [];
    return Array.isArray(terms) ? terms : [];
  } catch (err) {
    return [];
  }
});

const selectedTerminalId = ref(null);
const paymentMethods = computed(() => paymentStore.paymentMethods);
const activeSessionId = ref(null);
const openSessionModal = ref(false);
const isOpeningSession = ref(false);
const openingCashAmount = ref(0);

const adminSelectedBranch = computed({
  get: () => branchStore.selectedBranchId,
  set: (val) => setRequiredBranch(val)
});

const handleAdminBranchChange = async () => {
  const newBranchId = adminSelectedBranch.value;
  
  // تحديث الفرع في الـ store فوراً
  if (newBranchId !== branchStore.selectedBranchId) {
    branchStore.setSelectedBranch(newBranchId);
  }
  
  selectedTerminalId.value = null;
  activeSessionId.value = null;
  currentShift.value = null;
  
  try {
    const wid = newBranchId;
    if (wid) {
      const loadedTerminals = await terminalStore.fetchTerminals(wid);
      if (loadedTerminals && loadedTerminals.length) {
        selectedTerminalId.value = loadedTerminals[0].id;
      }
    }
  } catch (e) {
    // ignore
  }
  
  await ensureCashierSession();
  await fetchDashboardData();
};

// Shift state
const shiftState = ref('active');
const closingCashInput = ref(null);
const currentShift = ref(null);

// Dashboard data
const dashboardStats = ref({
  openingBalance: 0,
  totalSales: 0,
  totalReturns: 0,
  cashInDrawer: 0,
  expenses: 0,
  sessionData: {},
  electronicSettlements: { bank: 0, card: 0, wallet: 0, credit: 0, total: 0 },
  cashControl: { cashSales: 0, cashRefunds: 0, manualCashIn: 0, manualCashOut: 0 }
});
const cashDrawerTotal = ref(0);
const recentActivities = ref([]);
const topProducts = ref([]);
const topProductsTotal = computed(() => topProducts.value.reduce(
  (total, product) => total + (Number(product?.quantity) || 0),
  0
));
const compactNumberFormat = new Intl.NumberFormat('ar-EG', { notation: 'compact', maximumFractionDigits: 1 });

// Charts
const salesChart = ref(null);
const topProductsChart = ref(null);
const salesChartInstance = ref(null);
const topProductsChartInstance = ref(null);

// UI
const isLoadingData = ref(false);
const hasLoadedOnce = ref(false);

const openSessionModalRef = ref(null);
const endShiftModalRef = ref(null);

const varianceReason = ref('');
const selectedVarianceReason = ref('');
const varianceReasons = [
  { value: '', label: '-- اختر سبب الفرق --' },
  { value: 'counting_error', label: 'خطأ في العد' },
  { value: 'unrecorded_income', label: 'إيراد غير مسجل' },
  { value: 'manual_payment', label: 'دفع يدوي' },
  { value: 'other', label: 'سبب آخر' }
];

let dateTimeInterval = null;
let dashboardAbortCtrl = null;

// ─── Product Colors ───────────────────────────────────────────────────────────
const getProductColor = (i) => [
  { bg: 'bg-blue-100', text: 'text-blue-600' },
  { bg: 'bg-indigo-100', text: 'text-indigo-600' },
  { bg: 'bg-emerald-100', text: 'text-emerald-600' },
  { bg: 'bg-amber-100', text: 'text-amber-600' },
  { bg: 'bg-rose-100', text: 'text-rose-600' }
][i % 5];

// ─── Computeds ────────────────────────────────────────────────────────────────
const expenses = computed(() => dashboardStats.value.expenses || 0);
const actualCashDrawer = computed(() => cashDrawerTotal.value || 0);

const expectedInDrawer = computed(() => {
  if (dashboardStats.value.sessionData?.expected_cash !== undefined && dashboardStats.value.sessionData?.expected_cash !== null) {
    return dashboardStats.value.sessionData.expected_cash;
  }
  const opening = dashboardStats.value.openingBalance || 0;
  const cashSales = dashboardStats.value.cashControl?.cashSales || 0;
  const cashRefunds = dashboardStats.value.cashControl?.cashRefunds || 0;
  const cashIn = dashboardStats.value.cashControl?.manualCashIn || 0;
  const cashOut = dashboardStats.value.cashControl?.manualCashOut || 0;
  return opening + cashIn + cashSales - cashRefunds - cashOut;
});

const cashDifference = computed(() => (closingCashInput.value || 0) - expectedInDrawer.value);

const summaryCards = computed(() => [
  { id: 'total-sales', title: 'إجمالي المبيعات اليوم', value: dashboardStats.value.totalSales, icon: 'fas fa-chart-line', iconBg: 'bg-emerald-50', iconColor: 'text-emerald-600', valueColor: 'text-emerald-700' },
  { id: 'expenses', title: 'المصروفات والسحوبات', value: expenses.value, icon: 'fas fa-file-invoice-dollar', iconBg: 'bg-blue-50', iconColor: 'text-blue-600', valueColor: 'text-blue-700' },
  { id: 'total-returns', title: 'المرتجعات اليومية', value: dashboardStats.value.totalReturns, icon: 'fas fa-undo', iconBg: 'bg-rose-50', iconColor: 'text-rose-600', valueColor: 'text-rose-700' },
  { id: 'cash-drawer', title: 'السيولة المتوفرة بالدرج', value: actualCashDrawer.value, icon: 'fas fa-cash-register', iconBg: 'bg-amber-50', iconColor: 'text-amber-600', valueColor: 'text-amber-700', tooltip: 'نقدي فقط (فعلي)' }
]);

const displayedQuickActions = computed(() => {
  return [
    { id: 'pos', title: 'بدء عملية بيع', description: 'فتح نقطة البيع وإصدار فاتورة', icon: 'fas fa-cash-register', route: '/sales/pos', primary: true },
    { id: 'history', title: 'سجل المبيعات', description: 'البحث عن الفواتير ومراجعتها', icon: 'fas fa-receipt', route: '/sales/history' },
    { id: 'refund', title: 'تسجيل مرتجع', description: 'فتح إدارة المرتجعات وبدء تسجيلها', icon: 'fas fa-undo', route: '/returns' },
    { id: 'payments', title: 'سجل العمليات المالية', description: 'مراجعة المقبوضات والمدفوعات', icon: 'fas fa-file-invoice-dollar', route: '/payments' },
    { id: 'refresh', title: 'تحديث اللوحة', description: 'جلب أحدث بيانات المبيعات والسيولة', icon: 'fas fa-sync-alt' }
  ];
});

const sessionStatus = computed(() => activeSessionId.value
  ? { active: true, text: 'الجلسة مفتوحة', color: 'text-emerald-600', bgColor: 'bg-emerald-100', icon: 'fas fa-check-circle' }
  : { active: false, text: 'الجلسة مغلقة', color: 'text-amber-600', bgColor: 'bg-amber-100', icon: 'fas fa-exclamation-triangle' }
);

// ─── Helpers ──────────────────────────────────────────────────────────────────
const formatPrice = (v) => formatCurrencyLocale(v, 2);
const formatNumber = (v) => (Number(v) || 0).toLocaleString('en-US');

const resolveQueryRange = (today) => {
  const useLocal = activeSessionId.value !== null;
  if (useLocal) {
    return { startDate: `${today} 00:00:00`, endDate: `${today} 23:59:59`, useLocal: true };
  }
  const { startUtcIso, endUtcIso } = localDateRangeToUTC(today, today);
  return {
    startDate: startUtcIso || `${today} 00:00:00`,
    endDate: endUtcIso || `${today} 23:59:59`,
    useLocal: false
  };
};

const formatRelativeTime = (date) => {
  try {
    const now = new Date();
    const d = typeof date === 'string' ? new Date(date) : (date instanceof Date ? date : new Date());
    const diffSec = Math.round((now - d) / 1000);
    const diffMin = Math.round(diffSec / 60);
    const diffHour = Math.round(diffMin / 60);
    const diffDay = Math.round(diffHour / 24);
    const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    if (Math.abs(diffSec) < 60) return rtf.format(-diffSec, 'second');
    if (Math.abs(diffMin) < 60) return rtf.format(-diffMin, 'minute');
    if (Math.abs(diffHour) < 48) return rtf.format(-diffHour, 'hour');
    return rtf.format(-diffDay, 'day');
  } catch {
    try { return new Date(date).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); } catch { return ''; }
  }
};

const updateDateTime = () => {
  const now = new Date();
  currentTime.value = now.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
  currentDate.value = now.toLocaleDateString('ar-EG', { weekday: 'short', day: 'numeric', month: 'short' });
};

const getDeviceIdentity = () => {
  let id = localStorage.getItem('pos_device_id');
  if (!id) { id = 'dev-' + Math.random().toString(36).slice(2, 8) + '-' + Date.now().toString(36).slice(-6); try { localStorage.setItem('pos_device_id', id); } catch {} }
  const custom = (localStorage.getItem('pos_device_name') || '').trim();
  const nameBase = custom || (typeof navigator !== 'undefined' ? (navigator.platform || navigator.userAgent) : 'POS Device');
  return { device_id: id, device_name: (custom || `POS ${nameBase}`).slice(0, 64) };
};

// ─── Charts ───────────────────────────────────────────────────────────────────
function initChart(chartRef, type, data, instanceRef) {
  if (!chartRef.value) return;
  
  if (!data || typeof data !== 'object') {
    data = { labels: [], data: [] };
  }
  
  const safeLabels = Array.isArray(data.labels) ? data.labels : [];
  const safeData = Array.isArray(data.data) ? data.data.map(d => Number(d) || 0) : [];

  const ctx = chartRef.value.getContext('2d');
  if (instanceRef.value) { instanceRef.value.destroy(); instanceRef.value = null; }

  if (!safeLabels.length || !safeData.length) {
    if (!ctx) return;
    ctx.clearRect(0, 0, chartRef.value.width, chartRef.value.height);
    ctx.save(); ctx.fillStyle = '#94a3b8'; ctx.font = '500 12px Cairo, sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText('لا توجد بيانات', chartRef.value.width / 2, chartRef.value.height / 2);
    ctx.restore(); return;
  }

  if (!ctx) return;
  const numberFormat = new Intl.NumberFormat('ar-EG', { notation: 'compact', maximumFractionDigits: 1 });
  const colors = ['#3b82f6', '#10b981', '#8b5cf6', '#f59e0b', '#f43f5e'];
  const lineGradient = (context) => {
    const area = context.chart.chartArea;
    if (!area) return 'rgba(37, 99, 235, 0.08)';
    const gradient = context.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
    gradient.addColorStop(0, 'rgba(37, 99, 235, 0.22)');
    gradient.addColorStop(1, 'rgba(37, 99, 235, 0.01)');
    return gradient;
  };

  instanceRef.value = new Chart(ctx, {
    type,
    data: {
      labels: safeLabels,
      datasets: [{
        label: type === 'bar' ? 'الكمية المباعة' : 'المبيعات',
        data: safeData,
        backgroundColor: type === 'line' ? lineGradient : colors,
        borderColor: type === 'line' ? '#2563eb' : '#fff',
        borderWidth: type === 'line' ? 2.5 : 4,
        fill: type === 'line',
        tension: 0.38,
        pointRadius: 0,
        pointHoverRadius: type === 'line' ? 5 : 0,
        pointHoverBackgroundColor: '#fff',
        pointHoverBorderColor: '#2563eb',
        pointHoverBorderWidth: 2,
        hoverOffset: type === 'doughnut' ? 7 : 0,
        borderRadius: type === 'doughnut' ? 5 : 0,
        spacing: type === 'doughnut' ? 2 : 0
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 650, easing: 'easeOutQuart' },
      interaction: { mode: 'index', intersect: false },
      indexAxis: type === 'bar' ? 'y' : 'x',
      layout: { padding: type === 'doughnut' ? 8 : { top: 8, right: 4, bottom: 0, left: 4 } },
      plugins: {
        legend: {
          display: type === 'doughnut',
          position: 'bottom',
          rtl: true,
          labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 14, color: '#64748b', font: { family: 'Cairo', size: 10 } }
        },
        tooltip: {
          rtl: true,
          textDirection: 'rtl',
          backgroundColor: '#0f172a',
          titleColor: '#cbd5e1',
          bodyColor: '#fff',
          padding: 12,
          cornerRadius: 10,
          displayColors: true,
          boxPadding: 5,
          bodyFont: { family: 'Cairo', size: 11 },
          titleFont: { family: 'Cairo', size: 11, weight: '600' },
          callbacks: {
            label: context => {
              if (type === 'doughnut') {
                const product = topProducts.value[context.dataIndex];
                return ` ${formatNumber(product?.quantity || 0)} ${product?.unit || 'قطعة'} — ${formatPrice(product?.totalSales || 0)}`;
              }
              return ` ${context.dataset.label}: ${numberFormat.format(Number(context.raw) || 0)}`;
            }
          }
        }
      },
      scales: type === 'doughnut' ? {} : {
        x: {
          beginAtZero: true,
          grid: { color: type === 'bar' ? '#f1f5f9' : 'transparent', drawTicks: false },
          border: { display: false },
          ticks: { font: { family: 'Cairo', size: 10 }, color: '#94a3b8', padding: 8, callback: value => type === 'bar' ? numberFormat.format(value) : value }
        },
        y: {
          beginAtZero: true,
          grid: { color: type === 'line' ? '#f1f5f9' : 'transparent', drawTicks: false },
          border: { display: false },
          ticks: { font: { family: 'Cairo', size: 10 }, color: '#64748b', padding: 8, callback: value => type === 'line' ? numberFormat.format(value) : value }
        }
      }
    }
  });
}

const updateSalesChart = (d) => initChart(salesChart, 'line', d, salesChartInstance);
const updateTopProductsChart = (d) => initChart(topProductsChart, 'doughnut', d, topProductsChartInstance);

// ─── API ──────────────────────────────────────────────────────────────────────
const fetchDashboardData = async () => {
  if (dashboardAbortCtrl) dashboardAbortCtrl.abort();
  const requestController = new AbortController();
  dashboardAbortCtrl = requestController;

  isLoadingData.value = true;

  try {
    const today = getLocalDateISO();
    const wid = branchStore.selectedBranchId;

    if (!wid) {
      console.log('No branch selected, skipping dashboard data');
      return;
    }

    let summary = null;
    let cashierRecentActivities = null;
    let cashierPaymentBreakdown = null;
    let cashierPeriodTotals = null;
    let cashierHourlySales = null;
    try {
      const dashboardData = await analyticsStore.fetchDashboardSummary({
        branchId: wid,
        sessionId: activeSessionId.value,
        // The cashier summary uses local database calendar dates; don't convert
        // these boundaries to UTC or the day filter can include the previous day.
        startDate: today,
        endDate: today,
        force: true,
        signal: requestController.signal
      });

      if (dashboardData) {
        summary = dashboardData.data;
        if (
          Number.isFinite(Number(summary?.totalSales)) &&
          Number.isFinite(Number(summary?.totalReturns)) &&
          Number.isFinite(Number(summary?.expenses))
        ) {
          cashierPeriodTotals = {
            totalSales: Number(summary.totalSales),
            totalReturns: Number(summary.totalReturns),
            expenses: Number(summary.expenses)
          };
        }
        if (Array.isArray(summary?.recentActivities)) {
          cashierRecentActivities = summary.recentActivities;
        }
        if (Array.isArray(summary?.hourly_sales)) {
          cashierHourlySales = summary.hourly_sales;
        }
        if (summary?.paymentBreakdown && typeof summary.paymentBreakdown === 'object') {
          cashierPaymentBreakdown = summary.paymentBreakdown;
        }
      }
    } catch (e) {
      const isAborted = e?.name === 'AbortError' || e?.name === 'CanceledError';
      if (!isAborted) showToast('فشل تحميل ملخص لوحة المعلومات', 'error');
      throw e;
    }

    if (activeSessionId.value) {
      try {
        const summaryRes = await sessionStore.getSessionSummary(activeSessionId.value);
        if (summaryRes?.status === 'success' && summaryRes.data) {
          summary = {
            ...summaryRes.data,
            ...(cashierRecentActivities !== null
              ? { recentActivities: cashierRecentActivities }
              : {}),
            ...(cashierHourlySales !== null
              ? { hourly_sales: cashierHourlySales }
              : {}),
            ...(cashierPaymentBreakdown !== null
              ? { paymentBreakdown: cashierPaymentBreakdown }
              : {}),
            ...(cashierPeriodTotals !== null
              ? {
                  totalSales: cashierPeriodTotals.totalSales,
                  totalReturns: cashierPeriodTotals.totalReturns,
                  expenses: cashierPeriodTotals.expenses
                }
              : {}),
          };
        }
      } catch (e) {
        if (e?.response?.status !== 404) {
          console.error('Session summary failed:', e);
        }
      }
    }

    const data = summary || {};
    const sTotals = summary?.totals || {};
    const sCalc = summary?.calculated || {};
    const sSalesSum = summary?.sales_summary || {};
    const sCashCtrl = summary?.cash_control || {};
    const sElectronicSettle = summary?.electronic_settlements || {};
    const paymentBreakdown = summary?.paymentBreakdown || {};
    const salesPaymentBreakdown = summary?.payment_breakdown || {};
    const bankSettlement = sElectronicSettle.bank ?? paymentBreakdown.bank_total ?? salesPaymentBreakdown.bank ?? 0;
    const cardSettlement = sElectronicSettle.card ?? paymentBreakdown.card_total ?? salesPaymentBreakdown.card ?? 0;
    const walletSettlement = sElectronicSettle.wallet ?? paymentBreakdown.wallet_total ?? salesPaymentBreakdown.wallet ?? 0;
    const creditSettlement = sElectronicSettle.credit ?? paymentBreakdown.credit_total ?? salesPaymentBreakdown.credit ?? 0;

    dashboardStats.value = {
      openingBalance: sCashCtrl.opening_float || sCalc.opening_balance || data.openingBalance || 0,
      totalSales: cashierPeriodTotals?.totalSales ?? sSalesSum.gross_sales ?? sTotals.total_sales ?? data.totalSales ?? data.net_grand_total ?? 0,
      totalReturns: cashierPeriodTotals?.totalReturns ?? data.returns_grand_total ?? data.totalReturns ?? 0,
      expenses: data.expenses || 0,
      sessionData: {
        total_sales: cashierPeriodTotals?.totalSales ?? sSalesSum.gross_sales ?? sTotals.total_sales ?? 0,
        expected_cash: sCashCtrl.expected_physical_cash || sCalc.expected_cash || 0,
        actual_physical_cash: sCashCtrl.actual_physical_cash || sCalc.closing_balance || null,
        variance: sCashCtrl.variance || sCalc.variance_amount || null
      },
      electronicSettlements: {
        bank: bankSettlement,
        card: cardSettlement,
        wallet: walletSettlement,
        credit: creditSettlement,
        total: sElectronicSettle.total_electronic ?? sElectronicSettle.total ?? (bankSettlement + cardSettlement + walletSettlement)
      },
      cashControl: {
        cashSales: sCashCtrl.cash_sales || 0,
        cashRefunds: sCashCtrl.cash_refunds || 0,
        manualCashIn: sCashCtrl.manual_cash_in || 0,
        manualCashOut: sCashCtrl.manual_cash_out || 0
      }
    };

    if (Array.isArray(data.recentActivities) && data.recentActivities.length > 0) {
      recentActivities.value = data.recentActivities.map(activity => {
        const createdAt = activity.created_at || null;
        const parsedAmount = parseFloat(activity.amount || 0) || 0;
        const type = activity.type || 'sale';
        const reference = activity.reference_code || activity.reference_id || '';
        
        // Determine icon and color based on type
        let icon = 'fa-receipt';
        let colorClass = 'text-emerald-600 bg-emerald-100';
        
        if (type === 'return') {
          icon = 'fa-undo';
          colorClass = 'text-rose-600 bg-rose-100';
        } else if (type === 'withdrawal') {
          icon = 'fa-hand-holding-usd';
          colorClass = 'text-amber-600 bg-amber-100';
        } else if (type === 'deposit') {
          icon = 'fa-money-bill-wave';
          colorClass = 'text-blue-600 bg-blue-100';
        }
        
        return {
          id: `${type}_${activity.id}`,
          reference_id: activity.reference_id,
          type: type,
          amount: parsedAmount,
          raw_amount: parsedAmount,
          reference,
          description: activity.description || `عملية #${reference}`,
          icon: icon,
          colorClass: colorClass,
          time: createdAt ? formatRelativeTime(new Date(createdAt)) : '',
          fullTime: createdAt ? new Date(createdAt).toLocaleString('en-US', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
        };
      });
    } else {
      recentActivities.value = [];
    }

    cashDrawerTotal.value = Number(paymentBreakdown.cash_drawer) || 0;

    // Top products
    try {
      const { startDate, endDate } = resolveQueryRange(today);
      const salesResp = await analyticsStore.fetchSalesAnalytics({
        startDate,
        endDate,
        sessionId: activeSessionId.value,
        branchId: wid,
        force: true,
        signal: requestController.signal
      });
      const sData = salesResp?.data || salesResp || {};
      const hourlyRows = Array.isArray(data.hourly_sales) ? data.hourly_sales : [];
      const hourlyTotals = new Map(hourlyRows.map(row => [String(row.hour).slice(0, 2), Number(row.total_sales) || 0]));
      const currentHour = new Date().getHours();
      const labels = Array.from({ length: currentHour + 1 }, (_, hour) => `${String(hour).padStart(2, '0')}:00`);
      const hourlyData = labels.map(label => hourlyTotals.get(label.slice(0, 2)) || 0);
      const fallbackDailySales = Array.isArray(sData.daily_sales) ? sData.daily_sales : [];
      const fallbackChart = data.salesChart || data.sales_chart;
      updateSalesChart(hourlyRows.length
        ? { labels, data: hourlyData }
        : fallbackChart?.labels?.length
          ? fallbackChart
          : fallbackDailySales.length
            ? {
                labels: fallbackDailySales.map(row => row.date || ''),
                data: fallbackDailySales.map(row => Number(row.total_revenue ?? row.total) || 0)
              }
            : { labels: [], data: [] });
      
      if (Array.isArray(sData.top_products) && sData.top_products.length > 0) {
        topProducts.value = sData.top_products
          .filter(p => p && p.id)
          .map(p => ({
            id: p.id, 
            name: p.name || 'منتج غير معروف',
            code: p.code || '',
            quantity: parseFloat(p.total_quantity || 0) || 0,
            totalSales: parseFloat(p.total_revenue || 0) || 0,
            avgPrice: (parseFloat(p.total_quantity || 0) || 0) > 0 
              ? (parseFloat(p.total_revenue || 0) || 0) / (parseFloat(p.total_quantity || 0) || 1)
              : 0,
            orderCount: parseInt(p.order_count || 0) || 0,
            avgQuantityPerOrder: (parseInt(p.order_count || 0) || 0) > 0
              ? (parseFloat(p.total_quantity || 0) || 0) / (parseInt(p.order_count || 0) || 1)
              : 0,
            unit: p.unit || 'قطعة'
          }))
          .filter(p => p.quantity > 0)
          .sort((a, b) => b.quantity - a.quantity)
          .slice(0, 5);
        
        if (topProducts.value && topProducts.value.length > 0) {
          updateTopProductsChart({
            labels: topProducts.value.map(p => p?.name || 'منتج غير معروف'),
            data: topProducts.value.map(p => {
              const qty = parseFloat(p?.quantity) || 0;
              return isFinite(qty) ? qty : 0;
            })
          });
        } else {
          updateTopProductsChart({ labels: [], data: [] });
        }
      } else {
        topProducts.value = [];
        updateTopProductsChart({ labels: [], data: [] });
      }
    } catch (e) { 
      console.error('Error fetching sales analytics:', e);
      topProducts.value = []; 
      updateTopProductsChart({ labels: [], data: [] });
    }

  } catch (e) {
    const isAborted = e?.name === 'AbortError' || e?.name === 'CanceledError';
    if (!isAborted) showToast('فشل تحميل البيانات', 'error');
  } finally {
    if (dashboardAbortCtrl === requestController) {
      isLoadingData.value = false;
      hasLoadedOnce.value = true;
    }
  }
};

// ─── Session Actions ──────────────────────────────────────────────────────────
const getUser = () => {
  try {
    return authStore.user || JSON.parse(localStorage.getItem('user') || 'null');
  } catch {
    return null;
  }
};

const triggerOpenSession = async () => {
  try {
    const user = getUser();
    const userId = user?.id || null;
    const branchId = user?.branch_id ?? null;

    if (!userId) return;

    if (!branchId && !authStore.isAdmin) {
      showToast('لا يوجد فرع مرتبط بالمستخدم. يرجى تعيين فرع للكاشير.', 'error');
      return;
    }

    if (branchId) {
      const { device_id } = getDeviceIdentity();
      const result = await sessionStore.getCurrentSession(branchId, userId, device_id);
      if (result?.status === 'success' && result.data?.id) {
        activeSessionId.value = result.data.id;
        showToast('هناك جلسة مفتوحة بالفعل لهذا الفرع.', 'info');
        return;
      }
    }

    // ✅ تحديد الفرع المراد فتح جلسة له
    let targetBranchId = null;
    if (authStore.isAdmin) {
      // ✅ للإدمن، استخدم adminSelectedBranch (من header)
      targetBranchId = adminSelectedBranch.value;
      if (!targetBranchId) {
        showToast('يرجى اختيار الفرع من القائمة بالأعلى أولاً', 'error');
        return;
      }
    } else {
      // ✅ للكاشيرين، استخدم currentBranchId (آمن)
      targetBranchId = branchIsolation.currentBranchId.value;
      if (!targetBranchId) {
        showToast('لم يتم تعيين فرع لحسابك', 'error');
        return;
      }
    }

    // ✅ تحميل الأجهزة قبل فتح modal
    try {
      console.log('[CashierDashboard] About to fetch terminals for branch:', targetBranchId);
      const loadedTerminals = await terminalStore.fetchTerminals(targetBranchId, { force: true });
      console.log('[CashierDashboard] Fetch completed. Terminals count:', loadedTerminals?.length || 0);
      
      // تأكد من وجود أجهزة
      if (!loadedTerminals || loadedTerminals.length === 0) {
        showToast('لا توجد أجهزة نقاط بيع مسجلة لهذا الفرع. يرجى إضافة جهاز أولاً.', 'error');
        return;
      }
      
      // اختر أول جهاز إذا لم يكن هناك جهاز محدد
      if (!selectedTerminalId.value) {
        selectedTerminalId.value = loadedTerminals[0].id;
      }
    } catch (e) {
      console.error('[CashierDashboard] Error fetching terminals:', e);
      showToast('فشل تحميل أجهزة نقاط البيع', 'error');
      return;
    }

    openingCashAmount.value = 0;
    openSessionModal.value = true;
  } catch (error) {
    showToast(error?.response?.data?.message || 'تعذر بدء عملية فتح الجلسة', 'error');
  }
};

const confirmOpenSession = async () => {
  try {
    isOpeningSession.value = true;

    // ✅ التحقق من terminal_id قبل المتابعة
    if (!selectedTerminalId.value) {
      showToast('يرجى اختيار جهاز نقطة البيع (Terminal) قبل فتح الجلسة', 'error');
      isOpeningSession.value = false;
      return;
    }

    let wid = null;

    if (authStore.isAdmin) {
      // ✅ للإدمن، استخدم adminSelectedBranch (من header)
      wid = adminSelectedBranch.value;
      if (!wid) {
        showToast('الرجاء تحديد الفرع من القائمة بالأعلى', 'error');
        isOpeningSession.value = false;
        return;
      }
    } else {
      // ✅ للكاشيرين، استخدم currentBranchId (آمن)
      wid = branchIsolation.currentBranchId.value;
      if (!wid) {
        showToast('لم يتم تعيين فرع لحسابك', 'error');
        isOpeningSession.value = false;
        return;
      }
    }

    const { device_id, device_name } = getDeviceIdentity();
    
    // ✅ التحقق النهائي من terminal_id
    if (!selectedTerminalId.value) {
      showToast('يرجى اختيار جهاز نقطة البيع قبل المتابعة', 'error');
      isOpeningSession.value = false;
      return;
    }

    const result = await sessionStore.openSession({
      branch_id: wid,
      opening_cash_amount: openingCashAmount.value,
      session_type: 'manual',
      device_id,
      device_name,
      terminal_id: Number(selectedTerminalId.value)  // ✅ تحويل إلى رقم
    });
    if (result?.status === 'success' && result.data?.id) {
      activeSessionId.value = result.data.id;
      openSessionModal.value = false;
      try {
        if (wid && selectedTerminalId.value) {
          const shiftResult = await shiftStore.getCurrentShift(wid, selectedTerminalId.value, true);
          currentShift.value = shiftResult?.status === 'success' && shiftResult.data?.id ? shiftResult.data : currentShift.value;
        }
      } catch { /* non-critical */ }
      fetchDashboardData();
      showToast('تم فتح الجلسة', 'success');
    } else {
      showToast(result?.message || 'تعذر فتح الجلسة', 'error');
    }
  } catch (e) {
    showToast(e?.response?.data?.message || 'تعذر فتح الجلسة', 'error');
  }
  finally {
    isOpeningSession.value = false;
  }
};

// ─── Shift Actions (اُزيلت الدوال اليدوية — الوردية أوتوماتيك 100%) ──

const attemptEndShift = async () => {
  await fetchDashboardData();
  closingCashInput.value = null;
  varianceReason.value = '';
  selectedVarianceReason.value = '';
  shiftState.value = 'ending';
};

const handleVarianceReasonChange = () => {
  if (selectedVarianceReason.value !== 'other') varianceReason.value = selectedVarianceReason.value;
};

const confirmEndShift = async () => {
  if (closingCashInput.value === null || closingCashInput.value < 0) { showToast('الرجاء إدخال المبلغ الفعلي', 'error'); return; }
  const hasVariance = Math.abs(cashDifference.value) > 0.01;
  if (hasVariance && !varianceReason.value && !selectedVarianceReason.value) { showToast('الرجاء تحديد سبب الفرق', 'warning'); return; }
  try {
    const reasonToSend = selectedVarianceReason.value === 'other' ? varianceReason.value : selectedVarianceReason.value;
    const result = await sessionStore.closeSession(activeSessionId.value, closingCashInput.value, reasonToSend);
    if (result?.status === 'success') {
      activeSessionId.value = null;
      const variance = result.data?.closing?.variance ?? null;
      showToast(typeof variance === 'number' && variance !== 0 ? `تم الإغلاق. فرق الخزينة: ${variance.toFixed(2)}` : 'تم إغلاق الجلسة بنجاح.', variance !== 0 ? 'warning' : 'success');
      try {
        const wid = authStore.user?.branch_id || branchStore.selectedBranchId;
        if (wid && selectedTerminalId.value) {
          const shiftResult = await shiftStore.getCurrentShift(wid, selectedTerminalId.value, true);
          currentShift.value = shiftResult?.status === 'success' && shiftResult.data?.id ? shiftResult.data : null;
        }
      } catch { /* non-critical */ }
    } else {
      showToast(result?.message || 'تعذر الإغلاق', 'error');
    }
  } catch (e) { showToast(e?.response?.data?.message || 'تعذر الإغلاق', 'error'); }
  shiftState.value = 'active';
  closingCashInput.value = null;
  varianceReason.value = '';
  selectedVarianceReason.value = '';
  await fetchDashboardData();
};

// ─── Device ───────────────────────────────────────────────────────────────────

// ─── Event Handlers ───────────────────────────────────────────────────────────
const handleQuickAction = (action) => {
  if (action.adminOnly && !authStore.isAdmin) { showToast('هذه الصفحة للمشرفين فقط', 'error'); return; }
  if (action.id === 'refresh') { handleRefresh(); return; }
  if (action.route) router.push(action.params ? { path: action.route, query: action.params } : action.route);
};

const handleActivityClick = (activity) => {
  switch (activity.type) {
    case 'sale':       router.push({ path: '/sales/history', query: { id: activity.reference_id } }); break;
    case 'return':     router.push({ path: '/returns', query: { id: activity.reference_id } }); break;
    case 'withdrawal':
    case 'deposit':    router.push({ path: '/payments', query: { id: activity.reference_id } }); break;
    default:           showToast('لا يمكن عرض تفاصيل هذه العملية', 'info');
  }
};

const handleRefresh = () => fetchDashboardData();

const loadPaymentMethods = async () => {
  try {
    await paymentStore.fetchPaymentMethods();
  } catch (e) {
    console.error('Failed to load payment methods for dashboard', e);
  }
};

const ensureCashierSession = async () => {
  try {
    const user = getUser();
    const userId = user?.id || null;

    let branchId;
    try {
      branchId = effectiveBranchId.value;
    } catch (e) {
      activeSessionId.value = null;
      return;
    }

    if (!userId) {
      activeSessionId.value = null;
      return;
    }
    const { device_id } = getDeviceIdentity();
    const result = await sessionStore.getCurrentSession(branchId, userId, device_id);
    activeSessionId.value = result?.status === 'success' && result.data?.id ? result.data.id : null;
  } catch (e) {
    if (e?.response?.status !== 404) {
      console.error('Session check failed:', e);
    }
    activeSessionId.value = null;
  }
};

watch(openSessionModal, (val) => {
  if (val) nextTick(() => openSessionModalRef.value?.focus());
});
watch(() => shiftState.value === 'ending', (val) => {
  if (val) nextTick(() => endShiftModalRef.value?.focus());
});

// ─── Lifecycle ────────────────────────────────────────────────────────────────
onMounted(async () => {
  updateDateTime();
  dateTimeInterval = setInterval(updateDateTime, 1000);

  const stored = localStorage.getItem('user');
  if (stored) { try { const u = JSON.parse(stored); cashierName.value = u.name || u.username || 'مستخدم'; } catch {} }

  await fetchSettings();

  if (canSelectBranches.value) {
    try {
      await branchStore.fetchBranches();
      await ensureBranchSelected();
    } catch {}
  }

  try {
    const wid = effectiveBranchId.value;
    if (wid) {
      const loadedTerminals = await terminalStore.fetchTerminals(wid);
      if (!selectedTerminalId.value && loadedTerminals && loadedTerminals.length) {
        selectedTerminalId.value = loadedTerminals[0].id;
      }
    }
  } catch (e) {
    // ignore
  }

  try {
    const wid = effectiveBranchId.value;
    if (wid && selectedTerminalId.value) {
      const result = await shiftStore.getCurrentShift(wid, selectedTerminalId.value);
      currentShift.value = result?.status === 'success' && result.data?.id ? result.data : null;
    }
  } catch (e) {
    currentShift.value = null;
  }

  await loadPaymentMethods();
  await ensureCashierSession();
  await fetchDashboardData();
});

onUnmounted(() => {
  if (dateTimeInterval) clearInterval(dateTimeInterval);
});

onBeforeUnmount(() => {
  if (salesChartInstance.value) salesChartInstance.value.destroy();
  if (topProductsChartInstance.value) topProductsChartInstance.value.destroy();
  if (dashboardAbortCtrl) dashboardAbortCtrl.abort();
});
</script>

<style scoped>
/* Animations */
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

@keyframes modalIn {
  from { opacity: 0; transform: scale(0.98) translateY(10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

/* Scrollbar */
.custom-scroll::-webkit-scrollbar {
  width: 4px;
}

.custom-scroll::-webkit-scrollbar-thumb {
  @apply bg-slate-200 rounded-full;
}

/* Animation Classes */
.animate-modalIn {
  animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
</style>
