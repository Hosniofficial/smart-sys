<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-indigo-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="isLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-indigo-600/10 z-[110]">
      <div class="h-full bg-indigo-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>
    
    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة الموردين والذمم"
        description="أدر بيانات الموردين، وتابع الأرصدة المستحقة والمدفوعات ومواعيد السداد."
        :branches="canSelectBranches ? branches : []"
        :selectedBranch="selectedBranch"
        @branch-changed="handleBranchChange"
      >
        <template #controls>
          <button @click="openAddModal" class="h-9 px-4 rounded-md bg-indigo-600 text-white text-xs font-bold shadow-sm hover:bg-indigo-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-plus text-[10px]"></i> مورد جديد
          </button>
        </template>
      </PageHeader>

      <!-- KPIs Overview: Metric Grid -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <div v-for="kpi in kpis" :key="kpi.title" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.title }}</p>
            <p class="text-xl font-bold text-slate-900 font-mono tracking-tighter">{{ kpi.value }}</p>
          </div>
          <div class="w-10 h-10 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
            <i :class="kpi.icon"></i>
          </div>
        </div>
      </section>

      <!-- Toolbar: Search & View Toggle -->
      <section class="bg-white border border-slate-200 rounded-xl p-4 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-sm">
        <div class="relative flex-grow group max-w-xl">
          <input type="text" v-model="search" class="h-9 w-full bg-white border border-slate-200 rounded-md pr-9 pl-4 text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all" placeholder="ابحث باسم المورد أو رقم الهاتف..." />
          <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
        </div>
        
        <div class="flex items-center bg-slate-100 p-1 rounded-lg border border-slate-200/50">
          <button @click="viewMode = 'cards'" :class="[viewMode === 'cards' ? 'bg-white text-indigo-600 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']" class="px-4 py-1.5 rounded-md text-[10px] font-bold transition-all flex items-center gap-2">
            <i class="fas fa-th-large text-[9px]"></i> بطاقات
          </button>
          <button @click="viewMode = 'table'" :class="[viewMode === 'table' ? 'bg-white text-indigo-600 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']" class="px-4 py-1.5 rounded-md text-[10px] font-bold transition-all flex items-center gap-2">
            <i class="fas fa-list text-[9px]"></i> جدول
          </button>
        </div>
      </section>

      <!-- Main Content States -->
      <div class="relative min-h-[400px]">
        
        <!-- Loading State -->
        <div v-if="isLoading" class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div v-for="i in 6" :key="i" class="h-48 bg-white border border-slate-200 rounded-xl animate-pulse"></div>
        </div>

        <!-- Empty State -->
        <div v-else-if="loadError" class="py-24 text-center bg-white border border-rose-200 rounded-xl">
          <div class="w-16 h-16 bg-rose-50 text-rose-300 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-triangle-exclamation text-2xl"></i>
          </div>
          <h3 class="text-sm font-bold text-slate-900 uppercase tracking-widest">تعذر تحميل الموردين</h3>
          <p class="text-xs text-rose-600 mt-1">{{ loadError }}</p>
          <button @click="fetchContacts" class="mt-6 text-xs font-bold text-indigo-600 hover:underline">إعادة تحميل البيانات</button>
        </div>
        <div v-else-if="!filteredContacts.length" class="py-24 text-center bg-white border border-slate-200 rounded-xl">
          <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-truck-fast text-2xl"></i>
          </div>
          <h3 class="text-sm font-bold text-slate-900 uppercase tracking-widest">لا يوجد موردين</h3>
          <p class="text-xs text-slate-400 mt-1">لم يتم العثور على نتائج تطابق معايير البحث الحالية.</p>
          <button @click="fetchContacts" class="mt-6 text-xs font-bold text-indigo-600 hover:underline">إعادة تحميل البيانات</button>
        </div>

        <!-- View Mode: Cards -->
        <div v-else-if="viewMode === 'cards'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
          <div v-for="contact in filteredContacts" :key="contact.id" class="bg-white border border-slate-200 rounded-xl p-6 hover:border-indigo-500/30 hover:shadow-md transition-all group flex flex-col justify-between">
            <div class="space-y-4">
              <div class="flex items-start justify-between">
                <div class="w-10 h-10 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 group-hover:text-indigo-600 transition-colors border border-slate-100 group-hover:border-indigo-100">
                  <i class="fas fa-truck text-sm"></i>
                </div>
                <button @click="openPaymentModal(contact)" class="w-7 h-7 rounded-md bg-emerald-50 text-emerald-600 flex items-center justify-center hover:bg-emerald-600 hover:text-white transition-all shadow-sm" title="تسجيل دفعة">
                  <i class="fas fa-hand-holding-dollar text-[10px]"></i>
                </button>
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-900 truncate group-hover:text-indigo-600 transition-colors">{{ contact.name }}</h3>
                <p class="text-[10px] font-bold text-slate-400 font-mono tracking-tighter mt-1">{{ contact.phone || '—' }}</p>
              </div>
              <div class="p-3 rounded-lg bg-slate-50/50 border border-slate-100 group-hover:bg-white transition-all">
                <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">الرصيد المحاسبي</p>
                <p :class="[contact.balance > 0 ? 'text-emerald-600' : contact.balance < 0 ? 'text-rose-600' : 'text-slate-400']" class="text-sm font-bold font-mono tracking-tighter">
                  {{ formatPrice(Math.abs(contact.balance)) }}
                  <span class="text-[9px] font-bold mr-1 opacity-60">{{ contact.balance > 0 ? 'دائن' : contact.balance < 0 ? 'مدين' : 'مستقر' }}</span>
                </p>
              </div>
            </div>
            
            <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between">
              <button @click="goToStatement(contact)" class="text-[10px] font-bold text-indigo-500 hover:text-indigo-700 uppercase tracking-widest transition-colors">كشف الحساب</button>
              <div class="flex items-center gap-2">
                <button @click="viewContact(contact)" class="w-7 h-7 rounded border border-slate-100 text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-all"><i class="fas fa-eye text-[10px]"></i></button>
                <button @click="editContact(contact)" class="w-7 h-7 rounded border border-slate-100 text-slate-400 hover:text-amber-600 hover:bg-amber-50 transition-all"><i class="fas fa-pen text-[10px]"></i></button>
                <button @click="deleteContact(contact)" class="w-7 h-7 rounded border border-slate-100 text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-all"><i class="fas fa-trash-alt text-[10px]"></i></button>
              </div>
            </div>
          </div>
        </div>

        <!-- View Mode: Table -->
        <div v-else-if="viewMode === 'table'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-slate-50/50 border-b border-slate-200">
                  <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المورد والرمز المرجعي</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التواصل</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الرصيد الحالي</th>
                  <th class="px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium text-xs">
                <tr v-for="contact in paginatedContacts" :key="contact.id" class="hover:bg-indigo-50/20 transition-all group">
                  <td class="px-6 py-4">
                    <div class="flex flex-col">
                      <span class="font-bold text-slate-900 leading-none group-hover:text-indigo-600 transition-colors">{{ contact.name }}</span>
                      <span class="text-[9px] font-bold text-slate-400 font-mono mt-1 uppercase tracking-widest">REF: {{ contact.code || '---' }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-4 text-slate-600 font-mono">{{ contact.phone || '-' }}</td>
                  <td class="px-4 py-4 text-center">
                    <span :class="[contact.balance > 0 ? 'text-emerald-600 border-emerald-100 bg-emerald-50' : contact.balance < 0 ? 'text-rose-600 border-rose-100 bg-rose-50' : 'text-slate-400 bg-slate-50 border-slate-100']" class="px-2 py-0.5 rounded text-[10px] font-bold border font-mono">
                      {{ formatPrice(Math.abs(contact.balance || 0)) }}
                      {{ contact.balance > 0 ? 'دائن' : contact.balance < 0 ? 'مدين' : '' }}
                    </span>
                  </td>
                  <td class="px-8 py-4 text-center">
                    <div class="flex items-center justify-center gap-1.5">
                      <button @click="viewContact(contact)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center"><i class="fas fa-eye text-[10px]"></i></button>
                      <button @click="goToStatement(contact)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-indigo-600 hover:border-indigo-200 transition-all flex items-center justify-center"><i class="fas fa-file-invoice-dollar text-[10px]"></i></button>
                      <button @click="editContact(contact)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-amber-600 hover:border-amber-200 transition-all flex items-center justify-center"><i class="fas fa-pen text-[10px]"></i></button>
                      <button @click="deleteContact(contact)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-all flex items-center justify-center"><i class="fas fa-trash-alt text-[10px]"></i></button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination Footer -->
          <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
              صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ filters.totalPages.value }}</span>
              <span class="mx-2 text-slate-200">|</span>
              إجمالي <span class="text-slate-900">{{ filteredContacts.length }}</span> مورد
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
    </div>

    <!-- Modals: Minimal Standard Overlay -->
    
    <!-- Supplier Form -->
    <Teleport to="body">
    <transition name="fade">
      <div v-if="showFormModal" class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md">
        <div class="bg-white w-full max-w-xl rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn flex flex-col max-h-[90vh]">
          <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center text-white"><i :class="selectedContact?.id ? 'fas fa-edit' : 'fas fa-truck-fast'" class="text-xs"></i></div>
              <h3 class="text-sm font-bold text-slate-900 uppercase">{{ selectedContact?.id ? 'تحديث ملف المورد' : 'إضافة مورد جديد' }}</h3>
            </div>
            <button @click="showFormModal = false" class="text-slate-400 hover:text-slate-900 transition-colors"><i class="fas fa-times text-lg"></i></button>
          </div>
          <form @submit.prevent="handleFormSubmit(selectedContact)" class="p-8 space-y-6 overflow-y-auto">
            <div class="space-y-1.5"><label class="metadata-label">الاسم أو الشركة <span class="text-rose-500">*</span></label><input type="text" v-model="selectedContact.name" class="filter-input-v2 h-10 font-bold" required /></div>
            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5"><label class="metadata-label">رقم التواصل</label><input type="text" v-model="selectedContact.phone" class="filter-input-v2 h-10 font-mono" /></div>
              <div class="space-y-1.5"><label class="metadata-label">البريد الإلكتروني</label><input type="email" v-model="selectedContact.email" class="filter-input-v2 h-10" /></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5"><label class="metadata-label">الرقم الضريبي</label><input type="text" v-model="selectedContact.tax_number" class="filter-input-v2 h-10 font-mono" /></div>
              <div class="space-y-1.5"><label class="metadata-label">سقف المديونية</label><input type="number" v-model.number="selectedContact.credit_limit" class="filter-input-v2 h-10 font-mono text-indigo-600" /></div>
            </div>
            <div class="space-y-1.5"><label class="metadata-label">العنوان</label><textarea rows="2" v-model="selectedContact.address" class="filter-input-v2 h-auto py-2"></textarea></div>
            <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
              <button type="button" @click="showFormModal = false" class="px-6 h-10 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">إلغاء</button>
              <button type="submit" class="px-10 h-10 bg-indigo-600 text-white rounded-md text-xs font-bold shadow-lg shadow-indigo-900/20 active:scale-95 transition-all">حفظ البيانات</button>
            </div>
          </form>
        </div>
      </div>
    </transition>
    </Teleport>

    <!-- Payment Collection (Supplier Outgoing) -->
    <transition name="fade">
      <div v-if="showPaymentModal" class="fixed inset-0 z-[130] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md">
        <div class="bg-white w-full max-w-md rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn">
          <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50 text-center">
            <h3 class="text-sm font-bold text-slate-900 uppercase">تسجيل دفعة صادر (مورد)</h3>
            <p class="text-[10px] text-slate-400 mt-1 font-medium italic">سداد المبالغ للمورد: {{ selectedContact.name }}</p>
          </div>
          <form @submit.prevent="handlePaymentSubmit" class="p-8 space-y-6">
            <div class="grid grid-cols-2 gap-2 rounded-lg bg-slate-100 p-1">
              <button type="button" @click="paymentMode = 'allocate'" :disabled="hasPendingSupplierPayment" :class="paymentMode === 'allocate' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500'" class="h-9 rounded-md text-[10px] font-bold transition-all disabled:opacity-50">تخصيص على الفواتير</button>
              <button type="button" @click="paymentMode = 'general'" :disabled="hasPendingSupplierPayment" :class="paymentMode === 'general' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500'" class="h-9 rounded-md text-[10px] font-bold transition-all disabled:opacity-50">دفعة عامة للمورد</button>
            </div>
            <p v-if="hasPendingSupplierPayment" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[10px] font-semibold text-amber-800">
              توجد دفعة لم تُحسم نتيجتها. أعد المحاولة بالبيانات نفسها قبل بدء دفعة أخرى.
            </p>

            <div v-if="paymentMode === 'allocate'" class="space-y-2">
              <p class="text-[10px] font-bold text-slate-500">أدخل مبلغ التخصيص لكل فاتورة؛ يجب ألا يتجاوز المتبقي عليها.</p>
              <div v-if="isLoadingSupplierPurchases" class="flex justify-center py-5"><BaseSpinner /></div>
              <div v-else-if="!supplierOpenPurchases.length" class="rounded-md border border-amber-100 bg-amber-50 p-3 text-center text-[10px] font-bold text-amber-700">لا توجد فواتير مستحقة لهذا المورد.</div>
              <div v-else class="max-h-48 space-y-2 overflow-y-auto pr-1">
                <div v-for="purchase in supplierOpenPurchases" :key="purchase.id" class="grid grid-cols-[1fr_110px] items-center gap-3 rounded-md border border-slate-200 p-3">
                  <div class="min-w-0">
                    <div class="truncate text-[11px] font-bold text-slate-700">{{ purchase.invoice_number || `فاتورة #${purchase.id}` }}</div>
                    <div class="mt-1 text-[9px] text-slate-400">المتبقي: {{ formatPrice(purchase.remaining_balance) }}</div>
                  </div>
                  <input v-model.number="purchaseAllocations[purchase.id]" :disabled="hasPendingSupplierPayment" type="number" min="0" :max="purchase.remaining_balance" step="0.01" class="filter-input-v2 h-9 text-left font-mono" placeholder="0.00" aria-label="مبلغ التخصيص" />
                </div>
              </div>
              <div class="flex items-center justify-between border-t border-slate-100 pt-2 text-xs font-bold">
                <span class="text-slate-500">إجمالي المبلغ المخصص</span>
                <span class="font-mono text-indigo-700">{{ formatPrice(allocatedPaymentTotal) }}</span>
              </div>
            </div>

            <div v-else class="text-center">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 block">المبلغ المسدد</label>
              <input type="number" v-model.number="paymentData.amount" :disabled="hasPendingSupplierPayment" class="w-full h-14 text-3xl font-bold text-center text-rose-600 border-b-2 border-slate-100 focus:border-rose-500 outline-none transition-all font-mono" required />
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5"><label class="metadata-label">التاريخ</label><input ref="paymentDateRef" type="date" v-model="paymentData.payment_date" :disabled="hasPendingSupplierPayment" class="filter-input-v2 h-10 font-mono" required /></div>
              <div class="space-y-1.5"><label class="metadata-label">طريقة الدفع</label><select v-model.number="paymentData.payment_method_id" :disabled="hasPendingSupplierPayment" class="filter-input-v2 h-10 font-bold" required><option value="">-- اختر --</option><option v-for="m in paymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
            </div>
            <div class="space-y-1.5"><label class="metadata-label">الفرع المرتبط</label><select v-model="paymentData.branch_id" :disabled="!canSelectBranches || hasPendingSupplierPayment" class="filter-input-v2 h-10 disabled:bg-slate-100 disabled:text-slate-500"><option value="">-- اختياري --</option><option v-for="w in branches" :key="w.id" :value="String(w.id)">{{ w.name }}</option></select></div>
            <div class="space-y-1.5"><label class="metadata-label">ملاحظات</label><textarea v-model="paymentData.notes" :disabled="hasPendingSupplierPayment" rows="2" class="filter-input-v2 h-auto py-2 italic" placeholder="مرجع شيك، تحويل بنكي..."></textarea></div>
            <div class="pt-4 border-t border-slate-100 flex gap-3">
              <button type="button" @click="showPaymentModal = false" :disabled="isSubmittingSupplierPayment" class="flex-1 h-10 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors disabled:opacity-50">إلغاء</button>
              <button type="submit" :disabled="isSubmittingSupplierPayment || isLoadingSupplierPurchases" class="flex-[2] h-10 bg-indigo-600 text-white rounded-md text-xs font-bold shadow-lg shadow-indigo-900/20 active:scale-95 transition-all disabled:opacity-50">{{ isSubmittingSupplierPayment ? 'جارٍ التسجيل...' : 'تأكيد السداد' }}</button>
            </div>
          </form>
        </div>
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useToast } from '@/composables/useToast';
import { getLocalDateISO } from '@/utils';
import { useRouter } from 'vue-router';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import AlertService from '@/services/AlertService';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useTableFilters } from '@/composables/useTableFilters';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { usePendingIdempotentRequest } from '@/composables/usePendingIdempotentRequest';
import { useBreadcrumb } from '@/composables/useBreadcrumb';

const { showToast } = useToast(); 
const router = useRouter();
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { usePurchaseStore } from '@/stores/purchase/purchaseStore';
import { useStatementStore } from '@/stores/statement/statementStore';
import PageHeader from '@/components/PageHeader.vue';

// --- Logic Initialization ---
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { breadcrumb } = useBreadcrumb();
const authStore = useAuthStore();
const branchStore = useBranchStore();
const branchIsolation = useBranchIsolation();
const canSelectBranches = branchIsolation.isExempt;

// ✅ Use branchStore directly (removed useUserBranchPreference)
const branches = computed(() => branchStore.branches);
const currentBranchName = computed(() => {
  if (!branchStore.selectedBranchId) return 'غير محدد';
  const branch = branches.value.find(b => String(b.id) === String(branchStore.selectedBranchId));
  return branch?.name || 'غير محدد';
});

// Use unified branch store - single source of truth
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId,
  set: (val) => branchStore.setSelectedBranch(val)
});

const handleBranchChange = (newBranchId) => {
  if (!canSelectBranches.value) return;
  branchStore.setSelectedBranch(newBranchId);
  fetchContacts(); // Reload data with new branch
};
const formatPrice = (amount) => formatCurrencyLocale(amount, 2);

const supplierStore = useSupplierStore();
const paymentStore = usePaymentStore();
const purchaseStore = usePurchaseStore();
const statementStore = useStatementStore();
const contacts = computed(() => supplierStore.suppliers);
const search = ref('');
const showFormModal = ref(false);
const isLoading = ref(false);
const selectedContact = ref(null);
const viewMode = ref('cards'); 
const showPaymentModal = ref(false);
const loadError = ref('');
const paymentData = ref({ amount: null, payment_date: null, payment_method_id: null, branch_id: null, notes: '' });
const pendingSupplierPayment = usePendingIdempotentRequest('supplier-payment');
const hasPendingSupplierPayment = computed(() => !!pendingSupplierPayment.pending.value);
const paymentMode = ref('allocate');
const supplierOpenPurchases = ref([]);
const purchaseAllocations = ref({});
const isLoadingSupplierPurchases = ref(false);
const isSubmittingSupplierPayment = ref(false);
const allocatedPayments = computed(() =>
  supplierOpenPurchases.value
    .map(purchase => ({
      purchase_id: Number(purchase.id),
      amount: Math.round((Number(purchaseAllocations.value[purchase.id]) || 0) * 100) / 100,
      remaining_balance: Number(purchase.remaining_balance) || 0,
    }))
    .filter(allocation => allocation.amount > 0)
);
const allocatedPaymentTotal = computed(() =>
  Math.round(allocatedPayments.value.reduce((total, allocation) => total + allocation.amount, 0) * 100) / 100
);

// Date input refs
const paymentDateRef = ref(null);
const paymentMethods = ref([]);

// Logic: Filters
const filteredContacts = computed(() => {
  if (!search.value) return contacts.value;
  const q = search.value.toLowerCase();
  return contacts.value.filter(c => (c.name && c.name.toLowerCase().includes(q)) || (c.phone && c.phone.includes(q)));
});

// ─── Pagination (using useTableFilters composable)
const filters = useTableFilters('suppliers_filters');

const paginatedContacts = computed(() => {
  filters.totalCount.value = filteredContacts.value.length;
  const start = (filters.page.value - 1) * filters.perPage.value;
  const end = start + filters.perPage.value;
  return filteredContacts.value.slice(start, end);
});

// Logic: KPIs (Calculations Preserved)
const kpis = computed(() => {
  const creditors = contacts.value.filter(c => Number(c.balance) > 0);
  const totalPayable = creditors.reduce((sum, c) => sum + Number(c.balance || 0), 0);
  const topCreditor = [...creditors].sort((a, b) => Number(b.balance) - Number(a.balance))[0];
  return [
    { title: 'إجمالي الذمم الدائنة', value: formatPrice(totalPayable), icon: 'fas fa-hand-holding-dollar' },
    { title: 'موردين دائنين حالياً', value: creditors.length, icon: 'fas fa-truck-fast' },
    { title: 'أكبر رصيد دائن', value: topCreditor ? `${topCreditor.name} (${formatPrice(Number(topCreditor.balance))})` : 'لا يوجد', icon: 'fas fa-arrow-trend-down' }
  ];
});

const fetchContacts = async () => {
  isLoading.value = true;
  loadError.value = '';
  try {
    let params = {};
    if (!canSelectBranches.value) {
      try {
        const wid = branchIsolation.getRequiredBranchId();
        params.branch_id = String(wid);
      } catch (e) {
        loadError.value = e.message || 'لم يتم تعيين فرع للمستخدم';
        showToast(e.message || 'لم يتم تعيين مخزن للمستخدم', 'warning');
        return;
      }
    } else if (selectedBranch.value) {
      params.branch_id = String(selectedBranch.value);
    }
    // No selected branch for a branch-privileged user means all suppliers.
    const result = await supplierStore.fetchSuppliers({ force: true, params });
    if (result.status !== 'success') {
      loadError.value = result.message || 'تعذر تحميل الموردين.';
      showToast(loadError.value, 'error');
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || err.message || 'تعذر تحميل الموردين.';
    showToast(loadError.value, 'error');
  } finally { isLoading.value = false; }
};

const goToStatement = (c) => router.push({ name: 'AccountStatement', params: { type: 'suppliers', id: c.id } });
const openAddModal = () => {
  let branchId = null;
  if (!canSelectBranches.value) {
    try {
      branchId = branchIsolation.getRequiredBranchId();
    } catch {
      branchId = null;
    }
  } else {
    branchId = selectedBranch.value || null; // null when 'all' → admin must explicitly pick branch in form
  }
  selectedContact.value = { name: '', phone: '', email: '', tax_number: '', address: '', credit_limit: 0, branch_id: branchId };
  showFormModal.value = true;
};
const editContact = (c) => { selectedContact.value = { ...c }; showFormModal.value = true; };
const viewContact = (c) => { try { sessionStorage.setItem('selectedContact', JSON.stringify(c)); } catch {} router.push({ name: 'ContactDetails', params: { type: 'suppliers', id: c.id } }); };

const deleteContact = async (c) => {
  if (await AlertService.confirm(`هل أنت متأكد من حذف المورد ${c.name}?`, 'حذف المورد')) {
    try {
      const result = await supplierStore.deleteSupplier(c.id);
      if (result?.status !== 'success') {
        showToast(result?.message || 'فشل حذف المورد', 'error');
        return;
      }
      showToast('تم الحذف بنجاح', 'success');
      fetchContacts();
    } catch (e) { showToast(e.response?.data?.message || 'فشل الحذف', 'error'); }
  }
};

const handleFormSubmit = async (formData) => {
  try {
    if (formData.id) await supplierStore.updateSupplier(formData.id, formData);
    else await supplierStore.createSupplier(formData);
    showToast('تم الحفظ بنجاح', 'success');
    showFormModal.value = false;
    fetchContacts();
  } catch (e) { showToast(e.response?.data?.message || 'خطأ في الحفظ', 'error'); }
};

const openPaymentModal = async (c) => {
  let branchId = null;
  if (!canSelectBranches.value) {
    try {
      branchId = branchIsolation.getRequiredBranchId();
    } catch (error) {
      showToast(error.message || 'يجب تحديد الفرع قبل تسجيل دفعة المورد.', 'error');
      return;
    }
  } else {
    branchId = selectedBranch.value || null;
  }

  let pending;
  try {
    pending = pendingSupplierPayment.restore(c.id);
  } catch (error) {
    showToast(error.message || 'تعذر استعادة عملية الدفع السابقة.', 'error');
    return;
  }
  if (pending?.payload?.branch_id) {
    branchId = Number(pending.payload.branch_id);
  }

  selectedContact.value = c;
  paymentData.value = pending?.payload
    ? { ...pending.payload }
    : {
        amount: null,
        payment_date: getLocalDateISO(),
        payment_method_id: null,
        branch_id: branchId ? String(branchId) : '',
        notes: ''
      };
  paymentMode.value = pending
    ? (pending.payload.purchases?.length ? 'allocate' : 'general')
    : 'allocate';
  supplierOpenPurchases.value = [];
  purchaseAllocations.value = Object.fromEntries(
    (pending?.payload?.purchases || []).map(({ purchase_id, amount }) => [purchase_id, Number(amount)])
  );
  showPaymentModal.value = true;
  isLoadingSupplierPurchases.value = true;
  try {
    const pageSize = 100;
    const firstPage = await purchaseStore.fetchPurchasesList({
      branchId,
      supplierId: c.id,
      page: 1,
      perPage: pageSize,
      force: true,
    });
    if (firstPage.status !== 'success' || !firstPage.data) {
      throw new Error(firstPage.message || 'تعذر تحميل فواتير المورد.');
    }

    const allPurchases = [...(firstPage.data.items || [])];
    const totalPages = Math.ceil(Number(firstPage.data.total || allPurchases.length) / pageSize);
    for (let page = 2; page <= totalPages; page += 1) {
      const nextPage = await purchaseStore.fetchPurchasesList({
        branchId,
        supplierId: c.id,
        page,
        perPage: pageSize,
        force: true,
      });
      if (nextPage.status !== 'success' || !nextPage.data) {
        throw new Error(nextPage.message || 'تعذر تحميل جميع فواتير المورد.');
      }
      allPurchases.push(...(nextPage.data.items || []));
    }
    supplierOpenPurchases.value = allPurchases.filter(purchase =>
      Number(purchase.remaining_balance) > 0
      && !['canceled', 'cancelled', 'rejected'].includes(String(purchase.dynamic_status || purchase.status).toLowerCase())
    );
  } catch (error) {
    supplierOpenPurchases.value = [];
    showToast(error.message || 'تعذر تحميل فواتير المورد.', 'error');
  } finally {
    isLoadingSupplierPurchases.value = false;
  }
};

const handlePaymentSubmit = async () => {
  if (isSubmittingSupplierPayment.value) return;
  const savedRequest = pendingSupplierPayment.pending.value;
  const amount = savedRequest
    ? Number(savedRequest.payload.amount)
    : (paymentMode.value === 'allocate' ? allocatedPaymentTotal.value : Number(paymentData.value.amount));
  if (!Number.isFinite(amount) || amount <= 0) return showToast(paymentMode.value === 'allocate' ? 'خصص مبلغًا موجبًا لفاتورة واحدة على الأقل' : 'ادخل مبلغ صحيح', 'error');
  if (!(savedRequest?.payload?.payment_method_id || paymentData.value.payment_method_id)) return showToast('اختر الطريقة', 'error');
  if (!(savedRequest?.payload?.payment_date || paymentData.value.payment_date)) {
    showToast('الرجاء تحديد تاريخ الدفع', 'error');
    return;
  }
  if (!savedRequest && paymentMode.value === 'allocate' && allocatedPayments.value.some(allocation => allocation.amount > allocation.remaining_balance + 0.009)) {
    return showToast('مبلغ التخصيص لا يمكن أن يتجاوز المتبقي على الفاتورة.', 'error');
  }

  isSubmittingSupplierPayment.value = true;
  try {
    const payload = savedRequest?.payload || {
      supplier_id: Number(selectedContact.value?.id),
      amount,
      payment_date: paymentData.value.payment_date,
      payment_method_id: Number(paymentData.value.payment_method_id),
      branch_id: paymentData.value.branch_id ? String(paymentData.value.branch_id) : undefined,
      notes: paymentData.value.notes || '',
      ...(paymentMode.value === 'allocate'
        ? { purchases: allocatedPayments.value.map(({ purchase_id, amount: allocationAmount }) => ({ purchase_id, amount: allocationAmount })) }
        : {}),
    };
    const pending = pendingSupplierPayment.prepare(selectedContact.value?.id, payload);
    const res = await supplierStore.paySupplierDebt(pending.payload, pending.key);
    if (res.status === 'success') {
      showToast(paymentMode.value === 'allocate' ? 'تم تسجيل الدفعة وتخصيصها على الفواتير' : 'تم تسجيل الدفعة العامة بنجاح', 'success');
      showPaymentModal.value = false;
      pendingSupplierPayment.clear(selectedContact.value?.id);
      purchaseStore.clearPurchasesListCache();
      purchaseStore.clearDetailsCache();
      statementStore.clearSupplierCache();
      fetchContacts();
    } else {
      if (res.outcomeUnknown) {
        showToast('تعذر تأكيد نتيجة الدفعة. أعد المحاولة بالبيانات نفسها لتجنب تكرارها.', 'error');
      } else {
        pendingSupplierPayment.clear(selectedContact.value?.id);
        showToast(res.message || 'فشل التسجيل', 'error');
      }
    }
  } catch (e) {
    showToast(e.response?.data?.message || e.message || 'فشل التسجيل', 'error');
  } finally {
    isSubmittingSupplierPayment.value = false;
  }
};

onMounted(async () => {
  await Promise.all([fetchSettings(), paymentStore.fetchPaymentMethods().catch(() => {})]);
  paymentMethods.value = paymentStore.paymentMethods;
  // ✅ replaced initializePreferences with branchStore.fetchBranches()
  await branchStore.fetchBranches();
  fetchContacts();
});

</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 outline-none transition-all;
}

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1; }

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>