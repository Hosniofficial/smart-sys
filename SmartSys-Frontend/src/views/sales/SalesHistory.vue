<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="isLoadingList || isLoadingDetails || isMarkingPaid || isVoidingSale || isUpdatingSale" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Data Stale Warning Banner -->
      <transition name="slide-down">
        <div v-if="isDataStale" class="p-4 rounded-lg border-l-4 border-amber-400 bg-amber-50 flex items-start gap-3">
          <i class="fas fa-exclamation-triangle text-amber-600 text-lg mt-0.5"></i>
          <div class="flex-1">
            <p class="text-sm font-bold text-amber-900">⚠️ تحذير: البيانات قد تكون قديمة</p>
            <p class="text-xs text-amber-700 mt-1">فشل تحديث البيانات. البيانات المعروضة أسفله قد لا تعكس أحدث التغييرات. <span v-if="lastSuccessfulUpdate" class="text-amber-600 font-bold">آخر تحديث ناجح: {{ formatDateTime(lastSuccessfulUpdate) }}</span></p>
            <button @click="fetchSalesHistory" class="mt-2 px-3 py-1 bg-amber-600 text-white text-xs font-bold rounded hover:bg-amber-700 transition-colors">
              <i class="fas fa-redo ml-1"></i> إعادة محاولة
            </button>
          </div>
        </div>
      </transition>
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="سجل عمليات البيع"
        description="تتبع، فلترة، وإدارة جميع الفواتير الصادرة من النظام."
        :branches="canSelectBranch ? branches : []"
        :selectedBranch="selectedBranch"
        :hasExplicitSelection="hasExplicitBranchSelection"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <button 
            @click="filters.showFilters.value = !filters.showFilters.value" 
            :class="[filters.showFilters.value ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 border-slate-200']"
            class="h-9 px-4 rounded-md border text-xs font-bold transition-all flex items-center gap-2 shadow-sm"
          >
            <i class="fas fa-filter text-[10px]"></i>
            {{ filters.showFilters.value ? 'إخفاء أدوات التصفية' : 'تصفية النتائج' }}
          </button>
        </template>
      </PageHeader>

      <!-- KPI Summary: Supabase-inspired Data Cards -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'عدد الفواتير', val: filters.total.value, icon: 'fa-clipboard-list', color: 'text-blue-600', bg: 'bg-blue-50', show: true },
          { label: 'إجمالي المبيعات', val: formatPrice(filters.kpiSum.value), icon: 'fa-file-invoice-dollar', color: 'text-emerald-600', bg: 'bg-emerald-50', show: true },
          { label: 'إجمالي الضريبة', val: formatPrice(filters.kpiTax.value), icon: 'fa-percent', color: 'text-indigo-600', bg: 'bg-indigo-50', show: filters.kpiTax.value != null },
          { label: 'إجمالي الخصومات', val: formatPrice(filters.kpiDiscount.value), icon: 'fa-tags', color: 'text-rose-600', bg: 'bg-rose-50', show: filters.kpiDiscount.value != null }
        ].filter(k => k.show)" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Filters Panel: Linear-style high density inputs -->
      <transition name="slide-down">
        <div v-if="filters.showFilters.value" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden animate-fadeIn">
          <div class="p-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">بحث سريع</label>
                <div class="relative">
                  <input v-model="filters.searchQuery.value" type="text" class="filter-input" style="padding-right: 2rem;" placeholder="رقم الفاتورة أو العميل..." />
                  <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">من تاريخ</label>
                <div class="relative">
                  <input ref="dateFromRefLocal" type="date" v-model="filters.dateFrom.value" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="dateFromRefLocal?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer text-slate-300 hover:text-slate-500 transition-colors text-[10px]"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">إلى تاريخ</label>
                <div class="relative">
                  <input ref="dateToRefLocal" type="date" v-model="filters.dateTo.value" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="dateToRefLocal?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer text-slate-300 hover:text-slate-500 transition-colors text-[10px]"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">حالة السداد</label>
                <select v-model="filters.statusFilter.value" class="filter-input appearance-none">
                  <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end pt-4 border-t border-slate-50">
              <div class="md:col-span-2 space-y-1.5 relative">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">تصفية حسب العميل</label>
                <div class="relative">
                  <input v-model="filters.customerSearch.value" type="text" class="filter-input" style="padding-right: 2rem;" placeholder="ابحث عن عميل..." @focus="filters.showCustomerDropdown.value = true" @blur="filters.hideCustomerDropdown()" />
                  <i class="fas fa-user-circle absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                  
                  <div v-if="filters.showCustomerDropdown.value" class="absolute z-50 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-xl max-h-48 overflow-auto">
                    <button @mousedown.prevent="filters.clearCustomerFilter()" class="w-full text-right px-4 py-2 text-[11px] hover:bg-slate-50 border-b border-slate-50 text-blue-600 font-bold">جميع العملاء</button>
                    <button v-for="c in filteredCustomers" :key="c.id" @mousedown.prevent="filters.setCustomerFilter(c.id, c.name || c.customer_name)" class="w-full text-right px-4 py-2 text-[11px] hover:bg-slate-50 border-b border-slate-50 last:border-0 font-medium">
                      {{ c.name || c.customer_name }}
                    </button>
                  </div>
                </div>
              </div>

              <button @click="filters.clearAllFilters()" class="h-9 w-full rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all">
                إعادة تعيين الفلاتر
              </button>
            </div>
          </div>
        </div>
      </transition>

      <!-- Active Filter Chips -->
      <div v-if="filters.hasActiveFilters.value || filters.customerFilter.value || (canSelectBranch && selectedBranch !== null)" class="flex flex-wrap gap-2 animate-fadeIn">
        <div v-for="chip in [
          { show: filters.searchQuery.value, label: filters.searchQuery.value, clear: () => filters.searchQuery.value = '' },
          { show: filters.dateFrom.value, label: `من: ${filters.dateFrom.value}`, clear: () => filters.dateFrom.value = '' },
          { show: filters.dateTo.value, label: `إلى: ${filters.dateTo.value}`, clear: () => filters.dateTo.value = '' },
          { show: filters.statusFilter.value, label: getStatusLabel(filters.statusFilter.value), clear: () => filters.statusFilter.value = '' },
          { show: filters.customerFilter.value, label: filters.customerSearch.value, clear: () => filters.clearCustomerFilter() },
          { show: (canSelectBranch && selectedBranch !== null), label: `الفرع: ${branches.find(b => String(b.id) === String(selectedBranch))?.name || selectedBranch}`, clear: () => onBranchChange(null) }
        ].filter(c => c.show)" :key="chip.label" class="inline-flex items-center gap-2 px-2.5 py-1 bg-blue-50 border border-blue-100 rounded-md text-[10px] font-bold text-blue-700">
          {{ chip.label }}
          <i @click="chip.clear" class="fas fa-times cursor-pointer hover:text-blue-900 opacity-60"></i>
        </div>
      </div>

      <!-- Main Data Table -->
      <div class="bg-white rounded-xl overflow-hidden shadow-sm relative" :class="[
        isDataStale ? 'border-2 border-amber-300' : 'border border-slate-200'
      ]">
        
        <!-- Bulk Actions: Dark SaaS overlay -->
        <transition name="slide-up">
          <div v-if="filters.selectedIds.value.length" class="absolute bottom-6 left-1/2 -translate-x-1/2 z-[50] bg-slate-900 text-white px-6 py-3 rounded-full shadow-2xl flex items-center gap-6 border border-white/10">
            <div class="flex items-center gap-3 border-l border-white/10 pl-6">
              <span class="w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center text-[10px] font-bold">{{ filters.selectedIds.value.length }}</span>
              <span class="text-xs font-bold uppercase tracking-wider">فاتورة محددة</span>
            </div>
            <div class="flex items-center gap-4">
              <button @click="markSelectedAsPaid" :disabled="isMarkingPaid" class="text-xs font-bold text-emerald-400 hover:text-emerald-300 disabled:opacity-50 transition-colors">
                <i class="fas fa-money-check-alt ml-1.5"></i> تسديد المحدد
              </button>
              <button @click="exportSelectedCsv" class="text-xs font-bold text-blue-300 hover:text-blue-200 transition-colors">
                <i class="fas fa-download ml-1.5"></i> تصدير CSV
              </button>
              <button @click="printSelected" class="text-xs font-bold text-slate-300 hover:text-white transition-colors">
                <i class="fas fa-print ml-1.5"></i> طباعة
              </button>
              <button @click="filters.clearSelection()" class="text-xs font-bold text-slate-500 hover:text-slate-300 transition-colors">إلغاء</button>
            </div>
          </div>
        </transition>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 w-12">
                  <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500/20" />
                </th>
                <th @click="toggleSort('id')" class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest cursor-pointer group">
                  الفاتورة <i :class="filters.sortKey.value==='id' ? (filters.sortAsc.value? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'" class="fas ml-1 opacity-20 group-hover:opacity-100 transition-opacity"></i>
                </th>
                <th @click="toggleSort('created_at')" class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest cursor-pointer group">
                  التاريخ <i :class="filters.sortKey.value==='created_at' ? (filters.sortAsc.value? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'" class="fas ml-1 opacity-20 group-hover:opacity-100 transition-opacity"></i>
                </th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">العميل</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الأصناف</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الإجمالي</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المدفوع / المتبقي</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-if="isLoadingList">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 9" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!sales.length">
                <td colspan="9" class="py-20 text-center text-slate-300">
                   <i class="fas fa-receipt text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد فواتير مبيعات حتى الآن</p>
                </td>
              </tr>
              <tr v-for="sale in sales" :key="sale.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-6 py-4">
                  <input type="checkbox" :value="sale.id" v-model="filters.selectedIds.value" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500/20" />
                </td>
                <td class="px-4 py-4 text-xs font-bold text-slate-900">{{ sale.invoice_number || '#' + sale.id }}</td>
                <td class="px-4 py-4 text-[10px] font-mono text-slate-400">{{ formatDateTime(sale.created_at) }}</td>
                <td class="px-4 py-4 text-xs font-bold text-slate-700 truncate max-w-[150px]">{{ sale.customer_name || 'عميل نقدي' }}</td>
                <td class="px-4 py-4 text-center">
                  <span class="px-2 py-0.5 bg-slate-100 rounded text-[10px] font-bold text-slate-500">{{ sale.total_items }}</span>
                </td>
                <td class="px-4 py-4 text-xs font-bold text-blue-600">{{ formatPrice(getSaleGross(sale)) }}</td>
                <td class="px-4 py-4 text-[10px] font-bold whitespace-nowrap">
                  <div class="text-emerald-700">مدفوع: {{ formatPrice(getSalePaid(sale)) }}</div>
                  <div class="text-rose-600 mt-1">متبقي: {{ formatPrice(getSaleRemaining(sale)) }}</div>
                </td>
                <td class="px-4 py-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <span :class="[getDynamicStatusClass(sale.dynamic_status)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                      {{ getStatusLabel(sale.status === 'canceled' ? 'canceled' : sale.dynamic_status) }}
                    </span>
                    <span v-if="getAgeBadge(sale)" :class="[getAgeBadge(sale).cls]" class="px-1.5 py-0.5 rounded text-[8px] font-bold border">
                      {{ getAgeBadge(sale).label }}
                    </span>
                  </div>
                </td>
                <td class="px-4 py-4 text-center">
                  <button @click="viewSaleDetails(sale.id)" :disabled="isLoadingDetails" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center mx-auto disabled:opacity-50">
                    <i class="fas fa-eye text-[10px]"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ filters.total.value }}</span> فاتورة
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase">النتائج:</span>
               <select v-model.number="filters.perPage.value" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                 <option :value="10">10</option>
                 <option :value="20">20</option>
                 <option :value="50">50</option>
               </select>
             </div>
             <div class="flex items-center gap-1">
               <button @click="filters.previousPage()" :disabled="filters.page.value<=1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button>
               <button @click="filters.nextPage(totalPages)" :disabled="filters.page.value>=totalPages" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button>
             </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Details Modal -->
    <HistoryDetailsModal
      :show="showDetailsModal && !!selectedSale"
      @close="showDetailsModal = false"
      :title="`تفاصيل فاتورة البيع ${selectedSale?.invoice_number || '#' + selectedSale?.id}`"
      icon="fas fa-receipt"
    >

      <form v-if="isEditingSale" class="space-y-5" @submit.prevent="submitSaleEdit">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <label class="space-y-1.5">
            <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ الفاتورة</span>
            <input v-model="saleEditForm.sale_date" type="date" required class="filter-input" />
          </label>
          <label class="space-y-1.5">
            <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">طريقة الدفع</span>
            <select v-model="saleEditForm.payment_method_id" class="filter-input" :disabled="hasSalePayment">
              <option value="" :disabled="!!selectedSale?.payment_method_id">غير محددة</option>
              <option v-for="method in paymentMethods" :key="method.id" :value="String(method.id)">
                {{ method.name }}
              </option>
            </select>
            <span v-if="hasSalePayment" class="block text-[10px] text-amber-700">
              لا يمكن تغيير طريقة الدفع بعد تسجيل دفعة.
            </span>
          </label>
          <label class="space-y-1.5">
            <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">مركز التكلفة</span>
            <select v-model="saleEditForm.cost_center_id" class="filter-input" :disabled="!costCenters.length">
              <option value="">بدون مركز تكلفة</option>
              <option v-for="center in costCenters" :key="center.id" :value="String(center.id)">
                {{ center.name }}
              </option>
            </select>
            <span v-if="!costCenters.length" class="block text-[10px] text-amber-700">
              تعذر تحميل مراكز التكلفة؛ لن يتم تغيير المركز الحالي.
            </span>
          </label>
        </div>
        <label class="block space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">ملاحظات الفاتورة</span>
          <textarea v-model="saleEditForm.notes" rows="3" class="filter-input h-auto py-2"></textarea>
        </label>
        <p class="text-[10px] text-slate-500">
          التعديل متاح للفواتير غير المعتمدة وغير المرحلة محاسبياً، ولا يشمل الأصناف أو الكميات.
        </p>
      </form>

      <div v-else class="space-y-6">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          <div v-for="info in [
            { l: 'العميل', v: selectedSale?.customer_name || 'عميل نقدي' },
            { l: 'الفرع', v: selectedSale?.branch_name || '-' },
            { l: 'التاريخ', v: formatDateTime(selectedSale?.sale_date || selectedSale?.created_at) },
            { l: 'الكاشير', v: selectedSale?.created_by_name || '-' },
            { l: 'طريقة الدفع', v: getPaymentMethodName(selectedSalePaymentMethodId) },
            { l: 'حالة الفاتورة', v: getStatusLabel(selectedSale?.status === 'canceled' ? 'canceled' : selectedSale?.dynamic_status) },
          ]" :key="info.l" class="min-w-0 space-y-1 rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-2">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wide">{{ info.l }}</p>
            <p class="break-words text-[11px] font-bold leading-4 text-slate-800">{{ info.v }}</p>
          </div>
        </div>

        <!-- Status Message if Available -->
        <div v-if="getStatusMessage(selectedSale?.dynamic_status)" class="p-4 rounded-lg border-l-4 border-blue-400 bg-blue-50">
          <p class="text-xs font-bold text-blue-700">{{ getStatusMessage(selectedSale?.dynamic_status) }}</p>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-100 shadow-sm">
          <table class="w-full text-right text-xs">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-100">
                <th class="px-2 py-3 text-[10px] font-bold text-slate-400 uppercase">المنتج</th>
                <th class="px-2 py-3 text-[10px] font-bold text-slate-400 uppercase text-center">الكمية</th>
                <th class="px-2 py-3 text-[10px] font-bold text-slate-400 uppercase">سعر الوحدة</th>
                <th class="px-2 py-3 text[10px] font-bold text-slate-400 uppercase">الإجمالي الأصلي</th>
                <th class="px-2 py-3 text-[10px] font-bold text-slate-400 uppercase">الخصم</th>
                <th class="px-2 py-3 text-[10px] font-bold text-slate-400 uppercase">الضريبة</th>
                <th class="px-2 py-3 text-[10px] font-bold text-slate-400 uppercase">الصافي للوحدة</th>
                <th class="px-2 py-3 text-[10px] font-bold text-slate-400 uppercase">الإجمالي</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="item in selectedSale?.items" :key="item.id">
                <td class="px-2 py-3">
                  <p class="font-bold text-slate-800">{{ item.product_name }}</p>
                </td>
                <td class="px-2 py-3 text-center font-bold text-slate-500">{{ item.quantity }}</td>
                <td class="px-2 py-3 font-bold text-slate-700">{{ formatPrice(item.sale_price) }}</td>
                <td class="px-2 py-3 font-bold text-slate-700">{{ formatPrice((item.sale_price || 0) * (item.quantity || 0)) }}</td>
                <td class="px-2 py-3 font-bold text-rose-500">{{ formatPrice(item.discount_value || 0) }}</td>
                <td class="px-2 py-3 font-bold text-indigo-500">{{ formatPrice((item.net_price || 0) * (taxRate / 100)) }}</td>
                <td class="px-2 py-3 font-bold text-slate-700">{{ formatPrice(item.net_price || 0) }}</td>
                <td class="px-2 py-3 font-bold text-slate-900">{{ formatPrice(item.net_total * (1 + taxRate / 100)) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex flex-col md:flex-row gap-8">
          <div class="flex-1 p-5 rounded-xl bg-slate-50/50 border border-slate-100">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-2">ملاحظات الفاتورة</p>
            <p class="text-xs text-slate-600 italic">{{ selectedSale?.notes || 'لا توجد ملاحظات.' }}</p>
          </div>
          <div class="w-full md:w-72 space-y-3 bg-slate-900 p-6 rounded-xl text-white">
            <div class="flex justify-between text-[11px] font-medium text-slate-400"><span>الإجمالي الفرعي</span><span>{{ formatPrice(subTotalNet) }}</span></div>
            <div class="flex justify-between text-[11px] font-medium text-slate-400"><span>الخصومات</span><span class="text-rose-400">- {{ formatPrice(totalDiscount) }}</span></div>
            <div class="flex justify-between text-[11px] font-medium text-slate-400 pb-3 border-b border-white/10"><span>الضريبة ({{ taxRateDisplay }}%)</span><span>{{ formatPrice(finalNetTotal * (taxRate / 100)) }}</span></div>
            <div class="flex justify-between items-end pt-1">
              <span class="text-xs font-bold uppercase tracking-widest text-blue-400">الصافي</span>
              <span class="text-2xl font-bold tracking-tighter">{{ formatPrice(finalNetTotal * (1 + taxRate / 100)) }}</span>
            </div>
            <div class="pt-3 border-t border-white/10 space-y-2">
              <div class="flex justify-between text-[11px] font-medium text-slate-300">
                <span>صافي التحصيل</span>
                <span>{{ formatPrice(getSalePaid(selectedSale)) }}</span>
              </div>
              <div v-if="Number(selectedSale?.return_amount || 0) > 0" class="flex justify-between text-[11px] font-medium text-slate-300">
                <span>قيمة المرتجعات</span>
                <span class="text-amber-300">{{ formatPrice(selectedSale.return_amount) }}</span>
              </div>
              <div class="flex justify-between text-[11px] font-bold text-rose-300">
                <span>المتبقي</span>
                <span>{{ formatPrice(getSaleRemaining(selectedSale)) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div dir="rtl" class="mr-auto flex flex-row-reverse flex-wrap items-center gap-3">
        <button
          v-if="canEditSelectedSale && !isEditingSale"
          @click="openSaleEdit"
          class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap rounded-md border border-blue-200 px-4 text-xs font-bold text-blue-700 hover:bg-blue-50"
        >
          <i class="fas fa-edit ml-1.5"></i>تعديل الفاتورة
        </button>
        <button
          v-if="isEditingSale"
          @click="cancelSaleEdit"
          :disabled="isUpdatingSale"
          class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap px-4 text-xs font-bold text-slate-500"
        >
          إلغاء التعديل
        </button>
        <button
          v-if="isEditingSale"
          @click="submitSaleEdit"
          :disabled="isUpdatingSale"
          class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap rounded-md bg-blue-600 px-5 text-xs font-bold text-white disabled:opacity-50"
        >
          {{ isUpdatingSale ? 'جارٍ الحفظ...' : 'حفظ التعديلات' }}
        </button>
        <button
          v-if="canVoidSelectedSale && !isEditingSale"
          @click="voidSelectedSale"
          :disabled="isVoidingSale"
          class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap rounded-md border border-rose-200 px-4 text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
        >
          <i class="fas fa-ban ml-1.5"></i>{{ isVoidingSale ? 'جارٍ الإلغاء...' : 'إلغاء البيع' }}
        </button>
        <!-- زر تحصيل دفعة — يظهر فقط للفواتير غير المدفوعة أو المدفوعة جزئياً -->
        <button
          v-if="canCollectPayment && !isEditingSale"
          @click="openCollectModal"
          class="inline-flex h-10 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md border border-emerald-200 px-4 text-xs font-bold text-emerald-700 transition-all hover:bg-emerald-50"
        >
          <i class="fas fa-hand-holding-usd text-[10px]"></i> تحصيل دفعة
        </button>
        <button v-if="!isEditingSale" @click="printSaleDetails" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md bg-indigo-600 px-5 text-xs font-bold text-white shadow-lg shadow-indigo-900/20"><i class="fas fa-print"></i> طباعة الفاتورة</button>
        <button @click="showDetailsModal = false" :disabled="isUpdatingSale" class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap px-4 text-xs font-bold text-slate-500 disabled:opacity-50">إغلاق</button>
        </div>
      </template>
    </HistoryDetailsModal>

    <!-- Collect Payment Modal — Teleported to body to escape BaseModal stacking context -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="showCollectModal"
          class="fixed inset-0 z-[300] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md"
          @click.self="showCollectModal = false"
          @keydown.esc="showCollectModal = false"
        >
          <div class="bg-white w-full max-w-md rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn" dir="rtl">
            <!-- Header -->
            <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
              <div>
                <h3 class="text-sm font-bold text-slate-900">تحصيل دفعة</h3>
                <p class="text-[10px] text-slate-400 mt-0.5 font-medium font-mono">
                  فاتورة {{ selectedSale?.invoice_number || '#' + selectedSale?.id }}
                  <span v-if="collectOutstanding > 0" class="text-rose-500 mr-2">
                    · متبقي {{ formatPrice(collectOutstanding) }}
                  </span>
                </p>
              </div>
              <button type="button" @click="showCollectModal = false"
                      class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-900 hover:bg-slate-100 transition-all flex items-center justify-center">
                <i class="fas fa-times text-xs"></i>
              </button>
            </div>

            <!-- Form -->
            <form @submit.prevent="submitCollectPayment" class="px-8 py-6 space-y-4">
              <p
                v-if="pendingCollectPayment"
                class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[10px] font-semibold text-amber-800"
              >
                توجد عملية تحصيل لم تُحسم نتيجتها. أعد المحاولة بالبيانات نفسها حتى لا تُسجل الدفعة مرتين.
              </p>

              <!-- المبلغ -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">
                  المبلغ <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="collectForm.amount"
                  type="number" step="0.01" min="0.01"
                  :disabled="!!pendingCollectPayment"
                  class="h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all font-mono"
                  placeholder="0.00" required
                />
                <button
                  v-if="collectOutstanding > 0"
                  type="button"
                  @click="collectForm.amount = collectOutstanding"
                  :disabled="!!pendingCollectPayment"
                  class="text-[9px] font-bold text-blue-600 hover:underline px-1"
                >
                  تحصيل المتبقي كاملاً ({{ formatPrice(collectOutstanding) }})
                </button>
              </div>

              <!-- تاريخ الدفع -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">
                  تاريخ الدفع <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="collectForm.payment_date"
                  type="date"
                  :disabled="!!pendingCollectPayment"
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
                  v-model="collectForm.payment_method_id"
                  :disabled="!!pendingCollectPayment"
                  class="h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all appearance-none"
                  required
                >
                  <option value="">اختر طريقة الدفع</option>
                  <option v-for="m in collectiblePaymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
              </div>

              <!-- الفرع -->
              <!-- ملاحظات -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">ملاحظات</label>
                <textarea
                  v-model="collectForm.notes"
                  :disabled="!!pendingCollectPayment"
                  rows="2"
                  class="w-full bg-white border border-slate-200 rounded-md px-3 py-2 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all italic resize-none"
                  placeholder="مرجع التحويل، رقم الشيك..."
                ></textarea>
              </div>

              <!-- أزرار -->
              <div class="pt-4 border-t border-slate-100 flex gap-3">
                <button
                  type="button"
                  @click="showCollectModal = false"
                  class="flex-1 h-10 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors rounded-md hover:bg-slate-50"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  :disabled="isCollecting"
                  class="flex-[2] h-10 bg-emerald-600 text-white rounded-md text-xs font-bold shadow-lg shadow-emerald-900/20 active:scale-95 transition-all disabled:opacity-50"
                >
                  {{ isCollecting ? 'جاري التحصيل...' : 'تأكيد التحصيل' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue';
import { useRoute } from 'vue-router';
import { useHistoryFilters } from '@/composables/useHistoryFilters';
import { useSessionExemption } from '@/composables/useCashierSessionGuard';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useSalesStore } from '@/stores/sales/salesStore';
import { useSettingsStore } from '@/stores/settings/settingsStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useBootstrapStore } from '@/stores/bootstrap/bootstrapStore';
import { useCostCenterStore } from '@/stores/costCenter/costCenterStore';
import AlertService from '@/services/AlertService';
import { useToast } from '@/composables/useToast';
import { useLoader } from '@/composables/useLoader';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { getLocalDateISO, printDocument } from '@/utils';
import PageHeader from '@/components/PageHeader.vue';

const { showToast } = useToast();
const { showLoader, hideLoader } = useLoader();
const authStore = useAuthStore();
const branchStore = useBranchStore();
const branchIsolation = useBranchIsolation();
const salesStore = useSalesStore();
const settingsStore = useSettingsStore();
const customerStore = useCustomerStore();
const paymentStore = usePaymentStore();
const bootstrapStore = useBootstrapStore();
const costCenterStore = useCostCenterStore();
const route = useRoute();
const { ensureLoaded: ensureExemptionLoaded } = useSessionExemption();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { breadcrumb } = useBreadcrumb();
const formatPrice = (amount) => formatCurrencyLocale(amount, 2);

const taxRate = ref(0);
const taxRateDisplay = computed(() => taxRate.value.toFixed(0));

const sales = ref([]);
const paymentMethods = computed(() => paymentStore.paymentMethods);
const collectiblePaymentMethods = computed(() =>
  paymentMethods.value.filter(method => String(method.kind || '').toLowerCase() !== 'credit')
);
const customers = computed(() => customerStore.customers);
const branches = computed(() => branchStore.branches);
const costCenters = computed(() => costCenterStore.costCenters);

const selectedBranch = computed(() => branchStore.selectedBranchId);
const canSelectBranch = computed(() => branchIsolation.isExempt.value);

// ✅ يتتبع الاختيار اليدوي للفرع — لا يعتمد على localStorage مباشرة
// لأن fetchBranches() يكتب default branch في localStorage تلقائياً عند أول دخول
const userChoseBranch = ref(
  localStorage.getItem('selectedBranchId') !== null
);
const hasExplicitBranchSelection = computed(() => userChoseBranch.value);

const onBranchChange = (newBranchId) => {
  if (!canSelectBranch.value) return;
  branchStore.setSelectedBranch(newBranchId);
  userChoseBranch.value = true;
  filters.page.value = 1;
  fetchSalesHistory(true);  // ✅ force=true لإعادة جلب البيانات عند تغيير الفرع
};

const cashMethodId = computed(() => {
  const m = (paymentMethods.value || []).find(pm => String(pm.kind || '').toLowerCase() === 'cash');
  return m?.id || null;
});

const statusOptions = [
  { value: '', label: 'كل الحالات' },
  { value: 'paid', label: 'مدفوعة' },
  { value: 'partial', label: 'جزئي' },
  { value: 'unpaid', label: 'مستحقة' },
  { value: 'canceled', label: 'ملغاة' }
];

const filters = useHistoryFilters('sales_hist_filters');
filters.loadFromLocalStorage();

const filteredCustomers = computed(() => {
  const q = (filters.customerSearch.value || '').toLowerCase();
  const list = customers.value || [];
  if (!q) return list.slice(0, 50);
  return list.filter(c => String(c.name || c.customer_name || '').toLowerCase().includes(q)).slice(0, 50);
});

const totalPages = computed(() => Math.max(1, Math.ceil(filters.total.value / filters.perPage.value)));

const isLoadingList = ref(false);
const isLoadingDetails = ref(false);
const isMarkingPaid = ref(false);
const isVoidingSale = ref(false);
const isUpdatingSale = ref(false);
const isEditingSale = ref(false);
const saleEditForm = ref({ sale_date: '', notes: '', payment_method_id: '', cost_center_id: '' });
const isDataStale = ref(false);
const lastSuccessfulUpdate = ref(null);
let listAbortCtrl = null;
let detailsAbortCtrl = null;
let searchTimer = null;

const dateFromRefLocal = ref(null);
const dateToRefLocal = ref(null);

const allSelected = computed(() => sales.value.length > 0 && filters.selectedIds.value.length === sales.value.length);
const toggleSelectAll = (e) => { 
  filters.toggleSelectAll(e?.target?.checked ? sales.value : []);
};

const subTotalNet = computed(() => {
  if (!selectedSale.value?.items) return 0;
  return selectedSale.value.items.reduce((sum, item) => sum + (parseFloat(item.total) || 0), 0);
});

const totalDiscount = computed(() => {
  if (!selectedSale.value?.items) return 0;
  return selectedSale.value.items.reduce((sum, item) => sum + (parseFloat(item.discount_value) || 0), 0);
});

const finalNetTotal = computed(() => {
  if (selectedSale.value?.sale?.net_total_amount !== undefined) return parseFloat(selectedSale.value.sale.net_total_amount) || 0;
  if (selectedSale.value?.items) return selectedSale.value.items.reduce((sum, item) => sum + (parseFloat(item.net_total) || 0), 0);
  return 0;
});

const selectedSalePaymentMethodId = computed(() => {
  const s = selectedSale.value || {};
  return s.payment_method_id || (s.payments && s.payments[0]?.payment_method_id) || null;
});

const showDetailsModal = ref(false);
const selectedSale = ref(null);
const canEditSelectedSale = computed(() => {
  const sale = selectedSale.value;
  if (!sale) return false;
  const hasPermission = authStore.isSuperAdmin || authStore.user?.permissions?.includes('sale.edit') === true;
  return hasPermission
    && ['draft', 'pending_approval', 'rejected'].includes(String(sale.status || '').toLowerCase())
    && !Number(sale.journal_entry_id || 0);
});
const hasSalePayment = computed(() =>
  selectedSale.value?.has_payment_records === true
  || Number(selectedSale.value?.actual_paid_amount ?? selectedSale.value?.paid_amount ?? 0) > 0.009
);
const canVoidSelectedSale = computed(() => {
  const sale = selectedSale.value;
  if (!sale) return false;
  const hasPermission = authStore.isSuperAdmin || authStore.user?.permissions?.includes('sale.void') === true;
  const status = String(sale.status || '').toLowerCase();
  return hasPermission
    && !['paid', 'canceled', 'cancelled'].includes(status)
    && Number(sale.actual_paid_amount ?? sale.paid_amount ?? 0) <= 0.009
    && Number(sale.return_amount ?? 0) <= 0.009
    && Number(sale.return_credit_amount ?? 0) <= 0.009;
});

// ─── Collect Payment Modal ────────────────────────────────────────────────
const showCollectModal = ref(false);
const isCollecting = ref(false);
const collectForm = ref({ amount: null, payment_date: null, payment_method_id: null, notes: '' });
const pendingCollectPayment = ref(null);

const collectPaymentStorageKey = (saleId) =>
  `pending_sale_payment:${authStore.user?.tenant_id || 'platform'}:${authStore.user?.id || 'current'}:${saleId}`;

const createIdempotencyKey = () => {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  const bytes = new Uint8Array(16);
  globalThis.crypto.getRandomValues(bytes);
  return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
};

const loadPendingCollectPayment = (saleId) => {
  try {
    const storageKey = collectPaymentStorageKey(saleId);
    const storedValue = sessionStorage.getItem(storageKey);
    if (!storedValue) return null;
    return JSON.parse(storedValue);
  } catch (error) {
    console.error('Unable to restore pending sale payment:', error);
    showToast('تعذر استعادة عملية التحصيل السابقة؛ ابدأ عملية جديدة.', 'error');
    return null;
  }
};

// الفاتورة قابلة للتحصيل إذا لم تكن مدفوعة كاملاً أو ملغاة
const canCollectPayment = computed(() => {
  const sale = selectedSale.value;
  if (!sale) return false;
  // نتحقق من dynamic_status أولاً (يعكس المرتجعات والتسويات)، ثم status
  const status = String(sale.dynamic_status || sale.status || '').toLowerCase();
  if (['paid', 'returned', 'canceled', 'cancelled', 'closed_by_return', 'settled_by_return', 'settled_by_credit'].includes(status)) return false;
  if (getSaleRemaining(sale) <= 0.009) return false;
  return authStore.isSuperAdmin || authStore.user?.permissions?.includes('sale.payment.create') === true;
});

// المبلغ المتبقي على الفاتورة — يستخدم total_amount (شامل الضريبة) ليطابق paid_amount
const collectOutstanding = computed(() => {
  const sale = selectedSale.value;
  return getSaleRemaining(sale);
});

const openCollectModal = () => {
  const sale = selectedSale.value;
  if (!sale) return;

  const pending = loadPendingCollectPayment(sale.id);
  pendingCollectPayment.value = pending;
  showCollectModal.value = true;
  collectForm.value = pending
    ? { ...pending.payload }
    : {
        amount: collectOutstanding.value > 0 ? collectOutstanding.value : null,
        payment_date: getLocalDateISO(new Date()),
        payment_method_id: cashMethodId.value || (collectiblePaymentMethods.value[0]?.id ?? null),
        notes: '',
      };
};

const submitCollectPayment = async () => {
  const sale = selectedSale.value;
  if (!sale) return;

  if (!collectForm.value.amount || collectForm.value.amount <= 0) {
    showToast('ادخل مبلغ صحيح', 'error'); return;
  }
  if (!collectForm.value.payment_method_id) {
    showToast('اختر طريقة الدفع', 'error'); return;
  }
  if (!collectForm.value.payment_date) {
    showToast('الرجاء تحديد تاريخ الدفع', 'error'); return;
  }

  isCollecting.value = true;
  showLoader();
  try {
    const formPayload = {
      sale_id: Number(sale.id),
      customer_id: sale.customer_id ? Number(sale.customer_id) : undefined,
      amount: Number(collectForm.value.amount),
      payment_date: collectForm.value.payment_date,
      payment_method_id: Number(collectForm.value.payment_method_id),
      notes: collectForm.value.notes || undefined,
    };
    let pending = pendingCollectPayment.value;

    if (pending && pending.fingerprint !== JSON.stringify(formPayload)) {
      showToast('توجد دفعة غير مؤكدة لهذه الفاتورة. أعد المحاولة بنفس البيانات قبل تغييرها.', 'error');
      return;
    }

    const payload = pending?.payload || formPayload;
    const payloadFingerprint = JSON.stringify(payload);
    if (!pending) {
      pending = {
        key: createIdempotencyKey(),
        payload,
        fingerprint: payloadFingerprint,
      };
      sessionStorage.setItem(collectPaymentStorageKey(sale.id), JSON.stringify(pending));
      pendingCollectPayment.value = pending;
    }

    const res = await salesStore.addSalePayment(payload, pending.key);

    if (res?.status === 'success') {
      sessionStorage.removeItem(collectPaymentStorageKey(sale.id));
      pendingCollectPayment.value = null;
      showToast('تم التحصيل بنجاح', 'success');
      showCollectModal.value = false;
      // أعد تحميل تفاصيل الفاتورة لتحديث الحالة والمبلغ المدفوع في الـ modal
      await viewSaleDetails(sale.id);
      // وأعد تحميل القائمة في الخلفية
      fetchSalesHistory();
    } else {
      const isOutcomeUnknown = !res?.httpStatus || res.httpStatus >= 500;
      if (isOutcomeUnknown) {
        pendingCollectPayment.value = pending;
        showToast('تعذر تأكيد نتيجة التحصيل. أعد المحاولة بنفس البيانات لإتمام التحقق بأمان.', 'error');
      } else {
        sessionStorage.removeItem(collectPaymentStorageKey(sale.id));
        pendingCollectPayment.value = null;
        showToast(res?.message || 'فشل التحصيل', 'error');
      }
    }
  } catch (e) {
    showToast(e?.response?.data?.message || e?.message || 'فشل التحصيل', 'error');
  } finally {
    isCollecting.value = false;
    hideLoader();
  }
};

watch(showDetailsModal, (val) => {
  if (typeof window !== 'undefined') {
    document.body.style.overflow = val ? 'hidden' : '';
  }
});

const formatDateTime = (dateTimeString) => {
  if (!dateTimeString) return '';
  const date = dateTimeString instanceof Date ? dateTimeString : new Date(dateTimeString);
  return date.toLocaleString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const getStatusLabel = (status) => {
  const map = {
    paid: 'مدفوعة',
    canceled: 'ملغاة',
    cancelled: 'ملغاة',
    settled: 'مسددة',
    unpaid: 'غير مدفوعة',
    partial: 'مدفوعة جزئياً',
    pending_payment: 'قيد الدفع',
    returned: 'مرتجعة',
    settled_by_return: 'مسددة',
    closed_by_return: 'مرتجعة',
    settled_by_credit: 'مسوّاة بمرتجع',
    settled_mixed: 'مسوّاة نقدي/إشعار دائن',
  };
  return map[status] || status;
};

const getStatusMessage = (status) => {
  const messages = {
    settled_by_return: 'مسددة بإشعار دائن ناتج عن مرتجع.',
    settled_by_credit: 'مسددة بمرتجع.',
    settled_mixed: 'مسددة جزئياً نقدي وجزئياً بإشعار دائن.',
  };
  return messages[status] || null;
};

const getDynamicStatusClass = (status) => {
  const classMap = {
    paid: 'status-paid-v2',
    canceled: 'status-unpaid-v2',
    cancelled: 'status-unpaid-v2',
    settled: 'status-paid-v2',
    unpaid: 'status-unpaid-v2',
    partial: 'status-partial-v2',
    pending_payment: 'status-partial-v2',
    returned: 'status-unpaid-v2',
    settled_by_return: 'status-paid-v2',
    closed_by_return: 'status-unpaid-v2',
    settled_by_credit: 'status-paid-v2',
    settled_mixed: 'status-paid-v2',
  };
  return classMap[status] || 'status-partial-v2';
};

const getSaleGross = (s) => {
  if (!s) return 0;
  const net = parseFloat(s.net_total_amount ?? s.total_amount ?? 0) || 0;
  if (s.grand_total !== undefined && s.grand_total !== null) return parseFloat(s.grand_total) || net;
  const tax = (s.tax_amount !== undefined && s.tax_amount !== null) ? (parseFloat(s.tax_amount) || 0) : (net * (taxRate.value / 100));
  return net + tax;
};
const isSaleCanceled = (sale) =>
  ['canceled', 'cancelled'].includes(String(sale?.status || sale?.dynamic_status || '').toLowerCase());
const getSalePaid = (s) => Math.max(0, parseFloat(s?.actual_paid_amount ?? s?.paid_amount ?? 0) || 0);
const getSaleRemaining = (s) => {
  if (!s || isSaleCanceled(s)) return 0;
  const status = String(s.dynamic_status || s.status || '').toLowerCase();
  if (['returned', 'closed_by_return', 'settled_by_return', 'settled_by_credit'].includes(status)) return 0;
  if (s.remaining_balance !== null && s.remaining_balance !== undefined && s.remaining_balance !== '') {
    return Math.max(0, parseFloat(s.remaining_balance) || 0);
  }
  return Math.max(
    0,
    getSaleGross(s) - getSalePaid(s) - (parseFloat(s?.return_credit_amount ?? s?.return_credits ?? 0) || 0)
  );
};

const getPaymentMethodName = (id) => {
  if (!id) return 'غير محددة';
  const m = paymentMethods.value.find(p => Number(p.id) === Number(id));
  return m?.name || 'غير محددة';
};

const getAgeBadge = (sale) => {
  if (!sale || isSaleCanceled(sale) || ['paid', 'returned', 'settled_by_return', 'closed_by_return', 'settled_by_credit', 'settled_mixed'].includes(sale.dynamic_status)) return null;
  const base = sale.due_date || sale.created_at;
  if (!base) return null;
  const days = Math.max(0, Math.floor((Date.now() - new Date(base).getTime()) / (1000 * 60 * 60 * 24)));
  if (days <= 7) return { cls: 'bg-emerald-50 text-emerald-600 border-emerald-100', label: `${days} يوم` };
  if (days <= 30) return { cls: 'bg-amber-50 text-amber-600 border-amber-100', label: `${days} يوم` };
  return { cls: 'bg-rose-50 text-rose-600 border-rose-100', label: `${days} يوم` };
};

const fetchSalesHistory = async (forceRefresh = false) => {
  if (listAbortCtrl) listAbortCtrl.abort();
  listAbortCtrl = new AbortController();
  isLoadingList.value = true;
  showLoader();
  try {
    const params = filters.getApiParams({ includeTotals: true, force: forceRefresh });
    if (!canSelectBranch.value) {
      try {
        const wid = branchIsolation.getRequiredBranchId();
        params.branchId = String(wid);
      } catch (e) {
        sales.value = []; filters.total.value = 0; filters.kpiSum.value = 0; filters.kpiTax.value = null; filters.kpiDiscount.value = null;
        isDataStale.value = false;
        showToast(e.message || 'لم يتم تعيين مخزن لحسابك.', 'error'); return;
      }
    } else if (branchStore.selectedBranchId) {
      params.branchId = String(branchStore.selectedBranchId);
    }
    
    console.log('[fetchSalesHistory] API Call params:', {
      branchId: params.branchId,
      selectedBranchId: branchStore.selectedBranchId,
      canSelectBranch: canSelectBranch.value,
      paramsPayload: params
    });
    
    const response = await salesStore.fetchSalesList(params);
    const resData = response?.data || response;
    if (resData) {
      sales.value = resData.items || [];
      filters.total.value = resData.total || 0;
      filters.kpiSum.value = resData.kpiSum || 0;
      filters.kpiTax.value = resData.kpiTax ?? null;
      filters.kpiDiscount.value = resData.kpiDiscount ?? null;
      isDataStale.value = false;
      lastSuccessfulUpdate.value = new Date();
    } else {
      sales.value = []; filters.total.value = 0; filters.kpiSum.value = 0; filters.kpiTax.value = null; filters.kpiDiscount.value = null;
      isDataStale.value = false;
    }
  } catch (e) {
    const isAborted = e?.name === 'CanceledError' || e?.name === 'AbortError' || e?.message === 'canceled';
    if (!isAborted) {
      // احتفظ بالبيانات القديمة مع تنبيه واضح
      isDataStale.value = true;
      showToast('⚠️ فشل تحديث البيانات. البيانات المعروضة قد تكون قديمة. حاول مرة أخرى.', 'warning');
    }
  } finally { isLoadingList.value = false; hideLoader(); }
};

const viewSaleDetails = async (saleId) => {
  if (detailsAbortCtrl) detailsAbortCtrl.abort();
  detailsAbortCtrl = new AbortController();
  isLoadingDetails.value = true;
  showLoader();
  try {
    const payload = await salesStore.fetchSaleDetails(saleId);
    const saleData = payload?.data || payload;
    selectedSale.value = (saleData && saleData.sale) ? saleData.sale : saleData;
    isEditingSale.value = false;
    showDetailsModal.value = true;
  } catch (e) {
    const isAborted = e?.name === 'CanceledError' || e?.name === 'AbortError' || e?.message === 'canceled';
    if (!isAborted) showToast('فشل في تحميل تفاصيل الفاتورة', 'error');
  } finally { isLoadingDetails.value = false; hideLoader(); }
};

const openSaleEdit = async () => {
  if (!selectedSale.value || !canEditSelectedSale.value) return;
  if (!costCenters.value.length) {
    await costCenterStore.fetchCostCenters();
  }
  const sale = selectedSale.value;
  saleEditForm.value = {
    sale_date: String(sale.sale_date || sale.created_at || '').slice(0, 10),
    notes: sale.notes || '',
    payment_method_id: sale.payment_method_id ? String(sale.payment_method_id) : '',
    cost_center_id: sale.cost_center_id ? String(sale.cost_center_id) : '',
  };
  isEditingSale.value = true;
};

const cancelSaleEdit = () => {
  isEditingSale.value = false;
};

const submitSaleEdit = async () => {
  const sale = selectedSale.value;
  if (!sale || !canEditSelectedSale.value || isUpdatingSale.value) return;
  if (!saleEditForm.value.sale_date) {
    showToast('حدد تاريخ الفاتورة', 'error');
    return;
  }

  isUpdatingSale.value = true;
  showLoader();
  try {
    const payload = {
      sale_date: saleEditForm.value.sale_date,
      notes: saleEditForm.value.notes,
      cost_center_id: saleEditForm.value.cost_center_id || null,
    };
    if (!hasSalePayment.value) {
      payload.payment_method_id = saleEditForm.value.payment_method_id || null;
    }
    const result = await salesStore.updateSale(sale.id, payload);
    if (result?.status !== 'success') {
      showToast(result?.message || 'تعذر تعديل فاتورة البيع', 'error');
      return;
    }
    isEditingSale.value = false;
    showToast('تم تحديث الفاتورة بنجاح', 'success');
    await viewSaleDetails(sale.id);
    await fetchSalesHistory(true);
  } finally {
    isUpdatingSale.value = false;
    hideLoader();
  }
};

const voidSelectedSale = async () => {
  const sale = selectedSale.value;
  if (!sale || !canVoidSelectedSale.value || isVoidingSale.value) return;
  const confirmed = await AlertService.confirm(
    'سيتم إلغاء الفاتورة وعكس قيدها وحركة مخزونها إن وُجدت. لا يمكن إلغاء فاتورة عليها تحصيل أو مرتجع. هل تريد المتابعة؟',
    'تأكيد إلغاء البيع'
  );
  if (!confirmed) return;

  isVoidingSale.value = true;
  showLoader();
  try {
    const result = await salesStore.voidSale(sale.id, 'إلغاء من سجل المبيعات');
    if (result?.status !== 'success') {
      showToast(result?.message || 'تعذر إلغاء فاتورة البيع', 'error');
      return;
    }
    showDetailsModal.value = false;
    selectedSale.value = null;
    filters.clearSelection();
    showToast('تم إلغاء فاتورة البيع بنجاح', 'success');
    await fetchSalesHistory(true);
  } finally {
    isVoidingSale.value = false;
    hideLoader();
  }
};

const toggleSort = (key) => {
  if (filters.sortKey.value === key) filters.sortAsc.value = !filters.sortAsc.value;
  else { filters.sortKey.value = key; filters.sortAsc.value = true; }
  try { localStorage.setItem('sales_hist_sortKey', filters.sortKey.value); localStorage.setItem('sales_hist_sortAsc', filters.sortAsc.value ? '1' : '0'); } catch {}
  filters.page.value = 1; fetchSalesHistory();
};

const markSelectedAsPaid = async () => {
  if (!filters.selectedIds.value.length || isMarkingPaid.value) return;
  const rows = (sales.value || []).filter(s => new Set(filters.selectedIds.value).has(s.id));
  const payable = rows.filter(s => {
    const status = String(s.dynamic_status || s.status || '').toLowerCase();
    const paid = parseFloat(s.actual_paid_amount ?? s.paid_amount ?? 0);
    const returnCredits = parseFloat(s.return_credit_amount ?? 0);
    return !['paid', 'canceled', 'cancelled', 'closed_by_return', 'settled_by_return', 'settled_by_credit'].includes(status)
      && (getSaleGross(s) - paid - returnCredits > 0.0001);
  });
  if (!payable.length) { showToast('لا توجد فواتير قابلة للتحصيل ضمن المحدد', 'info'); return; }
  const batchPaymentMethodId = cashMethodId.value ?? collectiblePaymentMethods.value[0]?.id;
  if (!batchPaymentMethodId) { showToast('لا توجد طريقة دفع متاحة لتحصيل الفواتير المحددة', 'error'); return; }
  const confirmed = await AlertService.confirm(`سيتم تعليم ${payable.length} فاتورة كمسددة. هل تريد المتابعة؟`, 'تسديد الفواتير');
  if (!confirmed) return;
  isMarkingPaid.value = true; showLoader();
  let ok = 0, fail = 0;
  for (const s of payable) {
    try {
      const paid = parseFloat(s.actual_paid_amount ?? s.paid_amount ?? 0);
      const returnCredits = parseFloat(s.return_credit_amount ?? 0);
      const remaining = Math.max(0, getSaleGross(s) - paid - returnCredits);
      if (remaining <= 0) { ok++; continue; }
      const paymentMethodId = Number(batchPaymentMethodId);
      const storageKey = collectPaymentStorageKey(s.id);
      const intendedPayload = {
        sale_id: Number(s.id),
        customer_id: s.customer_id ? Number(s.customer_id) : undefined,
        amount: Number(remaining),
        payment_date: getLocalDateISO(new Date()),
        payment_method_id: paymentMethodId,
      };
      let pending = loadPendingCollectPayment(s.id);
      if (pending && (
        Number(pending.payload.sale_id) !== intendedPayload.sale_id
        || Number(pending.payload.amount) !== intendedPayload.amount
        || Number(pending.payload.payment_method_id) !== intendedPayload.payment_method_id
        || Number(pending.payload.customer_id || 0) !== Number(intendedPayload.customer_id || 0)
      )) {
        fail++;
        continue;
      }
      if (!pending) {
        pending = {
          key: createIdempotencyKey(),
          payload: intendedPayload,
          fingerprint: JSON.stringify(intendedPayload),
        };
        sessionStorage.setItem(storageKey, JSON.stringify(pending));
      }

      const res = await salesStore.addSalePayment(pending.payload, pending.key);
      if (res?.status === 'success') {
        sessionStorage.removeItem(storageKey);
        ok++;
      } else if (!res?.httpStatus || res.httpStatus >= 500) {
        fail++;
      } else {
        sessionStorage.removeItem(storageKey);
        fail++;
      }
    } catch (error) {
      console.error(`Bulk sale payment failed for sale ${s.id}:`, error);
      fail++;
    }
  }
  hideLoader(); isMarkingPaid.value = false;
  showToast(`تمت معالجة ${ok} بنجاح، وفشل ${fail}${fail ? '؛ ستبقى الفواتير الفاشلة محددة لإعادة المحاولة' : ''}`, fail ? 'warning' : 'success');
  if (!fail) filters.clearSelection();
  fetchSalesHistory();
};

const exportSelectedCsv = () => {
  const rows = (sales.value || []).filter(s => new Set(filters.selectedIds.value).has(s.id));
  if (!rows.length) { showToast('لا توجد فواتير محددة للتصدير', 'info'); return; }
  
  const headers = ['رقم الفاتورة', 'التاريخ', 'العميل', 'عدد الأصناف', 'الإجمالي', 'الحالة', 'طريقة الدفع'];
  const data = rows.map(s => [
    s.invoice_number || `#${s.id}`,
    formatDateTime(s.created_at),
    s.customer_name || 'عميل نقدي',
    s.total_items,
    formatPrice(getSaleGross(s)),
    getStatusLabel(s.status === 'canceled' ? 'canceled' : s.dynamic_status),
    getPaymentMethodName(s.payment_method_id)
  ]);
  
  const csvContent = [
    headers.join(','),
    ...data.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(','))
  ].join('\n');
  
  const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  const url = URL.createObjectURL(blob);
  link.setAttribute('href', url);
  link.setAttribute('download', `sales-export-${new Date().getTime()}.csv`);
  link.style.visibility = 'hidden';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  showToast(`تم تصدير ${rows.length} فاتورة بنجاح`, 'success');
};

const printSelected = () => {
  const rows = (sales.value || []).filter(s => new Set(filters.selectedIds.value).has(s.id));
  if (!rows.length) return;
  const win = window.open('', '_blank');
  const totalSelected = rows.reduce((sum, s) => sum + parseFloat(getSaleGross(s) || 0), 0);
  const html = `<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"/><title>طباعة فواتير</title><style>body{font-family:Tahoma,Arial,sans-serif;direction:rtl}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:8px;text-align:center}th{background:#f5f5f5}</style></head><body><h3>فواتير مختارة</h3><table><thead><tr><th>#</th><th>التاريخ</th><th>العميل</th><th>الأصناف</th><th>الإجمالي</th><th>الحالة</th></tr></thead><tbody>${rows.map(s => `<tr><td>${s.id}</td><td>${formatDateTime(s.created_at)}</td><td>${s.customer_name || 'عميل نقدي'}</td><td>${s.total_items}</td><td>${formatPrice(getSaleGross(s))}</td><td>${getStatusLabel(s.dynamic_status)}</td></tr>`).join('')}</tbody><tfoot><tr><th colspan="3">الملخص</th><th>${rows.length} فاتورة</th><th>${formatPrice(totalSelected)}</th><th></th></tr></tfoot></table></body></html>`;
  win.document.open(); win.document.write(html); win.document.close();
  win.onload = () => { win.print(); setTimeout(() => { try { win.close(); } catch {} }, 300); };
};

const getSelectedPrintTemplate = () => {
  let t = (localStorage.getItem('pos_print_template') || '').toLowerCase();
  if (t === 'thermal') t = 'thermal-compact';
  if (t === 'a4') t = 'a4-simple';
  return new Set(['thermal-compact', 'thermal-detailed', 'a4-simple', 'a4-professional']).has(t) ? t : 'thermal-compact';
};

const printSaleDetails = async () => {
  if (!selectedSale.value) return;
  const html = getBuilderByTemplate(getSelectedPrintTemplate())(selectedSale.value);
  await printDocument(html);
};

// isMounting flag: يمنع الـ watches من إطلاق fetchSalesHistory() أثناء onMounted
let isMounting = true;

watch([() => filters.dateFrom.value, () => filters.dateTo.value, () => filters.statusFilter.value, () => filters.customerFilter.value, () => filters.perPage.value], () => {
  if (isMounting) return;
  try {
    localStorage.setItem('sales_hist_dateFrom', filters.dateFrom.value || '');
    localStorage.setItem('sales_hist_dateTo', filters.dateTo.value || '');
    localStorage.setItem('sales_hist_status', filters.statusFilter.value || '');
    localStorage.setItem('sales_hist_customer', filters.customerFilter.value || '');
    localStorage.setItem('sales_hist_perPage', filters.perPage.value.toString());
  } catch {}
  filters.page.value = 1; fetchSalesHistory();
});

watch(() => filters.page.value, () => {
  if (isMounting) return;
  try { localStorage.setItem('sales_hist_page', filters.page.value.toString()); } catch {}
  fetchSalesHistory();
});

// selectedBranch مُزال من watch — تغيير الفرع يُعالج عبر onBranchChange() مباشرة
// إبقاؤه يُسبب race condition: fetchBranches() تُغيّر selectedBranchId أثناء onMounted فيُطلق watch مبكراً

watch(() => filters.searchQuery.value, () => {
  if (isMounting) return;
  if (searchTimer) clearTimeout(searchTimer);
  filters.page.value = 1; searchTimer = setTimeout(() => fetchSalesHistory(), 400);
});

watch(() => filters.customerFilter.value, (nv) => {
  const selected = (customers.value || []).find(c => String(c.id) === String(nv));
  filters.customerSearch.value = selected ? (selected.name || selected.customer_name || '') : '';
});

watch(() => route.query.id, async (nv) => {
  if (Number(nv || 0) > 0) { try { await viewSaleDetails(Number(nv)); } catch {} }
});

onMounted(async () => {
  try {
    const data = await bootstrapStore.fetchPaymentsData();
    if (data.customers) customerStore.customers = data.customers;
    if (data.paymentMethods) paymentStore.paymentMethods = data.paymentMethods;
    await Promise.all([fetchSettings(), settingsStore.fetchTaxSettings().catch(() => {}), ensureExemptionLoaded().catch(() => {})]);
  } catch (bootstrapError) {
    await Promise.all([fetchSettings(), settingsStore.fetchTaxSettings().catch(() => {}), ensureExemptionLoaded().catch(() => {}), customerStore.fetchCustomers().catch(() => {}), paymentStore.fetchPaymentMethods().catch(() => {})]);
  }
  try { taxRate.value = settingsStore.isTaxEnabled.value ? settingsStore.getTaxRate.value : 0; } catch {}
  
  const hadPriorBranchChoice = localStorage.getItem('selectedBranchId') !== null;
  try {
    branchStore.loadFromStorage();
    if (!branchStore.branches || branchStore.branches.length === 0) {
      await branchStore.fetchBranches();
    }
  } catch (err) {
    console.error('[SalesHistory] Failed to initialize branches:', err);
    showToast('فشل في تحميل قائمة الفروع', 'error');
    return;
  }

  userChoseBranch.value = hadPriorBranchChoice;
  
  let resolvedBranchId = branchStore.selectedBranchId;
  if (!canSelectBranch.value) {
    try {
      resolvedBranchId = branchIsolation.getRequiredBranchId();
    } catch (err) {
      console.error('[SalesHistory] Failed to resolve branch ID:', err);
      showToast(err.message || 'لم يتم تعيين الفرع', 'error');
      return;
    }
  }
  
  console.log('[SalesHistory] Before first API call:', {
    selectedBranchId: branchStore.selectedBranchId,
    selectedBranch: branchStore.selectedBranch?.name || null,
    canSelectBranch: canSelectBranch.value,
    resolvedBranchId,
    branchesCount: branchStore.branches.length
  });
  
  const selected = customers.value.find(c => String(c.id) === String(filters.customerFilter.value));
  filters.customerSearch.value = selected ? (selected.name || selected.customer_name || '') : '';
  filters.page.value = 1;
  isMounting = false;
  fetchSalesHistory();
  if (Number(route.query.id || 0) > 0) { try { await viewSaleDetails(Number(route.query.id)); } catch {} }
});

onUnmounted(() => {
  if (listAbortCtrl) listAbortCtrl.abort();
  if (detailsAbortCtrl) detailsAbortCtrl.abort();
  if (searchTimer) clearTimeout(searchTimer);
  if (typeof window !== 'undefined') document.body.style.overflow = '';
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

/* Form Inputs */
.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

/* Status Badges */
.status-paid-v2 {
  @apply bg-emerald-50 text-emerald-600 border-emerald-100;
}

.status-unpaid-v2 {
  @apply bg-rose-50 text-rose-600 border-rose-100;
}

.status-partial-v2 {
  @apply bg-amber-50 text-amber-600 border-amber-100;
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

/* Transition Classes */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-up-enter-from,
.slide-up-leave-to {
  opacity: 0;
  transform: translate(-50%, 20px);
}

/* Animation Classes */
.animate-modalIn {
  animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
</style>