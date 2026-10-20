<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    <div class="max-w-[1800px] mx-auto p-4 lg:p-8 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة المخزون"
        description="تابع الكميات المتاحة وقيمة المخزون، وراجع حركة الأصناف بين الفروع."
        :branches="branches"
        :selectedBranch="selectedBranch"
        branch-selection-mode="required"
        @branch-changed="handleBranchChange"
      >
        <template #controls>
          <div class="relative">
            <button
              @click="showPickerPanel = !showPickerPanel"
              class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2"
            >
              <i class="fas fa-sliders text-[10px]"></i>
              تسوية سريعة
            </button>

            <!-- Product Picker Panel: Refined Dropdown -->
            <transition name="dropdown">
              <div v-if="showPickerPanel" class="absolute left-0 top-full mt-2 w-80 bg-white rounded-lg shadow-2xl border border-slate-200 z-50 overflow-hidden animate-fadeIn" @click.stop>
                <div class="p-3 bg-slate-50 border-b border-slate-100">
                  <input v-model="pickerSearch" autofocus placeholder="بحث سريع عن صنف..." class="w-full h-8 rounded-md border border-slate-200 bg-white px-3 text-[11px] font-bold outline-none focus:border-blue-500" />
                </div>
                <div class="max-h-64 overflow-y-auto custom-scroll">
                  <div v-for="item in pickerItems" :key="item.product_id ?? item.id" @click="openAdjustFromPicker(item)" class="flex items-center justify-between px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-slate-50 last:border-0 group transition-colors">
                    <div class="min-w-0">
                      <p class="text-xs font-bold text-slate-800 truncate">{{ item.name }}</p>
                      <p class="text-[9px] text-slate-400 font-mono mt-0.5">{{ item.barcode || item.product_code || '—' }}</p>
                    </div>
                    <span :class="[item.quantity <= 0 ? 'bg-rose-50 text-rose-600 border-rose-100' : 'bg-emerald-50 text-emerald-600 border-emerald-100']" class="text-[10px] font-bold px-2 py-0.5 rounded border">
                      {{ item.quantity ?? 0 }}
                    </span>
                  </div>
                </div>
              </div>
            </transition>
          </div>
        </template>
      </PageHeader>

      <!-- KPIs Overview: Stripe-inspired Data Blocks -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي الأصناف', val: stats.totalProducts, icon: 'fa-boxes', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'قيمة المخزون', val: formatCurrency(stats.totalValue), icon: 'fa-coins', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'أوشكت على النفاذ', val: stats.aboutToFinish, icon: 'fa-exclamation-triangle', color: 'text-amber-600', bg: 'bg-amber-50' },
          { label: 'قريبة الانتهاء', val: stats.expiringSoon, icon: 'fa-hourglass-end', color: 'text-rose-600', bg: 'bg-rose-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900 font-mono tracking-tighter">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Main Content Grid -->
      <div class="space-y-6">
        
        <!-- Utility Toolbar -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 flex flex-col lg:flex-row lg:items-center justify-between gap-6 shadow-sm">
          <div class="flex-grow grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="space-y-1.5 group">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">بحث سريع</label>
              <div class="relative">
                <input v-model="search" type="text" class="filter-input-v2 pr-9" placeholder="الاسم أو الباركود..." />
                <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">تصفية التوفر</label>
              <select v-model="filter" class="filter-input-v2 appearance-none">
                <option value="all">كل المنتجات</option>
                <option value="low">منخفضة المخزون</option>
                <option value="about-to-finish">على وشك الانتهاء</option>
                <option value="out">المنتهية (نافذة)</option>
              </select>
            </div>

            <div class="space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">الترتيب</label>
              <select v-model="sort" class="filter-input-v2 appearance-none">
                <option value="name">الاسم أبجدياً</option>
                <option value="quantity">الكمية (الأكثر)</option>
                <option value="value">القيمة المالية</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Inventory Table: Professional High-Density Grid -->
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-slate-50/50 border-b border-slate-200">
                  <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الصنف والباركود</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التصنيف</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الكمية</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">التكلفة</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجمالي</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">التوفر</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الربح</th>
                  <template v-if="selectedBranch">
                    <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">حالة GL</th>
                    <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">التفعيل</th>
                  </template>
                  <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium">
                <tr v-if="!paginatedInventory.length" class="text-center">
                  <td :colspan="selectedBranch ? 12 : 11" class="py-24 text-slate-300">
                    <i class="fas fa-box-open text-3xl mb-4 opacity-20"></i>
                    <p class="text-xs font-bold uppercase tracking-widest">لا توجد أصناف في المخزون</p>
                  </td>
                </tr>
                <tr v-for="item in paginatedInventory" :key="item.id" class="hover:bg-blue-50/10 transition-all group">
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 border border-slate-100 group-hover:text-blue-600 transition-colors">
                        <i class="fas fa-box text-xs"></i>
                      </div>
                      <div class="flex flex-col">
                        <span class="text-xs font-bold text-slate-900 truncate max-w-[180px]">{{ item.name }}</span>
                        <span class="text-[9px] font-bold text-slate-400 font-mono mt-1 uppercase tracking-widest">{{ item.barcode || '--' }}</span>
                      </div>
                    </div>
                  </td>

                  <td class="px-4 py-4">
                    <span class="text-[10px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded">{{ item.category_name || 'عام' }}</span>
                  </td>

                  <td class="px-4 py-4 text-center">
                    <span :class="[getQuantityClass(item)]" class="px-2 py-0.5 rounded text-[10px] font-bold border font-mono">
                      {{ item.quantity }}
                    </span>
                  </td>

                  <td class="px-4 py-4 text-center text-xs font-bold text-slate-700 font-mono tracking-tighter">{{ formatCurrency(getPrice(item)) }}</td>
                  <td class="px-4 py-4 text-center text-xs font-bold text-blue-600 font-mono tracking-tighter">{{ formatCurrency(getPrice(item) * (item.quantity || 0)) }}</td>

                  <td class="px-4 py-4 text-center">
                    <span :class="['px-2 py-0.5 rounded text-[9px] font-bold border', getStatusClass(item)]">
                      {{ getStatusText(item) }}
                    </span>
                  </td>

                  <td class="px-4 py-4 text-center">
                    <template v-if="item.profit_margin_percent !== undefined && item.sale_price > 0">
                      <span :class="item.profit_margin_percent >= 0 ? 'text-emerald-600' : 'text-rose-600'" class="text-[10px] font-bold font-mono">
                        {{ item.profit_margin_percent }}%
                      </span>
                    </template>
                    <span v-else class="text-slate-300">—</span>
                  </td>

                  <template v-if="selectedBranch">
                    <td class="px-4 py-4 text-center">
                      <span v-if="item.gl_status === 'draft'" class="text-[9px] font-bold text-amber-600 bg-amber-50 border border-amber-100 px-1.5 py-0.5 rounded">غير مُفعَّل</span>
                      <span v-else-if="item.gl_status === 'active'" class="text-[9px] font-bold text-blue-600 bg-blue-50 border border-blue-100 px-1.5 py-0.5 rounded">مفعَّل</span>
                      <span v-else-if="item.gl_status === 'posted'" class="text-[9px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-100 px-1.5 py-0.5 rounded">✓ مُرصّد</span>
                    </td>

                    <td class="px-4 py-4 text-center">
                      <button v-if="item.gl_status === 'draft'" @click="openActivateModal(item)" class="h-7 px-3 text-[10px] font-bold bg-blue-600 text-white rounded hover:bg-blue-700 transition-all">تفعيل</button>
                      <button v-else-if="item.gl_status === 'active'" @click="openOpeningBalanceModal(item)" class="h-7 px-3 text-[10px] font-bold bg-amber-500 text-white rounded hover:bg-amber-600 transition-all">ترحيل الرصيد الافتتاحي</button>
                      <i v-else-if="item.gl_status === 'posted'" class="fas fa-check-double text-emerald-500 text-xs"></i>
                    </td>
                  </template>

                  <td class="px-6 py-4 text-center">
                    <div class="flex items-center justify-center gap-1.5">
                      <template v-if="selectedBranch">
                        <button @click="adjustStock(item)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-amber-600 hover:border-amber-200 transition-all flex items-center justify-center shadow-sm"><i class="fas fa-sliders text-[10px]"></i></button>
                        <button
                          v-if="canReverseOpeningBalance && item.opening_balance_id && item.opening_balance_has_transaction"
                          @click="reverseOpeningBalance(item)"
                          :disabled="reversingOpeningBalanceId === item.id"
                          title="عكس الرصيد الافتتاحي"
                          class="w-8 h-8 rounded-lg border border-rose-200 text-rose-500 hover:bg-rose-50 disabled:opacity-50 transition-all flex items-center justify-center shadow-sm"
                        >
                          <i :class="reversingOpeningBalanceId === item.id ? 'fas fa-spinner fa-spin' : 'fas fa-rotate-left'" class="text-[10px]"></i>
                        </button>
                        <button @click="openTransferModal(item)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-emerald-600 hover:border-emerald-200 transition-all flex items-center justify-center shadow-sm"><i class="fas fa-exchange-alt text-[10px]"></i></button>
                      </template>
                      <button @click="viewDetails(item)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center shadow-sm"><i class="fas fa-eye text-[10px]"></i></button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          </div>

        <!-- Pagination Footer -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ filters.totalPages.value }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ filteredInventory.length }}</span> منتج
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
               <select v-model.number="filters.perPage.value" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                 <option :value="10">10</option>
                 <option :value="20">20</option>
                 <option :value="50">50</option>
               </select>
             </div>
             <div class="flex items-center gap-1">
               <button @click="filters.previousPage()" :disabled="filters.page.value <= 1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button>
               <button @click="filters.nextPage(filters.totalPages.value)" :disabled="filters.page.value >= filters.totalPages.value" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button>
             </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== Modals: Restructured for SaaS consistency ===== -->
    
    <!-- Stock Transfer Modal -->
    <BaseModal :show="showTransferModal" @close="showTransferModal = false" maxWidth="md" align="center">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 bg-emerald-600 rounded-lg flex items-center justify-center text-white"><i class="fas fa-exchange-alt text-xs"></i></div>
          <h3 class="text-sm font-bold text-slate-900 uppercase">نقل مخزون داخلي</h3>
        </div>
      </template>

      <form @submit.prevent="submitTransfer" class="space-y-6">
        <div class="p-4 bg-slate-900 rounded-xl text-white flex justify-between items-center shadow-inner">
          <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">المتوفر حالياً</p><p class="text-xl font-bold font-mono">{{ transferItem?.quantity || 0 }}</p></div>
          <div class="text-left"><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">من مستودع</p><p class="text-xs font-bold text-blue-400">{{ currentBranchName }}</p></div>
        </div>
        <div class="space-y-1.5"><label class="metadata-label">الفرع المستلم</label><select v-model="transferData.toBranchId" class="filter-input-v2 h-10" required><option value="">اختر الفرع...</option><option v-for="b in transferableBranches" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
        <div class="space-y-1.5"><label class="metadata-label">الكمية المنقولة</label><input type="number" min="1" :max="transferItem?.quantity || 0" v-model.number="transferData.quantity" class="h-14 w-full bg-white border-2 border-slate-100 rounded-xl px-4 text-2xl font-bold text-center text-emerald-600 outline-none focus:border-emerald-500 transition-all" placeholder="0" required /></div>
        <div class="space-y-1.5"><label class="metadata-label">ملاحظات</label><textarea rows="2" v-model="transferData.notes" class="filter-input-v2 h-auto py-2 italic" placeholder="سبب النقل..."></textarea></div>

        <div class="flex gap-3 pt-2">
          <button type="button" @click="showTransferModal = false" class="flex-1 h-10 text-xs font-bold text-slate-500">إلغاء</button>
          <LoadingButton
            text="تأكيد النقل"
            :isLoading="isTransferring"
            loadingText="جاري..."
            variant="primary"
            loaderType="dots"
            type="submit"
            class="flex-2 h-10 px-8"
          />
        </div>
      </form>
    </BaseModal>

    <!-- Details Modal -->
    <BaseModal :show="showDetailsModal" @close="closeDetailsModal" maxWidth="4xl">
      <template #header>
        <h3 class="text-sm font-bold text-slate-900 uppercase">بطاقة تفاصيل الصنف</h3>
      </template>

      <!-- مؤشر التحميل أثناء جلب التفاصيل الكاملة من API -->
      <div v-if="isLoadingDetails" class="py-12 flex flex-col items-center justify-center gap-4 text-slate-400">
        <BaseSpinner size="32" color="#3b82f6" />
        <p class="text-xs font-bold uppercase tracking-widest">جاري تحميل التفاصيل...</p>
      </div>

      <div v-else-if="productDetails" class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <div class="bg-slate-900 p-8 rounded-2xl text-white shadow-xl relative overflow-hidden">
          <div class="absolute top-0 left-0 w-32 h-32 bg-white/5 rounded-full -translate-x-12 -translate-y-12"></div>
          <p class="text-[9px] font-bold text-blue-400 uppercase tracking-widest mb-3">البيانات الأساسية</p>
          <h4 class="text-2xl font-bold mb-1">{{ productDetails.name }}</h4>
          <p class="text-xs font-bold text-slate-500 font-mono mb-6">{{ productDetails.barcode || '—' }}</p>
          <div class="grid grid-cols-2 gap-4 border-t border-white/10 pt-6">
            <div><p class="text-[9px] font-bold text-slate-400 uppercase">الرصيد الحالي</p><p class="text-lg font-bold font-mono">{{ productDetails.quantity }} {{ productDetails.unit_name }}</p></div>
            <div><p class="text-[9px] font-bold text-slate-400 uppercase">القيمة التقديرية</p><p class="text-lg font-bold font-mono text-emerald-400">{{ formatCurrency(getPrice(productDetails) * (productDetails.quantity || 0)) }}</p></div>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4 font-bold text-xs">
          <div v-for="info in [
            {l: 'الفئة', v: productDetails.category_name || 'غير مصنف', c: 'text-slate-700'},
            {l: 'سعر البيع', v: formatCurrency(productDetails.sale_price || 0), c: 'text-blue-600'},
            {l: 'حد التنبيه', v: productDetails.min_quantity, c: 'text-slate-700'},
            {l: 'SKU الكود', v: productDetails.product_code || '--', c: 'text-indigo-600 font-mono'}
          ]" :key="info.l" class="p-4 rounded-xl bg-slate-50 border border-slate-100 flex flex-col justify-center">
            <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">{{ info.l }}</p>
            <p :class="info.c">{{ info.v }}</p>
          </div>
        </div>
      </div>

      <div v-if="!isLoadingDetails" class="space-y-4 mt-8">
        <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">سجل الحركات الأخيرة</h4>
        <div class="border border-slate-100 rounded-xl overflow-hidden shadow-sm">
          <table class="w-full text-right text-xs">
            <thead><tr class="bg-slate-50 border-b border-slate-100 text-slate-400 font-bold uppercase"><th class="px-4 py-3">التاريخ</th><th class="px-4 py-3">النوع</th><th class="px-4 py-3 text-center">الكمية</th><th class="px-4 py-3">البيان</th></tr></thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="h in productDetails?.recentHistory" :key="h.id" class="hover:bg-slate-50 transition-colors font-medium">
                <td class="px-4 py-3 text-slate-400 font-mono">{{ formatDate(h.movement_date || h.created_at) }}</td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 rounded bg-slate-100 text-[10px] font-bold text-slate-600">{{ getMovementTypeText(h.movement_type || h.type) }}</span></td>
                <td class="px-4 py-3 text-center font-bold text-slate-900">{{ h.quantity }}</td>
                <td class="px-4 py-3 text-slate-400 italic">{{ translateMovementNote(h.notes, h.movement_type || h.type) }}</td>
              </tr>
              <tr v-if="!productDetails?.recentHistory?.length"><td colspan="4" class="py-12 text-center text-slate-300 uppercase font-bold text-[10px]">لا توجد حركات مسجلة</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <template #footer>
        <button @click="closeDetailsModal" class="px-6 h-10 text-xs font-bold text-slate-500">إغلاق</button>
        <button
          v-if="canReverseOpeningBalance && productDetails?.opening_balance_id && productDetails?.opening_balance_has_transaction"
          @click="reverseOpeningBalance(productDetails)"
          :disabled="reversingOpeningBalanceId === productDetails.id"
          class="px-6 h-10 bg-rose-600 text-white rounded-md text-xs font-bold disabled:opacity-50"
        >
          {{ reversingOpeningBalanceId === productDetails.id ? 'جارٍ العكس...' : 'عكس الرصيد الافتتاحي' }}
        </button>
        <button @click="adjustStock(productDetails)" class="px-10 h-10 bg-blue-600 text-white rounded-md text-xs font-bold shadow-lg shadow-blue-900/20 active:scale-95 transition-all">تعديل المخزون</button>
      </template>
    </BaseModal>

    <!-- GL Activate Modal -->
    <BaseModal :show="showActivateModal" @close="showActivateModal = false" maxWidth="md" align="center">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 bg-blue-600 rounded-lg flex items-center justify-center text-white"><i class="fas fa-check-circle text-xs"></i></div>
          <h3 class="text-sm font-bold text-slate-900 uppercase">تفعيل المنتج</h3>
        </div>
      </template>

      <div class="space-y-4">
        <div class="p-4 bg-blue-50 rounded-xl border border-blue-100 text-blue-700">
          <p class="text-xs font-bold flex items-start gap-2">
            <i class="fas fa-info-circle mt-0.5 shrink-0"></i>
            <span>سيتم إنشاء حساب محاسبي للمنتج <strong>{{ selectedProductForActivation?.name }}</strong> في الفرع <strong>{{ branches.find(b => String(b.id) === String(selectedBranch))?.name }}</strong></span>
          </p>
        </div>
        <div class="p-4 bg-slate-50 rounded-xl border border-slate-100 space-y-2">
          <div class="flex justify-between items-center"><span class="text-[9px] font-bold text-slate-400 uppercase">المنتج</span><span class="text-sm font-bold text-slate-800">{{ selectedProductForActivation?.name }}</span></div>
          <div class="border-t border-slate-100 pt-2 flex justify-between items-center"><span class="text-[9px] font-bold text-slate-400 uppercase">الفرع</span><span class="text-sm font-bold text-slate-800">{{ branches.find(b => String(b.id) === String(selectedBranch))?.name }}</span></div>
          <div class="border-t border-slate-100 pt-2 flex justify-between items-center"><span class="text-[9px] font-bold text-slate-400 uppercase">الحالة الحالية</span><span class="px-2 py-0.5 rounded bg-orange-100 text-orange-700 text-[9px] font-bold">غير مُفعَّل</span></div>
        </div>
      </div>

      <template #footer>
        <button @click="showActivateModal = false" class="flex-1 h-10 text-xs font-bold text-slate-500">إلغاء</button>
        <button @click="activateProduct(selectedProductForActivation.id)" class="flex-2 h-10 bg-blue-600 text-white rounded-lg text-xs font-bold shadow-lg shadow-blue-900/20 active:scale-95 flex items-center justify-center gap-2 px-8">
          <i class="fas fa-check text-xs"></i> تفعيل الآن
        </button>
      </template>
    </BaseModal>

    <!-- Opening Balance Modal -->
    <BaseModal :show="showOpeningBalanceModal" @close="showOpeningBalanceModal = false" maxWidth="md" align="center">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 bg-emerald-600 rounded-lg flex items-center justify-center text-white"><i class="fas fa-balance-scale text-xs"></i></div>
          <h3 class="text-sm font-bold text-slate-900 uppercase">الرصيد الافتتاحي</h3>
        </div>
      </template>

      <form @submit.prevent="handleOpeningBalanceSubmit" class="space-y-6">
        <div class="p-4 bg-slate-900 rounded-xl text-white flex justify-between items-center shadow-inner">
          <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">الصنف</p><p class="text-sm font-bold">{{ selectedProductForActivation?.name }}</p></div>
          <div class="text-left"><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">الفرع</p><p class="text-xs font-bold text-emerald-400">{{ branches.find(b => String(b.id) === String(selectedBranch))?.name }}</p></div>
        </div>
        <div class="space-y-1.5">
          <label class="metadata-label">الكمية الافتتاحية</label>
          <input type="number" min="0" step="1" v-model.number="obQuantity" class="h-14 w-full bg-white border-2 border-slate-100 rounded-xl px-4 text-2xl font-bold text-center text-emerald-600 outline-none focus:border-emerald-500 transition-all" placeholder="0" required />
        </div>
        <div class="space-y-1.5">
          <label class="metadata-label">سعر التكلفة (الوحدة)</label>
          <input type="number" min="0" step="0.01" v-model.number="obUnitCost" class="h-14 w-full bg-white border-2 border-slate-100 rounded-xl px-4 text-2xl font-bold text-center text-emerald-600 outline-none focus:border-emerald-500 transition-all" placeholder="0.00" required />
        </div>
        <div class="p-4 bg-emerald-50 rounded-xl border border-emerald-100 flex justify-between items-center">
          <span class="text-[9px] font-bold text-emerald-600 uppercase">الإجمالي:</span>
          <span class="text-xl font-bold text-emerald-700 font-mono">{{ formatCurrency((obQuantity || 0) * (obUnitCost || 0)) }}</span>
        </div>
        <div class="p-4 bg-blue-50 rounded-xl border border-blue-100 text-blue-700">
          <p class="text-xs font-bold flex items-start gap-2"><i class="fas fa-info-circle mt-0.5 shrink-0"></i><span>سيتم إنشاء قيد محاسبي لإثبات الرصيد الافتتاحي للمخزون من حيث الكمية والقيمة المالية.</span></p>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="button" @click="showOpeningBalanceModal = false" class="flex-1 h-10 text-xs font-bold text-slate-500">إلغاء</button>
          <button type="submit" class="flex-2 h-10 bg-emerald-600 text-white rounded-lg text-xs font-bold shadow-lg shadow-emerald-900/20 active:scale-95 flex items-center justify-center gap-2 px-8">
            <i class="fas fa-check text-xs"></i> ترحيل الرصيد الافتتاحي
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- External Modal Components: Strictly logic-preserved -->
    <StockAdjustmentModal
      v-if="showStockModal && selectedItem"
      v-model="showStockModal"
      :branch-id="selectedBranch"
      :product="selectedItem"
      @adjusted="handleStockAdjusted"
      @close="closeStockModal"
    />

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import { useRoute } from 'vue-router';
import { useLoader } from '@/composables/useLoader';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useToast } from '@/composables/useToast';
import { useTableFilters } from '@/composables/useTableFilters';
import { getLocalDateISO } from '@/utils';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import AlertService from '@/services/AlertService';
import { useSessionExemption } from '@/composables/useCashierSessionGuard';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useRequiredBranch } from '@/composables/useRequiredBranch';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useProductStore } from '@/stores/product/productStore';
import { useInventoryStore } from '@/stores/inventory/inventoryStore';
import PageHeader from '@/components/PageHeader.vue';
import StockAdjustmentModal from '@/components/branch/StockAdjustmentModal.vue';

// ─── State ───────────────────────────────────────────────────────────────────
const branchStore = useBranchStore();
const branchIsolation = useBranchIsolation();
const productStore = useProductStore();
const inventoryStore = useInventoryStore();

const route = useRoute();
const { showLoader, hideLoader } = useLoader();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { showToast } = useToast();
const { ensureLoaded: ensureExemptionLoaded, isExempt } = useSessionExemption();
const { ensureBranchSelected, setRequiredBranch } = useRequiredBranch();
const { breadcrumb } = useBreadcrumb();
const authStore = useAuthStore();
const canReverseOpeningBalance = computed(() => {
  const permissions = authStore.user?.permissions || [];
  return authStore.isSuperAdmin
    || (permissions.includes('inventory.adjust') && permissions.includes('accounting.je.reverse'));
});

// ✅ Use branchStore directly - single source of truth
const branches = computed(() => branchStore.branches);
const selectedBranch = computed(() => branchIsolation.isExempt.value
  ? branchStore.selectedBranchId
  : authStore.user?.branch_id ?? null);

const currentBranchName = computed(() => {
  const branch = branches.value.find(b => b.id === selectedBranch.value);
  return branch?.name || 'All Branches';
});

const inventory = ref([]);
const stats = ref({
  totalProducts: 0,
  totalValue: 0,
  lowStock: 0,
  aboutToFinish: 0,
  expiringSoon: 0,
});
const products = ref([]);

const search = ref('');
const filter = ref('all');
const sort = ref('name');

// ─── Stock Adjustment State ────────────────────────────────────────────────────────────────
const showStockModal = ref(false);
const showDetailsModal = ref(false);
const selectedItem = ref(null);
const productDetails = ref(null);
const isLoadingDetails = ref(false);
const showPickerPanel = ref(false);
const pickerSearch = ref('');

const showActivateModal = ref(false);
const showOpeningBalanceModal = ref(false);
const selectedProductForActivation = ref(null);
const obQuantity = ref(0);
const obUnitCost = ref(0);
const reversingOpeningBalanceId = ref(null);

// ─── Transfer State (moved from BranchInventory) ─────────────────────────────
const showTransferModal = ref(false);
const transferItem = ref(null);
const isTransferring = ref(false);
const transferData = ref({ toBranchId: '', quantity: 0, notes: '' });

const transferableBranches = computed(() =>
  (branches.value || []).filter(b => String(b.id) !== String(selectedBranch.value))
);

// ─── Picker Computed ────────────────────────────────────────────────────────────
const pickerItems = computed(() => {
  const q = pickerSearch.value.trim().toLowerCase();
  const src = inventory.value.length ? inventory.value : [];
  if (!q) return src.slice(0, 50);
  return src.filter(i =>
    i.name?.toLowerCase().includes(q) ||
    i.barcode?.toLowerCase().includes(q) ||
    i.product_code?.toLowerCase().includes(q)
  ).slice(0, 50);
});

const openAdjustFromPicker = (item) => {
  showPickerPanel.value = false;
  pickerSearch.value = '';
  adjustStock(item);
};

// ─── Pagination (using useTableFilters composable)
const filters = useTableFilters('inventory_filters');

// ─── Computed ─────────────────────────────────────────────────────────────────
const filteredInventory = computed(() => {
  let filtered = inventory.value;
  if (search.value) {
    const term = search.value.toLowerCase();
    filtered = filtered.filter(
      item =>
        item.name.toLowerCase().includes(term) ||
        (item.barcode && item.barcode.toLowerCase().includes(term))
    );
  }
  if (filter.value === 'low') {
    // Only include items where min_quantity is defined and quantity <= min_quantity
    filtered = filtered.filter(item => 
      item.min_quantity > 0 && item.quantity <= item.min_quantity
    );
  } else if (filter.value === 'about-to-finish') {
    // Only include items where min_quantity is defined
    filtered = filtered.filter(item => 
      item.min_quantity > 0 && 
      item.quantity > 0 && 
      item.quantity <= item.min_quantity * 1.5
    );
  } else if (filter.value === 'out') {
    filtered = filtered.filter(item => item.quantity === 0);
  }
  filtered = filtered.slice().sort((a, b) => {
    if (sort.value === 'name') return a.name.localeCompare(b.name);
    if (sort.value === 'quantity') return b.quantity - a.quantity;
    if (sort.value === 'value') return getPrice(b) * b.quantity - getPrice(a) * a.quantity;
    return 0;
  });
  return filtered;
});

const totalInventoryValue = computed(() =>
  inventory.value.reduce((sum, item) => sum + getPrice(item) * (parseInt(item.quantity) || 0), 0)
);

const paginatedInventory = computed(() => {
  filters.totalCount.value = filteredInventory.value.length;
  const start = (filters.page.value - 1) * filters.perPage.value;
  const end = start + filters.perPage.value;
  return filteredInventory.value.slice(start, end);
});

// ─── Summary Loader ───────────────────────────────────────────────────
const loadSummary = async (force = false) => {
  if (!selectedBranch.value) {
    stats.value = { totalProducts: 0, totalValue: 0, lowStock: 0, aboutToFinish: 0, expiringSoon: 0 };
    return;
  }
  const res = await inventoryStore.fetchInventorySummary({
    branchId: selectedBranch.value || null,
    force
  });
  if (res.status === 'success' && res.data) {
    stats.value = {
      totalProducts: res.data.total_products  ?? 0,
      totalValue:    res.data.total_value     ?? 0,
      lowStock:      res.data.low_stock       ?? 0,
      aboutToFinish: res.data.about_to_finish ?? 0,
      expiringSoon:  res.data.expiring_soon   ?? 0,
    };
  }
};

// ─── Branch Change Handler ───────────────────────────────────────────────────
const handleBranchChange = (newBranchId) => {
  if (!setRequiredBranch(newBranchId)) return;
  // Reload data with new branch
  Promise.all([
    loadInventory(true),
    loadSummary(true),
  ]);
};

// ─── Data Loading ─────────────────────────────────────────────────────────────
const loadInventory = async (force = false) => {
  try {
    const params = { force };
    if (!isExempt.value) {
      try {
        const wid = branchIsolation.getRequiredBranchId();
        params.branchId = String(wid);
      } catch (e) {
        inventory.value = [];
        products.value = [];
        showToast(e.message || 'لم يتم تعيين مخزن', 'warning');
        return;
      }
    } else {
      if (!selectedBranch.value) {
        inventory.value = [];
        products.value = [];
        return;
      }
      params.branchId = String(selectedBranch.value);
    }

    const response = await productStore.fetchProducts(params);
    if (response.status === 'success') {
      const data = response.data?.items || response.data || [];
      const openingBalancesResponse = await productStore.fetchActiveOpeningBalances(selectedBranch.value);
      if (openingBalancesResponse.status !== 'success') {
        showToast(openingBalancesResponse.message || 'تعذر تحميل حالة الأرصدة الافتتاحية.', 'warning');
      }
      const openingBalancesByProduct = new Map(
        (openingBalancesResponse.data || []).map(opening => [String(opening.product_id), opening])
      );
      
      // Map new API response fields to component properties
      // New API returns: current_quantity, inventory_status, gl_status, profit_margin_percent, profit_markup_percent
      products.value  = data.map(p => ({
        ...p,
        ...(openingBalancesByProduct.has(String(p.id))
          ? {
              opening_balance_id: openingBalancesByProduct.get(String(p.id)).opening_balance_id,
              opening_quantity: openingBalancesByProduct.get(String(p.id)).opening_quantity,
              opening_unit_cost: openingBalancesByProduct.get(String(p.id)).opening_unit_cost,
              opening_balance_has_transaction: Boolean(Number(openingBalancesByProduct.get(String(p.id)).has_inventory_transaction)),
              opening_balance_has_journal: Boolean(Number(openingBalancesByProduct.get(String(p.id)).has_journal_entry)),
            }
          : { opening_balance_id: null }),
        id: p.id,
        name: p.name,
        barcode: p.barcode,
        category_name: p.category_name,
        category_id: p.category_id,
        purchase_price: parseFloat(p.purchase_price) || 0,
        sale_price: parseFloat(p.sale_price) || 0,
        quantity: parseInt(p.current_quantity) || 0,
        min_quantity: parseFloat(p.min_quantity) || 0,  // ← قيمة محددة: null → 0
        unit_name: p.unit_name || 'قطعة',
        unit_id: p.unit_id,
        inventory_status: p.inventory_status, // from API: in_stock, low_stock, out_of_stock, N/A
        gl_status: p.gl_status, // from API: posted, draft
        active: p.active,  // ← توحيد: is_active → active
        profit_margin_percent: parseFloat(p.profit_margin_percent) || 0,
        profit_markup_percent: parseFloat(p.profit_markup_percent) || 0,
        total_inventory_value: parseFloat(p.total_inventory_value) || 0,
        product_type: p.product_type || 'stock'
      }));
      
      inventory.value = [...products.value];
    }
  } catch (error) {
    console.error('Error loading inventory:', error);
    await AlertService.error('حدث خطأ أثناء تحميل بيانات المخزون: ' + (error.response?.data?.message || error.message), 'خطأ في التحميل');
  }
};

// ─── Price Logic (UPDATED to use API purchase_price) ──────────────────────────────────────────────────
const getPrice = item => {
  const pid = item.product_id || item.id;
  
  // Primary source: API purchase_price (most reliable)
  if (item.purchase_price > 0) return parseFloat(item.purchase_price);
  
  // Fallback: find in products array cache
  const product = products.value.find(p => p.id === pid);
  if (product?.purchase_price > 0) return parseFloat(product.purchase_price);
  
  // Last resort: use sale_price if no purchase price available
  if (product?.sale_price > 0) return parseFloat(product.sale_price);
  if (item.sale_price > 0) return parseFloat(item.sale_price);
  
  return 0;
};

// ─── Stock Adjustment Handlers ──────────────────────────────────────────────────────────────────
const adjustStock = item => {
  if (!selectedBranch.value) {
    showToast('يرجى اختيار فرع قبل تسوية المخزون', 'warning');
    return;
  }
  selectedItem.value = item;
  showStockModal.value = true;
};

const closeStockModal = () => {
  showStockModal.value = false;
  selectedItem.value = null;
};

const handleStockAdjusted = async () => {
  await loadInventory(true);  // ← force refresh to show updated quantities
  updateStats();
  // ✅ تحديث cache المنتجات في POS
  productStore.invalidateCache();
};

const reverseOpeningBalance = async (item) => {
  if (!canReverseOpeningBalance.value || !selectedBranch.value || !item?.id) return;

  const confirmed = await AlertService.confirm(
    `${item.opening_balance_has_journal ? 'سيتم عكس القيد المحاسبي وحركة المخزون' : 'سيتم عكس حركة المخزون فقط لعدم وجود قيد محاسبي مرحّل'} للرصيد الافتتاحي الخاص بـ "${item.name}" في فرع "${currentBranchName.value}". لا يُسمح بالعكس عند وجود أي حركة مخزون لاحقة أو رصيد محاسبي غير مطابق، وسيبقى سجل المنتج محفوظًا. هل تريد المتابعة؟`
  );
  if (!confirmed) return;

  reversingOpeningBalanceId.value = item.id;
  try {
    const result = await productStore.reverseOpeningBalance({
      productId: item.id,
      branchId: selectedBranch.value,
    });
    if (result.status !== 'success') {
      throw new Error(result.message || 'تعذر عكس الرصيد الافتتاحي.');
    }

    showToast(result.message || 'تم عكس الرصيد الافتتاحي مع الاحتفاظ بسجل المنتج.', 'success');
    productStore.invalidateCacheForBranch(selectedBranch.value);
    await Promise.all([loadInventory(true), loadSummary(true)]);
  } catch (error) {
    showToast(error?.message || 'تعذر عكس الرصيد الافتتاحي.', 'error');
  } finally {
    reversingOpeningBalanceId.value = null;
  }
};

// ─── Transfer Handlers (moved from BranchInventory) ──────────────────────────
const openTransferModal = (item) => {
  if (!selectedBranch.value) {
    showToast('يرجى اختيار فرع مصدر قبل نقل المخزون', 'warning');
    return;
  }
  transferItem.value = item;
  transferData.value = { toBranchId: '', quantity: 0, notes: '' };
  showTransferModal.value = true;
};

const submitTransfer = async () => {
  if (!selectedBranch.value) {
    showToast('يرجى اختيار فرع المصدر أولاً', 'warning');
    return;
  }
  if (!transferData.value.toBranchId || !transferData.value.quantity) {
    showToast('يرجى اختيار الفرع وإدخال الكمية', 'warning');
    return;
  }
  if (transferData.value.quantity > (transferItem.value?.quantity || 0)) {
    showToast('الكمية المطلوبة تتجاوز الكمية المتاحة في المخزون.', 'warning');
    return;
  }
  isTransferring.value = true;
  try {
    const result = await inventoryStore.transferStock({
      product_id: transferItem.value.product_id || transferItem.value.id,
      from_branch_id: selectedBranch.value,
      to_branch_id: transferData.value.toBranchId,
      quantity: transferData.value.quantity,
      notes: transferData.value.notes,
    });
    if (result.status !== 'success') throw new Error(result.message || 'فشل نقل المخزون');
    showToast('تم نقل المخزون بنجاح', 'success');
    showTransferModal.value = false;
    transferItem.value = null;
    transferData.value = { toBranchId: '', quantity: 0, notes: '' };
    await loadInventory(true);  // ← force refresh to show updated quantities
    updateStats();
    // ✅ تحديث cache المنتجات في POS
    productStore.invalidateCache();
  } catch (error) {
    showToast(error.response?.data?.message || 'فشل نقل المخزون', 'error');
  } finally {
    isTransferring.value = false;
  }
};


const openActivateModal = (item) => {
  // Ensure a branch is selected
  if (!selectedBranch.value) {
    showToast('يرجى اختيار فرع أولاً', 'warning');
    return;
  }
  selectedProductForActivation.value = item;
  showActivateModal.value = true;
};

const openOpeningBalanceModal = (item) => {
  // Ensure a branch is selected
  if (!selectedBranch.value) {
    showToast('يرجى اختيار فرع أولاً', 'warning');
    return;
  }
  selectedProductForActivation.value = item;
  obQuantity.value = 0;
  obUnitCost.value = 0;
  showOpeningBalanceModal.value = true;
};

const activateProduct = async (productId) => {
  try {
    // Ensure a branch is selected
    if (!selectedBranch.value) {
      showToast('يرجى اختيار فرع أولاً', 'warning');
      return;
    }
    
    const response = await productStore.activateProductInBranch(productId, Number(selectedBranch.value));
    if (response.status === 'success') {
      showToast('تم تفعيل المنتج في الفرع بنجاح', 'success');
      showActivateModal.value = false;
      // Reload inventory to get updated gl_status from API
      await loadInventory(true);
      updateStats();
      // ✅ تحديث cache المنتجات في POS
      productStore.invalidateCache();
    } else {
      showToast(response.message || 'فشل التفعيل', 'error');
    }
  } catch (e) {
    showToast(e.response?.data?.message || e.message || 'فشل التفعيل', 'error');
  }
};

const handleOpeningBalanceSubmit = async () => {
  if (!obQuantity.value || !obUnitCost.value) {
    showToast('يرجى إدخال الكمية والسعر', 'warning');
    return;
  }
  if (!selectedBranch.value) {
    showToast('يرجى اختيار فرع أولاً', 'warning');
    return;
  }

  try {
    // ✅ مباشرة: أرسل product_id + branch_id، الـ backend سيبحث عن mapping
    const response = await productStore.postOpeningBalance({
      product_id: selectedProductForActivation.value.id,
      branch_id: selectedBranch.value,
      quantity: obQuantity.value,
      unit_cost: obUnitCost.value,
      entry_date: getLocalDateISO(),
    });

    if (response.status === 'success') {
      showToast('تم ترحيل الرصيد الافتتاحي وإنشاء القيود المحاسبية بنجاح', 'success');
      showOpeningBalanceModal.value = false;
      obQuantity.value = 0;
      obUnitCost.value = 0;
      await loadInventory(true);
      updateStats();
      // ✅ تحديث cache المنتجات في POS
      productStore.invalidateCache();
    } else {
      showToast(response.message || 'فشل ترحيل الرصيد الافتتاحي', 'error');
    }
  } catch (e) {
    console.error('Opening balance error:', e);
    showToast(e.response?.data?.message || e.message || 'فشل ترحيل الرصيد الافتتاحي', 'error');
  }
};

// ─── Product Details (PRESERVED) ─────────────────────────────────────────────
const viewDetails = async item => {
  try {
    isLoadingDetails.value = true;
    showDetailsModal.value = true;
    productDetails.value = { ...item };
    const detailResponse = await productStore.getProductDetail(item.id, selectedBranch.value || null);
    const data = detailResponse?.status === 'success' ? detailResponse.data : null;
    if (data) {
      // Map nested response structure to flat template properties
      productDetails.value = {
        ...productDetails.value,
        ...data,
        // Flatten nested objects for template compatibility
        sale_price: data.pricing?.sale_price ?? data.sale_price,
        purchase_price: data.pricing?.purchase_price ?? data.purchase_price,
        min_sale_price: data.pricing?.min_sale_price ?? data.min_sale_price,
        quantity: data.inventory?.current_quantity ?? data.quantity,
        unit_name: data.inventory?.unit_name ?? data.unit_name,
        unit_id: data.inventory?.unit_id ?? data.unit_id,
        min_quantity: data.inventory?.min_quantity ?? data.min_quantity,
        max_quantity: data.inventory?.max_quantity ?? data.max_quantity,
        inventory_status: data.inventory?.inventory_status ?? data.inventory_status,
        category_name: data.category?.name ?? data.category_name,
        category_id: data.category?.id ?? data.category_id,
      };
    }
    const historyRes = await inventoryStore.getProductHistory(item.id, 5, {
      branchId: selectedBranch.value || null,
      force: true
    });
    productDetails.value.recentHistory = historyRes.data || [];
  } catch (error) {
    console.error('Error loading product details:', error);
    await AlertService.error('حدث خطأ أثناء تحميل تفاصيل المنتج', 'خطأ');
  } finally {
    isLoadingDetails.value = false;
  }
};

const closeDetailsModal = () => { showDetailsModal.value = false; productDetails.value = null; };

// ─── Stats Update (PRESERVED) ─────────────────────────────────────────────────
const updateStats = () => {
  loadSummary(true);
};

// ─── UI Helpers (UPDATED for new API status) ──────────────────────────────────────────────────
// Map inventory_status from API to UI classes
const getStatusClass = item => {
  const status = item.inventory_status || 'out_of_stock';
  return {
    'in_stock': 'bg-emerald-100 text-emerald-700 border-emerald-200',      // 🟢 Green
    'low_stock': 'bg-amber-100 text-amber-700 border-amber-200',            // 🟠 Orange
    'out_of_stock': 'bg-rose-100 text-rose-700 border-rose-200',            // 🔴 Red
    'N/A': 'bg-slate-100 text-slate-700 border-slate-200'                   // ⚪ Gray (service)
  }[status] || 'bg-slate-100 text-slate-700 border-slate-200';
};

const getStatusText = item => {
  const status = item.inventory_status || 'out_of_stock';
  return {
    'in_stock': 'متوفر بالمخزن',
    'low_stock': 'مخزون منخفض',
    'out_of_stock': 'نافذ من المخزن',
    'N/A': 'خدمة (بدون مخزون)'
  }[status] || 'غير معروف';
};

const getQuantityClass = item => {
  const status = item.inventory_status || 'out_of_stock';
  return {
    'in_stock': 'bg-blue-50 text-blue-600',       // 🟢 Green
    'low_stock': 'bg-amber-50 text-amber-600',     // 🟠 Orange
    'out_of_stock': 'bg-rose-50 text-rose-600',    // 🔴 Red
    'N/A': 'bg-slate-50 text-slate-600'            // ⚪ Gray
  }[status] || 'bg-slate-50 text-slate-600';
};

const getMovementTypeText = (type) => ({
  out:             'بيع',
  in:              'وارد',
  initial_stock:   'إدخال مخزون أولي',
  sale:            'بيع',
  sales:           'بيع',
  purchase:        'مشتريات',
  opening_balance:        'رصيد افتتاحي',
  opening_balance_manual: 'رصيد افتتاحي يدوي',
  opening_balance_bulk:   'رصيد افتتاحي دفعي',
  adjustment:      'تسوية مخزون',
  adjustment_in:   'تسوية وارد',
  adjustment_out:  'تسوية صادر',
  add:             'إضافة وارد',
  subtract:        'خصم / سحب',
  set:             'تعيين يدوي',
  transfer_in:     'تحويل وارد',
  transfer_out:    'تحويل صادر',
  transfer:        'تحويل بين فروع',
  return:          'مرتجع',
  return_in:       'مرتجع وارد',
  return_out:      'مرتجع صادر',
  write_off:       'إتلاف / شطب',
  correction:      'تصحيح',
}[type] || type || '—');

const NOTES_AR = {
  'Initial stock on product creation': 'رصيد افتتاحي عند إنشاء المنتج',
  'Initial stock':                     'رصيد افتتاحي',
  'Opening balance':                   'رصيد افتتاحي',
  'Stock adjustment':                  'تسوية مخزون',
  'Transfer in':                       'تحويل وارد',
  'Transfer out':                      'تحويل صادر',
  'Sale':                              'بيع',
  'Purchase':                          'مشتريات',
  'Return':                            'مرتجع',
};
const NOTES_PREFIX_AR = [
  { prefix: 'Opening balance commit @', ar: 'رصيد افتتاحي' },
  { prefix: 'Opening balance bulk',    ar: 'رصيد افتتاحي دفعي' },
  { prefix: 'Opening balance manual',  ar: 'رصيد افتتاحي يدوي' },
  { prefix: 'Opening balance',         ar: 'رصيد افتتاحي' },
  { prefix: 'Initial stock',           ar: 'رصيد افتتاحي عند إنشاء المنتج' },
  { prefix: 'Stock adjustment',         ar: 'تسوية مخزون' },
  { prefix: 'Transfer in',              ar: 'تحويل وارد' },
  { prefix: 'Transfer out',             ar: 'تحويل صادر' },
];
const translateMovementNote = (note, movementType = '') => {
  const typeTranslation = {
    initial_stock: 'رصيد افتتاحي عند إنشاء المنتج',
    opening_balance_manual: 'رصيد افتتاحي يدوي',
    opening_balance_bulk: 'رصيد افتتاحي دفعي',
  }[movementType];

  if (typeTranslation && (!note || note.startsWith('رصيد افتتاحي:'))) {
    const productName = note?.split(':').slice(1).join(':').trim();
    return productName ? `${typeTranslation}: ${productName}` : typeTranslation;
  }

  if (!note) return '—';
  const t = note.trim();
  if (NOTES_AR[t]) return NOTES_AR[t];
  const prefix = NOTES_PREFIX_AR.find(p => t.startsWith(p.prefix));
  return prefix ? prefix.ar : note;
};

const formatCurrency = v => formatCurrencyLocale(v, 2);

const formatDate = date => { if (!date) return '—'; return new Date(date).toLocaleDateString('ar-SA'); };

// ─── Lifecycle ────────────────────────────────────────────────────────────────
const handleOpeningBalancePosted = async (event) => {
  console.log('📡 Received openingBalancePosted event:', event.detail);
  try {
    showToast('🔄 جاري تحديث بيانات المخزون...', 'info');
    
    // Add a small delay to ensure backend has processed the data
    await new Promise(resolve => setTimeout(resolve, 300));
    
    await loadInventory(true);
    await loadSummary(true);
    
    showToast(
      `✅ ${event.detail?.message || 'تم تحديث بيانات المخزون بنجاح'} (${event.detail?.itemCount || ''} منتج)`,
      'success'
    );
    
    console.log('✅ Inventory refreshed successfully');
  } catch (error) {
    console.error('❌ Error refreshing inventory:', error);
    showToast('خطأ في تحديث البيانات: ' + error.message, 'error');
  }
};

// Check if there's a pending update from sessionStorage (for fallback)
const checkPendingOpeningBalance = async () => {
  try {
    const pending = sessionStorage.getItem('lastOpeningBalancePosted');
    if (pending) {
      const data = JSON.parse(pending);
      const timeDiff = Date.now() - new Date(data.timestamp).getTime();
      
      // If the update was recent (last 10 seconds), refresh the data
      if (timeDiff < 10000) {
        console.log('📡 Found pending opening balance update from sessionStorage');
        await handleOpeningBalancePosted({
          detail: {
            ...data,
            message: 'تم ترحيل الرصيد الافتتاحي (تحديثًا من الجلسة السابقة)'
          }
        });
        sessionStorage.removeItem('lastOpeningBalancePosted');
      }
    }
  } catch (error) {
    console.error('Error checking pending update:', error);
  }
};

onMounted(async () => {
  showLoader(true);
  try {
    await fetchSettings();
    await ensureExemptionLoaded();
    await branchStore.fetchBranches();
    if (isExempt.value) await ensureBranchSelected();
    await Promise.all([
      loadInventory(),
      loadSummary(),
    ]);
    
    // Check for pending opening balance updates from previous session
    await checkPendingOpeningBalance();

    // ─── Listen for opening balance posted events from OpeningBalance.vue ─────
    window.addEventListener('openingBalancePosted', handleOpeningBalancePosted);
    console.log('✅ Listener registered for openingBalancePosted events');

    // ─── Auto-open modal from query params (من BranchInventory router-links) ───
    const action    = route.query.action;
    const productId = route.query.product_id ? Number(route.query.product_id) : null;
    const branchId  = route.query.branch_id;
    if (action && productId) {
      if (branchId && isExempt.value && String(branchId) !== String(selectedBranch.value)) {
        if (!setRequiredBranch(branchId)) {
          showToast('الفرع المطلوب غير متاح لحسابك.', 'error');
          return;
        }
        await loadInventory();
      }
      const item = inventory.value.find(
        i => Number(i.product_id ?? i.id) === productId
      );
      if (item) {
        if (action === 'adjust')   adjustStock(item);
        else if (action === 'transfer') openTransferModal(item);
      }
    }
  } finally {
    hideLoader();
  }
});

onUnmounted(() => {
  // Clean up the event listener
  window.removeEventListener('openingBalancePosted', handleOpeningBalancePosted);
});
</script>

<style scoped>
/* Animations */
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes modalIn {
  from { opacity: 0; transform: scale(0.98) translateY(10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

@keyframes dropdownIn {
  from { opacity: 0; transform: translateY(-8px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Form Inputs */
.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.metadata-label {
  @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1;
}

/* Status Badges */
.status-badge {
  @apply px-2 py-0.5 rounded text-[9px] font-bold border flex items-center justify-center gap-1.5 w-fit mx-auto;
}

/* Pagination */
.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

/* Scrollbar */
.custom-scroll::-webkit-scrollbar {
  width: 5px;
}

.custom-scroll::-webkit-scrollbar-thumb {
  @apply bg-slate-200 rounded-full;
}

/* Animation Classes */
.animate-fadeIn {
  animation: fadeIn 0.4s ease-out;
}

.animate-modalIn {
  animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

.dropdown-enter-active {
  animation: dropdownIn 0.2s ease-out;
}
</style>