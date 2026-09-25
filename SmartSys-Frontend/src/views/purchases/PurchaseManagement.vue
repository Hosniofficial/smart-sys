
<template>

  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-indigo-100" dir="rtl">

    <!-- Top Progress Bar -->

    <div v-if="isLoading || isLoadingSearch" class="fixed top-0 left-0 right-0 h-0.5 bg-indigo-600/10 z-[110]">

      <div class="h-full bg-indigo-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>

    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header Area -->

      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة المشتريات"
        description="متابعة فواتير التوريد، تسوية حسابات الموردين، وإدارة تدفق المخزون."
        :branches="isExempt ? branches : []"
        :selectedBranch="branchStore.selectedBranchId"
        @branch-changed="(val) => { branchStore.setSelectedBranch(val); selectedBranch.value = branchStore.selectedBranchId; handleBranchChange(); }"
      >
        <template #controls>
          <button @click="openAddModal" class="h-9 px-6 rounded-md bg-indigo-600 text-white text-xs font-bold shadow-lg shadow-indigo-900/20 hover:bg-indigo-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-plus-circle text-[10px]"></i>
            إضافة فاتورة شراء
          </button>
        </template>
      </PageHeader>

      <!-- KPI Summary Overview -->

      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">

        <div v-for="kpi in [

          { label: 'إجمالي الفواتير', val: stats.totalPurchases, icon: 'fa-file-invoice', color: 'text-blue-600', bg: 'bg-blue-50' },

          { label: 'إجمالي المبالغ', val: formatCurrency(stats.totalAmount), icon: 'fa-money-bill-wave', color: 'text-emerald-600', bg: 'bg-emerald-50' },

          { label: 'الفواتير المعلقة', val: stats.pendingInvoices, icon: 'fa-clock', color: 'text-amber-600', bg: 'bg-amber-50' },

          { label: 'الموردون النشطون', val: stats.activeSuppliers, icon: 'fa-truck', color: 'text-indigo-600', bg: 'bg-indigo-50' }

        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">

          <div>

            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>

            <p class="text-xl font-bold text-slate-900">{{ kpi.val }}</p>

          </div>

          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">

            <i :class="['fas', kpi.icon]"></i>

          </div>

        </div>

      </section>

      <!-- Filters & Search Toolbar -->

      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">

        <div class="p-6 bg-slate-50/30 border-b border-slate-100 space-y-6">

          <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">

            <!-- Search -->

            <div class="lg:col-span-4 space-y-1.5 relative group">

              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">البحث عن فاتورة</label>

              <div class="relative">

                <input ref="searchInputRef" v-model="search" type="text" class="filter-input-v2" style="padding-right: 2rem;" placeholder="رقم الفاتورة أو المورد..." @focus="showSearchDropdown = true" @blur="handleSearchBlur" />

                <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>

                <Teleport to="body">

                  <transition name="dropdown">

                    <div v-if="showSearchDropdown && search.length > 0" ref="searchDropdownRef" class="fixed bg-white border border-slate-200 rounded-lg shadow-2xl overflow-hidden z-[99999]" :style="searchDropdownPosition">

                      <div v-if="isLoadingSearch" class="p-6 text-center">
                        <div class="flex items-center justify-center gap-1">
                          <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
                          <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
                          <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
                        </div>
                      </div>

                      <template v-else>

                        <div v-for="result in searchResults.slice(0, 10)" :key="result.id" @click="selectSearchResult(result)" class="px-4 py-3 hover:bg-slate-50 cursor-pointer border-b border-slate-50 flex items-center justify-between group">

                          <div>

                            <p class="text-xs font-bold text-slate-900">{{ result.invoice_number }}</p>

                            <p class="text-[10px] text-slate-400">{{ formatDate(result.purchase_date) }}</p>

                          </div>

                          <p class="text-xs font-bold text-indigo-600">{{ formatCurrency(result.total_amount || 0) }}</p>

                        </div>

                      </template>

                    </div>

                  </transition>

                </Teleport>

              </div>

            </div>

            <!-- Supplier -->

            <div class="lg:col-span-3 space-y-1.5">

              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">المورد</label>

              <select v-model="selectedSupplier" @change="() => { currentPage = 1; loadPurchases(); }" class="filter-input-v2 appearance-none font-bold">

                <option value="">كل الموردين</option>

                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>

              </select>

            </div>

            <div class="lg:col-span-2">

              <button @click="loadPurchases" class="h-9 w-full rounded-md border border-slate-200 bg-white text-slate-400 hover:text-indigo-600 transition-colors shadow-sm">

                <i class="fas fa-sync-alt text-xs"></i>

              </button>

            </div>

          </div>

          <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end pt-4 border-t border-slate-100">

             <div class="lg:col-span-3 space-y-1.5">

              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">من تاريخ</label>

              <div class="relative">
                <input ref="dateFromRef" type="date" v-model="dateFrom" class="filter-input-v2" style="padding-left: 2rem;" />
                <i @click="dateFromRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>

            </div>

            <div class="lg:col-span-3 space-y-1.5">

              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">إلى تاريخ</label>

              <div class="relative">
                <input ref="dateToRef" type="date" v-model="dateTo" class="filter-input-v2" style="padding-left: 2rem;" />
                <i @click="dateToRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>

            </div>



          </div>

        </div>

        <!-- Purchases Table -->

        <div class="overflow-x-auto">

          <table class="w-full text-right border-collapse">

            <thead>

              <tr class="bg-slate-50 border-b border-slate-200">

                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">رقم الفاتورة</th>

                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المورّد</th>

                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفرع</th>

                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">التاريخ</th>

                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الإجمالي</th>

                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>

                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>

              </tr>

            </thead>

            <tbody class="divide-y divide-slate-100">

              <template v-if="isLoading">

                <tr v-for="n in 5" :key="n" class="animate-pulse">

                  <td v-for="m in 7" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>

                </tr>

              </template>

              <tr v-else-if="purchases.length === 0">

                <td colspan="7" class="py-20 text-center text-slate-300">

                   <i class="fas fa-box-open text-3xl mb-4 opacity-20"></i>

                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد فواتير مشتريات</p>

                </td>

              </tr>

              <tr v-for="p in purchases" :key="p.id" class="hover:bg-indigo-50/10 transition-all group">

                <td class="px-6 py-4 text-xs font-bold text-slate-900 font-mono tracking-wider">{{ p.invoice_number }}</td>

                <td class="px-4 py-4">

                  <div class="flex items-center gap-3">

                    <div class="w-8 h-8 rounded bg-slate-100 flex items-center justify-center text-slate-400 group-hover:text-indigo-600 group-hover:bg-indigo-50 transition-all"><i class="fas fa-truck-loading text-[10px]"></i></div>

                    <span class="text-xs font-bold text-slate-700 truncate max-w-[150px]">{{ suppliers.find(s=>s.id===p.supplier_id)?.name || '-' }}</span>

                  </div>

                </td>

                <td class="px-4 py-4 text-[10px] font-bold text-slate-500 uppercase">{{ p.branch_name || branches.find(b => String(b.id) === String(p.branch_id))?.name || '-' }}</td>

                <td class="px-4 py-4 text-center text-[10px] font-mono text-slate-400">{{ p.purchase_date ? new Date(p.purchase_date).toLocaleDateString('en-US') : '-' }}</td>

                <td class="px-4 py-4 text-xs font-bold text-emerald-600 font-mono">{{ formatCurrency(p.total_amount) }}</td>

                <td class="px-4 py-4 text-center">

                  <span :class="[getPurchaseStatus(p.dynamic_status || p.status).class]" class="px-2 py-0.5 rounded text-[9px] font-bold border">

                    {{ getPurchaseStatus(p.dynamic_status || p.status).text }}

                  </span>

                </td>

                <td class="px-6 py-4 text-center">

                  <div class="flex items-center justify-center gap-2">

                    <button @click="openEditModal(p)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center"><i class="fas fa-pen text-[10px]"></i></button>

                    <button @click="confirmDelete(p.id)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-all flex items-center justify-center"><i class="fas fa-trash-alt text-[10px]"></i></button>

                  </div>

                </td>

              </tr>

            </tbody>

          </table>

        </div>

        <!-- Pagination -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ currentPage }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ totalCount }}</span> فاتورة
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
               <select v-model.number="pageSize" @change="currentPage = 1; loadPurchases();" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                 <option :value="10">10</option>
                 <option :value="20">20</option>
                 <option :value="50">50</option>
               </select>
             </div>
             <div class="flex items-center gap-1">
               <button @click="previousPage()" :disabled="currentPage <= 1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button>
               <button @click="nextPage(totalPages)" :disabled="currentPage >= totalPages" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button>
             </div>
          </div>
        </div>

      </div>

    </div>

    <!-- The Refactored Purchase Form Modal -->

    <PurchaseForm

      :show="showFormModal"

      :edit-mode="isEditMode"

      :initial-data="form"

      :suppliers="suppliers"

      :products="products"

      :branches="branches"

      :payment-methods="paymentMethods"

      :tax-settings="{ enabled: taxEnabled, rate: taxRate, value: taxValue }"

      :is-saving="isSaving"

      :is-exempt="isExempt"

      :pending-supplier-id="pendingSupplierId"

      :pending-product-add="pendingProductAdd"

      @close="showFormModal = false"

      @save="handleModalSave"

      @open-supplier-modal="showSupplierModal = true"

      @open-product-modal="(idx) => { productRowIndex = idx; showProductModal = true }"

    />

    <!-- Add Supplier Modal -->
    <BaseModal :show="showSupplierModal" @close="showSupplierModal = false" maxWidth="md" variant="modern" :zIndex="130">
      <template #header>
        <h3 class="text-xl font-bold text-slate-900 flex items-center gap-2">
          <i class="fas fa-truck text-indigo-500"></i> 
          إضافة مورد جديد
        </h3>
      </template>
      
      <div class="space-y-4">
        <div class="space-y-1.5">
          <label class="modal-label">اسم المورد</label>
          <input v-model="newSupplier.name" type="text" class="form-input-modern font-bold" />
        </div>
        <div class="space-y-1.5">
          <label class="modal-label">رقم الهاتف</label>
          <input v-model="newSupplier.phone" type="text" class="form-input-modern font-mono" />
        </div>
        <div class="space-y-1.5">
          <label class="modal-label">البريد</label>
          <input v-model="newSupplier.email" type="email" class="form-input-modern font-bold" />
        </div>
      </div>
      
      <template #footer>
        <button @click="showSupplierModal = false" class="flex-1 py-3 rounded-xl border-2 border-slate-200 font-bold text-slate-600 text-xs hover:bg-slate-50 transition-all">
          إلغاء
        </button>
        <button @click="saveNewSupplier" :disabled="isAddingSupplier" class="flex-[2] py-3 rounded-xl bg-slate-900 text-white font-bold text-xs shadow-xl active:scale-95 disabled:opacity-50">
          حفظ المورد
        </button>
      </template>
    </BaseModal>

    <!-- Add Product Modal -->
    <BaseModal :show="showProductModal" @close="showProductModal = false" maxWidth="lg" variant="modern" :zIndex="130">
      <template #header>
        <h3 class="text-xl font-bold text-slate-900 flex items-center gap-2">
          <i class="fas fa-cube text-blue-500"></i> 
          إضافة منتج سريع للمخزن
        </h3>
      </template>
      
      <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2 space-y-1.5">
          <label class="modal-label">اسم المنتج</label>
          <input v-model="newProduct.name" type="text" class="form-input-modern font-bold" />
        </div>
        <div class="space-y-1.5">
          <label class="modal-label">سعر الشراء</label>
          <input v-model.number="newProduct.purchase_price" type="number" class="form-input-modern font-bold text-emerald-600" />
        </div>
        <div class="space-y-1.5">
          <label class="modal-label">سعر البيع</label>
          <input v-model.number="newProduct.sale_price" type="number" class="form-input-modern font-bold text-blue-600" />
        </div>
      </div>
      
      <template #footer>
        <button @click="showProductModal = false" class="flex-1 py-3 rounded-xl border-2 border-slate-200 font-bold text-slate-600 text-xs hover:bg-slate-50 transition-all">
          إلغاء
        </button>
        <button @click="saveNewProduct" :disabled="isAddingProduct" class="flex-[2] py-3 rounded-xl bg-blue-600 text-white font-bold text-xs shadow-xl active:scale-95 disabled:opacity-50">
          إضافة للمخزن
        </button>
      </template>
    </BaseModal>

  </div>

</template>

<script setup>

import { ref, onMounted, computed, watch } from 'vue'
import { useToast } from '@/composables/useToast'

import { useCompanyCurrency } from '@/composables/useCompanyCurrency'

import { useTableFilters } from '@/composables/useTableFilters'

import { useSearchDropdown } from '@/composables/useSearchDropdown'

import { useBreadcrumb } from '@/composables/useBreadcrumb'

import PurchaseForm from '@/components/PurchaseForm.vue'

import LoadingButton from '@/components/ui/LoadingButton.vue';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';

import PageHeader from '@/components/PageHeader.vue';

import BaseModal from '@/components/BaseModal.vue';

import { getLocalDateISO } from '@/utils/date'

import { useSessionExemption } from '@/composables/useCashierSessionGuard'

import { useAuthStore } from '@/stores/auth'

import { useBranchStore } from '@/stores/branch'

import { useProductStore } from '@/stores/product/productStore'

import { usePaymentStore } from '@/stores/payment/paymentStore'

import { useSessionStore } from '@/stores/session/sessionStore'

import { usePurchaseStore } from '@/stores/purchase/purchaseStore'

import { useSupplierStore } from '@/stores/supplier/supplierStore'

import { useSettingsStore } from '@/stores/settings/settingsStore'

import { useBootstrapStore } from '@/stores/bootstrap'

import AlertService from '@/services/AlertService'

const { showToast } = useToast()

const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency()

const { breadcrumb } = useBreadcrumb()

const authStore = useAuthStore()

const branchStore = useBranchStore()

const productStore = useProductStore()

const paymentStore = usePaymentStore()

const sessionStore = useSessionStore()

const purchaseStore = usePurchaseStore()

const supplierStore = useSupplierStore()

const settingsStore = useSettingsStore()

const bootstrapStore = useBootstrapStore()

const { isExempt, ensureLoaded: ensureExemptionLoaded } = useSessionExemption()

const {

  page: currentPage, perPage: pageSize, totalCount, dateFrom, dateTo, dateFromRef, dateToRef,

  selectedBranch, branchId, handleBranchChange, previousPage, nextPage, loadFromLocalStorage

} = useTableFilters('purchases_filters', { initialPageSize: 20, onFilterChange: () => loadPurchases() })

const {

  search, searchResults, isLoadingSearch, showSearchDropdown, searchInputRef,

  searchDropdownPosition, handleSearchBlur, selectSearchResult

} = useSearchDropdown({

  onSearch: (query) => performSearch(query),

  onSelectResult: (result) => handleSearchSelect(result),

  onClear: () => handleSearchClear(),

})

const purchases = ref([]); const suppliers = ref([]); const products = ref([]);

const branches = computed(() => branchStore.branches);

const stats = ref({ totalPurchases: 0, totalAmount: 0, pendingInvoices: 0, activeSuppliers: 0 });

const isLoading = ref(true); const selectedSupplier = ref('');

const isSaving = ref(false); const isEditMode = ref(false);

const showFormModal = ref(false);

const paymentMethods = ref([]);

const form = ref({ supplier_id: '', branch_id: '', invoice_number: '', purchase_date: getLocalDateISO(), status: 'draft', discount: 0, discount_type: 'fixed', items: [], paid_amount: 0 });

const taxEnabled = ref(false); const taxRate = ref(0); const taxValue = ref(0);

const showSupplierModal = ref(false); const isAddingSupplier = ref(false);

const newSupplier = ref({ name: '', phone: '', email: '', address: '' });

const showProductModal = ref(false); const isAddingProduct = ref(false);

const newProduct = ref({ name: '', barcode: '', category_id: '', purchase_price: 0, sale_price: 0, min_quantity: 0, description: '' });

const productRowIndex = ref(null);

const pendingSupplierId = ref(null);

const pendingProductAdd = ref(null); // { index, product }

const totalPages = computed(() => Math.ceil((totalCount.value || 1) / pageSize.value))

const cashMethodId = computed(() => paymentMethods.value.find(pm => String(pm.kind || '').toLowerCase() === 'cash')?.id || null);

const loadPurchases = async () => {

  isLoading.value = true;

  try {

    if (suppliers.value.length === 0 || products.value.length === 0) {

      const data = await bootstrapStore.fetchManagementData('purchase');

      if (data.branches) branchStore.branches = data.branches;

      if (data.paymentMethods) paymentMethods.value = data.paymentMethods;

      if (data.suppliers) suppliers.value = data.suppliers;

      await productStore.fetchProducts({ force: true }).then(res => { if (res?.status === 'success') products.value = res.data || []; });

    }

    const response = await purchaseStore.fetchPurchases({

      branchId: branchId.value || undefined,

      page: currentPage.value, perPage: pageSize.value,

      search: search.value || undefined,

      supplier_id: selectedSupplier.value || undefined,

      dateFrom: dateFrom.value || undefined, dateTo: dateTo.value || undefined

    });

    if (response?.status === 'success') {

      const resData = response.data;

      purchases.value = Array.isArray(resData) ? resData : (resData?.items || []);

      totalCount.value = resData?.total || purchases.value.length;

      stats.value = {

        totalPurchases: totalCount.value,

        totalAmount: purchases.value.reduce((s, p) => s + parseFloat(p.total_amount || 0), 0),

        pendingInvoices: purchases.value.filter(p => p.status === 'pending').length,

        activeSuppliers: new Set(purchases.value.map(p => p.supplier_id)).size

      };

    }

  } catch (error) { purchases.value = []; totalCount.value = 0; }

  finally { isLoading.value = false; }

};

const performSearch = async (query) => {

  if (!query.trim()) { searchResults.value = []; return; }

  isLoadingSearch.value = true;

  try {

    const response = await purchaseStore.fetchPurchases({ branchId: branchId.value || undefined, search: query.trim(), perPage: 50 });

    if (response?.status === 'success') {

      searchResults.value = Array.isArray(response.data) ? response.data : (response.data?.items || []);

    }

  } catch { searchResults.value = []; } finally { isLoadingSearch.value = false; }

};

const handleSearchSelect = (result) => {

  search.value = result.invoice_number || 'INV-' + result.id;

  showSearchDropdown.value = false;

  purchases.value = [result];

  totalCount.value = 1;

};

const handleSearchClear = () => { search.value = ''; searchResults.value = []; currentPage.value = 1; loadPurchases(); };

watch([selectedSupplier], () => { currentPage.value = 1; loadPurchases(); });

const openAddModal = async () => {

  isEditMode.value = false;

  let nextInv = ''; try { const res = await purchaseStore.getNextInvoiceNumber(); if (res.status === 'success') nextInv = res.data?.invoice_number || ''; } catch {}

  // #4: إعادة تحميل إعدادات الضريبة في كل مرة يُفتح فيها Modal الإضافة
  try {
    const settings = await fetchSettings();
    if (settings) {
      taxEnabled.value = settings['company.tax_enabled'] === '1';
      taxRate.value = parseFloat(settings['company.tax_rate']) || 0;
      taxValue.value = taxEnabled.value ? taxRate.value : 0;
    }
  } catch (_) {}

  form.value = { supplier_id: '', branch_id: branchStore.selectedBranchId || branches.value[0]?.id || '', invoice_number: nextInv, purchase_date: getLocalDateISO(), status: 'received', discount: 0, discount_type: 'fixed', items: [], paid_amount: 0, payment_method_id: cashMethodId.value };

  showFormModal.value = true;

}

const openEditModal = (p) => { isEditMode.value = true; form.value = { discount: p.discount_value || 0, discount_type: p.discount_type || 'fixed', ...p, items: Array.isArray(p.items) ? p.items : [], payment_method_id: p.payment_method_id ?? null }; showFormModal.value = true; };

const getDeviceIdentity = () => {

  let id = localStorage.getItem('pos_device_id');

  if (!id) { id = 'dev-' + Math.random().toString(36).slice(2, 8); try { localStorage.setItem('pos_device_id', id); } catch {} }

  return { device_id: id };

};

const handleModalSave = async (payload) => {

  const isCash = paymentMethods.value.find(m => Number(m.id) === Number(payload.payment_method_id))?.kind === 'cash';

  const paidAmt = parseFloat(payload.paid_amount || 0);

  try { await ensureExemptionLoaded(); } catch (_) {}

  if (!isExempt.value && isCash && paidAmt > 0) {

    try {

      const result = await sessionStore.getCurrentSession(String(payload.branch_id), authStore?.user?.id || null, getDeviceIdentity().device_id);

      if (result?.status !== 'success' || !result.data?.id) {

        showToast('لا توجد جلسة كاشير مفتوحة. لا يمكن إتمام المدفوعات النقدية بدون جلسة.', 'error');

        return;

      }

    } catch (error) {

      showToast('فشل في التحقق من جلسة الكاشير', 'error');

      return;

    }

  }

  await savePurchase(payload);

};

const savePurchase = async (payload) => {

  isSaving.value = true;

  try {

    const response = isEditMode.value ? await purchaseStore.updatePurchase(form.value.id, payload) : await purchaseStore.createPurchase(payload);

    if (response.status === 'success') {

      showToast('تم الحفظ بنجاح'); showFormModal.value = false; await loadPurchases();

      productStore.invalidateCacheForBranch(payload.branch_id);

    } else { showToast(response.message || 'فشل الحفظ', 'error'); }

  } catch (e) { showToast(e.response?.data?.message || 'فشل الحفظ', 'error'); }

  finally { isSaving.value = false; }

}

const confirmDelete = (id) => AlertService.confirm('هل أنت متأكد من حذف هذه الفاتورة؟') && deletePurchase(id);

const deletePurchase = async (id) => {

  try {

    const res = await purchaseStore.deletePurchase(id);

    if (res.status === 'success') { showToast('تم الحذف'); await loadPurchases(); productStore.invalidateCache(); }

  } catch { showToast('خطأ في الحذف', 'error'); }

};

const saveNewSupplier = async () => {

  if (!newSupplier.value.name.trim()) { showToast('ادخل اسم المورّد', 'error'); return; }

  isAddingSupplier.value = true;

  try {

    const response = await supplierStore.createSupplier(newSupplier.value);

    if (response.status === 'success') {

      const data = response.data;

      suppliers.value.push(data);

      pendingSupplierId.value = data.id; // المودال الفرعي بيراقب القيمة دي ويطبقها لو الحقل فاضي

      showSupplierModal.value = false;

      newSupplier.value = { name: '', phone: '', email: '', address: '' };

      showToast('تمت إضافة المورد بنجاح', 'success');

    } else {

      showToast(response.message || 'حدث خطأ أثناء إضافة المورد', 'error');

    }

  } catch (error) {

    showToast('حدث خطأ أثناء إضافة المورد', 'error');

  } finally {

    isAddingSupplier.value = false;

  }

};

const saveNewProduct = async () => {

  if (!newProduct.value.name.trim()) { showToast('ادخل اسم المنتج', 'error'); return; }

  isAddingProduct.value = true;

  try {

    const response = await productStore.createProduct(newProduct.value);

    if (response.status === 'success') {

      const data = response.data;

      if (!products.value.find(p => p.id === data.id)) products.value.push(data);

      if (productRowIndex.value !== null) {

        pendingProductAdd.value = { index: productRowIndex.value, product: data };

      }

      showProductModal.value = false;

      newProduct.value = { name: '', barcode: '', category_id: '', purchase_price: 0, sale_price: 0, min_quantity: 0, description: '' };

      productRowIndex.value = null;

      showToast('تمت إضافة المنتج بنجاح', 'success');

    } else {

      showToast(response.message || 'حدث خطأ أثناء إضافة المنتج', 'error');

    }

  } catch (error) {

    showToast('حدث خطأ أثناء إضافة المنتج', 'error');

  } finally {

    isAddingProduct.value = false;

  }

};

const getPurchaseStatus = (s) => {

  const map = { draft: { text: 'مسودة', class: 'bg-slate-50 text-slate-400 border-slate-100' }, pending: { text: 'قيد الانتظار', class: 'bg-amber-50 text-amber-600 border-amber-100' }, approved: { text: 'معتمدة', class: 'bg-blue-50 text-blue-600 border-blue-100' }, received: { text: 'مستلمة', class: 'bg-indigo-50 text-indigo-600 border-indigo-100' }, completed: { text: 'مكتملة', class: 'bg-emerald-50 text-emerald-600 border-emerald-100' }, partial: { text: 'جزئي', class: 'bg-amber-50 text-amber-600 border-amber-100' }, cancelled: { text: 'ملغاة', class: 'bg-rose-50 text-rose-600 border-rose-100' } };

  return map[s] || { text: s, class: 'bg-slate-50' };

};

const formatCurrency = (v) => formatCurrencyLocale(v, 2);

const formatDate = (date) => date ? new Date(date).toLocaleDateString('en-US', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '';

onMounted(async () => {

  await branchStore.initialize();

  try { await ensureExemptionLoaded(); } catch (_) {}

  loadFromLocalStorage();

  const settings = await fetchSettings();

  if (settings) {

    taxEnabled.value = settings['company.tax_enabled'] === '1';

    taxRate.value = parseFloat(settings['company.tax_rate']) || 0;

    taxValue.value = taxEnabled.value ? taxRate.value : 0;

  }

  if (!isExempt.value && authStore.user?.branch_id) branchStore.setSelectedBranch(authStore.user.branch_id);

  loadPurchases();

});

</script>

<style scoped>

@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 { @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 outline-none transition-all; }

.pagination-btn-v2 { @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all; }

.custom-scroll::-webkit-scrollbar { width: 5px; }

.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }

@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.modal-label { @apply block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 px-1; }

.form-input-modern { @apply w-full h-12 bg-white border border-slate-200 rounded-2xl px-4 outline-none transition-all duration-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50 shadow-sm font-bold text-sm; }

</style>