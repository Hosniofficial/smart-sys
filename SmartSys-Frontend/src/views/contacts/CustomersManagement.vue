<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="isLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>
    
    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="قاعدة بيانات العملاء"
        description="أدر بيانات العملاء، وتابع الأرصدة المستحقة والمدفوعات والتحصيلات."
        :branches="branches"
        :selectedBranch="selectedBranch"
        @branch-changed="handleBranchChange"
      >
        <template #controls>
          <button @click="openAddModal" class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-user-plus text-[10px]"></i> إضافة عميل
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
          <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
            <i :class="kpi.icon"></i>
          </div>
        </div>
      </section>

      <!-- Toolbar: Search & View Toggle -->
      <section class="bg-white border border-slate-200 rounded-xl p-4 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-sm">
        <div class="relative flex-grow group max-w-xl">
          <input type="text" v-model="search" class="h-9 w-full bg-white border border-slate-200 rounded-md pr-9 pl-4 text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all" placeholder="ابحث باسم العميل أو رقم الهاتف..." />
          <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
        </div>
        
        <div class="flex items-center bg-slate-100 p-1 rounded-lg border border-slate-200/50">
          <button @click="viewMode = 'cards'" :class="[viewMode === 'cards' ? 'bg-white text-blue-600 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']" class="px-4 py-1.5 rounded-md text-[10px] font-bold transition-all flex items-center gap-2">
            <i class="fas fa-th-large text-[9px]"></i> بطاقات
          </button>
          <button @click="viewMode = 'table'" :class="[viewMode === 'table' ? 'bg-white text-blue-600 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']" class="px-4 py-1.5 rounded-md text-[10px] font-bold transition-all flex items-center gap-2">
            <i class="fas fa-list text-[9px]"></i> جدول
          </button>
        </div>
      </section>

      <!-- Main Content States -->
      <div class="relative min-h-[400px]">
        
        <!-- 1. Skeleton Loading -->
        <div v-if="isLoading" class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div v-for="i in 6" :key="i" class="h-48 bg-white border border-slate-200 rounded-xl animate-pulse"></div>
        </div>

        <!-- 2. Empty State -->
        <div v-else-if="!filteredContacts.length" class="py-24 text-center bg-white border border-slate-200 rounded-xl">
          <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-users-slash text-2xl"></i>
          </div>
          <h3 class="text-sm font-bold text-slate-900 uppercase tracking-widest">لا يوجد عملاء</h3>
          <p class="text-xs text-slate-400 mt-1">لم يتم العثور على عملاء يطابقون معايير البحث.</p>
          <button @click="fetchContacts" class="mt-6 text-xs font-bold text-blue-600 hover:underline">إعادة تحميل البيانات</button>
        </div>

        <!-- 3. View Mode: Cards -->
        <div v-else-if="viewMode === 'cards'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
          <div v-for="contact in filteredContacts" :key="contact.id" class="bg-white border border-slate-200 rounded-xl p-6 hover:border-blue-500/30 hover:shadow-md transition-all group flex flex-col justify-between">
            <div class="space-y-4">
              <div class="flex items-start justify-between">
                <div class="w-10 h-10 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 group-hover:text-blue-600 transition-colors border border-slate-100 group-hover:border-blue-100">
                  <i class="fas fa-user text-sm"></i>
                </div>
                <button v-if="contact.balance !== 0" @click="openPaymentModal(contact)" class="w-7 h-7 rounded-md bg-emerald-50 text-emerald-600 flex items-center justify-center hover:bg-emerald-600 hover:text-white transition-all shadow-sm" title="تحصيل دفعة">
                  <i class="fas fa-hand-holding-usd text-[10px]"></i>
                </button>
              </div>
              <div>
                <h3 class="text-sm font-bold text-slate-900 truncate group-hover:text-blue-600 transition-colors">{{ contact.name }}</h3>
                <p class="text-[10px] font-bold text-slate-400 font-mono tracking-tighter mt-1">{{ contact.phone || '—' }}</p>
              </div>
              <div class="p-3 rounded-lg bg-slate-50/50 border border-slate-100 group-hover:bg-white transition-all">
                <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">الرصيد</p>
                <p :class="[contact.balance > 0 ? 'text-rose-600' : contact.balance < 0 ? 'text-emerald-600' : 'text-slate-400']" class="text-sm font-bold font-mono tracking-tighter">
                  {{ formatPrice(Math.abs(contact.balance)) }}
                  <span class="text-[9px] font-bold mr-1 opacity-60">{{ contact.balance > 0 ? 'مدين' : contact.balance < 0 ? 'دائن' : 'مستقر' }}</span>
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

        <!-- 4. View Mode: Table -->
        <div v-else-if="viewMode === 'table'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-slate-50/50 border-b border-slate-200">
                  <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الاسم والتعريف</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">رقم الهاتف</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الرصيد المالي</th>
                  <th class="px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium text-xs">
                <tr v-for="contact in paginatedContacts" :key="contact.id" class="hover:bg-blue-50/20 transition-all group">
                  <td class="px-6 py-4">
                    <div class="flex flex-col">
                      <span class="font-bold text-slate-900 leading-none group-hover:text-blue-600 transition-colors">{{ contact.name }}</span>
                      <span class="text-[9px] font-bold text-slate-400 font-mono mt-1 uppercase tracking-widest">ID: {{ contact.code || '---' }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-4 text-slate-600 font-mono">{{ contact.phone || '-' }}</td>
                  <td class="px-4 py-4 text-center">
                    <span :class="[contact.balance > 0 ? 'text-rose-600 border-rose-100 bg-rose-50' : contact.balance < 0 ? 'text-emerald-600 border-emerald-100 bg-emerald-50' : 'text-slate-400 bg-slate-50 border-slate-100']" class="px-2 py-0.5 rounded text-[10px] font-bold border font-mono">
                      {{ formatPrice(Math.abs(contact.balance || 0)) }}
                      {{ contact.balance > 0 ? 'مدين' : contact.balance < 0 ? 'دائن' : '' }}
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
              إجمالي <span class="text-slate-900">{{ filteredContacts.length }}</span> عميل
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
    
    <!-- Customer Form -->
    <Teleport to="body">
    <transition name="fade">
      <div v-if="showFormModal" class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md">
        <div class="bg-white w-full max-w-xl rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn flex flex-col max-h-[90vh]">
          <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white"><i :class="selectedContact?.id ? 'fas fa-user-edit' : 'fas fa-user-plus'" class="text-xs"></i></div>
              <h3 class="text-sm font-bold text-slate-900 uppercase">{{ selectedContact?.id ? 'تحديث ملف العميل' : 'إضافة عميل جديد' }}</h3>
            </div>
            <button @click="showFormModal = false" class="text-slate-400 hover:text-slate-900 transition-colors"><i class="fas fa-times text-lg"></i></button>
          </div>
          <form @submit.prevent="handleFormSubmit(selectedContact)" class="p-8 space-y-6 overflow-y-auto">
            <div class="space-y-1.5"><label class="metadata-label">الاسم بالكامل <span class="text-rose-500">*</span></label><input type="text" v-model="selectedContact.name" class="filter-input-v2 h-10 font-bold" required /></div>
            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5"><label class="metadata-label">رقم الهاتف</label><input type="text" v-model="selectedContact.phone" class="filter-input-v2 h-10 font-mono" /></div>
              <div class="space-y-1.5"><label class="metadata-label">البريد الإلكتروني</label><input type="email" v-model="selectedContact.email" class="filter-input-v2 h-10" /></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5"><label class="metadata-label">الرقم الضريبي</label><input type="text" v-model="selectedContact.tax_number" class="filter-input-v2 h-10 font-mono" /></div>
              <div class="space-y-1.5"><label class="metadata-label">حد الائتمان</label><input type="number" v-model.number="selectedContact.credit_limit" class="filter-input-v2 h-10 font-mono text-blue-600" /></div>
            </div>
            <div class="space-y-1.5"><label class="metadata-label">العنوان</label><textarea rows="2" v-model="selectedContact.address" class="filter-input-v2 h-auto py-2"></textarea></div>
            <div class="pt-4 border-t border-slate-100 flex justify-end gap-3">
              <button type="button" @click="showFormModal = false" class="px-6 h-10 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">إلغاء</button>
              <button type="submit" class="px-10 h-10 bg-blue-600 text-white rounded-md text-xs font-bold shadow-lg shadow-blue-900/20 active:scale-95 transition-all">حفظ التغييرات</button>
            </div>
          </form>
        </div>
      </div>
    </transition>
    </Teleport>

    <!-- Payment Collection Modal — Teleported to body -->
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
                <h3 class="text-sm font-bold text-slate-900">تحصيل دفعة من</h3>
                <p class="text-[10px] text-slate-400 mt-0.5 font-medium">{{ selectedContact?.name }}</p>
              </div>
              <button type="button" @click="showPaymentModal = false"
                      class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-900 hover:bg-slate-100 transition-all flex items-center justify-center">
                <i class="fas fa-times text-xs"></i>
              </button>
            </div>

            <!-- Form -->
            <form @submit.prevent="handlePaymentSubmit" class="px-8 py-6 space-y-4">
              <p v-if="hasPendingCustomerPayment" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[10px] font-semibold text-amber-800">
                توجد دفعة لم تُحسم نتيجتها. أعد المحاولة بالبيانات نفسها قبل بدء دفعة أخرى.
              </p>
              <!-- المبلغ -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">
                  المبلغ <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="paymentData.amount"
                  :disabled="hasPendingCustomerPayment"
                  type="number" step="0.01" min="0.01"
                  class="h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all font-mono"
                  placeholder="0.00" required
                />
                <button
                  v-if="Number(selectedContact?.balance) > 0"
                  type="button"
                  @click="paymentData.amount = Number(selectedContact.balance)"
                  :disabled="hasPendingCustomerPayment"
                  class="text-[9px] font-bold text-blue-600 hover:underline px-1"
                >
                  تحصيل الرصيد كاملاً ({{ formatPrice(Number(selectedContact?.balance)) }})
                </button>
              </div>

              <!-- تاريخ الدفع -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">
                  تاريخ الدفع <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="paymentData.payment_date"
                  :disabled="hasPendingCustomerPayment"
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
                  v-model.number="paymentData.payment_method_id"
                  :disabled="hasPendingCustomerPayment"
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
                  :disabled="hasPendingCustomerPayment"
                  class="h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all appearance-none"
                  required
                >
                  <option value="">اختر الفرع</option>
                  <option v-for="w in branches" :key="w.id" :value="w.id">{{ w.name || w.branch_name }}</option>
                </select>
              </div>

              <!-- ملاحظات -->
              <div class="space-y-1.5">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">ملاحظات</label>
                <textarea
                  v-model="paymentData.notes"
                  :disabled="hasPendingCustomerPayment"
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
                <button
                  type="submit"
                  :disabled="hasPendingCustomerPayment && !paymentData.amount"
                  class="flex-[2] h-10 bg-emerald-600 text-white rounded-md text-xs font-bold shadow-lg shadow-emerald-900/20 active:scale-95 transition-all"
                >
                  {{ hasPendingCustomerPayment ? 'إعادة المحاولة بنفس البيانات' : 'تأكيد التحصيل' }}
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
import { ref, computed, onMounted, watch } from 'vue';
import { useToast } from '@/composables/useToast';
import { useTableFilters } from '@/composables/useTableFilters';
import { getLocalDateISO } from '@/utils';
import { useRouter } from 'vue-router';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import AlertService from '@/services/AlertService';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useSessionExemption } from '@/composables/useCashierSessionGuard';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { usePendingIdempotentRequest } from '@/composables/usePendingIdempotentRequest';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import PageHeader from '@/components/PageHeader.vue';

const { showToast } = useToast(); 
const router = useRouter();

// --- Logic Initialization ---
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { ensureLoaded: ensureExemptionLoaded, isExempt } = useSessionExemption();
const { breadcrumb } = useBreadcrumb();
const authStore = useAuthStore();
const branchStore = useBranchStore();
const branchIsolation = useBranchIsolation();
const customerStore = useCustomerStore();
const paymentStore = usePaymentStore();

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
  branchStore.setSelectedBranch(newBranchId);
  fetchContacts(); // Reload data with new branch
};
const formatPrice = (amount) => formatCurrencyLocale(amount, 2);

const contacts = ref([]);
const search = ref('');
const showFormModal = ref(false);
const isLoading = ref(false);
const selectedContact = ref(null);
const viewMode = ref('cards'); 
const showPaymentModal = ref(false);
const paymentData = ref({ amount: null, payment_date: null, payment_method_id: null, branch_id: null, notes: '' });
const pendingCustomerPayment = usePendingIdempotentRequest('customer-payment');
const hasPendingCustomerPayment = computed(() => !!pendingCustomerPayment.pending.value);

// Date input refs
const paymentDateRef = ref(null);
const paymentMethods = ref([]);
const collectiblePaymentMethods = computed(() =>
  paymentMethods.value.filter(method => String(method.kind || '').toLowerCase() !== 'credit')
);

// Logic: Filters
const filteredContacts = computed(() => {
  if (!search.value) return contacts.value;
  const q = search.value.toLowerCase();
  return contacts.value.filter(c => (c.name && c.name.toLowerCase().includes(q)) || (c.phone && c.phone.includes(q)));
});

// ─── Pagination (using useTableFilters composable)
const filters = useTableFilters('customers_filters');

const paginatedContacts = computed(() => {
  filters.totalCount.value = filteredContacts.value.length;
  const start = (filters.page.value - 1) * filters.perPage.value;
  const end = start + filters.perPage.value;
  return filteredContacts.value.slice(start, end);
});

// Logic: KPIs
const kpis = computed(() => {
  const debtors = contacts.value.filter(c => Number(c.balance) > 0);
  const totalReceivable = debtors.reduce((sum, c) => sum + Number(c.balance || 0), 0);
  const topDebtor = [...debtors].sort((a, b) => Number(b.balance) - Number(a.balance))[0];
  return [
    { title: 'إجمالي الذمم المدينة', value: formatPrice(totalReceivable), icon: 'fas fa-file-invoice-dollar' },
    { title: 'عملاء مدينين حالياً', value: debtors.length, icon: 'fas fa-user-clock' },
    { title: 'أكبر رصيد مدين', value: topDebtor ? `${topDebtor.name} (${formatPrice(Number(topDebtor.balance))})` : 'لا يوجد', icon: 'fas fa-arrow-trend-up' }
  ];
});

const fetchContacts = async () => {
  isLoading.value = true;
  try {
    let params = {};
    if (!isExempt.value) {
      try {
        const wid = branchIsolation.getRequiredBranchId();
        params.branch_id = String(wid);
      } catch (e) {
        contacts.value = [];
        showToast(e.message || 'لم يتم تعيين مخزن للمستخدم', 'warning');
        return;
      }
    } else if (selectedBranch.value) {
      params.branch_id = String(selectedBranch.value);
    }
    // isExempt + no branch selected → no branch_id param → API returns all customers
    const res = await customerStore.fetchCustomers({ force: true, params });
    contacts.value = Array.isArray(res) ? res : (res.data || []);
  } catch (err) {
    showToast(err.response?.data?.message || 'حدث خطأ في التحميل', 'error');
  } finally { isLoading.value = false; }
};

const goToStatement = (c) => router.push({ name: 'AccountStatement', params: { type: 'customers', id: c.id } });
const openAddModal = () => {
  let branchId = null;
  if (!isExempt.value) {
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
const viewContact = (c) => { try { sessionStorage.setItem('selectedContact', JSON.stringify(c)); } catch {} router.push({ name: 'ContactDetails', params: { type: 'customers', id: c.id } }); };

const deleteContact = async (c) => {
  if (await AlertService.confirm(`هل أنت متأكد من حذف ${c.name}?`, 'حذف العميل')) {
    try {
      const result = await customerStore.deleteCustomer(c.id);
      if (result?.status !== 'success') {
        showToast(result?.message || 'فشل حذف العميل', 'error');
        return;
      }
      showToast('تم الحذف بنجاح', 'success');
      fetchContacts();
    } catch (e) { showToast(e.response?.data?.message || 'فشل الحذف', 'error'); }
  }
};

const handleFormSubmit = async (formData) => {
  try {
    if (formData.id) await customerStore.updateCustomer(formData.id, formData);
    else await customerStore.createCustomer(formData);
    showToast('تم الحفظ بنجاح', 'success');
    showFormModal.value = false;
    fetchContacts();
  } catch (e) { showToast(e.response?.data?.message || 'خطأ في الحفظ', 'error'); }
};

const openPaymentModal = (c) => {
  selectedContact.value = c;
  try {
    const pending = pendingCustomerPayment.restore(c.id);
    paymentData.value = pending?.payload
      ? { ...pending.payload }
      : {
          amount: Number(c.balance) > 0 ? Number(c.balance) : null,
          payment_date: getLocalDateISO(),
          payment_method_id: null,
          branch_id: branchStore.selectedBranchId || null,
          notes: '',
        };
    showPaymentModal.value = true;
  } catch (error) {
    showToast(error.message || 'تعذر استعادة عملية التحصيل السابقة.', 'error');
  }
};

const handlePaymentSubmit = async () => {
  if (!paymentData.value.amount || paymentData.value.amount <= 0) return showToast('ادخل مبلغ صحيح', 'error');
  if (!paymentData.value.payment_method_id) return showToast('اختر طريقة الدفع', 'error');
  if (!paymentData.value.payment_date) { showToast('الرجاء تحديد تاريخ الدفع', 'error'); return; }
  try {
    const customerId = selectedContact.value?.id;
    const payload = {
      amount: Number(paymentData.value.amount),
      payment_date: paymentData.value.payment_date,
      payment_method_id: Number(paymentData.value.payment_method_id),
      branch_id: paymentData.value.branch_id ? Number(paymentData.value.branch_id) : undefined,
      notes: paymentData.value.notes || '',
    };
    const pending = pendingCustomerPayment.prepare(customerId, payload);
    const res = await customerStore.recordCustomerPayment(customerId, pending.payload, pending.key);
    if (res.status === 'success') {
      pendingCustomerPayment.clear(customerId);
      showToast(`تم التسجيل بنجاح`, 'success');
      showPaymentModal.value = false;
      fetchContacts();
    } else {
      if (res.outcomeUnknown) {
        showToast('تعذر تأكيد نتيجة التحصيل. أعد المحاولة بالبيانات نفسها.', 'error');
      } else {
        pendingCustomerPayment.clear(customerId);
        showToast(res.message || 'فشل التسجيل', 'error');
      }
    }
  } catch (e) {
    showToast(e.response?.data?.message || e.message || 'فشل التسجيل', 'error');
  }
};

onMounted(async () => {
  await Promise.all([fetchSettings(), ensureExemptionLoaded(), paymentStore.fetchPaymentMethods().catch(() => {})]);
  paymentMethods.value = paymentStore.paymentMethods;
  // ✅ replaced initializePreferences with branchStore.fetchBranches()
  await branchStore.fetchBranches();
  fetchContacts();
});

</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
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