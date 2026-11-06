<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <PageLoadingBar :active="isLoading || detailsLoading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <PageHeader
        :breadcrumb="{ parent: { label: 'العمليات', path: '/repairs' }, current: 'طلبات الضمان' }"
        title="إدارة الضمان"
        description="تابع مطالبات الضمان، وتحقّق من صلاحية التغطية، وراجع حالة الطلبات وسجلات الضمان."
        :branches="branches"
        :selectedBranch="selectedBranch"
        :hasExplicitSelection="hasExplicitBranchSelection"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <button @click="showCreate = true" class="h-9 px-6 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-plus-circle text-[10px]"></i>
            إنشاء طلب جديد
          </button>
        </template>
      </PageHeader>

      <section class="flex items-center justify-center">
        <div class="flex bg-slate-100 p-1 rounded-lg border border-slate-200/50">
          <button
            v-for="t in statusTabs"
            :key="t.value"
            @click="statusFilter = t.value"
            :class="[statusFilter === t.value ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']"
            class="px-8 py-2 rounded-md text-xs font-bold transition-all uppercase tracking-wider"
          >
            {{ t.label }}
          </button>
        </div>
      </section>

      <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="p-6 bg-slate-50/30 border-b border-slate-100 space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
          
          <div class="lg:col-span-4 space-y-1.5 group">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">بحث سريع</label>
            <div class="relative">
              <input v-model="search" type="text" class="filter-input-v2 h-9 text-[11px]" style="padding-right: 2rem; padding-left: 2rem;" placeholder="رقم الطلب، العميل، الرقم التسلسلي..." @keydown.enter.prevent="applyFilters" />
              <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
              <button v-if="search" type="button" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 transition hover:text-slate-600" aria-label="مسح البحث" @click="search = ''">
                <i class="fas fa-times-circle text-xs"></i>
              </button>
            </div>
          </div>

          <div class="lg:col-span-3 space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">أولوية الطلب</label>
            <select v-model="priorityFilter" class="filter-input-v2 h-9 appearance-none font-bold">
              <option v-for="p in priorityOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
          </div>

          <div class="lg:col-span-2 space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">من تاريخ</label>
            <div class="relative">
              <input ref="dateFromRef" type="date" v-model="dateFrom" class="filter-input-v2 h-9 font-mono" style="padding-left: 2rem;" />
              <i @click="dateFromRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
            </div>
          </div>

          <div class="lg:col-span-2 space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">إلى تاريخ</label>
            <div class="relative">
              <input ref="dateToRef" type="date" v-model="dateTo" class="filter-input-v2 h-9 font-mono" style="padding-left: 2rem;" />
              <i @click="dateToRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
            </div>
          </div>

          <div class="lg:col-span-1">
            <button
              type="button"
              :disabled="isLoading"
              :aria-busy="isLoading"
              aria-label="تحديث النتائج"
              title="تحديث النتائج"
              class="h-9 w-9 rounded-md border border-slate-200 bg-white p-0 text-slate-500 shadow-sm transition-all hover:border-blue-200 hover:bg-blue-50 hover:text-blue-600 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60"
              @click="applyFilters(true)"
            >
              <i class="fas fa-sync-alt text-xs transition-transform duration-500" :class="{ 'animate-spin': isLoading }" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        </div>
      </section>

      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest w-20 text-center">الرقم</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">العميل المستفيد</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الرقم التسلسلي</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الأولوية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <template v-if="isLoading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 6" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="items.length === 0">
                <td colspan="6" class="py-24 text-center text-slate-300">
                   <i class="fas fa-shield-virus text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد طلبات ضمان مسجلة</p>
                </td>
              </tr>
              <tr v-for="r in pageItems" :key="r.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-6 py-4 text-center font-mono font-bold text-slate-400">#{{ r.id }}</td>
                <td class="px-4 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 border border-slate-100">
                      <i class="fas fa-user text-[10px]"></i>
                    </div>
                    <span class="font-bold text-slate-900">{{ r.customerName || r.customerId }}</span>
                  </div>
                </td>
                <td class="px-4 py-4 font-mono text-slate-500 uppercase tracking-tight">{{ r.productSerial || '—' }}</td>
                <td class="px-4 py-4 text-center">
                  <span :class="[priorityClass(r.priority)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ priorityLabel(r.priority) }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center">
                  <span :class="[statusClass(r.status)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ statusLabel(r.status) }}
                  </span>
                </td>
                <td class="px-8 py-4 text-center">
                  <button @click="openDetails(r.id)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center mx-auto shadow-sm">
                    <i class="fas fa-eye text-[10px]"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ filters.totalPages.value }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ warrantyStore.warrantiesTotal }}</span> طلب ضمان
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
               <select v-model.number="filters.perPage.value" @change="applyFilters" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                 <option :value="10">10</option>
                 <option :value="20">20</option>
                 <option :value="50">50</option>
               </select>
             </div>
             <PaginationControls
               :page="filters.page.value"
               :total-pages="filters.totalPages.value"
               @previous="changePage(filters.page.value - 1)"
               @next="changePage(filters.page.value + 1)"
             />
          </div>
        </div>
      </div>
    </div>

    <HistoryDetailsModal
      :show="showCreate"
      @close="showCreate = false; resetForm()"
      title="فتح تذكرة ضمان جديدة"
      subtitle="تسجيل طلب صيانة وضمان جديد"
      icon="fas fa-shield-halved"
      maxWidth="4xl"
    >

      <div class="space-y-5">
        <div class="grid grid-cols-1 gap-4 rounded-xl border border-slate-100 bg-slate-50/40 p-4 sm:grid-cols-2">
          <div class="space-y-4">
            <div class="space-y-1.5 relative">
              <label class="metadata-label">العميل المستهدف <span class="text-rose-500">*</span></label>
              <div class="relative group">
                <input v-model="customerQuery" type="text" class="filter-input-v2 h-10 text-[11px] font-bold leading-4 placeholder:text-[11px] placeholder:font-bold placeholder:leading-4 pr-9 pl-8" placeholder="ابحث باسم العميل أو رقم الهاتف..." @input="customerActiveIndex = 0; handleCustomerInput(customerQuery)" @focus="showCustomerDropdown = true; debouncedCustomerSearch(customerQuery)" @blur="scheduleHideCustomerDropdown" @keydown.down.prevent="moveCustomerActive(1)" @keydown.up.prevent="moveCustomerActive(-1)" @keydown.enter.prevent="selectActiveCustomer" @keydown.esc.prevent="showCustomerDropdown = false" />
                <i class="fas fa-user-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                <button v-if="customerQuery" type="button" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 transition hover:text-slate-600" aria-label="مسح بحث العميل" @mousedown.prevent="clearCustomerSearch">
                  <i class="fas fa-times-circle text-xs"></i>
                </button>
                
                <div v-if="showCustomerDropdown" class="absolute z-50 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-xl max-h-48 overflow-auto py-2">
                  <div v-if="filteredCustomerResults.length === 0" class="px-4 py-2 text-[10px] text-slate-400 font-bold">{{ customerQuery.length < 2 ? 'اكتب حرفين على الأقل للبحث...' : 'لا توجد نتائج...' }}</div>
                  <li v-for="(c, idx) in filteredCustomerResults" :key="c.id" @mousedown.prevent="selectCustomer(c)" :class="idx === customerActiveIndex ? 'bg-blue-50' : 'hover:bg-blue-50'" class="px-4 py-2.5 cursor-pointer border-b border-slate-50 last:border-0 list-none transition-colors">
                    <p class="text-xs font-bold text-slate-800">{{ c.name }}</p>
                    <p class="text-[9px] text-slate-400 font-mono">{{ c.phone || c.email || c.id }}</p>
                  </li>
                </div>
              </div>
              <p v-if="errors.customerId" class="text-[9px] text-rose-500 font-bold px-1">{{ errors.customerId }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <label class="metadata-label h-8 flex items-center">الرقم التسلسلي (SN)</label>
                <input v-model="form.productSerial" type="text" class="filter-input-v2 h-10 text-[11px] font-bold leading-4 placeholder:text-[11px] placeholder:font-bold placeholder:leading-4 uppercase" placeholder="أدخل الرقم التسلسلي..." />
              </div>
              <div class="space-y-1.5">
                <label class="metadata-label h-8 flex items-center">تاريخ الشراء</label>
                <input ref="purchaseDateRef" v-model="form.purchaseDate" type="date" class="filter-input-v2 h-10 text-[11px] font-medium font-mono" />
              </div>
            </div>
          </div>

          <div class="space-y-4">
            <div class="space-y-1.5">
              <label class="metadata-label">أولوية المعالجة <span class="text-rose-500">*</span></label>
              <select v-model="form.priority" class="filter-input-v2 h-10 text-[11px] font-medium">
                <option value="low">منخفضة</option><option value="medium">متوسطة</option><option value="high">مرتفعة</option><option value="urgent">عاجل</option>
              </select>
              <p v-if="errors.priority" class="text-[9px] text-rose-500 font-bold px-1">{{ errors.priority }}</p>
            </div>
            <div class="space-y-1.5 relative">
              <label class="metadata-label h-8 flex items-center">فاتورة البيع المرجعية <span class="text-slate-400 font-normal normal-case">(اختياري)</span></label>
              <input
                v-model="invoiceQuery"
                type="text"
                class="filter-input-v2 h-10 text-[11px] font-bold leading-4 placeholder:text-[11px] placeholder:font-bold placeholder:leading-4"
                :class="{ 'border-rose-300': errors.invoiceId }"
                :disabled="!form.customerId"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="warranty-sale-dropdown"
                :aria-expanded="showInvoiceDropdown.toString()"
                placeholder="ابحث برقم الفاتورة بعد اختيار العميل..."
                @input="handleInvoiceInput"
                @focus="showInvoiceDropdown = Boolean(form.customerId); debouncedInvoiceSearch(invoiceQuery)"
                @blur="scheduleHideInvoiceDropdown"
                @keydown.down.prevent="moveInvoiceActive(1)"
                @keydown.up.prevent="moveInvoiceActive(-1)"
                @keydown.enter.prevent="selectActiveInvoice"
                @keydown.esc.prevent="showInvoiceDropdown = false"
              />
              <button v-if="invoiceQuery" type="button" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 transition hover:text-slate-600" aria-label="مسح بحث الفاتورة" @mousedown.prevent="clearSelectedInvoice">
                <i class="fas fa-times-circle text-xs"></i>
              </button>
              <div
                v-if="showInvoiceDropdown && form.customerId"
                id="warranty-sale-dropdown"
                role="listbox"
                :aria-busy="invoiceSearchLoading"
                class="absolute z-50 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-xl max-h-48 overflow-auto py-2"
              >
                <div v-if="invoiceSearchLoading" role="status" class="px-4 py-2 text-[10px] text-slate-400 font-bold">جارٍ البحث عن فواتير البيع...</div>
                <div v-else-if="invoiceSearchError" role="alert" class="px-4 py-2 text-[10px] text-rose-600 font-bold">{{ invoiceSearchError }}</div>
                <div v-else-if="invoiceQuery.trim().length === 1" role="status" class="px-4 py-2 text-[10px] text-slate-400 font-bold">اكتب حرفين على الأقل للبحث...</div>
                <div v-else-if="invoiceResults.length === 0" role="status" class="px-4 py-2 text-[10px] text-slate-400 font-bold">لا توجد فواتير بيع لهذا العميل.</div>
                <button
                  v-for="(sale, idx) in invoiceResults"
                  :key="sale.id"
                  type="button"
                  role="option"
                  @mousedown.prevent="selectInvoice(sale)"
                  :class="idx === invoiceActiveIndex ? 'bg-blue-50' : 'hover:bg-blue-50'"
                  class="w-full px-4 py-2.5 text-right cursor-pointer border-b border-slate-50 last:border-0 transition-colors"
                >
                  <span class="block text-xs font-bold text-slate-800">{{ sale.invoiceNumber || `فاتورة #${sale.id}` }}</span>
                  <span class="block text-[9px] text-slate-400 font-mono mt-1">#{{ sale.id }} · {{ formatDateTime(sale.createdAt) }}</span>
                </button>
              </div>
              <p v-if="errors.invoiceId" class="text-[9px] text-rose-500 font-bold px-1">{{ errors.invoiceId }}</p>
              <p v-if="invoiceQuery && form.invoiceId" class="text-[9px] text-emerald-600 font-bold px-1">تم ربط الطلب بفاتورة البيع المحددة.</p>
            </div>
          </div>
        </div>

        <div class="space-y-1.5 rounded-xl border border-slate-100 bg-white">
          <label class="metadata-label">تفاصيل المشكلة والعطل <span class="text-rose-500">*</span></label>
          <textarea v-model="form.issueDescription" rows="3" class="w-full rounded-lg border border-slate-200 bg-slate-50 p-4 text-[11px] font-bold text-slate-700 placeholder:text-[11px] placeholder:font-bold outline-none transition-all focus:bg-white" placeholder="اكتب وصف العطل أو المشكلة بالتفصيل..."></textarea>
          <p v-if="errors.issueDescription" class="text-[9px] text-rose-500 font-bold px-1">{{ errors.issueDescription }}</p>
        </div>

        <div class="space-y-4 rounded-xl border border-slate-100 bg-slate-50/40 p-4">
          <div class="flex items-center justify-between">
            <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">العناصر المشمولة في الطلب</h4>
            <button type="button" @click="addItem" class="h-7 px-4 bg-slate-900 text-white rounded text-[10px] font-bold hover:bg-black transition-all">+ إضافة عنصر</button>
          </div>

          <div class="space-y-3">
            <div v-for="(it, idx) in form.items" :key="idx" class="p-4 bg-slate-50 rounded-xl border border-slate-200 relative group transition-all hover:bg-white">
              <button v-if="form.items.length > 1" @click="removeItem(idx)" class="absolute -left-2 -top-2 w-6 h-6 bg-rose-500 text-white rounded-full flex items-center justify-center shadow-lg"><i class="fas fa-times text-[8px]"></i></button>
              <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                <div class="md:col-span-6 relative">
                  <input v-model="it._productQuery" type="text" class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-[11px] font-bold text-slate-700 placeholder:text-[11px] placeholder:font-bold" :class="{'border-rose-300': itemErrors[idx]?.productId}" placeholder="ابحث عن المنتج..." @input="it._productActiveIndex = 0; debouncedProductSearch(idx, it._productQuery)" @focus="it._showProductDropdown = true; if (it._productQuery) debouncedProductSearch(idx, it._productQuery)" @blur="scheduleHideProductDropdown(it)" @keydown.down.prevent="moveProductActive(it, 1)" @keydown.up.prevent="moveProductActive(it, -1)" @keydown.enter.prevent="selectActiveProduct(idx, it)" @keydown.esc.prevent="it._showProductDropdown = false" />
                  <div v-if="it._showProductDropdown" class="absolute z-50 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-xl max-h-40 overflow-auto py-1">
                    <div v-if="it._productSearchError" role="alert" class="px-3 py-2 text-[10px] text-rose-600 font-bold">{{ it._productSearchError }}</div>
                    <div v-else-if="(it._productResults || []).length === 0" class="px-3 py-2 text-[10px] text-slate-400 font-bold">{{ (it._productQuery || '').length < 2 ? 'اكتب حرفين على الأقل للبحث...' : 'لا توجد نتائج...' }}</div>
                    <li v-for="(p, productIdx) in it._productResults" :key="p.id" @mousedown.prevent="selectProduct(idx, p)" :class="productIdx === (it._productActiveIndex || 0) ? 'bg-blue-50' : 'hover:bg-blue-50'" class="px-3 py-1.5 cursor-pointer text-[10px] font-bold border-b border-slate-50 last:border-0 list-none">{{ p.name }}</li>
                  </div>
                  <p v-if="itemErrors[idx]?.productId" class="text-[9px] text-rose-500 font-bold mt-1 px-1">{{ itemErrors[idx].productId }}</p>
                </div>
                <div class="md:col-span-2">
                  <input v-model.number="it.quantity" type="number" class="h-9 w-full bg-white border border-slate-200 rounded-md text-center font-bold text-[11px]" :class="{'border-rose-300': itemErrors[idx]?.quantity}" min="1" @change="validateItem(idx)" />
                  <p v-if="itemErrors[idx]?.quantity" class="text-[9px] text-rose-500 font-bold mt-1 px-1">{{ itemErrors[idx].quantity }}</p>
                </div>
                <div class="md:col-span-4">
                  <input v-model="it.issueNotes" type="text" class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-[11px] font-bold text-slate-700 placeholder:text-[11px] placeholder:font-bold" placeholder="أضف ملاحظات المنتج..." />
                </div>
              </div>
            </div>
          </div>
          <p v-if="errors.items" class="text-[9px] text-rose-500 font-bold px-1 mt-2">{{ errors.items }}</p>
        </div>
      </div>

      <template #footer>
        <div class="mr-auto flex items-center gap-3">
          <button type="button" @click="showCreate = false; resetForm()" class="inline-flex h-10 items-center justify-center px-4 text-xs font-bold text-slate-500 hover:text-slate-800 transition-colors">إلغاء</button>
          <LoadingButton
            type="button"
            text="تسجيل طلب الضمان"
            :isLoading="creating"
            loadingText="جاري التسجيل..."
            variant="primary"
            loaderType="dots"
            @click="createWarranty"
            :disabled="!isFormValid"
            class="px-10 h-10"
          />
        </div>
      </template>
    </HistoryDetailsModal>

    <HistoryDetailsModal
      :show="showDetails && !!details"
      @close="closeDetails"
      :title="`ملف التذكرة الفنية #${details?.id || ''}`"
      :subtitle="details?.productSerial || ''"
      icon="fas fa-shield-halved"
      maxWidth="5xl"
    >
          <div class="space-y-6">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
              <div v-for="info in [
                { l: 'العميل', v: customerName || details.customerId, c: 'text-slate-900' },
                { l: 'الأولوية', v: priorityLabel(details.priority), b: priorityClass(details.priority) },
                { l: 'الحالة الحالية', v: statusLabel(details.status), b: statusClass(details.status) },
                { l: 'الرقم التسلسلي', v: details.productSerial || '—', c: 'text-indigo-600 font-mono' }
              ]" :key="info.l" class="rounded-lg border border-slate-100 bg-slate-50/60 p-3 flex flex-col justify-center">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">{{ info.l }}</p>
                <span v-if="info.b" :class="[info.b]" class="px-2 py-0.5 rounded text-[9px] font-bold border w-fit">{{ info.v }}</span>
                <p v-else :class="[info.c, 'text-xs font-bold']">{{ info.v }}</p>
              </div>
            </div>

            <div class="relative overflow-hidden rounded-xl bg-blue-600 p-5 text-white shadow-lg">
               <div class="absolute top-0 left-0 w-24 h-24 bg-white/10 rounded-full -translate-x-6 -translate-y-6"></div>
               <div class="relative z-10 space-y-3">
                  <p class="text-[10px] font-bold text-blue-200 uppercase tracking-widest">وصف العطل المبلّغ عنه</p>
                  <p class="text-sm font-bold leading-relaxed">{{ details.issueDescription }}</p>
               </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
              <div class="lg:col-span-7 space-y-6">
                <div class="space-y-4">
                  <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">سجل التحديثات (Timeline)</h4>
                  <div class="mr-4 pr-6 border-r border-slate-100 space-y-8">
                    <div v-for="e in timelineEvents" :key="e.id" class="relative">
                      <div class="absolute -right-[27px] top-0 w-3 h-3 rounded-full border-2 border-white ring-2 ring-slate-100" :class="e.type === 'status' ? 'bg-blue-500' : 'bg-slate-300'"></div>
                      <div class="space-y-2">
                        <p class="text-[9px] font-bold text-slate-400 uppercase font-mono tracking-tighter">{{ formatDateTime(e.at) }} • {{ e.by || 'النظام' }}</p>
                        <div v-if="e.type === 'status'" class="p-3 bg-slate-50 rounded-lg border border-slate-100 text-[11px] font-bold text-slate-600">
                           تغيير الحالة: {{ statusLabel(e.from) }} <i class="fas fa-arrow-left-long mx-2 opacity-30"></i> {{ statusLabel(e.to) }}
                           <p v-if="e.note" class="mt-1.5 text-[10px] italic font-medium opacity-70">{{ e.note }}</p>
                        </div>
                        <div v-else :class="[e.isInternal ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-white text-slate-700 border-slate-100 shadow-sm']" class="p-4 rounded-xl border text-[11px] font-bold leading-relaxed relative">
                          <span v-if="e.isInternal" class="absolute left-3 top-3 text-[8px] bg-amber-500 text-white px-1.5 py-0.5 rounded font-black uppercase">داخلية</span>
                          {{ e.content }}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <section class="p-6 bg-slate-50 border border-slate-200 rounded-xl space-y-6">
                  <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5"><label class="metadata-label">تحديث الحالة</label><select v-model="statusChange.value" class="filter-input-v2 h-9 font-bold"><option value="open">مفتوح</option><option value="in_progress">قيد المعالجة</option><option value="pending_customer">بانتظار العميل</option><option value="resolved">تم الحل</option><option value="closed">مغلق</option></select></div>
                    <div class="space-y-1.5"><label class="metadata-label">ملاحظة التغيير</label><input v-model="statusChange.note" class="filter-input-v2 h-9" placeholder="اختياري..." /></div>
                  </div>
                  <LoadingButton
                    text="تطبيق تغيير الحالة"
                    :isLoading="isUpdatingStatus"
                    loadingText="جاري التحديث..."
                    variant="primary"
                    class="h-9 w-full"
                    @click="applyStatus"
                  />

                  <div class="pt-6 border-t border-slate-200 space-y-4">
                    <textarea v-model="noteContent" rows="2" class="filter-input-v2 h-auto py-3 italic" placeholder="إضافة ملاحظة فنية جديدة..."></textarea>
                    <div class="flex items-center justify-between">
                      <label class="flex items-center gap-2 cursor-pointer group"><input type="checkbox" v-model="noteInternal" class="w-4 h-4 rounded border-slate-300 text-amber-500 focus:ring-0" /><span class="text-[10px] font-bold text-slate-400 group-hover:text-slate-900">ملاحظة داخلية للموظفين</span></label>
                      <LoadingButton
                        text="إرسال الملاحظة"
                        :isLoading="isAddingNote"
                        :disabled="!noteContent.trim()"
                        loadingText="جاري الإرسال..."
                        variant="primary"
                        class="h-9 px-8"
                        @click="addNote"
                      />
                    </div>
                  </div>
                </section>
              </div>

              <div class="lg:col-span-5 space-y-6">
                <div class="space-y-4">
                   <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">القطع المشمولة</h4>
                   <div class="bg-white border border-slate-200 rounded-xl overflow-hidden divide-y divide-slate-50 shadow-sm">
                      <div v-for="it in (details.items || [])" :key="it.id" class="p-4 flex items-center justify-between hover:bg-slate-50 transition-colors">
                        <div>
                          <p class="text-xs font-bold text-slate-800">{{ productDisplayName(it.productId) }}</p>
                          <p class="text-[9px] text-slate-400 mt-1 font-medium italic">{{ it.issueNotes || 'بدون ملاحظات فنية' }}</p>
                        </div>
                        <span class="px-2 py-1 bg-slate-100 rounded font-mono font-bold text-[10px] text-slate-500">x{{ formatQty(it.quantity) }}</span>
                      </div>
                   </div>
                </div>

                <div class="space-y-4">
                   <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">المرفقات والوثائق</h4>
                   <div class="grid grid-cols-2 gap-3">
                      <div v-for="a in details.attachments" :key="a.id" class="group bg-white p-3 rounded-lg border border-slate-200 shadow-sm transition-all hover:shadow-lg">
                        <div v-if="isImage(a.filename)" @click="openLightbox(fileUrl(a.filename))" class="aspect-video rounded-md bg-slate-100 overflow-hidden cursor-zoom-in mb-3 border border-slate-50">
                           <img :src="fileUrl(a.filename)" class="w-full h-full object-cover transition-transform group-hover:scale-105" />
                        </div>
                        <div v-else class="aspect-video rounded-md bg-slate-100 flex items-center justify-center text-slate-300 mb-3"><i class="fas fa-file-pdf text-3xl"></i></div>
                        <p class="text-[9px] font-bold text-slate-600 truncate uppercase">{{ a.originalName }}</p>
                        <div class="flex justify-between mt-3 pt-2 border-t border-slate-50">
                           <a :href="fileUrl(a.filename)" target="_blank" class="text-[9px] font-bold text-blue-600 uppercase hover:underline">تحميل</a>
                           <button @click="removeAttachment(a.id)" class="text-[9px] font-bold text-rose-500 uppercase hover:underline">حذف</button>
                        </div>
                      </div>
                   </div>
                   <label class="flex flex-col items-center justify-center h-20 border-2 border-slate-100 border-dashed rounded-xl cursor-pointer bg-slate-50 hover:bg-white hover:border-blue-200 transition-all">
                      <i class="fas fa-plus-circle text-slate-300 text-lg"></i>
                      <p class="text-[9px] font-bold text-slate-400 mt-1.5 uppercase">إرفاق ملف جديد</p>
                      <input type="file" @change="onFileChange" class="hidden" />
                   </label>
                </div>
              </div>
            </div>
          </div>

      <template #footer>
        <button @click="closeDetails" class="h-9 px-8 rounded-md bg-slate-900 text-white text-[11px] font-bold uppercase transition-all tracking-widest hover:bg-black">إغلاق الملف</button>
      </template>
    </HistoryDetailsModal>

    <transition name="fade">
      <div v-if="lightboxOpen" class="fixed inset-0 z-[200] bg-slate-950/95 backdrop-blur-md flex items-center justify-center p-8" @click.self="closeLightbox">
        <img :src="lightboxSrc" class="max-w-full max-h-full object-contain rounded-lg shadow-2xl animate-modalIn" />
        <button @click="closeLightbox" class="absolute top-8 left-8 w-10 h-10 rounded-full bg-white/10 text-white flex items-center justify-center hover:bg-white/20 transition-all"><i class="fas fa-times text-lg"></i></button>
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { useSalesStore } from '@/stores/sales/salesStore';
import { useProductStore } from '@/stores/product/productStore';
import { useToast } from '@/composables/useToast';
import { useTableFilters } from '@/composables/useTableFilters';
import { useDateValidation } from '@/composables/useDateValidation';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import AlertService from '@/services/AlertService';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import { useWarrantyStore } from '@/stores/warranty/warrantyStore';

const router = useRouter();
const route = useRoute();
const warrantyStore = useWarrantyStore();
const { breadcrumb } = useBreadcrumb();
const branchStore = useBranchStore();
const { showToast } = useToast();
const { validateDateRange } = useDateValidation();

const items = computed(() => warrantyStore.warranties);
const isLoading = computed(() => warrantyStore.isLoading);
const error = computed(() => warrantyStore.error);
const branches = computed(() => branchStore.branches);
const selectedBranch = computed(() => branchStore.selectedBranchId);
const userChoseBranch = ref(localStorage.getItem('selectedBranchId') !== null && localStorage.getItem('selectedBranchId') !== 'all');
const hasExplicitBranchSelection = computed(() => userChoseBranch.value && branchStore.selectedBranchId !== null);

const search = ref('');
const statusFilter = ref('');
const priorityFilter = ref('');
const filters = useTableFilters('warranty_filters');
let warrantyListRequestId = 0;

const onBranchChange = (newBranchId) => {
  branchStore.setSelectedBranch(newBranchId);
  userChoseBranch.value = (newBranchId !== null && newBranchId !== '' && newBranchId === 'all');
  filters.page.value = 1;
  fetchList(true);
};
const dateFrom = ref('');
const dateTo = ref('');
const dateFromRef = ref(null);
const dateToRef = ref(null);
const purchaseDateRef = ref(null);

// Status tabs
const statusTabs = [
  { value: '', label: 'الكل' },
  { value: 'open', label: 'مفتوح' },
  { value: 'in_progress', label: 'قيد المعالجة' },
  { value: 'pending_customer', label: 'بانتظار العميل' },
  { value: 'resolved', label: 'تم الحل' },
  { value: 'closed', label: 'مغلق' }
];

// Create modal state
const showCreate = ref(false);
const creating = ref(false);
const form = ref({
  customerId: null,
  invoiceId: null,
  productSerial: '',
  purchaseDate: '',
  priority: 'medium',
  issueDescription: '',
  items: [{ productId: null, quantity: 1, issueNotes: '', _productQuery: '', _productResults: [], _productSearchError: '', _showProductDropdown: false, _productActiveIndex: 0, _productName: '' }]
});

// Customer autocomplete
const customerQuery = ref('');
const customerSelectionLabel = ref('');
const customerStore = useCustomerStore();
const salesStore = useSalesStore();
const customers = computed(() => customerStore.customers);
const customerSearchResults = ref([]);
const customerActiveIndex = ref(0);
const filteredCustomerResults = computed(() => {
  return Array.isArray(customerSearchResults.value)
    ? customerSearchResults.value.filter(c => c && c.id)
    : [];
});
const productStore = useProductStore();
const showCustomerDropdown = ref(false);
let customerSearchAbortCtrl = null;
const invoiceQuery = ref('');
const invoiceResults = ref([]);
const invoiceSearchLoading = ref(false);
const invoiceSearchError = ref('');
const showInvoiceDropdown = ref(false);
const invoiceActiveIndex = ref(0);
let invoiceSearchRequestId = 0;

const pendingTimeouts = new Set();

function safeTimeout(fn, delay) {
  const id = setTimeout(() => {
    pendingTimeouts.delete(id);
    fn();
  }, delay);
  pendingTimeouts.add(id);
  return id;
}

// Replaces inline setTimeout(() => showCustomerDropdown = false, 150) in template
function scheduleHideCustomerDropdown() {
  safeTimeout(() => { showCustomerDropdown.value = false; }, 150);
}

// Replaces inline setTimeout(() => it._showProductDropdown = false, 150) in template
function scheduleHideProductDropdown(item) {
  safeTimeout(() => { item._showProductDropdown = false; }, 150);
}

onUnmounted(() => {
  pendingTimeouts.forEach(id => clearTimeout(id));
  pendingTimeouts.clear();
});

// Debounce helper

function debounce(fn, delay = 300) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), delay);
  };
}

const customerName = ref('');
const productNameMap = ref({});

function formatQty(q) {
  const n = Number(q);
  if (!Number.isFinite(n)) return q;
  const r = Math.round(n);
  return Math.abs(n - r) < 1e-6 ? r : n;
}

function productDisplayName(id) {
  const name = productNameMap.value[id];
  return name ? name : `#${id}`;
}

function formatDateTime(val) {
  if (!val) return '—';
  return new Date(val).toLocaleString('en-US');
}

async function fetchCustomerNameById(id) {
  try {
    const list = await customerStore.searchCustomers(String(id));
    const exact = (list || []).find(c => String(c.id) === String(id));
    if (exact?.name) customerName.value = exact.name;
  } catch (_) { /* ignore */ }
}

const productNameCache = new Map();

async function fetchProductNameById(id) {
  if (!id) return;
  if (productNameCache.has(id)) {
    productNameMap.value[id] = productNameCache.get(id);
    return;
  }
  try {
    const result = await productStore.searchProducts({ query: String(id), limit: 1 });
    const list = result?.data || [];
    const m = list.find(p => String(p.id) === String(id)) || list[0];
    if (m?.name) {
      productNameCache.set(id, m.name);
      productNameMap.value = { ...productNameMap.value, [id]: m.name };
    }
  } catch (_) { /* ignore */ }
}

async function enrichDetailsNames() {
  const d = details.value;
  if (!d) return;
  customerName.value = d.customerName || '';
  if (!customerName.value && d.customerId) await fetchCustomerNameById(d.customerId);
  const arr = Array.isArray(d.items) ? d.items : [];
  for (const it of arr) {
    if (it.productName) {
      productNameMap.value = { ...productNameMap.value, [it.productId]: it.productName };
    } else if (it.productId) {
      fetchProductNameById(it.productId);
    }
  }
}

const searchCustomers = async (q) => {
  if (!q || q.trim().length < 2) { 
    customerSearchResults.value = [];
    return; 
  }
  try {
    if (customerSearchAbortCtrl) customerSearchAbortCtrl.abort();
    customerSearchAbortCtrl = new AbortController();
    const result = await customerStore.searchCustomers(q.trim());
    const list = result?.data;
    customerSearchResults.value = Array.isArray(list) ? list : [];
  } catch (_) { 
    customerSearchResults.value = [];
  }
};

const debouncedCustomerSearch = debounce((q) => {
  showCustomerDropdown.value = true;
  searchCustomers(q);
}, 300);

function clearSelectedInvoice() {
  form.value.invoiceId = null;
  invoiceQuery.value = '';
  invoiceResults.value = [];
  invoiceSearchError.value = '';
  showInvoiceDropdown.value = false;
  invoiceSearchRequestId += 1;
}

function handleCustomerInput(query) {
  if (form.value.customerId && query !== customerSelectionLabel.value) {
    form.value.customerId = null;
    customerSelectionLabel.value = '';
    clearSelectedInvoice();
  }
  debouncedCustomerSearch(query);
}

function clearCustomerSearch() {
  customerQuery.value = '';
  customerActiveIndex.value = 0;
  handleCustomerInput('');
  showCustomerDropdown.value = true;
}

function moveCustomerActive(direction) {
  const length = filteredCustomerResults.value.length;
  if (!length) return;
  customerActiveIndex.value = (customerActiveIndex.value + direction + length) % length;
}

function selectActiveCustomer() {
  const customer = filteredCustomerResults.value[customerActiveIndex.value];
  if (customer) selectCustomer(customer);
}

function handleInvoiceInput() {
  invoiceSearchRequestId += 1;
  invoiceActiveIndex.value = 0;
  invoiceResults.value = [];
  invoiceSearchLoading.value = false;
  if (form.value.invoiceId) {
    form.value.invoiceId = null;
  }
  errors.value.invoiceId = invoiceQuery.value.trim() ? 'اختر فاتورة بيع من النتائج أو امسح نص البحث.' : '';
  invoiceSearchError.value = '';
  showInvoiceDropdown.value = Boolean(form.value.customerId);
  debouncedInvoiceSearch(invoiceQuery.value);
}

const searchCustomerSales = async (query) => {
  const customerId = form.value.customerId;
  const requestId = ++invoiceSearchRequestId;
  if (!customerId || (query.trim().length > 0 && query.trim().length < 2)) {
    invoiceResults.value = [];
    invoiceSearchLoading.value = false;
    return;
  }

  invoiceSearchLoading.value = true;
  invoiceSearchError.value = '';
  try {
    const result = await salesStore.fetchSalesList({
      customerId,
      search: query.trim() || undefined,
      perPage: 20,
      force: true,
    });
    if (requestId !== invoiceSearchRequestId || String(customerId) !== String(form.value.customerId)) return;
    if (result.status !== 'success') {
      invoiceResults.value = [];
      invoiceSearchError.value = result.message || 'تعذر تحميل فواتير البيع.';
      return;
    }
    invoiceResults.value = result.data?.items || [];
  } catch (error) {
    if (requestId !== invoiceSearchRequestId) return;
    console.error('Warranty sale search failed:', error);
    invoiceResults.value = [];
    invoiceSearchError.value = error?.message || 'تعذر تحميل فواتير البيع.';
  } finally {
    if (requestId === invoiceSearchRequestId) {
      invoiceSearchLoading.value = false;
    }
  }
};

const debouncedInvoiceSearch = debounce((query) => {
  searchCustomerSales(String(query || ''));
}, 300);

function scheduleHideInvoiceDropdown() {
  safeTimeout(() => { showInvoiceDropdown.value = false; }, 150);
}

function selectInvoice(sale) {
  form.value.invoiceId = Number(sale.id);
  invoiceQuery.value = sale.invoiceNumber || `فاتورة #${sale.id}`;
  invoiceSearchError.value = '';
  errors.value.invoiceId = '';
  showInvoiceDropdown.value = false;
}

function moveInvoiceActive(direction) {
  const length = invoiceResults.value.length;
  if (!length) return;
  invoiceActiveIndex.value = (invoiceActiveIndex.value + direction + length) % length;
}

function selectActiveInvoice() {
  const sale = invoiceResults.value[invoiceActiveIndex.value];
  if (sale) selectInvoice(sale);
}

const selectCustomer = (c) => {
  if (!c || !c.id) {
    console.warn('selectCustomer called with invalid customer:', c);
    return;
  }
  form.value.customerId = c.id;
  customerQuery.value = c.name || c.phone || String(c.id);
  customerSelectionLabel.value = customerQuery.value;
  showCustomerDropdown.value = false;
  customerActiveIndex.value = 0;
  clearSelectedInvoice();
  validateCustomer();
};

let productSearchAbortCtrlMap = new Map();

const searchProducts = async (idx, q) => {
  const it = form.value.items[idx];
  if (!it) return;
  it._productSearchError = '';
  if (!q || q.trim().length < 2) { it._productResults = []; return; }
  try {
    const prev = productSearchAbortCtrlMap.get(idx);
    if (prev) prev.abort();
    const ctrl = new AbortController();
    productSearchAbortCtrlMap.set(idx, ctrl);
    const result = await productStore.searchProducts({ query: q.trim(), limit: 20 });
    if (result?.status !== 'success') {
      it._productResults = [];
      it._productSearchError = result?.message || 'تعذر البحث عن المنتجات.';
      return;
    }
    it._productResults = Array.isArray(result.data) ? result.data.filter(product => product?.id) : [];
  } catch (error) {
    it._productResults = [];
    it._productSearchError = error?.message || 'تعذر البحث عن المنتجات.';
    console.error('Warranty product search failed:', error);
  }
};

const debouncedProductSearch = debounce((idx, q) => {
  const it = form.value.items[idx];
  if (it) it._showProductDropdown = true;
  searchProducts(idx, q);
}, 300);

function moveProductActive(item, direction) {
  const length = item?._productResults?.length || 0;
  if (!length) return;
  item._productActiveIndex = ((item._productActiveIndex || 0) + direction + length) % length;
}

function selectActiveProduct(idx, item) {
  const product = item?._productResults?.[item._productActiveIndex || 0];
  if (product) selectProduct(idx, product);
}

const selectProduct = (idx, p) => {
  const it = form.value.items[idx];
  if (!it) return;
  it.productId = p.id;
  it._productName = p.name;
  it._productQuery = `${p.name}${p.barcode ? ' - ' + p.barcode : ''}`;
  it._showProductDropdown = false;
  it._productActiveIndex = 0;
  validateItem(idx);
};

const showDetails = ref(false);
const details = ref(null);
const detailsLoading = ref(false);
const statusChange = ref({ value: 'open', note: '' });
const noteContent = ref('');
const isUpdatingStatus = ref(false);
const isAddingNote = ref(false);
const noteInternal = ref(false);

const timelineEvents = computed(() => {
  const evts = [];
  const d = details.value || {};
  const statusHist = Array.isArray(d.statusHistory) ? d.statusHistory : [];
  for (const h of statusHist) {
    evts.push({
      type: 'status',
      id: `st_${h.id || h.createdAt || Math.random()}`,
      at: h.createdAt || h.date || h.at || null,
      by: h.createdBy || h.user || null,
      from: h.from,
      to: h.to,
      note: h.note || '',
    });
  }
  const notes = Array.isArray(d.notes) ? d.notes : [];
  for (const n of notes) {
    evts.push({
      type: 'note',
      id: `nt_${n.id || n.createdAt}`,
      at: n.createdAt || null,
      by: n.createdByName || n.createdBy || null,
      content: n.note || '',
      isInternal: !!n.isInternal,
    });
  }
  evts.sort((a, b) => new Date(b.at || 0) - new Date(a.at || 0));
  return evts;
});

const lightboxOpen = ref(false);
const lightboxSrc = ref('');

function fileUrl(fileName) { return `/api/public/uploads/${fileName}`; }
function isImage(fileName) {
  const ext = String(fileName || '').toLowerCase().split('.').pop();
  return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'].includes(ext);
}
function openLightbox(src) { lightboxSrc.value = src; lightboxOpen.value = true; }
function closeLightbox() { lightboxOpen.value = false; lightboxSrc.value = ''; }

const priorityOptions = [
  { value: '', label: 'كل الأولويات' },
  { value: 'low', label: 'منخفض' },
  { value: 'medium', label: 'متوسط' },
  { value: 'high', label: 'عالٍ' },
  { value: 'urgent', label: 'عاجل' }
];

async function fetchList(forceRefresh = false) {
  const requestId = ++warrantyListRequestId;
  // Validate date range if both dates are provided
  if (dateFrom.value && dateTo.value) {
    if (!validateDateRange(dateFrom.value, dateTo.value)) {
      // Error message is shown automatically by useDateValidation
      return;
    }
  }

  const params = {
    search: search.value || undefined,
    status: statusFilter.value || undefined,
    priority: priorityFilter.value || undefined,
    dateFrom: dateFrom.value || undefined,
    dateTo: dateTo.value || undefined,
    page: filters.page.value,
    perPage: filters.perPage.value,
    branchId: Number.isInteger(Number(selectedBranch.value)) && Number(selectedBranch.value) > 0
      ? String(selectedBranch.value)
      : undefined
  };

  const result = await warrantyStore.fetchWarranties(params, forceRefresh);
  if (requestId !== warrantyListRequestId) return;
  if (result.status === 'success') {
    filters.totalCount.value = result.total ?? result.data.length;
  } else {
    showToast(result.message || 'فشل تحميل طلبات الضمان', 'error');
  }
}

function applyFilters(forceRefresh = false) {
  if (filters.page.value !== 1) {
    filters.page.value = 1;
  }
  fetchList(forceRefresh);
}

const debouncedApplyFilters = debounce(applyFilters, 300);

function changePage(page) {
  if (page >= 1 && page <= filters.totalPages.value && page !== filters.page.value) {
    filters.page.value = page;
    fetchList();
  }
}

function resetForm() {
  form.value = {
    customerId: null, invoiceId: null, productSerial: '', purchaseDate: '',
    priority: 'medium', issueDescription: '',
    items: [{ productId: null, quantity: 1, issueNotes: '', _productQuery: '', _productResults: [], _productSearchError: '', _showProductDropdown: false, _productActiveIndex: 0, _productName: '' }]
  };
  customerQuery.value = '';
  customerSelectionLabel.value = '';
  showCustomerDropdown.value = false;
  clearSelectedInvoice();
  errors.value = { customerId: '', invoiceId: '', priority: '', issueDescription: '', items: '' };
  itemErrors.value = [];
}

function addItem() {
  form.value.items.push({ productId: null, quantity: 1, issueNotes: '', _productQuery: '', _productResults: [], _productSearchError: '', _showProductDropdown: false, _productActiveIndex: 0, _productName: '' });
}

function removeItem(idx) {
  if (form.value.items.length <= 1) return;
  form.value.items.splice(idx, 1);
}

const errors = ref({ customerId: '', invoiceId: '', priority: '', issueDescription: '', items: '' });
const itemErrors = ref([]);

function ensureItemErrorsSize() {
  while (itemErrors.value.length < form.value.items.length) itemErrors.value.push({ productId: '', quantity: '' });
  while (itemErrors.value.length > form.value.items.length) itemErrors.value.pop();
}
function validateCustomer() { errors.value.customerId = form.value.customerId ? '' : 'العميل مطلوب'; }
function validatePriority() { errors.value.priority = form.value.priority ? '' : 'الرجاء اختيار أولوية'; }
function validateIssueDescription() {
  const v = (form.value.issueDescription || '').trim();
  errors.value.issueDescription = v.length >= 5 ? '' : 'وصف المشكلة مطلوب (5 أحرف على الأقل)';
}
function validateItem(idx) {
  ensureItemErrorsSize();
  const it = form.value.items[idx];
  if (!it) return;
  const e = itemErrors.value[idx];
  e.productId = it.productId ? '' : 'اختر منتجاً';
  e.quantity = it.quantity && Number(it.quantity) >= 1 ? '' : 'يجب ألا تقل الكمية عن 1.';
}
function validateItemsSummary() {
  const hasAny = form.value.items.some(i => i.productId);
  errors.value.items = hasAny ? '' : 'أضف عنصرًا واحدًا على الأقل';
}

const isFormValid = computed(() => {
  const noFieldErrors =
    !errors.value.customerId &&
    !errors.value.invoiceId &&
    !errors.value.priority &&
    !errors.value.issueDescription &&
    !errors.value.items;
  const noItemErrors = itemErrors.value.every(e => !e.productId && !e.quantity);
  return noFieldErrors && noItemErrors;
});

// Watcher drives validation whenever form data changes (no side effects in computed)
watch(
  () => ({
    customerId: form.value.customerId,
    priority: form.value.priority,
    issueDescription: form.value.issueDescription,
    items: form.value.items.map(i => ({ productId: i.productId, quantity: i.quantity }))
  }),
  () => {
    validateCustomer();
    validatePriority();
    validateIssueDescription();
    ensureItemErrorsSize();
    form.value.items.forEach((_, idx) => validateItem(idx));
    validateItemsSummary();
  },
  { deep: true }
);

watch(() => form.value.items.length, ensureItemErrorsSize);

async function createWarranty() {
  // Run a final validation pass before submit
  validateCustomer(); validatePriority(); validateIssueDescription();
  errors.value.invoiceId = invoiceQuery.value.trim() && !form.value.invoiceId
    ? 'اختر فاتورة بيع من النتائج أو امسح نص البحث.'
    : '';
  ensureItemErrorsSize();
  form.value.items.forEach((_, idx) => validateItem(idx));
  validateItemsSummary();

  if (!isFormValid.value) {
    showToast('تحقق من الحقول المطلوبة قبل الإرسال', 'error');
    return;
  }

  creating.value = true;
  try {
    const payload = {
      customerId: form.value.customerId,
      invoiceId: form.value.invoiceId,
      productSerial: form.value.productSerial,
      purchaseDate: form.value.purchaseDate,
      priority: form.value.priority,
      issueDescription: form.value.issueDescription,
      items: form.value.items
        .filter(i => i.productId)
        .map(i => ({ productId: i.productId, quantity: i.quantity, issueNotes: i.issueNotes }))
    };
    const result = await warrantyStore.createWarranty(payload);
    if (result.status === 'success') {
      showCreate.value = false;
      resetForm();
      await fetchList();
      if (result.data?.id) openDetails(result.data.id);
    } else {
      showToast(result.message || 'فشل إنشاء الطلب', 'error');
    }
  } catch (e) {
    console.error(e);
    showToast('فشل إنشاء طلب الضمان', 'error');
  } finally {
    creating.value = false;
  }
}

async function openDetails(id) {
  detailsLoading.value = true;
  showDetails.value = true;
  try {
    const result = await warrantyStore.fetchWarrantyDetails(id);
    if (result.status === 'success') {
      details.value = result.data;
      statusChange.value = { value: result.data?.status || 'open', note: '' };
      await enrichDetailsNames();
      const q = new URLSearchParams({ ...(route.query || {}), id: String(id) });
      router.replace({ path: '/warranty', query: Object.fromEntries(q.entries()) });
    } else {
      showToast(result.message || 'فشل تحميل التفاصيل', 'error');
    }
  } finally {
    detailsLoading.value = false;
  }
}

function closeDetails() {
  showDetails.value = false;
  details.value = null;
  customerName.value = '';
  const q = { ...(route.query || {}) };
  delete q.id;
  router.replace({ path: '/warranty', query: q });
}

watch(() => route.query.id, (id) => {
  if (id && String(details.value?.id) !== String(id)) {
    openDetails(id);
    return;
  }
  if (!id && showDetails.value) {
    showDetails.value = false;
    details.value = null;
    customerName.value = '';
  }
});

async function applyStatus() {
  if (!details.value || isUpdatingStatus.value) return;
  isUpdatingStatus.value = true;
  try {
    const result = await warrantyStore.updateWarrantyStatus(details.value.id, statusChange.value.value, statusChange.value.note);
    if (result.status === 'success') {
      statusChange.value.note = '';
      details.value = result.data;
      await enrichDetailsNames();
      await fetchList();
    } else {
      showToast(result.message || 'تعذر تغيير الحالة', 'error');
    }
  } finally {
    isUpdatingStatus.value = false;
  }
}

async function addNote() {
  if (!details.value || !noteContent.value.trim() || isAddingNote.value) return;
  isAddingNote.value = true;
  try {
    const result = await warrantyStore.addNote(details.value.id, noteContent.value, noteInternal.value);
    if (result.status === 'success') {
      noteContent.value = '';
      noteInternal.value = false;
      details.value = result.data;
      await enrichDetailsNames();
    } else {
      showToast(result.message || 'تعذر إضافة الملاحظة', 'error');
    }
  } finally {
    isAddingNote.value = false;
  }
}

async function onFileChange(e) {
  if (!details.value) return;
  const file = e.target.files?.[0];
  if (!file) return;
  const result = await warrantyStore.uploadAttachment(details.value.id, file);
  if (result.status === 'success') {
    details.value = result.data;
    await enrichDetailsNames();
  } else {
    showToast(result.message || 'تعذر رفع المرفق', 'error');
  }
  e.target.value = '';
}

async function removeAttachment(attId) {
  if (!details.value) return;
  const confirmed = await AlertService.confirm('هل تريد حذف هذا المرفق؟', 'حذف المرفق');
  if (!confirmed) return;
  const result = await warrantyStore.deleteAttachment(details.value.id, attId);
  if (result.status === 'success') {
    details.value = result.data;
    await enrichDetailsNames();
    showToast('تم حذف المرفق بنجاح', 'success');
  } else {
    showToast(result.message || 'تعذر حذف المرفق', 'error');
  }
}

const pageItems = items;

onMounted(async () => {
  const hadPriorBranchChoice = localStorage.getItem('selectedBranchId') !== null
      && localStorage.getItem('selectedBranchId') !== 'all';
  branchStore.loadFromStorage();
  if (!branchStore.branches || branchStore.branches.length === 0) {
    await branchStore.fetchBranches();
  }

  userChoseBranch.value = hadPriorBranchChoice;

  await fetchList();
  const id = route.query?.id;
  if (id) openDetails(id);
});

watch([search, statusFilter, priorityFilter], () => {
  debouncedApplyFilters();
});

function statusLabel(s) {
  return ({ open: 'مفتوح', inProgress: 'قيد المعالجة', pendingCustomer: 'بانتظار العميل', resolved: 'تم الحل', closed: 'مغلق' }[s] || s || '-');
}
function statusClass(s) {
  return ({ open: 'bg-blue-50 text-blue-700', inProgress: 'bg-yellow-50 text-yellow-800', pendingCustomer: 'bg-amber-50 text-amber-800', resolved: 'bg-green-50 text-green-800', closed: 'bg-gray-100 text-gray-800' }[s] || 'bg-gray-100 text-gray-800');
}
function priorityLabel(p) {
  return ({ low: 'منخفض', medium: 'متوسط', high: 'عالٍ', urgent: 'عاجل' }[p] || p || '-');
}
function priorityClass(p) {
  return ({ low: 'bg-gray-100 text-gray-700', medium: 'bg-blue-50 text-blue-700', high: 'bg-orange-50 text-orange-700', urgent: 'bg-red-50 text-red-700' }[p] || 'bg-gray-100 text-gray-700');
}
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v3 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.status-badge { @apply px-2 py-0.5 rounded text-[9px] font-bold border inline-flex items-center justify-center; }

.dropdown-list { @apply absolute z-50 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-xl max-h-48 overflow-auto py-1; }
.dropdown-item { @apply px-4 py-2 hover:bg-blue-50 cursor-pointer flex justify-between items-center transition-colors border-b border-slate-50 last:border-0; }

.modal-overlay { /* removed - using BaseModal */ }

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.dropdown-enter-active { animation: dropdownIn 0.2s ease-out; }
@keyframes dropdownIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
</style>