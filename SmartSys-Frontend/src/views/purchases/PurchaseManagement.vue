
<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-indigo-100" dir="rtl">

    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoading || isLoadingSearch" />
    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header Area -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة المشتريات"
        description="تابع فواتير المشتريات، وأدر حسابات الموردين، وراجع حركة الأصناف الواردة إلى المخزون."
        :branches="isExempt ? branches : []"
        :selectedBranch="branchStore.selectedBranchId"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <button v-if="canCreatePurchase" @click="openAddModal" class="h-9 px-6 rounded-md bg-indigo-600 text-white text-xs font-bold shadow-lg shadow-indigo-900/20 hover:bg-indigo-700 transition-all active:scale-95 flex items-center gap-2">
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
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">بحث سريع</label>
              <div class="relative">
                <input ref="searchInputRef" v-model="search" type="text" class="filter-input-v2 h-9" style="padding-right: 2rem;" placeholder="رقم الفاتورة أو المورد..." @focus="showSearchDropdown = true" @blur="handleSearchBlur" />
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
                            <p class="text-[10px] text-slate-400">{{ formatDateTime(result.invoice_date || result.purchase_date || result.created_at) }}</p>
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
              <select v-model="selectedSupplier" @change="() => { currentPage = 1; loadPurchases(); }" class="filter-input-v2 h-9 appearance-none font-bold">
                <option value="">كل الموردين</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
            </div>

            <div class="lg:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">من تاريخ</label>
              <div class="relative">
                <input ref="dateFromRef" type="date" v-model="dateFrom" class="filter-input-v2 h-9" style="padding-left: 2rem;" />
                <i @click="dateFromRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>
            </div>
            <div class="lg:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">إلى تاريخ</label>
              <div class="relative">
                <input ref="dateToRef" type="date" v-model="dateTo" class="filter-input-v2 h-9" style="padding-left: 2rem;" />
                <i @click="dateToRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>
            </div>
            <div class="lg:col-span-1">
              <LoadingButton
                text=""
                loadingText=""
                :showTextDuringLoad="false"
                icon="fas fa-sync-alt"
                :isLoading="isLoading"
                variant="secondary"
                class="h-9 w-full rounded-md border border-slate-200 bg-white p-0 text-slate-400 shadow-sm hover:text-indigo-600"
                aria-label="تحديث المشتريات"
                @click="loadPurchases"
              />
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
                  <span class="text-xs font-bold text-slate-700 truncate max-w-[180px]">{{ p.supplier_name || suppliers.find(s => String(s.id) === String(p.supplier_id))?.name || '-' }}</span>
                </td>
                <td class="px-4 py-4 text-[10px] font-bold text-slate-500 uppercase">{{ p.branch_name || branches.find(b => String(b.id) === String(p.branch_id))?.name || '-' }}</td>
                <td class="px-4 py-4 text-center text-[10px] font-mono text-slate-500">{{ formatDateTime(p.invoice_date || p.purchase_date || p.created_at) || '-' }}</td>
                <td class="px-4 py-4 text-xs font-bold text-emerald-600 font-mono">{{ formatCurrency(p.total_amount) }}</td>
                <td class="px-4 py-4 text-center">
                  <span :class="[getPurchaseStatus(p.dynamic_status || p.status).class]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ getPurchaseStatus(p.dynamic_status || p.status).text }}
                  </span>
                </td>
                <td class="px-6 py-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <button @click="openPurchaseDetails(p)" title="تفاصيل الفاتورة في تبويب" aria-label="تفاصيل الفاتورة في تبويب" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-indigo-600 hover:border-indigo-200 transition-all flex items-center justify-center"><i class="fas fa-eye text-[10px]"></i></button>
                    <button v-if="canEditPurchase && !['cancelled', 'canceled', 'rejected'].includes(p.dynamic_status || p.status) && !['posted', 'reversed'].includes(p.journal_status)" @click="openEditModal(p)" title="تعديل الفاتورة" aria-label="تعديل الفاتورة" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center"><i class="fas fa-pen text-[10px]"></i></button>
                    <button v-if="canDeletePurchase && !['cancelled', 'canceled', 'rejected'].includes(p.dynamic_status || p.status)" :title="Number(p.linked_payment_count || 0) > 0 ? 'الفاتورة مرتبطة بمدفوعات ولا يمكن إلغاؤها.' : (Number(p.linked_return_count || 0) > 0 ? 'الفاتورة مرتبطة بمرتجعات ولا يمكن إلغاؤها.' : 'إلغاء الفاتورة')" @click="confirmDelete(p)" class="w-8 h-8 rounded-lg border border-slate-200 transition-all flex items-center justify-center" :class="Number(p.linked_payment_count || 0) > 0 || Number(p.linked_return_count || 0) > 0 ? 'text-amber-600 border-amber-200 hover:bg-amber-50' : 'text-slate-400 hover:text-rose-600 hover:border-rose-200'"><i :class="Number(p.linked_payment_count || 0) > 0 || Number(p.linked_return_count || 0) > 0 ? 'fas fa-lock text-[10px]' : 'fas fa-trash-alt text-[10px]'"></i></button>
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
            <PaginationControls
              :page="currentPage"
              :total-pages="totalPages"
              @previous="previousPage()"
              @next="nextPage(totalPages)"
            />
          </div>
        </div>
      </div>

    </div>

    <HistoryDetailsModal
      :show="showDetailsModal && !!selectedPurchase"
      @close="closePurchaseDetails"
      :title="`تفاصيل فاتورة الشراء #${selectedPurchase?.invoice_number || selectedPurchase?.id || ''}`"
      icon="fas fa-file-invoice"
    >
      <div v-if="isLoadingDetails" class="space-y-4" aria-live="polite">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          <div v-for="index in 4" :key="index" class="h-14 animate-pulse rounded-lg bg-slate-100"></div>
        </div>
        <div class="h-36 animate-pulse rounded-xl bg-slate-100"></div>
      </div>
      <div v-else-if="detailsError" class="rounded-lg border border-rose-100 bg-rose-50 p-4 text-xs font-bold text-rose-700" role="alert">
        {{ detailsError }}
      </div>
      <div v-else class="space-y-6">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          <div v-for="info in purchaseDetailFacts" :key="info.label" class="min-w-0 space-y-1 rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-2">
            <p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">{{ info.label }}</p>
            <p class="break-words text-[11px] font-bold leading-4 text-slate-800">{{ info.value }}</p>
          </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-100 shadow-sm">
          <table class="w-full min-w-[560px] text-right text-xs">
            <thead>
              <tr class="border-b border-slate-100 bg-slate-50">
                <th class="px-4 py-3 text-[10px] font-bold text-slate-500">المنتج</th>
                <th class="px-4 py-3 text-center text-[10px] font-bold text-slate-500">الكمية</th>
                <th class="px-4 py-3 text-center text-[10px] font-bold text-slate-500">سعر الوحدة</th>
                <th class="px-4 py-3 text-left text-[10px] font-bold text-slate-500">الإجمالي</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="(item, index) in purchaseDetailItems" :key="item.id || item.product_id || index">
                <td class="px-4 py-3 font-bold text-slate-800">{{ item.product_name || item.name || '-' }}</td>
                <td class="px-4 py-3 text-center font-bold text-slate-500">{{ item.quantity ?? item.qty ?? 0 }}</td>
                <td class="px-4 py-3 text-center font-bold text-slate-700">{{ formatCurrency(purchaseItemUnitPrice(item)) }}</td>
                <td class="px-4 py-3 text-left font-bold text-slate-900">{{ formatCurrency(purchaseItemTotal(item)) }}</td>
              </tr>
              <tr v-if="!purchaseDetailItems.length">
                <td colspan="4" class="px-4 py-8 text-center text-xs font-medium text-slate-400">لا توجد أصناف لعرضها.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex flex-col md:flex-row gap-8">
          <div class="flex-1 p-5 rounded-xl bg-slate-50/50 border border-slate-100">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-2">ملاحظات الفاتورة</p>
            <p class="text-xs text-slate-600 italic">{{ selectedPurchase?.notes || 'لا توجد ملاحظات.' }}</p>
          </div>
          <div class="w-full md:w-72 space-y-2.5 bg-slate-50 border border-slate-200 p-5 rounded-xl">
            <div class="flex justify-between text-[11px] font-medium text-slate-500"><span>المجموع</span><span class="font-mono font-bold text-slate-700">{{ formatCurrency(selectedPurchase?.total_amount || 0) }}</span></div>
            <div class="flex justify-between text-[11px] font-medium text-slate-500 pb-2.5 border-b border-slate-200"><span>المدفوع</span><span class="font-mono font-bold text-emerald-600">{{ formatCurrency(selectedPurchase?.actual_paid_amount ?? selectedPurchase?.paid_amount ?? 0) }}</span></div>
            <!-- صافي الفاتورة مميز -->
            <div class="flex justify-between items-center bg-indigo-600 text-white px-4 py-3 rounded-lg">
              <span class="text-[11px] font-bold uppercase tracking-widest opacity-80">صافي الفاتورة</span>
              <span class="text-xl font-bold font-mono tracking-tighter">{{ formatCurrency(selectedPurchase?.total_amount || 0) }}</span>
            </div>
            <div class="pt-1 space-y-2">
              <div v-if="Number(selectedPurchase?.return_amount || 0) > 0" class="flex justify-between text-[11px] font-medium text-slate-500">
                <span>قيمة المرتجعات</span>
                <span class="font-mono font-bold text-amber-500">{{ formatCurrency(selectedPurchase.return_amount) }}</span>
              </div>
              <div v-if="(selectedPurchase?.remaining_balance ?? ((selectedPurchase?.total_amount || 0) - (selectedPurchase?.actual_paid_amount ?? selectedPurchase?.paid_amount ?? 0))) > 0" class="flex justify-between text-[11px] font-bold pt-1 border-t border-slate-200">
                <span class="text-slate-500">المتبقي</span>
                <span class="font-mono text-rose-600">{{ formatCurrency(selectedPurchase?.remaining_balance ?? ((selectedPurchase?.total_amount || 0) - (selectedPurchase?.actual_paid_amount ?? selectedPurchase?.paid_amount ?? 0))) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="mr-auto flex flex-row flex-wrap items-center gap-3">
          <!-- طباعة الفاتورة -->
          <button
            @click="printPurchaseDetails"
            class="inline-flex h-10 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md border border-indigo-200 px-4 text-xs font-bold text-indigo-700 hover:bg-indigo-50"
          >
            <i class="fas fa-print"></i> طباعة الفاتورة
          </button>
          <!-- إغلاق -->
          <button @click="closePurchaseDetails" class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap rounded-md px-4 text-xs font-bold text-slate-500 transition-colors hover:bg-slate-100">إغلاق</button>
        </div>
      </template>
    </HistoryDetailsModal>

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

      :pending-product-add="pendingProductAdd"

      @close="handlePurchaseModalClose"

      @save="handleModalSave"

      @open-product-modal="({ index, branchId }) => { productRowIndex = index; productBranchId = branchId; showProductModal = true }"

    />

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
        <LoadingButton
          text="إضافة للمخزن"
          :isLoading="isAddingProduct"
          loadingText="جاري الإضافة..."
          variant="primary"
          class="flex-[2] py-3 rounded-xl"
          @click="saveNewProduct"
        />
      </template>
    </BaseModal>

  </div>

</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import { useCompanyCurrency } from '@/composables/useCompanyCurrency'
import { useTableFilters } from '@/composables/useTableFilters'
import { useSearchDropdown } from '@/composables/useSearchDropdown'
import { useBreadcrumb } from '@/composables/useBreadcrumb'
import PurchaseForm from '@/components/PurchaseForm.vue'
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue'
import LoadingButton from '@/components/ui/LoadingButton.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import BaseModal from '@/components/BaseModal.vue';
import { getLocalDateISO, isSettingEnabled, printDocument } from '@/utils'
import { buildPurchaseHtml } from '@/utils/printTemplates'
import { useSessionExemption } from '@/composables/useCashierSessionGuard'
import { useAuthStore } from '@/stores/auth/authStore'
import { useBranchStore } from '@/stores/branch/branchStore'
import { useBranchIsolation } from '@/composables/useBranchIsolation'
import { useProductStore } from '@/stores/product/productStore'
import { usePaymentStore } from '@/stores/payment/paymentStore'
import { useSessionStore } from '@/stores/session/sessionStore'
import { usePurchaseStore } from '@/stores/purchase/purchaseStore'
import { ensureCashSupplier } from '@/services/suppliers'
import { useSettingsStore } from '@/stores/settings/settingsStore'
import { useBootstrapStore } from '@/stores/bootstrap/bootstrapStore'
import AlertService from '@/services/AlertService'

const { showToast } = useToast()
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency()
const { breadcrumb } = useBreadcrumb()
const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const canCreatePurchase = computed(() =>
  authStore.isSuperAdmin || authStore.user?.permissions?.includes('purchase.create') === true
)
const canEditPurchase = computed(() =>
  authStore.isSuperAdmin || authStore.user?.permissions?.includes('purchase.edit') === true
)
const canDeletePurchase = computed(() =>
  authStore.isSuperAdmin || authStore.user?.permissions?.includes('purchase.delete') === true
)

const branchStore = useBranchStore()
const branchIsolation = useBranchIsolation()
const productStore = useProductStore()
const paymentStore = usePaymentStore()
const sessionStore = useSessionStore()
const purchaseStore = usePurchaseStore()
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
const pendingPurchaseStorageKey = `pending_purchase_create_request:${authStore.user?.tenant_id || 'no-tenant'}:${authStore.user?.id || 'no-user'}`;
const readPendingPurchaseRequest = () => {
  try {
    const saved = JSON.parse(sessionStorage.getItem(pendingPurchaseStorageKey) || 'null');
    return saved?.key && saved?.payload ? { ...saved, outcomeUnknown: true } : null;
  } catch {
    return null;
  }
};
const pendingPurchaseRequest = ref(readPendingPurchaseRequest());
const restorePendingPurchaseForm = () => {
  if (!canCreatePurchase.value) return false;
  const payload = pendingPurchaseRequest.value?.payload;
  if (!payload) return false;

  form.value = {
    ...payload,
    discount: payload.discount_value ?? payload.discount ?? 0,
    items: Array.isArray(payload.items) ? payload.items.map((item) => ({
      ...item,
      purchase_price: item.purchase_price ?? item.cost ?? item.price ?? 0,
      has_batch_number: Boolean(item.batch_number),
      has_expiry_date: Boolean(item.expiry_date),
      has_serial_number: Boolean(item.serial),
    })) : [],
  };
  isEditMode.value = false;
  showFormModal.value = true;
  return true;
};

const showFormModal = ref(false);
const showDetailsModal = ref(false);
const selectedPurchase = ref(null);
const isLoadingDetails = ref(false);
const detailsError = ref('');
let detailsRequestId = 0;
const purchaseDetailItems = computed(() => {
  const purchase = selectedPurchase.value;
  return Array.isArray(purchase?.items) ? purchase.items
    : (Array.isArray(purchase?.purchase?.items) ? purchase.purchase.items : []);
});
const purchaseItemUnitPrice = (item) => Number(item?.price ?? item?.unit_price ?? item?.purchase_price ?? item?.cost ?? 0) || 0;
const purchaseItemTotal = (item) => Number(
  item?.total ?? item?.subtotal ?? (purchaseItemUnitPrice(item) * Number(item?.quantity ?? item?.qty ?? 0))
) || 0;
const purchaseDetailFacts = computed(() => {
  const purchase = selectedPurchase.value;
  if (!purchase) return [];
  return [
    { label: 'المورد', value: purchase.supplier_name || purchase.supplier?.name || '-' },
    { label: 'الفرع', value: purchase.branch_name || branches.value.find(branch => String(branch.id) === String(purchase.branch_id))?.name || '-' },
    { label: 'التاريخ', value: formatDateTime(purchase.invoice_date || purchase.purchase_date || purchase.created_at) || '-' },
    { label: 'الكاشير', value: purchase.created_by_name || 'النظام' },
    { label: 'الحالة', value: getPurchaseStatus(purchase.dynamic_status || purchase.status).text },
  ];
});

const paymentMethods = ref([]);

const form = ref({ supplier_id: '', branch_id: '', invoice_number: '', purchase_date: getLocalDateISO(), status: 'draft', discount: 0, discount_type: 'fixed', items: [], paid_amount: 0 });

const taxEnabled = ref(false); const taxRate = ref(0); const taxValue = ref(0);

const showProductModal = ref(false); const isAddingProduct = ref(false);

const newProduct = ref({ name: '', barcode: '', category_id: '', purchase_price: 0, sale_price: 0, min_quantity: 0, description: '' });

const productRowIndex = ref(null);
const productBranchId = ref(null);

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
        totalAmount: purchases.value.reduce((s, p) =>
          s + (['cancelled', 'canceled', 'rejected'].includes(p.dynamic_status || p.status) ? 0 : parseFloat(p.total_amount || 0)), 0),
        pendingInvoices: purchases.value.filter(p => p.status === 'pending').length,
        activeSuppliers: new Set(purchases.value.map(p => p.supplier_id)).size
      };
    }
  } catch (error) { purchases.value = []; totalCount.value = 0; }
  finally { isLoading.value = false; }

};

const onBranchChange = (value) => {
  branchStore.setSelectedBranch(value);
  selectedBranch.value = branchStore.selectedBranchId;
  if (currentPage.value === 1) {
    loadPurchases();
  } else {
    handleBranchChange();
  }
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
  if (!canCreatePurchase.value) return;

  if (isSaving.value) return;
  if (pendingPurchaseRequest.value?.outcomeUnknown) {
    restorePendingPurchaseForm();
    showToast('هناك طلب شراء سابق نتيجته غير مؤكدة. أعد المحاولة بنفس البيانات قبل إنشاء فاتورة جديدة.', 'warning');
    return;
  }

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

  let cashSupplier = suppliers.value.find(supplier =>
    supplier.name === 'مورد نقدي' && (supplier.branch_id == null || String(supplier.branch_id) === String(branchStore.selectedBranchId))
  );
  if (!cashSupplier) {
    try {
      const result = await ensureCashSupplier();
      cashSupplier = result?.data;
      if (!cashSupplier?.id) throw new Error('استجابة تجهيز المورد النقدي غير مكتملة.');
      suppliers.value = [...suppliers.value.filter(supplier => String(supplier.id) !== String(cashSupplier.id)), cashSupplier];
    } catch (error) {
      showToast(error?.response?.data?.message || error?.message || 'تعذر تجهيز المورد النقدي.', 'error');
      return;
    }
  }

  form.value = { supplier_id: cashSupplier.id, branch_id: branchStore.selectedBranchId || branches.value[0]?.id || '', invoice_number: nextInv, purchase_date: getLocalDateISO(), status: 'received', discount: 0, discount_type: 'fixed', items: [], paid_amount: 0, payment_method_id: cashMethodId.value };

  showFormModal.value = true;

}

const openEditModal = (p) => {
  if (!canEditPurchase.value) return;
  if (['posted', 'reversed'].includes(p.journal_status)) {
    showToast('لا يمكن تعديل فاتورة شراء مرحّلة؛ ألغِها بالطريقة الآمنة وأنشئ فاتورة بديلة.', 'warning');
    return;
  }
  if (pendingPurchaseRequest.value?.outcomeUnknown) {
    restorePendingPurchaseForm();
    showToast('تحقق من طلب الشراء السابق قبل فتح فاتورة أخرى.', 'warning');
    return;
  }
  isEditMode.value = true;
  form.value = { discount: p.discount_value || 0, discount_type: p.discount_type || 'fixed', ...p, items: Array.isArray(p.items) ? p.items : [], payment_method_id: p.payment_method_id ?? null };
  showFormModal.value = true;
};

const viewPurchaseDetails = async (purchase) => {
  const purchaseId = Number(typeof purchase === 'object' ? purchase?.id : purchase)
  if (!Number.isInteger(purchaseId) || purchaseId <= 0) return;
  const purchaseRow = typeof purchase === 'object'
    ? purchase
    : purchases.value.find(item => Number(item.id) === purchaseId) || { id: purchaseId }

  const requestId = ++detailsRequestId;
  selectedPurchase.value = purchaseRow;
  detailsError.value = '';
  showDetailsModal.value = true;
  isLoadingDetails.value = true;

  try {
    const details = await purchaseStore.fetchPurchaseDetails(purchaseId, { force: true });
    if (requestId === detailsRequestId) {
      selectedPurchase.value = { ...purchaseRow, ...(details || {}) };
    }
  } catch (error) {
    if (requestId === detailsRequestId) {
      detailsError.value = error.response?.data?.message || error.message || 'تعذر تحميل تفاصيل الفاتورة.';
      showToast(detailsError.value, 'error');
    }
  } finally {
    if (requestId === detailsRequestId) isLoadingDetails.value = false;
  }
};

const openPurchaseDetails = async (purchase) => {
  if (!purchase?.id) return;
  if (Number(route.query.id) === Number(purchase.id)) {
    await viewPurchaseDetails(purchase);
    return;
  }
  await router.push({ name: 'Purchases', query: { ...route.query, id: String(purchase.id) } });
};

const printPurchaseDetails = async () => {
  if (!selectedPurchase.value) return;
  const p = { ...selectedPurchase.value, items: selectedPurchase.value.items || [] };
  await printDocument(buildPurchaseHtml(p));
};

const closePurchaseDetails = () => {
  detailsRequestId++;
  showDetailsModal.value = false;
  isLoadingDetails.value = false;
  if (route.query.id) {
    const query = { ...route.query };
    delete query.id;
    router.replace({ name: 'Purchases', query });
  }
};

watch(() => route.query.id, async (id) => {
  if (Number(id) > 0) {
    await viewPurchaseDetails(Number(id));
    return;
  }
  closePurchaseDetails();
}, { immediate: true });

const handlePurchaseModalClose = async () => {
  if (isSaving.value) {
    showToast('انتظر انتهاء حفظ الفاتورة قبل إغلاق النافذة.', 'warning');
    return;
  }
  if (pendingPurchaseRequest.value?.outcomeUnknown) {
    const verifiedMissing = await AlertService.confirm(
      'نتيجة الحفظ غير مؤكدة. لا تبدأ فاتورة جديدة قبل مراجعة قائمة المشتريات. هل تحققت من عدم إنشاء الفاتورة وتريد إلغاء إعادة المحاولة؟',
      'التحقق من نتيجة الحفظ',
      { confirmText: 'تحققت، إغلاق', cancelText: 'إبقاء الفاتورة' }
    );
    if (!verifiedMissing) {
      restorePendingPurchaseForm();
      showToast('أعد المحاولة بنفس البيانات لتجنب تكرار الفاتورة.', 'warning');
      return;
    }
    pendingPurchaseRequest.value = null;
    try { sessionStorage.removeItem(pendingPurchaseStorageKey); } catch (_) {}
  }
  showFormModal.value = false;
};

const getDeviceIdentity = () => {
  let id = localStorage.getItem('pos_device_id');
  if (!id) { id = 'dev-' + Math.random().toString(36).slice(2, 8); try { localStorage.setItem('pos_device_id', id); } catch {} }
  return { device_id: id };
};

const handleModalSave = async (payload) => {
  const isCash = paymentMethods.value.find(m => Number(m.id) === Number(payload.payment_method_id))?.kind === 'cash';
  const paidAmt = parseFloat(payload.paid_amount || 0);
  try { await ensureExemptionLoaded(); } catch (_) {}
  const settings = await fetchSettings(true);
  const sessionsEnabled = isSettingEnabled(settings?.['pos.sessions.enabled']);
  if (sessionsEnabled && !isExempt.value && isCash && paidAmt > 0) {
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
  let idempotencyKey = null;
  if (!isEditMode.value) {
    const payloadFingerprint = JSON.stringify(payload);
    const pending = pendingPurchaseRequest.value;
    if (pending?.outcomeUnknown && pending.fingerprint !== payloadFingerprint) {
      showToast('بيانات الفاتورة تغيّرت بعد طلب غير مؤكد. أعد البيانات الأصلية أو تحقق من الفواتير أولًا.', 'warning');
      return;
    }

    if (pending?.outcomeUnknown) {
      idempotencyKey = pending.key;
    } else {
      idempotencyKey = globalThis.crypto?.randomUUID?.()
        || `purchase-${Date.now()}-${Math.random().toString(36).slice(2)}`;
      pendingPurchaseRequest.value = {
        key: idempotencyKey,
        fingerprint: payloadFingerprint,
        payload: JSON.parse(JSON.stringify(payload)),
        outcomeUnknown: false,
      };
      try {
        sessionStorage.setItem(pendingPurchaseStorageKey, JSON.stringify(pendingPurchaseRequest.value));
      } catch (_) {}
    }
  }

  isSaving.value = true;
  let createConfirmed = false;
  try {
    const response = isEditMode.value
      ? await purchaseStore.updatePurchase(form.value.id, payload)
      : await purchaseStore.createPurchase(payload, idempotencyKey);

    if (response.status === 'success') {
      if (!isEditMode.value) {
        createConfirmed = true;
        pendingPurchaseRequest.value = null;
        try { sessionStorage.removeItem(pendingPurchaseStorageKey); } catch (_) {}
      }
      showToast('تم الحفظ بنجاح');
      showFormModal.value = false;
      await loadPurchases();
      productStore.invalidateCacheForBranch(payload.branch_id);
    } else if (!isEditMode.value && response.outcomeUnknown) {
      pendingPurchaseRequest.value = { ...pendingPurchaseRequest.value, outcomeUnknown: true };
      try { sessionStorage.setItem(pendingPurchaseStorageKey, JSON.stringify(pendingPurchaseRequest.value)); } catch (_) {}
      showToast('انتهت مهلة الرد أو تعذر تأكيده؛ قد تكون الفاتورة حُفظت. أعد المحاولة بنفس البيانات، ولا تبدأ فاتورة جديدة قبل التأكد.', 'warning');
    } else {
      if (!isEditMode.value && response.statusCode === 409) {
        pendingPurchaseRequest.value = { ...pendingPurchaseRequest.value, outcomeUnknown: true };
        try { sessionStorage.setItem(pendingPurchaseStorageKey, JSON.stringify(pendingPurchaseRequest.value)); } catch (_) {}
      } else if (!isEditMode.value) {
        pendingPurchaseRequest.value = null;
        try { sessionStorage.removeItem(pendingPurchaseStorageKey); } catch (_) {}
      }
      showToast(response.message || 'فشل الحفظ', 'error', 10000);
    }
  } catch (e) {
    if (!isEditMode.value && !createConfirmed) {
      pendingPurchaseRequest.value = { ...pendingPurchaseRequest.value, outcomeUnknown: true };
      try { sessionStorage.setItem(pendingPurchaseStorageKey, JSON.stringify(pendingPurchaseRequest.value)); } catch (_) {}
      showToast('تعذر تأكيد نتيجة الحفظ. أعد المحاولة بنفس البيانات قبل إنشاء فاتورة أخرى.', 'warning');
    } else {
      showToast('تم إنشاء الفاتورة، لكن تعذر تحديث القائمة. حدّث الصفحة للتحقق من النتيجة.', 'warning');
    }
  } finally {
    isSaving.value = false;
  }
}

const confirmDelete = async (purchase) => {
  if (!canDeletePurchase.value || !purchase?.id) return;
  if (Number(purchase.linked_payment_count || 0) > 0) {
    showToast('لا يمكن إلغاء فاتورة مرتبطة بمدفوعات؛ استخدم مسار مرتجع المشتريات للحفاظ على الأثر المحاسبي.', 'warning');
    return;
  }
  if (Number(purchase.linked_return_count || 0) > 0) {
    showToast('لا يمكن إلغاء فاتورة مرتبطة بمرتجعات؛ يجب الاحتفاظ بسجل الفاتورة والمرتجعات.', 'warning');
    return;
  }
  if (await AlertService.confirm(
    'سيتم إلغاء الفاتورة غير المدفوعة مع الاحتفاظ بسجلها وعكس القيد والمخزون المتاح. هل تريد المتابعة؟',
    'تأكيد إلغاء الفاتورة',
    { confirmText: 'إلغاء الفاتورة', cancelText: 'رجوع' }
  )) {
    deletePurchase(purchase.id);
  }
};

const deletePurchase = async (id) => {
  try {
    const res = await purchaseStore.deletePurchase(id);
    if (res.status === 'success') {
      showToast(res.message || 'تم إلغاء الفاتورة', 'success');
      await loadPurchases();
      productStore.invalidateCache();
    } else {
      showToast(res.message || 'تعذر إلغاء الفاتورة', 'error');
    }
  } catch (error) { showToast(error?.message || 'تعذر إلغاء الفاتورة', 'error'); }
};

const saveNewProduct = async () => {
  if (!newProduct.value.name.trim()) { showToast('ادخل اسم المنتج', 'error'); return; }
  if (!productBranchId.value) { showToast('حدد فرع الاستلام أولاً', 'warning'); return; }
  isAddingProduct.value = true;
  try {
    const response = await productStore.createProduct({
      ...newProduct.value,
      branch_assignments: {
        branch_ids: [Number(productBranchId.value)],
        initial_quantity: 0,
        initial_unit_cost: 0,
      },
    });
    if (response.status === 'success') {
      const data = response.data;
      if (!products.value.find(p => p.id === data.id)) products.value.push(data);
      if (productRowIndex.value !== null) {
        pendingProductAdd.value = { index: productRowIndex.value, product: data };
      }
      showProductModal.value = false;
      newProduct.value = { name: '', barcode: '', category_id: '', purchase_price: 0, sale_price: 0, min_quantity: 0, description: '' };
      productRowIndex.value = null;
      productBranchId.value = null;
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
  const map = {
    draft: { text: 'مسودة', class: 'bg-slate-50 text-slate-400 border-slate-100' },
    pending: { text: 'قيد الانتظار', class: 'bg-amber-50 text-amber-600 border-amber-100' },
    approved: { text: 'معتمدة', class: 'bg-blue-50 text-blue-600 border-blue-100' },
    received: { text: 'مستلمة', class: 'bg-indigo-50 text-indigo-600 border-indigo-100' },
    due: { text: 'مستحقة', class: 'bg-amber-50 text-amber-600 border-amber-100' },
    partial: { text: 'مدفوعة جزئياً', class: 'bg-amber-50 text-amber-600 border-amber-100' },
    paid: { text: 'مدفوعة', class: 'bg-emerald-50 text-emerald-600 border-emerald-100' },
    settled: { text: 'مسددة', class: 'bg-emerald-50 text-emerald-600 border-emerald-100' },
    returned: { text: 'مرتجعة', class: 'bg-orange-50 text-orange-600 border-orange-100' },
    cancelled: { text: 'ملغاة', class: 'bg-rose-50 text-rose-600 border-rose-100' },
    canceled: { text: 'ملغاة', class: 'bg-rose-50 text-rose-600 border-rose-100' },
    rejected: { text: 'مرفوضة', class: 'bg-rose-50 text-rose-600 border-rose-100' },
    completed: { text: 'مكتملة', class: 'bg-emerald-50 text-emerald-600 border-emerald-100' }
  };
  const status = String(s || '').toLowerCase();
  return map[status] || { text: status || 'غير محددة', class: 'bg-slate-50 text-slate-500 border-slate-100' };
};

const formatCurrency = (v) => formatCurrencyLocale(v, 2);

const formatDateTime = (value) => {
  if (!value) return '';
  const parsed = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(parsed.getTime())
    ? String(value)
    : parsed.toLocaleString('ar-EG', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true });
};

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
  if (pendingPurchaseRequest.value?.outcomeUnknown) {
    restorePendingPurchaseForm();
    showToast('يوجد طلب شراء سابق لم يصل تأكيده. أعد المحاولة بنفس البيانات للتحقق دون إنشاء فاتورة مكررة.', 'warning');
  }
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

/* Form Inputs */
.filter-input-v2 {
  @apply focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500;
}

.form-input-modern {
  @apply w-full h-12 bg-white border border-slate-200 rounded-2xl px-4 outline-none transition-all duration-300 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50 shadow-sm font-bold text-sm;
}

.modal-label {
  @apply block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 px-1;
}

/* Pagination */
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
</style>