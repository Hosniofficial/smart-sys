<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar (Active on Loading / Searching / Saving) -->
    <div 
      v-if="isSearchingProducts || isSaving || isPrinting || loadingSession || refreshingSession" 
      class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]"
    >
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1700px] mx-auto p-5 lg:p-8 space-y-6 animate-fadeIn">
      
      <PageHeader
        :breadcrumb="{ parent: 'المبيعات', current: 'نقطة البيع' }"
        title="نقطة البيع"
        description="ابحث عن المنتجات، وجهّز سلة البيع، ثم أصدر الفاتورة وسجّل عملية التحصيل."
        :branches="[]"
      >
        <template #controls>
          <button 
            @click="goToCashierDashboard" 
            class="h-9 px-4 rounded-lg border border-blue-100 bg-blue-50 text-blue-700 text-xs font-bold transition-all flex items-center gap-2 shadow-sm hover:bg-blue-100 active:scale-95"
          >
            <i class="fas fa-chart-line text-[11px] text-blue-600"></i>
            <span>لوحة التحكم</span>
          </button>
        </template>
      </PageHeader>

      <!-- Main POS Grid -->
      <div class="flex flex-col lg:flex-row gap-6 w-full items-start">
        
        <!-- Right Column: Product Search & Catalog Table -->
        <div class="w-full lg:w-7/12 xl:w-2/3 space-y-4">
          
          <!-- Filters & Search Bar Card -->
          <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
              
              <!-- Search Box -->
              <div class="sm:col-span-4 relative group">
                <input
                  v-model="searchQuery"
                  ref="productSearchInputRef"
                  @input="debouncedSearch"
                  @focus="searchQuery && debouncedSearch()"
                  @keydown.enter.prevent="addFirstResult"
                  type="text"
                  placeholder="ابحث بالاسم، الكود، أو الباركود..."
                  class="filter-input"
                  style="padding-right: 2.5rem; padding-left: 2rem;"
                />
                <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] group-focus-within:text-blue-500 transition-colors pointer-events-none"></i>
                <kbd class="absolute left-2.5 top-1/2 -translate-y-1/2 bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded text-[9px] font-mono text-slate-400 font-bold">F1</kbd>
              </div>

              <!-- Categories Select -->
              <div class="sm:col-span-3 relative">
                <select v-model="selectedCategory" @change="debouncedSearch" class="filter-input appearance-none" style="padding-right: 2rem;">
                  <option value="">كل التصنيفات</option>
                  <option v-for="cat in categories || []" :key="cat?.id" :value="cat?.id">{{ cat?.name }}</option>
                </select>
                <i class="fas fa-tags absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
              </div>

              <!-- Branch Selector (Admins/Managers) -->
              <div v-if="canSelectBranch" class="sm:col-span-3 relative">
                <select v-model="selectedBranch" @change="debouncedSearch" class="filter-input appearance-none" style="padding-right: 2rem;">
                  <option disabled :value="null">-- اختر الفرع --</option>
                  <option v-for="wh in branchStore.branches" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
                </select>
                <i class="fas fa-building absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
              </div>

              <!-- Toggles Icons Only -->
              <div class="sm:col-span-2 flex items-center gap-2">
                <button 
                  type="button"
                  @click="toggleInactive" 
                  :class="[showInactive ? 'text-rose-600 bg-rose-50 border-rose-200' : 'text-slate-400 border-slate-200 hover:bg-slate-50']"
                  class="w-9 h-9 rounded-lg border flex items-center justify-center transition-all shadow-sm"
                  title="عرض المنتجات غير النشطة"
                >
                  <i class="fas fa-eye-slash text-[11px]"></i>
                </button>

                <button 
                  type="button"
                  @click="toggleExpiring" 
                  :class="[showExpiring ? 'text-amber-600 bg-amber-50 border-amber-200' : 'text-slate-400 border-slate-200 hover:bg-slate-50']"
                  class="w-9 h-9 rounded-lg border flex items-center justify-center transition-all shadow-sm"
                  title="عرض المنتجات القريبة من انتهاء الصلاحية"
                >
                  <i class="fas fa-hourglass-end text-[11px]"></i>
                </button>
              </div>

            </div>
          </div>

          <!-- Product Catalog Table Card -->
          <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm relative min-h-[420px]">
            <div class="overflow-x-auto">
              <table class="w-full text-right border-collapse">
                <thead>
                  <tr class="bg-slate-50 border-b border-slate-200">
                    <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">#</th>
                    <th class="px-3 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">كود</th>
                    <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">اسم الصنف</th>
                    <th class="px-3 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الرصيد</th>
                    <th class="px-3 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الوحدة</th>
                    <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-left">سعر البيع</th>
                    <th class="px-3 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-left">أقل سعر</th>
                    <th class="px-3 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الباركود</th>
                    <th class="px-3 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التصنيف</th>
                    <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center w-14">إضافة</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  
                  <!-- Skeleton Loading -->
                  <template v-if="isSearchingProducts">
                    <tr v-for="row in 6" :key="row" class="animate-pulse">
                      <td class="px-4 py-3 text-center"><div class="h-3 bg-slate-100 rounded w-4 mx-auto"></div></td>
                      <td class="px-3 py-3"><div class="h-3 bg-slate-100 rounded w-12"></div></td>
                      <td class="px-4 py-3"><div class="h-3 bg-slate-100 rounded w-32"></div></td>
                      <td class="px-3 py-3 text-center"><div class="h-4 bg-slate-100 rounded-full w-8 mx-auto"></div></td>
                      <td class="px-3 py-3"><div class="h-3 bg-slate-100 rounded w-10"></div></td>
                      <td class="px-4 py-3"><div class="h-3 bg-slate-100 rounded w-16"></div></td>
                      <td class="px-3 py-3"><div class="h-3 bg-slate-100 rounded w-14"></div></td>
                      <td class="px-3 py-3"><div class="h-3 bg-slate-100 rounded w-16"></div></td>
                      <td class="px-3 py-3"><div class="h-3 bg-slate-100 rounded w-16"></div></td>
                      <td class="px-4 py-3 text-center"><div class="h-7 w-7 bg-slate-100 rounded-lg mx-auto"></div></td>
                    </tr>
                  </template>

                  <!-- Product Rows -->
                  <tr 
                    v-for="(product, i) in filteredSearchResults" 
                    :key="product.id" 
                    class="hover:bg-blue-50/20 transition-all group font-medium"
                  >
                    <td class="px-4 py-3 text-center text-slate-400 font-mono text-[11px]">{{ i + 1 }}</td>
                    <td class="px-3 py-3">
                      <span class="text-[10px] font-bold font-mono text-slate-600 bg-slate-50 px-1.5 py-0.5 rounded border border-slate-100">
                        {{ product.product_code ?? '-' }}
                      </span>
                    </td>
                    <td class="px-4 py-3">
                      <div class="text-xs font-bold text-slate-900 leading-snug">{{ product.name }}</div>
                    </td>
                    <td class="px-3 py-3 text-center">
                      <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold font-mono bg-slate-100 text-slate-700">
                        {{ product.product_type === 'service' ? 'خدمة' : (product.quantity ?? 0) }}
                      </span>
                    </td>
                    <td class="px-3 py-3 text-[11px] text-slate-500 font-medium">{{ product.unit_name ?? '-' }}</td>
                    <td class="px-4 py-3 text-left font-mono font-bold text-xs text-blue-600">
                      {{ formatPrice(product.sale_price) }}
                    </td>
                    <td class="px-3 py-3 text-left font-mono text-[11px] text-amber-600 font-bold">
                      {{ formatPrice(product.min_sale_price) }}
                    </td>
                    <td class="px-3 py-3 text-[10px] font-mono text-slate-400">{{ product.barcode ?? '-' }}</td>
                    <td class="px-3 py-3">
                      <span class="text-[10px] font-medium text-slate-500 bg-slate-50 px-2 py-0.5 rounded border border-slate-100">
                        {{ product.category_name ?? '-' }}
                      </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                      <button
                        @click="addToInvoice(product)"
                        :disabled="loadingProductId === product.id"
                        class="w-7 h-7 rounded-lg border border-slate-200 text-slate-500 hover:text-blue-600 hover:border-blue-200 hover:bg-blue-50 transition-all flex items-center justify-center active:scale-90 mx-auto disabled:opacity-50"
                        title="إضافة للسلة"
                      >
                        <div v-if="loadingProductId === product.id" class="flex items-center gap-1">
                          <div class="w-1 h-1 rounded-full bg-white animate-pulse"></div>
                          <div class="w-1 h-1 rounded-full bg-white animate-pulse" style="animation-delay: 0.2s"></div>
                          <div class="w-1 h-1 rounded-full bg-white animate-pulse" style="animation-delay: 0.4s"></div>
                        </div>
                        <i v-else class="fas fa-plus text-[10px]"></i>
                      </button>
                    </td>
                  </tr>

                  <!-- Empty State -->
                  <tr v-if="!filteredSearchResults.length && !isSearchingProducts">
                    <td colspan="10" class="py-20 text-center text-slate-300">
                      <i class="fas fa-box-open text-3xl mb-3 opacity-20"></i>
                      <p class="text-xs font-bold uppercase tracking-widest">لا توجد نتائج مطابقة</p>
				    </td>
                  </tr>

                </tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- Left Column: Checkout Cart & Session Status -->
        <div class="w-full lg:w-5/12 xl:w-1/3 sticky top-6 space-y-4">
          
          <!-- Shift / Session Status Widget -->
          <div v-if="sessionsEnabled" class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 bg-slate-50/70 border-b border-slate-100 flex items-center justify-between">
              <h3 class="text-xs font-bold text-slate-900 flex items-center gap-2">
                <i class="fas fa-cash-register text-blue-600 text-xs"></i>
                <span>حالة الوردية</span>
              </h3>
              <div class="flex items-center gap-2">
                <BusyIndicator v-if="loadingSession || refreshingSession" type="dots" size="sm" :delay="300" />
                <span v-if="currentSession" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[9px] font-bold border border-emerald-200 bg-emerald-50 text-emerald-700">
                  <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                  <span>مفتوحة</span>
                </span>
                <span v-else class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[9px] font-bold border border-slate-200 bg-slate-50 text-slate-500">
                  <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                  <span>مغلقة</span>
                </span>
              </div>
            </div>

            <div class="p-4 space-y-2.5">
              <div v-if="currentSession" class="space-y-2">
                <div class="flex justify-between text-xs">
                  <span class="text-slate-400 font-medium">نوع الجلسة:</span>
                  <span class="font-bold text-slate-800">{{ currentSession.session_type_label || sessionTypeToLabel(currentSession.session_type || 'manual') }}</span>
                </div>
                <div class="flex justify-between text-xs">
                  <span class="text-slate-400 font-medium">وقت البدء:</span>
                  <span class="font-bold font-mono text-slate-800">{{ formatSessionStart(currentSession.start_time) }}</span>
                </div>
                <div class="flex justify-between text-xs">
                  <span class="text-slate-400 font-medium">الجهاز:</span>
                  <span class="font-bold text-slate-800">{{ deviceLabel(currentSession) }}</span>
                </div>
                <div v-if="currentSession.terminal_name || currentSession.terminal_code || currentSession.terminal_id" class="flex justify-between text-xs">
                  <span class="text-slate-400 font-medium">الترمينال:</span>
                  <span class="font-bold text-slate-800 font-mono">{{ currentSession.terminal_name || currentSession.terminal_code || currentTerminalLabel || ('#' + currentSession.terminal_id) }}</span>
                </div>
                <div v-if="currentSession.shift_id" class="flex justify-between text-xs">
                  <span class="text-slate-400 font-medium">رقم الوردية:</span>
                  <span class="font-bold font-mono text-indigo-600">#{{ currentSession.shift_id }}</span>
                </div>
                <div class="flex justify-between text-xs pt-1 border-t border-slate-50">
                  <span class="text-slate-400 font-medium">الرصيد الافتتاحي:</span>
                  <span class="font-bold font-mono text-blue-600">{{ formatPrice(currentSession.opening_cash_amount || 0) }}</span>
                </div>
                <div class="pt-2">
                  <button 
                    @click="openRenameDevice" 
                    class="h-8 w-full rounded-md border border-slate-200 text-[11px] font-bold text-slate-600 hover:bg-slate-50 transition flex items-center justify-center gap-1.5"
                  >
                    <i class="fas fa-edit text-[10px]"></i>
                    <span>تسمية الجهاز</span>
                  </button>
                </div>
              </div>

              <div v-else class="space-y-3">
                <p class="text-xs text-slate-500 leading-relaxed">لا توجد وردية نشطة حالياً لهذا المخزن.</p>
                <div class="text-[11px] text-slate-400 font-bold flex items-center gap-1.5">
                  <i class="fas fa-info-circle text-blue-500"></i>
                  <span>نمط الجلسات: {{ sessionsModeLabel }}</span>
                </div>
                <div v-if="sessionLimitReached" class="text-xs text-rose-600 font-bold flex items-center gap-1.5">
                  <i class="fas fa-triangle-exclamation"></i>
                  <span>تم الوصول إلى الحد الأقصى للجلسات اليوم.</span>
                </div>
                <button 
                  @click="handleOpenSession" 
                  class="h-9 w-full bg-blue-600 text-white rounded-lg text-xs font-bold shadow-sm hover:bg-blue-700 active:scale-95 transition-all flex items-center justify-center gap-2 disabled:opacity-50"
                  :disabled="sessionLimitReached || refreshingSession"
                >
                  <i class="fas fa-door-open text-[10px]"></i>
                  <span>فتح جلسة جديدة</span>
                </button>
                <button 
                  @click="openRenameDevice" 
                  class="h-8 w-full rounded-md border border-slate-200 text-[11px] font-bold text-slate-600 hover:bg-slate-50 transition"
                >
                  <i class="fas fa-edit text-[10px] ml-1"></i>
                  <span>تسمية الجهاز</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Checkout Cart Container -->
          <div class="bg-white border border-slate-200 rounded-2xl shadow-sm flex flex-col min-h-[520px] overflow-hidden">

            <!-- Customer Selection Header -->
            <div class="p-4 border-b border-slate-100 space-y-3">
              <div class="flex items-center justify-between">
                <label class="text-xs font-bold text-slate-900 flex items-center gap-2">
                  <i class="fas fa-user-circle text-blue-600"></i>
                  <span>بيانات العميل</span>
                </label>
                <button 
                  v-if="invoice.length > 0" 
                  @click="holdInvoice" 
                  class="text-[11px] text-amber-600 hover:text-amber-700 font-bold flex items-center gap-1.5 transition-colors"
                >
                  <i class="fas fa-pause-circle"></i>
                  <span>تعليق الفاتورة</span>
                </button>
              </div>

              <!-- Customer Search Input -->
              <div class="relative">
                <input
                  v-model="customerQuery"
                  @input="customerActiveIndex = 0; debouncedCustomerSearch(); showCustomerDropdown = true"
                  @focus="showCustomerDropdown = true"
                  @blur="hideCustomerDropdown"
                  @keydown.down.prevent="showCustomerDropdown = true; moveCustomerActive(1)"
                  @keydown.up.prevent="showCustomerDropdown = true; moveCustomerActive(-1)"
                  @keydown.enter.prevent="selectActiveCustomer()"
                  @keydown.esc.prevent="showCustomerDropdown = false"
                  type="text"
                  placeholder="ابحث بالاسم أو رقم الهاتف..."
                  class="filter-input"
                  style="padding-right: 2rem;"
                />
                <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                <button
                  v-if="customerQuery"
                  @click="customerQuery=''; customerActiveIndex=0; debouncedCustomerSearch(); showCustomerDropdown = true"
                  type="button"
                  class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 hover:text-slate-600 transition"
                >
                  <i class="fas fa-times-circle text-xs"></i>
                </button>

                <!-- Customer Dropdown Results -->
                <div 
                  v-if="showCustomerDropdown" 
                  class="absolute z-30 w-full mt-1.5 bg-white border border-slate-200 rounded-lg shadow-xl max-h-48 overflow-y-auto custom-scroll"
                >
                  <ul class="text-xs divide-y divide-slate-50 font-medium">
                    <li 
                      @mousedown.prevent="selectedCustomer=''; customerQuery=''; showCustomerDropdown=false" 
                      class="px-3.5 py-2 hover:bg-slate-50 cursor-pointer flex justify-between items-center text-slate-700"
                    >
                      <span class="font-bold">عميل نقدي </span>
                      <i class="fas fa-money-bill-wave text-emerald-600 text-xs"></i>
                    </li>
                    <li 
                      v-for="(c, idx) in filteredCustomers || []" 
                      :key="c?.id" 
                      @mousedown.prevent="selectCustomer(c)" 
                      :class="['px-3.5 py-2 cursor-pointer flex items-center justify-between', idx === customerActiveIndex ? 'bg-blue-50' : 'hover:bg-slate-50']"
                    >
                      <div>
                        <div class="font-bold text-slate-900">{{ c.name }}</div>
                        <div class="text-[10px] font-mono text-slate-400">{{ c.phone || '-' }}</div>
                      </div>
                      <span :class="['text-[9px] font-mono font-bold px-2 py-0.5 rounded border', (c.balance || 0) > 0 ? 'bg-rose-50 text-rose-600 border-rose-100' : 'bg-emerald-50 text-emerald-600 border-emerald-100']">
                        {{ formatPrice(c.balance || 0) }}
                      </span>
                    </li>
                  </ul>
                </div>
              </div>

              <!-- Selected Customer Card -->
              <div class="p-3 bg-slate-50 rounded-lg border border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <div class="w-8 h-8 rounded-full bg-white border border-slate-200 text-blue-600 flex items-center justify-center text-xs shadow-sm">
                    <i class="fas fa-user"></i>
                  </div>
                  <div>
                    <h4 class="text-xs font-bold text-slate-900">{{ selectedCustomerData?.name || 'عميل نقدي' }}</h4>
                  </div>
                </div>
                <div v-if="selectedCustomerData" class="text-left">
                  <span :class="['text-[9px] font-mono font-bold px-2 py-0.5 rounded border', (selectedCustomerData?.balance || 0) > 0 ? 'bg-rose-50 text-rose-600 border-rose-100' : 'bg-emerald-50 text-emerald-600 border-emerald-100']">
                    {{ formatPrice(selectedCustomerData?.balance || 0) }}
                  </span>
                </div>
              </div>
              <div v-if="(selectedCustomerData?.balance || 0) > 0" class="text-[10px] px-2.5 py-1 rounded bg-amber-50 border border-amber-200 text-amber-800 font-bold flex items-center gap-1.5">
                <i class="fas fa-triangle-exclamation text-amber-500"></i>
                <span>هذا العميل لديه رصيد مستحق سابق.</span>
              </div>
            </div>

            <!-- Held Invoices List -->
            <div v-if="heldInvoices.length > 0" class="px-4 py-2.5 bg-amber-50/60 border-b border-amber-100 space-y-1.5">
              <div class="flex items-center justify-between text-[10px] font-bold text-amber-800 uppercase tracking-wider">
                <span>فواتير معلقة ({{ heldInvoices.length }})</span>
              </div>
              <ul class="space-y-1">
                <li 
                  v-for="(held, index) in heldInvoices" 
                  :key="index" 
                  class="flex items-center justify-between bg-white rounded-md px-3 py-1.5 border border-amber-200/60 shadow-sm"
                >
                  <div>
                    <div class="text-xs font-bold text-slate-800">{{ held.customer_name }}</div>
                    <div class="text-[10px] text-slate-400 font-mono">{{ held.items.length }} أصناف · {{ formatPrice(held.finalTotal) }}</div>
                  </div>
                  <button 
                    @click="resumeInvoice(index)" 
                    class="h-6 px-2 text-[10px] font-bold bg-amber-100 text-amber-800 rounded hover:bg-amber-200 transition"
                  >
                    استئناف
                  </button>
                </li>
              </ul>
            </div>

            <!-- Cart Table Area -->
            <div class="flex-grow overflow-y-auto max-h-[340px] p-3 custom-scroll">
              
              <!-- Approval System Alert -->
              <div v-if="requireApproval" class="mb-2 text-[10px] px-3 py-1.5 rounded-md bg-amber-50 border border-amber-200 text-amber-800 font-bold flex items-center gap-2">
                <i class="fas fa-info-circle text-amber-600"></i>
                <span>نظام الموافقات مفعل. سيتم قيد الفاتورة كمسودة موافقة.</span>
              </div>

              <table class="w-full text-right text-xs">
                <thead>
                  <tr class="border-b border-slate-100 text-[10px] font-bold text-slate-400 uppercase tracking-wider pb-1">
                    <th class="py-1.5">الصنف</th>
                    <th class="py-1.5 text-center">الكمية</th>
                    <th class="py-1.5 text-center">السعر</th>
                    <th class="py-1.5 text-center">الإجمالي</th>
                    <th class="py-1.5 text-center w-6"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                  <tr v-for="item in invoice" :key="item.id" class="hover:bg-slate-50/50 group transition-all">
                    <!-- Name & Sub-details -->
                    <td class="py-2.5 pl-2">
                      <div class="font-bold text-slate-900 leading-tight">{{ item.name }}</div>
                      <div class="flex items-center gap-2 mt-1 text-[10px]">
                        <!-- Batches Select -->
                        <select 
                          v-if="item.product_type !== 'service' && item.has_batch_number"
                          v-model="item.batch_number" 
                          class="h-6 text-[10px] bg-white border border-slate-200 rounded px-1 outline-none" 
                          @change="onBatchChange(item)"
                        >
                          <option value="">-- الدفعة --</option>
                          <option v-for="batch in item.available_batches" :key="batch.batch_number" :value="batch.batch_number">{{ batch.batch_number }} ({{ batch.quantity }})</option>
                        </select>
                        
                        <!-- Serials Select -->
                        <select 
                          v-if="item.product_type !== 'service' && item.has_serial_number"
                          v-model="item.serial" 
                          class="h-6 text-[10px] bg-white border border-slate-200 rounded px-1 outline-none"
                        >
                          <option value="">-- السيريال --</option>
                          <option v-for="serial in item.available_serials" :key="serial" :value="serial">{{ serial }}</option>
                        </select>

                        <!-- Expiry Tag -->
                        <div v-if="item.product_type !== 'service' && item.has_expiry_date && item.expiry_date" class="font-mono text-[9px] font-bold">
                          <span :class="{'text-rose-600': isExpired(item.expiry_date), 'text-amber-600': isExpiringSoon(item.expiry_date), 'text-slate-500': !isExpiringSoon(item.expiry_date) && !isExpired(item.expiry_date)}">
                            {{ formatDate(item.expiry_date) }}
                          </span>
                        </div>
                      </div>
                      <div v-if="Number(item.min_sale_price) > 0 && item.sale_price < item.min_sale_price" class="text-[9px] font-mono text-amber-600 font-bold mt-0.5">
                        أقل من الحد الأدنى - يتطلب اعتماد مشرف ({{ formatPrice(item.min_sale_price) }})
                      </div>
                    </td>

                    <!-- Quantity Input -->
                    <td class="py-2.5 px-1 text-center">
                      <input 
                        type="number" 
                        v-model.number="item.selectedQuantity" 
                        min="1" 
                        class="w-12 h-7 text-center font-mono font-bold bg-white border border-slate-200 rounded text-xs focus:ring-1 focus:ring-blue-500 outline-none" 
                        @change="updateQty(item, item.selectedQuantity)" 
                      />
                    </td>

                    <!-- Price Input -->
                    <td class="py-2.5 px-1 text-center">
                      <input 
                        type="number" 
                        v-model.number="item.sale_price" 
                        :min="item.min_sale_price" 
                        :class="['w-16 h-7 text-center font-mono font-bold bg-white border rounded text-xs focus:ring-1 focus:ring-blue-500 outline-none', item.sale_price < item.min_sale_price ? 'border-rose-300 text-rose-600' : 'border-slate-200 text-slate-800']" 
                        @change="validateSalePrice(item)" 
                      />
                      <div v-if="Number(item.sale_price) < Number(item.catalog_sale_price)" class="mt-1 text-[9px] font-bold text-rose-500 whitespace-nowrap">
                        خصم {{ formatPrice((Number(item.catalog_sale_price) - Number(item.sale_price)) * Number(item.selectedQuantity || 0)) }}
                      </div>
                    </td>

                    <!-- Line Total -->
                    <td class="py-2.5 px-1 font-mono font-bold text-xs text-blue-600 text-center">
                      {{ formatPrice(getNetItemTotal(item)) }}
                    </td>

                    <!-- Delete Button -->
                    <td class="py-2.5 pr-1 text-center">
                      <button 
                        @click="removeFromInvoice(item)" 
                        class="w-6 h-6 rounded text-slate-300 hover:text-rose-600 hover:bg-rose-50 transition-all flex items-center justify-center mx-auto"
                        title="حذف من السلة"
                      >
                        <i class="fas fa-times text-[11px]"></i>
                      </button>
                    </td>
                  </tr>

                  <!-- Empty Cart State -->
                  <tr v-if="invoice.length === 0">
                    <td colspan="5" class="py-14 text-center text-slate-300">
                      <i class="fas fa-basket-shopping text-3xl mb-2 opacity-20 block"></i>
                      <p class="text-xs font-bold uppercase tracking-widest">سلة البيع فارغة</p>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Financial Totals Section -->
            <div class="p-4 bg-slate-50/70 border-t border-slate-200 space-y-2.5">
              <div class="flex justify-between text-xs">
                <span class="text-slate-400 font-medium">الإجمالي الفرعي:</span>
                <span class="font-mono font-bold text-slate-800">{{ formatPrice(totalAmount) }}</span>
              </div>

              <!-- Discount Controls -->
              <div class="flex items-center gap-2 text-xs">
                <select v-model="discountType" class="h-7 text-[11px] font-bold bg-white border border-slate-200 rounded px-1.5 text-slate-600 outline-none">
                  <option>مبلغ</option>
                  <option>نسبة %</option>
                </select>
                <input 
                  v-model.number="discountValue" 
                  type="number" 
                  min="0" 
                  class="w-16 h-7 text-xs font-mono font-bold bg-white border border-slate-200 rounded px-2 text-center outline-none focus:border-blue-500" 
                />
                <span class="text-[10px] text-slate-400 font-mono">{{ discountType === 'مبلغ' ? formatPrice(discountValue) : discountValue + '%' }}</span>
                <div class="flex-grow"></div>
                <span class="text-rose-600 font-mono font-bold text-xs">- {{ formatPrice(discountAmount) }}</span>
              </div>

              <!-- Taxes -->
              <div class="flex justify-between text-xs">
                <span class="text-slate-400 font-medium">ضريبة القيمة المضافة ({{ (taxValue || 0).toFixed(0) }}%):</span>
                <span class="font-mono font-bold text-slate-800">{{ formatPrice(taxAmount) }}</span>
              </div>

              <!-- Final Total Box -->
              <div class="pt-2.5 border-t border-slate-200 space-y-3">
                <div class="flex justify-between items-center">
                  <div class="flex items-center gap-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-900">المجموع الصافي</span>
                    <span :class="['text-[9px] font-bold px-2 py-0.5 rounded-full border', saleStatus === 'paid' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : saleStatus === 'partial' ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-rose-50 text-rose-700 border-rose-200']">
                      {{ saleStatus === 'paid' ? 'مدفوعة' : saleStatus === 'partial' ? 'جزئي' : 'مستحقة' }}
                    </span>
                  </div>
                  <span class="text-xl font-bold font-mono tracking-tight text-blue-600">{{ formatPrice(finalTotal) }}</span>
                </div>

                <!-- Payment Form Controls -->
                <div class="grid grid-cols-2 gap-2">
                  <div class="relative">
                    <select v-model="selectedPaymentMethod" class="filter-input text-xs h-9 appearance-none" style="padding-right: 1.75rem;">
                      <option v-for="method in paymentMethods || []" :key="method?.id" :value="method?.id">{{ method?.name }}</option>
                    </select>
                    <i class="fas fa-wallet absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                  </div>
                  <input 
                    v-model.number="actualPaidAmount" 
                    type="number" 
                    min="0" 
                    :max="finalTotal" 
                    placeholder="المدفوع نقداً..." 
                    class="filter-input text-xs h-9 text-center font-mono font-bold" 
                    :class="{ 'border-rose-300 text-rose-600': actualPaidAmount > finalTotal }" 
                    :disabled="requireApproval" 
                  />
                </div>

                <div v-if="!requireApproval && actualPaidAmount > finalTotal" class="text-[10px] p-2 rounded-md bg-rose-50 border border-rose-200 text-rose-600 font-bold flex items-center gap-1.5">
                  <i class="fas fa-circle-exclamation"></i>
                  <span>المبلغ المدفوع يتجاوز إجمالي الفاتورة!</span>
                </div>

                <div v-if="remainingAmount > 0" class="flex justify-between items-center p-2 rounded-md bg-rose-50 border border-rose-100 text-rose-700 text-xs font-bold font-mono">
                  <span>المتبقي (ذمم آجلة):</span>
                  <span>{{ formatPrice(remainingAmount) }}</span>
                </div>

                <!-- Action Execute Buttons -->
                <div class="space-y-2 pt-1">
                  <LoadingButton
                    text="حفظ وطباعة الفاتورة"
                    :isLoading="isPrinting"
                    loadingText="جاري الطباعة..."
                    icon="fas fa-print"
                    variant="primary"
                    loaderType="dots"
                    @click="saveAndPrint"
                    class="h-10 w-full rounded-lg text-xs font-bold shadow-sm hover:bg-blue-700"
                  />

                  <LoadingButton
                      text="حفظ الفاتورة"
                      :isLoading="isSaving"
                      loadingText="جاري الحفظ..."
                      icon="fas fa-save"
                      variant="primary"
                      loaderType="dots"
                      @click="saveSale()"
                      :disabled="isPrinting"
                      class="h-9 w-full rounded-lg text-xs font-bold shadow-sm"
                    />
                </div>
              </div>
            </div>

          </div>

        </div>

      </div>

    </div>

    <!-- ===== SYSTEM MODALS ===== -->

    <!-- Open Session Modal -->
    <BaseModal :show="showOpenDialog" @close="showOpenDialog = false" maxWidth="md">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-door-open"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">فتح جلسة كاشير جديدة</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">بدء وردية مبيعات جديدة في النظام</p>
          </div>
        </div>
      </template>
      
      <div class="space-y-4">
        <!-- Branch Selection Modal (Admins/Managers only) -->
        <!-- 📝 VALIDATION: authStore.isAdmin ensures only role_id in [1,2,3] can select branches
             branches.length > 0 prevents showing empty selector if no branches exist -->
        <div v-if="authStore.isAdmin && branches.length > 0" class="space-y-1.5">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">المخزن / الفرع المستهدف <span class="text-rose-500">*</span></label>
          <select v-model="selectedBranch" class="filter-input appearance-none" style="padding-right: 2rem;">
            <option disabled value="">-- اختر الفرع --</option>
            <option v-for="wh in branches" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
          </select>
          <i class="fas fa-building absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
        </div>

        <div v-if="terminals.length" class="space-y-1.5">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">جهاز نقطة البيع (الترمينال) <span class="text-rose-500">*</span></label>
          <div class="relative">
            <select v-model="selectedTerminalId" class="filter-input appearance-none" style="padding-right: 2rem;">
              <option disabled value="">-- اختر الجهاز --</option>
              <option v-for="t in terminals" :key="t.id" :value="t.id">{{ t.code ? (t.code + ' - ' + t.name) : t.name }}</option>
            </select>
            <i class="fas fa-desktop absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
          </div>
        </div>

        <div class="space-y-1.5">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">المبلغ الافتتاحي بالخزينة <span class="text-rose-500">*</span></label>
          <input v-model.number="openingAmount" type="number" min="0" step="0.01" class="filter-input font-mono text-center text-sm font-bold" placeholder="0.00" />
        </div>

        <div class="bg-blue-50 border border-blue-100 p-3 rounded-lg text-xs text-blue-700 flex items-center gap-2">
          <i class="fas fa-info-circle shrink-0"></i>
          <span>سيتم فتح الجلسة وربط المعاملات المالية بالفرع والجهاز الحالي.</span>
        </div>
      </div>
      
      <template #footer>
        <button @click="showOpenDialog = false" class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">
          إلغاء
        </button>
        <LoadingButton
          text="تأكيد فتح الوردية"
          :isLoading="openSubmitting"
          loadingText="جاري التأكيد..."
          variant="primary"
          loaderType="dots"
          @click="confirmOpenSession"
          class="px-6 h-9"
        />
      </template>
    </BaseModal>

    <!-- Close Session Modal -->
    <BaseModal :show="showCloseDialog" @close="showCloseDialog = false" maxWidth="md">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-rose-600 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-stopwatch"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">إنهاء الوردية وجرد الصندوق</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">إغلاق الجلسة الحالية وتثبيت الفروقات</p>
          </div>
        </div>
      </template>
      
      <div class="space-y-4">
        <div class="grid grid-cols-2 gap-3 text-xs">
          <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
            <div class="text-slate-400 text-[10px] font-bold uppercase mb-1">الرصيد الافتتاحي</div>
            <div class="text-sm font-bold font-mono text-blue-600">{{ formatPrice(currentSession?.opening_cash_amount || 0) }}</div>
          </div>
          <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
            <div class="text-slate-400 text-[10px] font-bold uppercase mb-1">المتوقع بالخزينة</div>
            <div class="text-xs font-bold text-slate-700">انظر ملخص الكاشير</div>
          </div>
        </div>

        <div class="space-y-1.5">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">المبلغ الفعلي في الخزينة (الجرد اليدوي)</label>
          <input v-model="closeCountedCash" type="number" step="0.01" min="0" class="filter-input font-mono text-center text-sm font-bold" placeholder="0.00" />
        </div>

        <div v-if="closeCountedCash !== ''" :class="['p-3 rounded-lg border text-center text-xs font-mono font-bold', cashDifferencePos === 0 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200']">
          <div>{{ cashDifferencePos === 0 ? 'مطابق تماماً للرصيد المتوقع' : 'يوجد فارق في الصندوق' }}</div>
          <div class="mt-0.5">الفارق: {{ formatPrice(cashDifferencePos) }}</div>
        </div>

        <div class="space-y-1.5">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">سبب الفرق (اختياري)</label>
          <select v-model="selectedVarianceReason" class="filter-input appearance-none" style="padding-right: 2rem;" @change="handleVarianceReasonChange">
            <option v-for="reason in varianceReasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
          </select>
          <i class="fas fa-question-circle absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
          <textarea v-if="selectedVarianceReason === 'other'" v-model="varianceReason" rows="2" class="filter-input h-auto p-2.5 text-xs mt-2" placeholder="حدد تفاصيل سبب الفرق..."></textarea>
        </div>

        <div class="space-y-1.5">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">ملاحظات الإغلاق</label>
          <textarea v-model="closeNotes" rows="2" class="filter-input h-auto p-2.5 text-xs" placeholder="أي ملاحظات إضافية..."></textarea>
        </div>
      </div>
      
      <template #footer>
        <button @click="showCloseDialog = false" class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">
          إلغاء
        </button>
        <LoadingButton
          text="تأكيد إنهاء الوردية"
          :isLoading="closeSubmitting"
          loadingText="جاري الإنهاء..."
          variant="danger"
          loaderType="dots"
          @click="confirmCloseSession"
          class="px-6 h-9"
        />
      </template>
    </BaseModal>

    <!-- Session Summary Modal -->
    <BaseModal :show="showSummaryDialog" @close="showSummaryDialog = false" maxWidth="lg">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-slate-900 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-receipt"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">ملخص وإحصائيات الوردية</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">تفاصيل الحركات المالية المكتملة</p>
          </div>
        </div>
      </template>
      
      <div class="space-y-4" v-if="closeSummary">
        <span :class="['inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-bold border', closeSummary.closing?.variance === 0 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200']">
          <i :class="closeSummary.closing?.variance === 0 ? 'fas fa-check-circle' : 'fas fa-triangle-exclamation'"></i>
          <span>{{ closeSummary.closing?.variance === 0 ? 'تم الإغلاق بدون أي فروقات نقدية' : `يوجد فارق مسجل: ${formatPrice(closeSummary.closing?.variance)}` }}</span>
        </span>

        <div class="grid grid-cols-2 gap-3 text-xs">
          <div class="bg-slate-50 border border-slate-100 rounded-lg p-3"><div class="text-slate-400 text-[10px] uppercase font-bold mb-1">وقت البداية</div><div class="font-mono text-slate-800">{{ formatSessionStart(closeSummary.session?.start_time) }}</div></div>
          <div class="bg-slate-50 border border-slate-100 rounded-lg p-3"><div class="text-slate-400 text-[10px] uppercase font-bold mb-1">وقت النهاية</div><div class="font-mono text-slate-800">{{ formatSessionStart(closeSummary.session?.end_time) }}</div></div>
          <div class="bg-slate-50 border border-slate-100 rounded-lg p-3"><div class="text-slate-400 text-[10px] uppercase font-bold mb-1">إجمالي المدفوعات</div><div class="font-mono font-bold text-blue-600">{{ formatPrice(closeSummary.totals?.payments || 0) }}</div></div>
          <div class="bg-slate-50 border border-slate-100 rounded-lg p-3"><div class="text-slate-400 text-[10px] uppercase font-bold mb-1">نقد داخل (Cash In)</div><div class="font-mono font-bold text-emerald-600">{{ formatPrice(closeSummary.totals?.cash_in || 0) }}</div></div>
          <div class="bg-slate-50 border border-slate-100 rounded-lg p-3"><div class="text-slate-400 text-[10px] uppercase font-bold mb-1">نقد خارج (Cash Out)</div><div class="font-mono font-bold text-rose-600">{{ formatPrice(closeSummary.totals?.cash_out || 0) }}</div></div>
          <div class="bg-slate-50 border border-slate-100 rounded-lg p-3"><div class="text-slate-400 text-[10px] uppercase font-bold mb-1">المتوقع بالدرج</div><div class="font-mono font-bold text-slate-900">{{ formatPrice(closeSummary.calculated?.expected_cash || 0) }}</div></div>
        </div>
      </div>
      
      <template #footer>
        <button 
          @click="printSummary" 
          class="px-5 h-9 rounded-md border border-slate-200 bg-white text-slate-700 text-xs font-bold hover:bg-slate-50 transition flex items-center gap-2"
        >
          <i class="fas fa-print text-[11px]"></i>
          <span>طباعة تقرير الجلسة</span>
        </button>
        <button 
          @click="showSummaryDialog = false" 
          class="px-6 h-9 rounded-md bg-slate-900 text-white text-xs font-bold hover:bg-black transition"
        >
          إغلاق
        </button>
      </template>
    </BaseModal>

    <!-- Rename Device Modal -->
    <BaseModal :show="showRenameDevice" @close="showRenameDevice = false" maxWidth="md">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-slate-900 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-desktop"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">تسمية جهاز نقطة البيع</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">تعيين معرف دائم للجهاز الحالي</p>
          </div>
        </div>
      </template>
      
      <div class="space-y-2">
        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">اسم الجهاز المخصص</label>
        <input v-model.trim="deviceNameInput" type="text" maxlength="64" class="filter-input" placeholder="مثال: كاشير 1 - الفرع الرئيسي" />
        <p class="text-[10px] text-slate-400 font-bold">اتركه فارغاً للاعتماد على المعرف الافتراضي للشبكة.</p>
      </div>
      
      <template #footer>
        <button @click="showRenameDevice = false" class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">
          إلغاء
        </button>
        <button @click="saveDeviceName" class="px-6 h-9 rounded-md bg-slate-900 text-white text-xs font-bold hover:bg-black transition">
          حفظ
        </button>
      </template>
    </BaseModal>

    <BaseModal :show="showBelowCostReasonModal" @close="cancelBelowCostReason" maxWidth="md" :closeOnClickOutside="false">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center">
            <i class="fas fa-triangle-exclamation"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900">اعتماد بيع بأقل من التكلفة</h3>
            <p class="text-[10px] font-bold text-slate-400">يتطلب سببًا واضحًا وسيُحفظ في ملاحظات الفاتورة</p>
          </div>
        </div>
      </template>

      <div class="space-y-4">
        <div class="rounded-lg border border-rose-100 bg-rose-50 p-3 space-y-2">
          <div v-for="item in belowCostApprovalItems" :key="item.id" class="flex justify-between gap-3 text-xs">
            <span class="font-bold text-slate-700">{{ item.name }}</span>
            <span class="text-rose-700 whitespace-nowrap">
              سعر البيع {{ formatPrice(effectiveNetUnitPrice(item)) }} · التكلفة {{ formatPrice(Number(item.purchase_price) * Number(item.conversion_factor || 1)) }}
            </span>
          </div>
        </div>
        <label class="block space-y-2">
          <span class="text-xs font-bold text-slate-700">سبب البيع بأقل من التكلفة <span class="text-rose-600">*</span></span>
          <textarea
            v-model="belowCostReason"
            rows="3"
            maxlength="500"
            class="filter-input h-auto p-3 text-sm"
            placeholder="اكتب سببًا واضحًا لاعتماد هذا السعر..."
            autofocus
          ></textarea>
          <span class="block text-[10px] text-slate-400">5 أحرف على الأقل، وبحد أقصى 500 حرف.</span>
        </label>
      </div>

      <template #footer>
        <button @click="cancelBelowCostReason" class="px-5 h-9 text-xs font-bold text-slate-500 hover:text-slate-900">
          إلغاء البيع
        </button>
        <button
          @click="submitBelowCostReason"
          :disabled="belowCostReason.trim().length < 5"
          class="px-5 h-9 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          اعتماد ومتابعة
        </button>
      </template>
    </BaseModal>

    <!-- Branch Required Modal -->
    <Transition name="fade">
      <div v-if="showBranchRequiredModal" class="fixed inset-0 z-[100] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white border border-slate-200 rounded-xl p-6 max-w-sm w-full shadow-2xl text-center space-y-4 animate-fadeIn">
          <div class="w-12 h-12 bg-amber-50 text-amber-600 border border-amber-100 rounded-xl flex items-center justify-center mx-auto shadow-sm">
            <i class="fas fa-building text-lg"></i>
          </div>
          
          <div class="space-y-1">
            <h2 class="text-sm font-bold text-slate-900">
              <span v-if="canSelectBranch">حدد الفرع لبدء المبيعات</span>
              <span v-else>اختر فرعاً للمتابعة</span>
            </h2>
            <p class="text-xs text-slate-400 font-medium">نظام نقطة البيع يتطلب عزل وتحديد الفرع بدقة قبل العمل.</p>
          </div>
          
          <!-- Branch Selector for Admin -->
          <div v-if="canSelectBranch">
            <select v-model="tempBranchId" class="filter-input appearance-none text-xs" style="padding-right: 2rem;">
              <option :value="null" disabled>-- اختر الفرع المطلوب --</option>
              <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
            <i class="fas fa-building absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
          </div>
          
          <!-- Regular User Notice -->
          <div v-else-if="authStore.user?.branch_id" class="p-3 bg-blue-50 border border-blue-100 rounded-lg text-xs font-bold text-blue-700">
            تم تحديد فرعك: {{ branches.find(b => b.id === authStore.user?.branch_id)?.name || 'الفرع الرئيسي' }}
          </div>
          
          <div class="grid grid-cols-2 gap-2 pt-2">
            <button @click="cancelBranchSelection" class="h-9 rounded-md bg-slate-100 text-slate-600 text-xs font-bold hover:bg-slate-200 transition-all">
              إلغاء
            </button>
            <button 
              @click="confirmBranchSelection" 
              :disabled="!tempBranchId && canSelectBranch"
              class="h-9 rounded-md bg-slate-900 text-white text-xs font-bold hover:bg-black transition-all disabled:opacity-50"
            >
              تأكيد
            </button>
          </div>
        </div>
      </div>
    </Transition>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import BusyIndicator from '@/components/ui/BusyIndicator.vue';
import BaseModal from '@/components/BaseModal.vue';
import PageHeader from '@/components/PageHeader.vue';
import { getLocalDateISO, isSettingEnabled, printDocument } from '@/utils';
import { getBuilderByTemplate } from '@/utils/printTemplates';
import { useCashierSessionGuard } from '@/composables/useCashierSessionGuard';
import { useToast } from '@/composables/useToast';
import { useLoader } from '@/composables/useLoader';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useSessionStore } from '@/stores/session/sessionStore';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useProductStore } from '@/stores/product/productStore';
import { useInventoryStore } from '@/stores/inventory/inventoryStore';
import { useSalesStore } from '@/stores/sales/salesStore';
import { useCatalogStore } from '@/stores/catalog/catalogStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useTerminalStore } from '@/stores/terminal/terminalStore';
import { useAnalyticsStore } from '@/stores/analytics/analyticsStore';
import { useBootstrapStore } from '@/stores/bootstrap/bootstrapStore';
import AlertService from '@/services/AlertService';

const isComponentMounted = ref(false);
const router = useRouter();
const { showToast } = useToast();
const { showLoader, hideLoader } = useLoader();
const authStore = useAuthStore();
const branchStore = useBranchStore();
const catalogStore = useCatalogStore();
const customerStore = useCustomerStore();
const paymentStore = usePaymentStore();
const terminalStore = useTerminalStore();
const analyticsStore = useAnalyticsStore();
const productStore = useProductStore();
const inventoryStore = useInventoryStore();
const salesStore = useSalesStore();
const bootstrapStore = useBootstrapStore();
const { ensureOpenSession } = useCashierSessionGuard();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const formatPrice = (amount) => formatCurrencyLocale(amount, 2);
const userChoseBranch = ref(localStorage.getItem('selectedBranchId') !== null && localStorage.getItem('selectedBranchId') !== 'all');

const canApproveBelowCost = computed(() => {
  const role = String(authStore.user?.role || '').toLowerCase();
  return authStore.isAdmin || authStore.isOwner || ['manager', 'branch_manager', 'supervisor', 'branch_supervisor', 'administrator', 'super_admin'].includes(role);
});
const canSelectBranch = computed(() => {
  const role = String(authStore.user?.role || '').toLowerCase();
  return Number(authStore.user?.tenant_id) > 0 && (authStore.isAdmin || role === 'manager');
});
const branches = computed(() => branchStore.branches);
const terminals = ref([]);
const selectedTerminalId = ref('');
const currentSession = ref(null);
const loadingSession = ref(false);
const refreshingSession = ref(false);
const lastSessionId = ref(null);
const sessionsMode = ref('');
const sessionsEnforcementEnabled = ref(false);
const settingsLoaded = ref(false);
const sessionLimitReached = ref(false);
const enforceForRoles = ref([]);

const sessionsEnabled = computed(() => sessionsEnforcementEnabled.value);
const sessionsModeLabel = computed(() => {
  switch ((sessionsMode.value || '').toLowerCase()) {
    case 'one_per_day': return 'جلسة واحدة يوميًا';
    case 'two_per_day': return 'جلستان يوميًا';
    case 'three_per_day': return 'ثلاث جلسات يوميًا';
    default: return 'غير محدد';
  }
});
const enforceSessionForMe = computed(() => {
  const rid = authStore.user?.role_id;
  return Array.isArray(enforceForRoles.value) && enforceForRoles.value.map(n => Number(n)).includes(Number(rid));
});
const currentTerminalLabel = computed(() => {
  const sid = currentSession.value?.terminal_id;
  if (!sid) return '';
  const t = (terminals.value || []).find(tt => Number(tt.id) === Number(sid));
  return t ? (t.code ? `${t.code} - ${t.name}` : (t.name || '')) : '';
});
const showOpenDialog = ref(false);
const openingAmount = ref('');
const openSubmitting = ref(false);
const showCloseDialog = ref(false);
const closeCountedCash = ref('');
const closeNotes = ref('');
const closeSubmitting = ref(false);
const closeSummary = ref(null);
const showSummaryDialog = ref(false);
const varianceReason = ref('');
const selectedVarianceReason = ref('');
const varianceReasons = [
  { value: '', label: '-- اختر سبب الفرق --' },
  { value: 'counting_error', label: 'خطأ في العد' },
  { value: 'unrecorded_income', label: 'إيراد غير مسجل' },
  { value: 'manual_payment', label: 'دفع يدوي' },
  { value: 'other', label: 'سبب آخر' }
];
const showRenameDevice = ref(false);
const deviceNameInput = ref('');
const cashDifferencePos = computed(() => Number(closeCountedCash.value || 0) - 0);
const searchQuery = ref('');
const selectedCategory = ref('');
const categories = computed(() => catalogStore.getCategoriesForBranch(branchStore.selectedBranchId));
const searchResults = ref([]);
const isSearchingProducts = ref(false);
const loadingProductId = ref(null); // لمنع الضغط المتعدد على زر الإضافة
const showInactive = ref(false);
const showExpiring = ref(false);
const productSearchInputRef = ref(null);
let productSearchAbortCtrl = null;

const filteredSearchResults = computed(() => {
  let results = [...searchResults.value];
  if (showInactive.value) results = results.filter(p => !p.active);
  if (showExpiring.value) {
    const today = new Date();
    const soonDate = new Date();
    soonDate.setDate(today.getDate() + 7);
    results = results.filter(p => {
      if (!p.expire_date) return false;
      const d = new Date(p.expire_date);
      return d >= today && d <= soonDate;
    });
  }
  return results;
});
const customers = ref([]);
const customerSearchResults = ref([]);
const selectedCustomer = ref('');
const customerQuery = ref('');
const showCustomerDropdown = ref(false);
const customerActiveIndex = ref(0);
let customerSearchAbortCtrl = null;
let customerSearchRequestId = 0;

const selectedCustomerData = computed(() => customers.value.find(c => c.id === selectedCustomer.value) || null);
const filteredCustomers = computed(() => {
  const q = (customerQuery.value || '').toString().trim().toLowerCase();
  if (!q) return customers.value;
  return customerSearchResults.value.filter(c =>
    (c.name || '').toLowerCase().includes(q) ||
    (c.phone || '').toLowerCase().includes(q) ||
    (c.code || '').toLowerCase().includes(q)
  );
});
const invoice = ref([]);
const heldInvoices = ref([]);
const discountType = ref('مبلغ');
const discountValue = ref(0);
const taxValue = ref(0);
const taxEnabled = ref(false);
const taxRate = ref(0);
const showTaxInPrice = ref(true);
const requireApproval = ref(false);
const paymentMethods = computed(() => paymentStore.paymentMethods);
const selectedPaymentMethod = ref('');
const setDefaultCashPaymentMethod = () => {
  const methods = paymentMethods.value || [];
  const isCashMethod = (method) => {
    const kind = String(method.kind || method.type || method.method_type || '').trim().toLowerCase();
    const code = String(method.code || '').trim().toLowerCase();
    const name = String(method.name || '').trim().toLowerCase();
    return kind === 'cash'
      || code === 'cash'
      || code.includes('cash')
      || name.includes('cash')
      || ['نقدي', 'نقدا', 'نقداً', 'نقدًا'].includes(name);
  };
  const cashMethod = methods.find(isCashMethod);
  selectedPaymentMethod.value = cashMethod?.id ?? methods[0]?.id ?? '';
};
const actualPaidAmount = ref(0);
const isSaving = ref(false);
const isPrinting = ref(false);
const showBelowCostReasonModal = ref(false);
const belowCostReason = ref('');
const belowCostApprovalItems = ref([]);
let resolveBelowCostReason = null;

const enteredSubtotal = computed(() => invoice.value.reduce((sum, item) => sum + item.sale_price * item.selectedQuantity, 0));
const totalAmount = computed(() => invoice.value.reduce(
  (sum, item) => sum + Math.max(Number(item.catalog_sale_price) || 0, Number(item.sale_price) || 0) * item.selectedQuantity,
  0
));
const manualPriceDiscountAmount = computed(() => Math.max(0, totalAmount.value - enteredSubtotal.value));

const invoiceDiscountAmount = computed(() => {
  let disc = parseFloat(discountValue.value) || 0;
  if (discountType.value === 'نسبة %') disc = Math.min(Math.max(disc, 0), 100) / 100 * enteredSubtotal.value;
  else disc = Math.min(disc, enteredSubtotal.value);
  return disc;
});

const discountAmount = computed(() => manualPriceDiscountAmount.value + invoiceDiscountAmount.value);

const taxAmount = computed(() => (parseFloat(taxValue.value) || 0) / 100 * (enteredSubtotal.value - invoiceDiscountAmount.value));
const finalTotal = computed(() => enteredSubtotal.value - invoiceDiscountAmount.value + taxAmount.value);

const remainingAmount = computed(() => Math.max(0, finalTotal.value - (parseFloat(actualPaidAmount.value) || 0)));

const saleStatus = computed(() => {
  const paid = parseFloat(actualPaidAmount.value) || 0;
  const total = parseFloat(finalTotal.value) || 0;
  if (total <= 0) return 'due';
  if (paid >= total) return 'paid';
  if (paid > 0) return 'partial';
  return 'due';
});

const currentPaymentMethodName = computed(() => {
  const id = Number(selectedPaymentMethod.value || 0);
  const m = (paymentMethods.value || []).find(pm => Number(pm.id) === id);
  return m?.name || 'غير محددة';
});

const effectiveNetUnitPrice = (item) => {
  const quantity = Number(item.selectedQuantity) || 0;
  if (quantity <= 0) return 0;
  const lineTotal = (Number(item.sale_price) || 0) * quantity;
  const allocatedDiscount = enteredSubtotal.value > 0
    ? invoiceDiscountAmount.value * (lineTotal / enteredSubtotal.value)
    : 0;
  return Math.max(0, Number(item.sale_price) - allocatedDiscount / quantity);
};

const requestBelowCostReason = (items) => new Promise((resolve) => {
  belowCostApprovalItems.value = [...items];
  belowCostReason.value = '';
  resolveBelowCostReason = resolve;
  showBelowCostReasonModal.value = true;
});

const finishBelowCostReason = (reason) => {
  const resolve = resolveBelowCostReason;
  resolveBelowCostReason = null;
  showBelowCostReasonModal.value = false;
  if (resolve) resolve(reason);
};

const submitBelowCostReason = () => {
  const reason = belowCostReason.value.trim();
  if (reason.length < 5) return;
  finishBelowCostReason(reason);
};

const cancelBelowCostReason = () => finishBelowCostReason(null);
const sessionTypeToLabel = (t) => {
  const v = String(t || '').toLowerCase();
  if (v === 'manual') return 'يدوي';
  if (v === 'daily') return 'يومي';
  if (v === 'morning') return 'صباحية';
  if (v === 'evening') return 'مسائية';
  return t || '-';
};

const formatSessionStart = (value) => {
  if (!value) return '';
  try {
    const d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(value);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}/${pad(d.getMonth()+1)}/${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  } catch { return String(value); }
};

const getDeviceIdentity = () => {
  let id = localStorage.getItem('pos_device_id');
  if (!id) {
    id = 'dev-' + Math.random().toString(36).slice(2, 8) + '-' + Date.now().toString(36).slice(-6);
    try { localStorage.setItem('pos_device_id', id); } catch {}
  }
  const custom = (localStorage.getItem('pos_device_name') || '').trim();
  const nameBase = 'جهاز كاشير';
  return {
    device_id: id,
    device_name: (custom || nameBase).slice(0, 64)
  };
};

const deviceLabel = (s) => {
  if (!s) return getDeviceIdentity().device_name;
  const custom = (localStorage.getItem('pos_device_name') || '').trim();
  return s.device_name || custom || (s.device_id ? `Device ${s.device_id}` : getDeviceIdentity().device_name);
};

const getActivebranchId = () => selectedBranch.value || authStore.user?.branch_id;

const getNetItemTotal = (item) => {
  const total = item.sale_price * item.selectedQuantity;
  const disc = invoiceDiscountAmount.value;
  const all = enteredSubtotal.value;
  const itemDiscount = all > 0 ? disc * (total / all) : 0;
  return total - itemDiscount;
};

const isExpired = (expiryDate) => expiryDate ? new Date(expiryDate) < new Date() : false;
const isExpiringSoon = (expiryDate) => {
  if (!expiryDate) return false;
  const diff = Math.ceil((new Date(expiryDate) - new Date()) / (1000 * 60 * 60 * 24));
  return diff <= 30 && diff > 0;
};
const formatDate = (date) => date ? new Date(date).toLocaleDateString('en-US') : '-';
const loadAllSettings = async () => {
  try {
    const settings = await fetchSettings();
    
    if (settings) {
      // ✅ Tax Settings
      taxEnabled.value = settings['company.tax_enabled'] === '1';
      taxRate.value = parseFloat(settings['company.tax_rate']) || 0;
      showTaxInPrice.value = settings['invoice.show_tax_in_price'] !== '0';
      taxValue.value = taxEnabled.value ? taxRate.value : 0;
      requireApproval.value = settings['pos.require_approval'] === '1' || settings['pos.require_approval'] === 1 || settings['pos.require_approval'] === true;
      
      // ✅ POS Session Settings
      sessionsEnforcementEnabled.value = isSettingEnabled(settings['pos.sessions.enabled']);
      sessionsMode.value = (settings['pos.sessions.mode'] || '').toString();
      const raw = settings['pos.sessions.enforce_for_roles'];
      let parsed = [];
      if (Array.isArray(raw)) parsed = raw;
      else if (typeof raw === 'string') {
        try { parsed = raw.trim().startsWith('[') ? JSON.parse(raw) : raw.split(',').map(x => Number(x.trim())).filter(n => !isNaN(n)); } catch {}
      }
      enforceForRoles.value = parsed.map(n => Number(n)).filter(n => !isNaN(n));
    }
  } catch { 
    taxEnabled.value = false; 
    taxRate.value = 0; 
    taxValue.value = 0; 
  } finally {
    settingsLoaded.value = true;
  }
};

const refreshSession = async () => {
  const wid = getActivebranchId();
  try {
    loadingSession.value = true;
    if (sessionsEnabled.value) {
      const { device_id } = getDeviceIdentity();
      const sessionStore = useSessionStore();
      if (!wid && !enforceSessionForMe.value) {
        const result = await sessionStore.getCurrentSession(
          undefined,
          authStore.user?.id,
          device_id
        );
        currentSession.value = result.status === 'success' ? result.data : null;
      } else if (wid) {
        const result = await sessionStore.getCurrentSession(
          wid,
          authStore.user?.id,
          device_id
        );
        currentSession.value = result.status === 'success' ? result.data : null;
      } else {
        currentSession.value = null;
      }
      if (currentSession.value?.id && lastSessionId.value !== currentSession.value.id) {
        lastSessionId.value = currentSession.value.id;
      }
    } else {
      currentSession.value = null;
    }
  } catch { currentSession.value = null; lastSessionId.value = null; }
  finally { loadingSession.value = false; }
};

const fetchTerminals = async () => {
  try {
    const wid = getActivebranchId();
    if (!wid) { terminals.value = []; selectedTerminalId.value = ''; return; }
    await terminalStore.fetchTerminals(wid);
    terminals.value = terminalStore.getTerminalsForBranch(wid).value;
    if (!selectedTerminalId.value && terminals.value.length > 0) selectedTerminalId.value = terminals.value[0].id;
  } catch { terminals.value = []; }
};

const handleOpenSession = async () => {
  const wid = getActivebranchId();
  if (!wid && enforceSessionForMe.value) { showToast('الرجاء اختيار مخزن أولاً', 'warning'); return; }
  if (!selectedTerminalId.value) { showToast('الرجاء اختيار جهاز نقطة البيع قبل فتح الجلسة', 'warning'); return; }
  openingAmount.value = '';
  showOpenDialog.value = true;
};

const confirmOpenSession = async () => {
  const wid = getActivebranchId();
  if (!wid && enforceSessionForMe.value) { showToast('الرجاء اختيار مخزن أولاً', 'warning'); return; }
  if (!selectedTerminalId.value) { showToast('الرجاء اختيار جهاز نقطة البيع', 'warning'); return; }
  const amount = Number(String(openingAmount.value ?? '').trim() || 0);
  if (isNaN(amount) || amount < 0) { showToast('قيمة الرصيد الافتتاحي غير صحيحة', 'warning'); return; }
  try {
    openSubmitting.value = true;
    const sessionStore = useSessionStore();
    const { device_id, device_name } = getDeviceIdentity();
    const result = await sessionStore.openSession({ branch_id: String(wid) ?? undefined, opening_cash_amount: amount, session_type: 'manual', device_id, device_name, terminal_id: selectedTerminalId.value });
    if (result.status === 'success' && result.data?.id) {
      showToast('تم فتح جلسة الكاشير بنجاح', 'success');
      sessionLimitReached.value = false;
      lastSessionId.value = result.data.id;
      showOpenDialog.value = false;
      openingAmount.value = '';
      await refreshSession();
    }
  } catch (e) {
    const msg = e?.response?.data?.message || 'تعذر فتح الجلسة';
    showToast(msg, 'error');
    if (String(msg).includes('الحد الأقصى')) sessionLimitReached.value = true;
  } finally { openSubmitting.value = false; }
};

const resetCloseDialog = () => { closeCountedCash.value = ''; closeNotes.value = ''; closeSubmitting.value = false; varianceReason.value = ''; selectedVarianceReason.value = ''; };
const handleVarianceReasonChange = () => { if (selectedVarianceReason.value !== 'other') varianceReason.value = selectedVarianceReason.value; };

const confirmCloseSession = async () => {
  if (!currentSession.value?.id) return;
  const val = String(closeCountedCash.value ?? '').trim();
  if (val === '') { showToast('الرجاء إدخال قيمة النقدية المعدودة', 'warning'); return; }
  const amount = Number(val);
  if (isNaN(amount) || amount < 0) { showToast('قيمة النقدية غير صحيحة', 'warning'); return; }
  
  const counted = parseFloat(closeCountedCash.value);
  if (!isNaN(counted) && Math.abs(cashDifferencePos.value) > 0.01) {
    if (!selectedVarianceReason.value && !varianceReason.value) {
      showToast('الرجاء تحديد سبب الفرق في النقدية قبل الإغلاق', 'warning');
      return;
    }
  }
  
  try {
    closeSubmitting.value = true;
    const sessionStore = useSessionStore();
    const reasonToSend = selectedVarianceReason.value === 'other' ? varianceReason.value : selectedVarianceReason.value;
    const result = await sessionStore.closeSession(currentSession.value.id, amount, reasonToSend);
    closeSummary.value = result.status === 'success' ? result.data : null;
    showToast('تم إغلاق الجلسة بنجاح', 'success');
    showCloseDialog.value = false;
    resetCloseDialog();
    await refreshSession();
    showSummaryDialog.value = true;
  } catch (e) {
    showToast(e?.response?.data?.message || 'تعذر إغلاق الجلسة', 'error');
  } finally { closeSubmitting.value = false; }
};

const printSummary = () => {
  if (!closeSummary.value) return;
  const s = closeSummary.value.session || {}, t = closeSummary.value.totals || {}, c = closeSummary.value.calculated || {};
  const html = `<!doctype html><html lang="ar"><head><meta charset="utf-8"><title>ملخص الجلسة</title><style>body{font-family:Arial,Helvetica,sans-serif;direction:rtl;padding:16px}table{width:100%;border-collapse:collapse}td{padding:6px;border-bottom:1px solid #eee}</style></head><body onload="window.print();setTimeout(()=>window.close(),300);"><h2>ملخص الجلسة #${s.id||''}</h2><table><tr><td>البداية</td><td>${s.start_time||''}</td></tr><tr><td>النهاية</td><td>${s.end_time||''}</td></tr><tr><td>افتتاحي</td><td>${Number(s.opening_cash_amount||0).toFixed(2)}</td></tr><tr><td>مدفوعات</td><td>${Number(t.payments||0).toFixed(2)}</td></tr><tr><td>نقد داخل</td><td>${Number(t.cash_in||0).toFixed(2)}</td></tr><tr><td>نقد خارج</td><td>${Number(t.cash_out||0).toFixed(2)}</td></tr><tr><td>رصيد متوقع</td><td>${Number(c.expected_cash||0).toFixed(2)}</td></tr></table></body></html>`;
  const w = window.open('', '_blank', 'width=720,height=600');
  if (!w) return;
  w.document.open(); w.document.write(html); w.document.close();
};

const openRenameDevice = () => { try { deviceNameInput.value = localStorage.getItem('pos_device_name') || ''; } catch { deviceNameInput.value = ''; } showRenameDevice.value = true; };
const saveDeviceName = () => {
  const val = String(deviceNameInput.value || '').trim();
  try {
    if (val) localStorage.setItem('pos_device_name', val.slice(0, 64));
    else localStorage.removeItem('pos_device_name');
    showToast(val ? 'تم حفظ اسم الجهاز' : 'تمت إعادة الاسم الافتراضي', 'success');
    showRenameDevice.value = false;
  } catch { showToast('تعذر حفظ اسم الجهاز', 'error'); }
};
let debounceTimer = null;
const debouncedSearch = async () => { 
  if (!isComponentMounted.value) return;
  
  clearTimeout(debounceTimer); 
  if (!selectedBranch.value) return; 
  debounceTimer = setTimeout(searchProducts, 300); 
};

const searchProducts = async () => {
  const wid = selectedBranch.value || authStore.user?.branch_id;
  if (!wid) {
    showToast('الرجاء اختيار المخزن أولاً للبحث عن المنتجات', 'warning');
    return;
  }

  if (productSearchAbortCtrl) {
    productSearchAbortCtrl.abort();
  }
  productSearchAbortCtrl = new AbortController();
  const currentCtrl = productSearchAbortCtrl;

  isSearchingProducts.value = true;
  try {
    const res = await productStore.searchProducts({ query: searchQuery.value, branchId: wid, categoryId: selectedCategory.value, limit: 100 });
    if (currentCtrl === productSearchAbortCtrl) {
      const list = res?.data || [];
      searchResults.value = list.map(p => ({ ...p, quantity: p.current_quantity, selectedQuantity: 1 }));
    }
  } catch (e) {
    const isAborted = e?.name === 'CanceledError' || e?.name === 'AbortError' || e?.message === 'canceled' || e?.code === 'ERR_CANCELED';
    if (!isAborted) showToast('حدث خطأ أثناء البحث', 'error');
  } finally {
    if (currentCtrl === productSearchAbortCtrl) isSearchingProducts.value = false;
  }
};

const addFirstResult = () => {
  if (filteredSearchResults.value.length) { addToInvoice(filteredSearchResults.value[0]); searchQuery.value = ''; searchResults.value = []; }
};

const addToInvoice = async prod => {
  // منع الضغط المتعدد على نفس المنتج
  if (loadingProductId.value === prod.id) return;
  loadingProductId.value = prod.id;
  try {
    const wid = selectedBranch.value || authStore.user?.branch_id;
    if (!wid) { showToast('الرجاء اختيار مخزن أولاً', 'error'); return; }
    const isService = prod.product_type === 'service';
    const availableQty = prod.quantity ?? 0;
    if (prod.selectedQuantity < 1) { showToast('الكمية يجب أن تكون أكبر من الصفر', 'warning'); return; }
    if (!isService && prod.selectedQuantity > availableQty) { showToast(`الكمية المطلوبة غير متاحة. الكمية المتاحة: ${availableQty}`, 'warning'); return; }
    let availableBatches = [], availableSerials = [];
    if (!isService && (prod.has_batch_number || prod.has_serial_number)) {
      try {
        const batchData = await inventoryStore.fetchBatches({ productId: prod.id, branchId: wid });
        if (prod.has_batch_number) availableBatches = batchData.data?.batches || [];
        if (prod.has_serial_number) availableSerials = batchData.data?.serials || [];
      } catch {}
    }
    const exist = invoice.value.find(i => i.id === prod.id);
    if (exist) {
      const newQty = exist.selectedQuantity + prod.selectedQuantity;
      exist.selectedQuantity = !isService && newQty > availableQty
        ? (showToast(`تم تعديل الكمية إلى الحد الأقصى: ${availableQty}`, 'warning'), availableQty)
        : newQty;
    } else {
      invoice.value.push({ ...prod, catalog_sale_price: Number(prod.sale_price), manual_price_override: false, unit_id: prod.unit_id || (prod.unit && prod.unit.id) || 1, available_batches: availableBatches, available_serials: availableSerials, batch_number: '', expiry_date: '', serial: '' });
    }
  } catch { showToast('حدث خطأ أثناء إضافة المنتج', 'error'); }
  finally { loadingProductId.value = null; }
  searchQuery.value = '';
  searchResults.value = [];
};

const validateSalePrice = (item) => {
  item.manual_price_override = Number(item.sale_price) !== Number(item.catalog_sale_price);
};

const updateQty = (item, qty) => {
  if (qty < 1) item.selectedQuantity = 1;
  else if (item.product_type !== 'service' && qty > item.quantity) { item.selectedQuantity = item.quantity; showToast('تجاوزت الحد الأقصى للمخزون', 'warning'); }
  else item.selectedQuantity = qty;
};

const removeFromInvoice = i => { invoice.value = invoice.value.filter(x => x.id !== i.id); };

const onBatchChange = (item) => {
  if (item.batch_number && item.available_batches) {
    const batch = item.available_batches.find(b => b.batch_number === item.batch_number);
    if (batch) {
      item.expiry_date = batch.expiry_date || '';
      if (item.selectedQuantity > batch.quantity) { item.selectedQuantity = batch.quantity; showToast(`تم تعديل الكمية إلى المتاح في الدفعة: ${batch.quantity}`, 'warning'); }
    }
  }
};
let customerDebounceTimer = null;
const debouncedCustomerSearch = () => { clearTimeout(customerDebounceTimer); customerDebounceTimer = setTimeout(searchCustomers, 300); };
const searchCustomers = async (q) => {
  customerSearchAbortCtrl?.abort();
  customerSearchAbortCtrl = new AbortController();
  const searchRequestId = (customerSearchRequestId += 1);
  const branchId = getActivebranchId();
  if (!branchId) {
    customers.value = [];
    customerSearchResults.value = [];
    return;
  }

  try {
    const query = (customerQuery.value || '').trim();
    if (!query) {
      const response = await customerStore.fetchCustomers({
        force: true,
        params: { branch_id: String(branchId) }
      });
      if (searchRequestId !== customerSearchRequestId || String(branchId) !== String(getActivebranchId())) return;
      if (response.status === 'success') {
        customers.value = response.data || [];
        customerSearchResults.value = customers.value;
      }
      return;
    }
    const searchResponse = await customerStore.searchCustomers(query, {
      force: true,
      params: { branch_id: String(branchId) }
    });
    if (searchRequestId !== customerSearchRequestId || String(branchId) !== String(getActivebranchId())) return;
    if (searchResponse.status === 'success') {
      customerSearchResults.value = searchResponse.data || [];
    } else {
      customerSearchResults.value = [];
    }
  } catch {
    if (customerSearchAbortCtrl?.signal?.aborted || searchRequestId !== customerSearchRequestId) return;
    customerSearchResults.value = [];
  }
};

const selectCustomer = (c) => { selectedCustomer.value = c?.id || ''; customerQuery.value = c?.name || ''; showCustomerDropdown.value = false; };
const hideCustomerDropdown = () => setTimeout(() => { showCustomerDropdown.value = false; }, 120);
const moveCustomerActive = (dir) => { const len = filteredCustomers.value.length; if (!len) return; customerActiveIndex.value = (customerActiveIndex.value + dir + len) % len; };
const selectActiveCustomer = () => { const list = filteredCustomers.value; if (list.length) selectCustomer(list[customerActiveIndex.value]); };
const getMaxAllowedDiscount = () => {
  if (!invoice.value.length) return 0;
  let maxDiscount = Infinity;
  invoice.value.forEach(item => {
    const allowed = (item.sale_price - (item.min_sale_price || 0)) * item.selectedQuantity;
    if (allowed < maxDiscount) maxDiscount = allowed;
  });
  return Math.max(maxDiscount === Infinity ? 0 : maxDiscount, 0);
};

watch([discountValue, discountType, invoice], () => {
  if (!invoice.value.length) return;
  let disc = parseFloat(discountValue.value) || 0;
  const total = totalAmount.value;
  const maxAllowed = getMaxAllowedDiscount();
  if (discountType.value === 'نسبة %') {
    const discAmount = (Math.min(Math.max(disc, 0), 100) / 100) * total;
    if (discAmount > maxAllowed) {
      discountValue.value = total > 0
        ? Math.floor((maxAllowed / total) * 100 * 100) / 100
        : 0;
      showToast('تم تعديل نسبة الخصم لتناسب سعر البيع المسموح!', 'warning');
    }
  } else {
    if (disc > maxAllowed) {
      discountValue.value = Math.floor(maxAllowed * 100) / 100;
      showToast('تم تعديل مبلغ الخصم ليتناسب مع سعر البيع المسموح!', 'warning');
    }
  }
}, { deep: true });
const resetInvoiceState = () => {
  invoice.value = [];
  discountValue.value = 0;
  taxValue.value = taxEnabled.value ? taxRate.value : 0;
  selectedCustomer.value = '';
  customerQuery.value = '';
  actualPaidAmount.value = 0;
  setDefaultCashPaymentMethod();
};

const holdInvoice = () => {
  if (!invoice.value.length) { showToast('لا توجد فاتورة لتعليقها', 'warning'); return; }
  heldInvoices.value.push({ items: JSON.parse(JSON.stringify(invoice.value)), customer_id: selectedCustomer.value, customer_name: selectedCustomerData.value?.name || 'عميل نقدي', discountType: discountType.value, discountValue: discountValue.value, actualPaidAmount: actualPaidAmount.value, finalTotal: finalTotal.value, timestamp: new Date().toLocaleTimeString() });
  resetInvoiceState();
  showToast('تم تعليق الفاتورة بنجاح', 'success');
};

const resumeInvoice = (index) => {
  if (invoice.value.length > 0) { showToast('يرجى حفظ الفاتورة الحالية أو تعليقها أولاً.', 'warning'); return; }
  const held = heldInvoices.value[index];
  invoice.value = held.items; selectedCustomer.value = held.customer_id; customerQuery.value = held.customer_name;
  discountType.value = held.discountType; discountValue.value = held.discountValue; actualPaidAmount.value = held.actualPaidAmount;
  heldInvoices.value.splice(index, 1);
  showToast('تم استئناف الفاتورة بنجاح', 'success');
};

const saveSale = async (options = {}) => {
  if (isSaving.value || (isPrinting.value && !options.allowWhilePrinting)) return null;
  isSaving.value = true;
  let loaderShown = false;
  try {
    const wid = selectedBranch.value || authStore.user?.branch_id;
    if (!wid) { showToast('الرجاء اختيار مخزن قبل الحفظ', 'error'); return null; }
    const branchIdStr = String(wid);
    if (!invoice.value.length) { showToast('الفاتورة فارغة', 'warning'); return null; }
    const belowCostItems = new Set();
    const belowMinimumPriceItems = new Set();
    for (const item of invoice.value) {
      const netUnitPrice = effectiveNetUnitPrice(item);
      const conversionFactor = Number(item.conversion_factor || 1);
      const minimumSalePrice = Number(item.min_sale_price) * conversionFactor;
      if (Number.isFinite(minimumSalePrice) && minimumSalePrice > 0 && netUnitPrice + 0.005 < minimumSalePrice) {
        belowMinimumPriceItems.add(item);
      }
      const unitCost = Number(item.purchase_price) * conversionFactor;
      if (Number.isFinite(unitCost) && unitCost > 0 && netUnitPrice + 0.005 < unitCost) {
        belowCostItems.add(item);
      }
    }
    let belowPriceConfirmed = false;
    let belowCostReason = '';
    if (belowCostItems.size) {
      if (!canApproveBelowCost.value) {
        showToast('اعتماد البيع بأقل من التكلفة متاح للمدير أو المشرف فقط.', 'error');
        return null;
      }
      belowCostReason = await requestBelowCostReason([...belowCostItems]);
      if (!belowCostReason) return null;
      belowPriceConfirmed = true;
    } else if (belowMinimumPriceItems.size) {
      const warningLines = [];
      warningLines.push(`أقل من الحد الأدنى المحدد: ${[...belowMinimumPriceItems].map(item => item.name).join('، ')}`);
      belowPriceConfirmed = await AlertService.confirm(
        `تنبيه: ${warningLines.join('\n')}\nهل تؤكد اعتماد البيع؟`,
        'تأكيد البيع بأقل من الحد',
        {
          confirmText: 'تأكيد البيع',
          cancelText: 'إلغاء',
          closeOnBackdrop: false
        }
      );
      if (!belowPriceConfirmed) return null;
    }
    showLoader();
    loaderShown = true;
    if (!requireApproval.value) {
      try {
        const pm = Number(selectedPaymentMethod.value);
        const method = (paymentMethods.value || []).find(m => Number(m.id) === pm);
        if (sessionsEnabled.value && method?.kind === 'cash' && (parseFloat(actualPaidAmount.value) || 0) > 0) {
          const ok = await ensureOpenSession(wid, { autoOpen: false, opening_cash_amount: 0, prompt: false, session_type: 'manual' });
          if (!ok) { openingAmount.value = ''; showOpenDialog.value = true; showToast('لا توجد جلسة كاشير مفتوحة. يرجى فتح جلسة أولاً.', 'warning'); return null; }
        }
      } catch (e) { showToast(e?.message || 'خطأ أثناء التحقق من الجلسة', 'error'); return null; }
    }
    const pm = Number(selectedPaymentMethod.value);
    const method = (paymentMethods.value || []).find(m => Number(m.id) === pm);
    const isCreditMethod = method?.kind === 'credit';
    const rawPaidAmount = parseFloat(actualPaidAmount.value) || 0;
    if (!requireApproval.value && rawPaidAmount > finalTotal.value + 0.01) {
      showToast(`المبلغ المدفوع (${rawPaidAmount.toFixed(2)}) لا يمكن أن يتجاوز إجمالي الفاتورة (${finalTotal.value.toFixed(2)})`, 'error');
      return null;
    }
    const intendedPaidAmount = requireApproval.value ? (method?.kind === 'cash' ? (parseFloat(finalTotal.value) || 0) : 0) : Math.min(rawPaidAmount, finalTotal.value);
    if (isCreditMethod && intendedPaidAmount > 0) { showToast('طريقة الدفع الآجلة لا تقبل أي مبلغ مدفوع — اجعل المبلغ المدفوع = 0 أو اختر طريقة دفع أخرى', 'error'); return null; }
    const status = requireApproval.value ? 'pending_approval' : (intendedPaidAmount >= finalTotal.value ? 'paid' : (intendedPaidAmount > 0 ? 'partial' : 'due'));
    if ((['due','partial','pending_payment'].includes(status) || (status === 'pending_approval' && isCreditMethod)) && !selectedCustomer.value) { showToast('يجب تحديد عميل لفواتير الآجل والذمم', 'error'); return null; }
    const payload = {
      customer_id: selectedCustomer.value || null,
      branch_id: branchIdStr,
      items: invoice.value.map(i => ({ product_id: i.id, quantity: i.selectedQuantity, sale_price: i.sale_price, manual_price_override: Number(i.sale_price) !== Number(i.catalog_sale_price), below_cost_confirmed: belowCostItems.has(i) && belowPriceConfirmed, below_minimum_price_confirmed: belowMinimumPriceItems.has(i) && belowPriceConfirmed, purchase_price: i.purchase_price || 0, unit_id: Number(i.unit_id || (i.unit && i.unit.id) || 1), branch_id: branchIdStr, conversion_factor: 1, batch_number: (i.batch_number?.trim()) || null, expiry_date: (i.expiry_date?.trim()) || null, serial: (i.serial?.trim()) || null })),
      discount_type: discountType.value === 'نسبة %' ? 'percentage' : 'fixed',
      discount_value: parseFloat(discountValue.value) || 0,
      tax_rate: parseFloat(taxValue.value) || 0,
      payment_method_id: selectedPaymentMethod.value || null,
      paid_amount: intendedPaidAmount,
      tenant_id: authStore.user?.tenant_id,
      status,
      user_id: authStore.user?.id,
      device_id: getDeviceIdentity().device_id
    };
    if (belowCostReason) payload.below_cost_reason = belowCostReason;
    const res = await salesStore.createSale(payload);
    if (res.status === 'success') {
      const d = res.data;
      const invoiceNo = d?.invoice_number ?? d?.sale?.invoice_number ?? d?.sale_id ?? d?.id ?? '';
      
      // ✅ تحديث تلقائي للكميات — امسح cache جميع المنتجات في المستودع
      productStore.invalidateCacheForBranch(wid);
      // ✅ إعادة جلب فعلية بدل الاكتفاء بمسح الكاش، لتحديث الجدول المعروض فوراً
      await searchProducts();
      
      showToast(`تم الحفظ (#${invoiceNo})`, 'success');
      if (options.returnResult) {
        return { ...((typeof d === 'object' && d) || {}), sale_id: d?.sale_id ?? d?.id ?? d?.sale?.id, id: d?.id ?? d?.sale_id ?? d?.sale?.id, invoice_number: d?.invoice_number ?? d?.sale?.invoice_number ?? invoiceNo };
      }
      if (!options.skipReset) resetInvoiceState();
      return { success: true };
    } else {
      showToast(res.message || 'فشل في الحفظ', 'error');
      return null;
    }
  } catch (e) {
    const errorCode = e?.response?.data?.error_code;
    const msg = e?.response?.data?.message || 'فشل في الحفظ';
    showToast(msg, 'error');
    
    // ✅ معالجة خاصة لخطأ المخزون غير الكافي
    if (errorCode === 'insufficient_stock') {
      // حدّث فورًا كمية المنتج المعني في السلة والجدول
      const wid = selectedBranch.value || authStore.user?.branch_id;
      if (wid) {
        productStore.invalidateCacheForBranch(wid);
        await searchProducts();
        
        const productId = e?.response?.data?.product_id;
        const availableQty = e?.response?.data?.available_qty;
        
        // حدّث الكمية في السلة الحالية
        if (productId && searchResults.value) {
          const item = invoice.value.find(i => i.id === productId);
          if (item) {
            const fresh = searchResults.value.find(p => p.id === productId);
            if (fresh) {
              item.quantity = fresh.quantity;
              // تنبيه إضافي للمستخدم
              showToast(`الكمية المتاحة: ${availableQty} فقط`, 'warning');
            }
          }
        }
      }
    }
    
    return null;
  } finally {
    if (loaderShown) hideLoader();
    isSaving.value = false;
  }
};

const getSelectedPrintTemplate = () => {
  let t = (localStorage.getItem('pos_print_template') || '').toLowerCase();
  if (t === 'thermal') t = 'thermal-compact';
  if (t === 'a4') t = 'a4-simple';
  return new Set(['thermal-compact', 'thermal-detailed', 'a4-simple', 'a4-professional']).has(t) ? t : 'thermal-compact';
};

const fetchSaleDetails = async (saleId) => { return await salesStore.fetchSaleDetails(saleId); };
const fetchSaleDetailsWithRetry = async (saleId, retries = 3, delayMs = 500) => {
  let lastErr;
  for (let attempt = 1; attempt <= retries; attempt++) {
    try { const data = await fetchSaleDetails(saleId); if (data) return data; } catch (e) { lastErr = e; }
    if (attempt < retries) await new Promise(r => setTimeout(r, delayMs));
  }
  if (lastErr) throw lastErr;
  return null;
};

const openPrintWindow = async (html, existingWindow = null) => {
  const w = await printDocument(html, existingWindow);
  if (!w && existingWindow !== null) showToast('تعذر فتح نافذة الطباعة. يرجى السماح بالنوافذ المنبثقة.', 'error');
  return w;
};

const saveAndPrint = async () => {
  if (isSaving.value || isPrinting.value) return;
  if (requireApproval.value) {
    isPrinting.value = true;
    try { const result = await saveSale({ skipReset: false, returnResult: true }); if (result) showToast('تم الحفظ كطلب موافقة. لا يمكن الطباعة قبل الاعتماد.', 'info'); }
    finally { isPrinting.value = false; }
    return;
  }
  const preOpened = window.open('', '_blank');
  if (!preOpened) { showToast('تم حظر نافذة الطباعة. يرجى السماح بالنوافذ المنبثقة.', 'error'); return; }
  isPrinting.value = true;
  let didSave = false;
  const invoiceSnapshot = JSON.parse(JSON.stringify(invoice.value || []));
  const totals = { total_amount: parseFloat(totalAmount.value || 0), discount_value: parseFloat(discountAmount.value || 0), tax_amount: parseFloat(taxAmount.value || 0), net_total_amount: parseFloat((enteredSubtotal.value - invoiceDiscountAmount.value) || 0), paid_amount: parseFloat(actualPaidAmount.value || 0) };
  const customerName = selectedCustomerData.value?.name || 'عميل نقدي';
  try {
    const result = await saveSale({ skipReset: true, returnResult: true, allowWhilePrinting: true });
    if (!result) { preOpened.close?.(); return; }
    didSave = true;
    const saleId = result.sale_id ?? result.id;
    if (!saleId) { preOpened.close?.(); return; }
    let sale = null;
    try { sale = await fetchSaleDetailsWithRetry(saleId, 3, 600); } catch {}
    if (!sale || !Array.isArray(sale.items) || !sale.items.length) {
      sale = { id: saleId, invoice_number: result.invoice_number || saleId, sale_date: getLocalDateISO(), customer_name: customerName, items: invoiceSnapshot.map(it => ({ product_name: it.name, name: it.name, quantity: it.selectedQuantity, sale_price: it.sale_price, net_total: parseFloat((it.sale_price * it.selectedQuantity).toFixed(2)) })), ...totals };
    }
    openPrintWindow(getBuilderByTemplate(getSelectedPrintTemplate())(sale), preOpened);
  } catch { try { preOpened.close?.(); } catch {} }
  finally { if (didSave) resetInvoiceState(); isPrinting.value = false; }
};
const loadInitial = async () => {
  try {
    const wid = getActivebranchId();
    if (!wid) { showToast('لم يتم تحديد مستودع', 'error'); return; }
    const params = { branch_id: String(wid) };
    
    // استخدام Bootstrap API لتحميل جميع البيانات دفعة واحدة
    try {
      const data = await bootstrapStore.fetchPosData();
      
      // تطبيق البيانات على الـ stores المحلية
      if (data.branches) branchStore.branches = data.branches;
      if (data.paymentMethods) paymentStore.paymentMethods = data.paymentMethods;
      
      // تحميل العملاء بشكل منفصل لأنهم ليسوا في POS bootstrap
      const customerResponse = await customerStore.fetchCustomers({ force: true, params }).catch(err => {
        console.warn('Failed to fetch customers:', err);
        return null;
      });
      if (String(wid) === String(getActivebranchId()) && customerResponse?.status === 'success') {
        customers.value = customerResponse.data || [];
        customerSearchResults.value = customers.value;
      }
      
      console.log('[SalesPoint] Bootstrap data loaded successfully');
    } catch (bootstrapError) {
      console.warn('[SalesPoint] Bootstrap API failed, using fallback', bootstrapError);
      
      // Fallback: تحميل البيانات بشكل منفصل كما كان الكود الأصلي
      const [customerResponse] = await Promise.all([
        customerStore.fetchCustomers({ force: true, params }),
        paymentStore.fetchPaymentMethods()
      ]);
      if (String(wid) === String(getActivebranchId()) && customerResponse?.status === 'success') {
        customers.value = customerResponse.data || [];
        customerSearchResults.value = customers.value;
      }
    }
    // Data is automatically cached in stores
  } catch (error) { 
    showToast('فشل تحميل البيانات الأولية', 'error'); 
  }
};
const handleSessionRefreshRequest = () => refreshSession();
const handleSaleReturnRecorded = async (event) => {
  await refreshSession();
  debouncedSearch();

  const customerId = event?.detail?.customerId;

  if (customerId && selectedCustomer.value === customerId) {
    await searchCustomers();
  }
};
const handleVoucherRecorded = async () => {
  await refreshSession();
};
watch(finalTotal, (v) => { if (!requireApproval.value) { const pm = Number(selectedPaymentMethod.value); const method = (paymentMethods.value || []).find(m => Number(m.id) === pm); if (method?.kind !== 'credit') actualPaidAmount.value = parseFloat(v.toFixed(2)); } });
watch(selectedPaymentMethod, (newVal) => { if (!newVal) return; const pm = Number(newVal); const method = (paymentMethods.value || []).find(m => Number(m.id) === pm); if (method?.kind === 'credit') actualPaidAmount.value = 0; else if (!requireApproval.value) actualPaidAmount.value = parseFloat(finalTotal.value.toFixed(2)); });
watch(requireApproval, (v) => { actualPaidAmount.value = v ? 0 : parseFloat(finalTotal.value.toFixed(2)); });
const handleBranchChange = async (newBranchId) => {
  if (!settingsLoaded.value || !newBranchId) return;
  
  const oldBranchId = branchStore.selectedBranchId;
  
  // تحذير لو في فاتورة مفتوحة عند تغيير المخزن
  if (oldBranchId && newBranchId !== oldBranchId && invoice.value.length > 0) {
    const confirmed = window.confirm(
      `لديك ${invoice.value.length} منتج في الفاتورة الحالية.\nتغيير المخزن سيمسح الفاتورة. هل تريد المتابعة؟`
    );
    if (!confirmed) {
      return;
    }
    resetInvoiceState();
  }
  
  // ✅ حفظ الاختيار بشكل دائم
  branchStore.setSelectedBranch(newBranchId);
  // ✅ تحديث flag الاختيار اليدوي
  userChoseBranch.value = (newBranchId !== null && newBranchId !== '' && newBranchId !== 'all');
  customerSearchAbortCtrl?.abort();
  customerSearchRequestId += 1;
  customers.value = [];
  customerSearchResults.value = [];
  selectedCustomer.value = '';
  customerQuery.value = '';

  sessionLimitReached.value = false;
  // 🔄 إعادة تحميل كل البيانات المرتبطة بالفرع
  await Promise.all([fetchTerminals(), refreshSession(), loadInitial()]);
  debouncedSearch();
};

// ✅ الـ selector الحسوب يستخدم handleBranchChange عند التغيير
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId,
  set: (val) => handleBranchChange(val)
});

watch(discountType, (v) => { try { localStorage.setItem('pos_discountType', v || ''); } catch {} });
watch(showInactive, (v) => { try { localStorage.setItem('pos_showInactive', v ? '1' : '0'); } catch {} });
watch(showExpiring, (v) => { try { localStorage.setItem('pos_showExpiring', v ? '1' : '0'); } catch {} });
watch(heldInvoices, (v) => { try { localStorage.setItem('held_invoices', JSON.stringify(v)); } catch {} }, { deep: true });
const showBranchRequiredModal = ref(false);
const tempBranchId = ref(null);

const confirmBranchSelection = async () => {
  // Validation: Admin must select a branch
  if (canSelectBranch.value && !tempBranchId.value) {
    return;
  }
  
  const branchId = tempBranchId.value || authStore.user?.branch_id;
  if (!branchId) return;
  
  // ✅ استخدم setSelectedBranch لحفظ الاختيار بشكل دائم في localStorage
  // هذا يضمن أنه عند تحديث الصفحة سيتم استعادة الفرع بدون ظهور Modal
  branchStore.setSelectedBranch(branchId);
  showBranchRequiredModal.value = false;
  await Promise.all([loadInitial(), refreshSession(), fetchTerminals()]);
  setDefaultCashPaymentMethod();
  debouncedSearch();
};

const cancelBranchSelection = () => router.push('/cashier');

const goToCashierDashboard = () => router.push('/cashier');
const toggleInactive = () => (showInactive.value = !showInactive.value);
const toggleExpiring = () => (showExpiring.value = !showExpiring.value);
const handleKeydown = (e) => {
  if (e.key === 'F1') { e.preventDefault(); productSearchInputRef.value?.focus(); }
  if (e.key === 'F2') { e.preventDefault(); if (invoice.value.length > 0) saveAndPrint(); }
  if (e.key === 'F3') { e.preventDefault(); if (invoice.value.length > 0) saveSale(); }
};

onMounted(async () => {
  // ✅ تعيين حالة المكون قبل أي شيء
  isComponentMounted.value = true;
  
  // ✅ FIX: تهيئة/استعادة branch context قبل أول API call (نفس النمط من SalesHistory)
  // سجّل ما إذا كان المستخدم اختار فرعاً في جلسة سابقة (قبل fetchBranches يكتب default)
  const hadPriorBranchChoice = localStorage.getItem('selectedBranchId') !== null 
                               && localStorage.getItem('selectedBranchId') !== 'all';

  try {
    branchStore.loadFromStorage();
    if (!branchStore.branches || branchStore.branches.length === 0) {
      await branchStore.fetchBranches();
    }
  } catch {}

  // بعد fetchBranches: أعد تعيين الـ flag بما كان موجوداً قبل الكتابة
  // لأن fetchBranches() قد تكتب selectedBranchId في localStorage تلقائياً
  userChoseBranch.value = hadPriorBranchChoice;
  
  // ✅ تحميل كل الإعدادات مرة واحدة بدل 3 مرات
  await loadAllSettings();

  try {
    // لا نستعيد وسيلة دفع قديمة: الافتراضي النقدي يحدد بعد تحميل القائمة.
    discountType.value = localStorage.getItem('pos_discountType') || discountType.value;
    showInactive.value = localStorage.getItem('pos_showInactive') === '1';
    showExpiring.value = localStorage.getItem('pos_showExpiring') === '1';
    const held = localStorage.getItem('held_invoices');
    if (held) heldInvoices.value = JSON.parse(held);
  } catch {}

  // Sync with branch store if available
  if (branchStore.selectedBranchId) {
    // branch already selected — proceed normally
  } else if (canSelectBranch.value) {
    // Admin with 'all branches' setting
    if (!branchStore.selectedBranchId) {
      // Admin chose 'all branches' globally → ask for a temporary branch for this session
      // ✅ الآن عند الاختيار، setSelectedBranch سيحفظ الاختيار في localStorage
      // فعند التحديث القادم سيتم استعادة الفرع بدون Modal
      showBranchRequiredModal.value = true;
      return; // wait for modal confirmation before continuing init
    }
  } else {
    // Regular user with assigned branch
    const defaultBranch = authStore.user?.branch_id;
    if (defaultBranch && !branchStore.selectedBranchId) {
      // ✅ استخدم setSelectedBranch لحفظ الفرع المخصص
      branchStore.setSelectedBranch(defaultBranch);
    }
  }

  // branch is set — run all initial requests
  await Promise.all([loadInitial(), refreshSession(), fetchTerminals()]);
  setDefaultCashPaymentMethod();
  debouncedSearch();

  window.addEventListener('pos:return-recorded', handleSaleReturnRecorded);
  window.addEventListener('pos:voucher-recorded', handleVoucherRecorded);
  window.addEventListener('pos:session-refresh-request', handleSessionRefreshRequest);

  window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
  isComponentMounted.value = false;
  
  clearTimeout(debounceTimer);
  
  window.removeEventListener('keydown', handleKeydown);
  window.removeEventListener('pos:return-recorded', handleSaleReturnRecorded);
  
  if (productSearchAbortCtrl) {
    productSearchAbortCtrl.abort();
  }
  window.removeEventListener('pos:voucher-recorded', handleVoucherRecorded);
  window.removeEventListener('pos:session-refresh-request', handleSessionRefreshRequest);
  if (debounceTimer) clearTimeout(debounceTimer);
  if (customerDebounceTimer) clearTimeout(customerDebounceTimer);
  if (productSearchAbortCtrl) productSearchAbortCtrl.abort();
  if (customerSearchAbortCtrl) customerSearchAbortCtrl.abort();
});
</script>

<style scoped>
/* Animations */
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Form Inputs */
.filter-input {
  @apply w-full h-9 bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

/* Scrollbar */
.custom-scroll::-webkit-scrollbar {
  width: 4px;
}

.custom-scroll::-webkit-scrollbar-thumb {
  @apply bg-slate-200 rounded-full;
}

/* Animation Classes */
.animate-fadeIn {
  animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
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
</style>