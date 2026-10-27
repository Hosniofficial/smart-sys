<template>

  <BaseModal :show="show" @close="$emit('close')" maxWidth="2xl" :showCloseButton="false">
    <template #header>
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white text-sm shadow-lg shadow-blue-900/20">
          <i :class="editMode ? 'fas fa-pen-to-square' : 'fas fa-plus-circle'"></i>
        </div>
        <div>
          <h3 class="text-sm font-bold text-slate-900 uppercase tracking-tight">{{ editMode ? 'تعديل سند مالي' : 'إصدار سند جديد' }}</h3>

            <p class="text-[10px] text-slate-400 font-mono mt-1">{{ localForm.reference_number || 'سند مالي معتمد' }}</p>

          </div>

        </div>
      </template>

      <!-- Body -->
      <form @submit.prevent="handleSave" class="space-y-8" dir="rtl">

        <div v-if="listsLoading" class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-blue-700 text-sm font-black text-center flex items-center justify-center gap-2">

          <BaseSpinner :size="16" color="#3b82f6" />

          <span>جاري تحميل القوائم...</span>

        </div>

        <div v-if="displayError" class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-black text-center">

          <i class="fas fa-exclamation-triangle ml-2"></i>{{ displayError }}

        </div>

        <!-- Step 1: Core Info -->

        <div class="grid grid-cols-2 gap-6 bg-slate-50/50 p-6 rounded-xl border border-slate-200">

          <div class="space-y-1.5">

            <label class="metadata-label">نوع العملية</label>

            <select v-model="localForm.type" class="form-input-v3 font-bold" required>

              <option value="receipt">سند قبض (وارد)</option>

              <option value="payment">سند صرف (صادر)</option>

            </select>

          </div>

          <div class="space-y-1.5">

            <label class="metadata-label">تاريخ السند</label>

            <div class="relative">

              <input ref="voucherDateRef" type="date" v-model="localForm.date" class="form-input-v3 font-mono" style="padding-left: 2rem;" required />

              <i @click="voucherDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>

            </div>

          </div>

          <div class="col-span-2 space-y-1.5">

            <label class="metadata-label">الفرع المرتبط</label>

            <select v-model="localForm.branch_id" class="form-input-v3" :required="!isExempt">

              <option value="" disabled>-- اختر الفرع --</option>

              <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>

            </select>

          </div>

        </div>

        <!-- Step 2: Financials -->

        <div class="grid grid-cols-2 gap-6">

          <div class="space-y-1.5">

            <label class="metadata-label">المبلغ</label>

            <div class="relative">

              <input type="number" step="0.01" min="0.01" v-model.number="localForm.amount" class="h-14 w-full bg-white border-2 border-slate-100 rounded-xl px-4 text-2xl font-bold text-center text-blue-600 outline-none focus:border-blue-500 transition-all" required placeholder="0.00" />

              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[10px] font-black text-slate-300 uppercase">{{ currencySymbol }}</span>

            </div>

          </div>

          <div class="space-y-1.5">

            <label class="metadata-label">طريقة الدفع</label>

            <select v-model="localForm.payment_method_id" @change="updateAccount" class="form-input-v3 h-14 font-bold" required>

              <option value="">-- اختر الطريقة --</option>

              <option v-for="m in paymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option>

            </select>

            <div v-if="localForm.account_id" class="text-[9px] font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded border border-blue-100">

               <i class="fas fa-link ml-1"></i> الحساب المرتبط: {{ getAccountName(localForm.account_id) }}

            </div>

          </div>

        </div>

        <!-- Step 3: Counterparty -->

        <div class="space-y-6">

          <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1 border-b border-slate-100 pb-2">تفاصيل الطرف المقابل</h4>

          <div v-if="localForm.type === 'receipt'" class="space-y-4">

            <div class="space-y-1.5">

              <label class="metadata-label">العميل</label>

              <select v-model="localForm.customer_id" class="form-input-v3 font-bold">

                <option :value="null">-- إيراد عام (بدون عميل) --</option>

                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>

              </select>

            </div>

            <div v-if="localForm.customer_id" class="space-y-1.5">

              <label class="metadata-label">ربط بفاتورة مبيعات <span class="text-slate-400 font-normal normal-case">(اختياري)</span></label>

              <div v-if="customerSalesLoading" class="text-xs text-slate-400 font-bold py-2 flex items-center justify-center h-14">جاري تحميل الفواتير...</div>

              <p v-else-if="customerSalesError" class="text-xs text-rose-600 font-bold py-2">{{ customerSalesError }}</p>

              <select v-else v-model="localForm.sale_id" class="form-input-v3 h-14 font-bold">

                <option :value="null">— بدون ربط —</option>

                <option v-if="customerPendingSales.length === 0" disabled>لا توجد فواتير مستحقة</option>

                <option v-for="s in customerPendingSales" :key="s.id" :value="s.id">

                  {{ s.invoice_number }} — {{ formatPrice(s.net_total_amount) }} (متبقي: {{ formatPrice(s.remaining_balance ?? (s.net_total_amount - (s.actual_paid_amount || s.paid_amount || 0))) }})

                </option>

              </select>

            </div>

            <div v-if="localForm.customer_id" class="p-4 bg-amber-50 border border-amber-100 rounded-xl flex items-start gap-3">

              <i class="fas fa-circle-exclamation text-amber-500 mt-0.5"></i>

              <p class="text-[11px] font-bold text-amber-800 leading-relaxed italic">تنبيه محاسبي: لن يتم قبول السند إذا كان رصيد العميل دائن (له مبلغ). القبض مخصص فقط لتسديد المديونيات المستحقة. لرد المبالغ للعميل يرجى استخدام سند صرف.</p>

            </div>

          </div>

          <div v-else class="space-y-6">

            <div class="flex gap-6 px-1">

              <label class="flex items-center gap-2 cursor-pointer group">

                <input type="radio" v-model="localForm.payment_to_type" value="supplier" class="text-blue-600 focus:ring-0" />

                <span class="text-xs font-bold text-slate-600 group-hover:text-slate-900">مورد</span>

              </label>

              <label class="flex items-center gap-2 cursor-pointer group">

                <input type="radio" v-model="localForm.payment_to_type" value="expense" class="text-blue-600 focus:ring-0" />

                <span class="text-xs font-bold text-slate-600 group-hover:text-slate-900">مصروف</span>

              </label>

            </div>

            <div v-if="localForm.payment_to_type === 'supplier'" class="space-y-4 animate-fadeIn">

              <div class="space-y-1.5">

                <label class="metadata-label">المورد</label>

                <select v-model="localForm.supplier_id" class="form-input-v3 font-bold">

                  <option value="">-- اختر المورد --</option>

                  <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>

                </select>

              </div>

              <div v-if="localForm.supplier_id" class="space-y-1.5">

                <label class="metadata-label">ربط بفاتورة شراء <span class="text-slate-400 font-normal normal-case">(اختياري)</span></label>

                <div v-if="supplierPurchasesLoading" class="text-xs text-slate-400 font-bold py-2">جاري تحميل الفواتير...</div>

                <select v-else v-model="localForm.purchase_id" class="form-input-v3 font-bold">

                  <option :value="null">— بدون ربط بفاتورة —</option>

                  <option v-if="supplierPendingPurchases.length === 0" disabled>لا توجد فواتير مستحقة لهذا المورد</option>

                  <option v-for="p in supplierPendingPurchases" :key="p.id" :value="p.id">

                    {{ p.invoice_number }} — {{ formatPrice(p.total_amount) }} (متبقي: {{ formatPrice(p.remaining_balance ?? (p.total_amount - (p.actual_paid_amount || p.paid_amount || 0))) }})

                  </option>

                </select>

              </div>

            </div>

            <div v-else class="space-y-1.5 animate-fadeIn">

              <label class="metadata-label">حساب المصروف</label>

              <select v-model="localForm.expense_account_id" class="form-input-v3 font-bold">

                <option value="">-- اختر المصروف --</option>

                <option v-for="e in expenses" :key="e.id" :value="e.id">{{ e.name }}</option>

              </select>

            </div>

          </div>

        </div>

        <div class="space-y-1.5">

          <label class="metadata-label">البيان / الوصف</label>

          <textarea v-model="localForm.description" rows="2" class="form-input-v3 h-auto p-3 italic text-slate-500 font-medium" placeholder="اكتب تفاصيل إضافية للعملية..."></textarea>

        </div>

        <!-- Footer -->

        <div class="pt-4 border-t border-slate-100 flex justify-end gap-3 shrink-0">

          <button type="button" @click="$emit('close')" class="px-6 h-10 text-xs font-bold text-slate-500">إلغاء</button>

          <LoadingButton
            :text="editMode ? 'تحديث السند' : 'إصدار السند الآن'"
            :isLoading="isSaving"
            :loadingText="editMode ? 'جاري التحديث...' : 'جاري الإصدار...'"
            variant="primary"
            loaderType="dots"
            type="submit"
            class="px-10 h-10"
          />

        </div>

      </form>

  </BaseModal>

</template>

<script setup>

import { ref, computed, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';

import { useToast } from '@/composables/useToast';

import { useSalesStore } from '@/stores/sales/salesStore';

import { usePurchaseStore } from '@/stores/purchase/purchaseStore';

const props = defineProps({

  show: Boolean, editMode: Boolean, initialData: Object, branches: Array,

  customers: Array, suppliers: Array, expenses: Array, paymentMethods: Array,

  accounts: Array, isSaving: Boolean, isExempt: Boolean, currencySymbol: String,

  serverError: { type: String, default: '' },

  listsLoading: { type: Boolean, default: false }

});

const emit = defineEmits(['close', 'save']);

const { showToast } = useToast();

const salesStore = useSalesStore();

const purchaseStore = usePurchaseStore();

const localForm = ref(JSON.parse(JSON.stringify(props.initialData)));

const localError = ref('');

const displayError = computed(() => localError.value || props.serverError);

const formatPrice = (amount) => {

  if (!amount) return '0';

  return Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2 });

};

const updateAccount = () => {

  const pm = props.paymentMethods.find(m => m.id === localForm.value.payment_method_id);

  if (pm?.account_id) localForm.value.account_id = pm.account_id;

};

const getAccountName = (accountId) => {

  if (!accountId) return '';

  return props.accounts.find(a => a.id === accountId)?.name || '(حساب غير موجود)';

};

const customerSales = ref([]);

const customerSalesLoading = ref(false);

const customerSalesError = ref('');

const customerPendingSales = computed(() =>

  customerSales.value.filter(s => {

    const remaining = s.remaining_balance ?? (s.net_total_amount - (s.actual_paid_amount || s.paid_amount || 0));

    const allowedStatuses = ['pending_payment', 'partial', 'due'];
    const status = String(s.status || '').toLowerCase();
    const dynamicStatus = String(s.dynamic_status || s.status || '').toLowerCase();

    return remaining > 0
      && allowedStatuses.includes(status)
      && allowedStatuses.includes(dynamicStatus);

  })

);

const loadCustomerSales = async (customerId) => {

  customerSales.value = [];
  customerSalesError.value = '';

  if (!customerId) return;

  customerSalesLoading.value = true;

  try {

    const res = await salesStore.fetchSalesList({
      customerId,
      branchId: localForm.value.branch_id || undefined,
      perPage: 100,
      force: true
    });
    if (res?.status !== 'success') {
      throw new Error(res?.message || 'تعذر تحميل فواتير العميل.');
    }
    customerSales.value = res?.data?.items || [];

  } catch (error) {
    customerSales.value = [];
    customerSalesError.value = error?.message || 'تعذر تحميل فواتير العميل.';
  }

  finally { customerSalesLoading.value = false; }

};

watch(

  [() => localForm.value.customer_id, () => localForm.value.type, () => localForm.value.branch_id],

  ([custId, type]) => {

    if (custId && type === 'receipt') loadCustomerSales(custId);

    else { customerSales.value = []; localForm.value.sale_id = null; }

  }

);

const supplierPurchases = ref([]);

const supplierPurchasesLoading = ref(false);

const supplierPendingPurchases = computed(() =>

  supplierPurchases.value.filter(p => {

    const remaining = p.remaining_balance ?? (p.total_amount - (p.actual_paid_amount || p.paid_amount || 0));

    const status = p.dynamic_status || p.status;

    return remaining > 0 && !['paid', 'settled', 'closed_by_return', 'returned', 'settled_by_return'].includes(status);

  })

);

const loadSupplierPurchases = async (supplierId) => {

  supplierPurchases.value = [];

  if (!supplierId) return;

  supplierPurchasesLoading.value = true;

  try {

    const res = await purchaseStore.fetchPurchasesList({ supplierId, perPage: 100, force: true });

    supplierPurchases.value = res?.data?.items || [];

    const pending = supplierPendingPurchases.value;

    if (pending.length === 1 && !localForm.value.purchase_id) {

      localForm.value.purchase_id = pending[0].id;

      showToast(`تم ربط الفاتورة "${pending[0].invoice_number}" تلقائياً`, 'info');

    } else if (pending.length > 1 && !localForm.value.purchase_id) {

      showToast(`يوجد ${pending.length} فاتورة مستحقة - اختر الفاتورة المراد ربطها`, 'warning');

    }

  } catch { supplierPurchases.value = []; }

  finally { supplierPurchasesLoading.value = false; }

};

watch(

  [() => localForm.value.supplier_id, () => localForm.value.payment_to_type],

  ([suppId, payToType]) => {

    localForm.value.purchase_id = null;

    if (suppId && payToType === 'supplier') loadSupplierPurchases(suppId);

    else supplierPurchases.value = [];

  }

);

if (localForm.value.customer_id && localForm.value.type === 'receipt') loadCustomerSales(localForm.value.customer_id);

if (localForm.value.supplier_id && localForm.value.payment_to_type === 'supplier') loadSupplierPurchases(localForm.value.supplier_id);

const validateForm = () => {

  if (!props.isExempt && !localForm.value.branch_id) { localError.value = 'يجب اختيار المخزن.'; return false; }

  if (localForm.value.type === 'receipt' && !localForm.value.customer_id) {

    localError.value = 'يجب اختيار العميل لسند القبض.'; return false;

  }

  if (localForm.value.type === 'payment' && localForm.value.payment_to_type === 'supplier' && !localForm.value.supplier_id) {

    localError.value = 'يجب اختيار المورد لسند الصرف.'; return false;

  }

  if (localForm.value.type === 'payment' && localForm.value.payment_to_type === 'expense' && !localForm.value.expense_account_id) {

    localError.value = 'يجب اختيار حساب المصروف لسند الصرف.'; return false;

  }

  if (!localForm.value.payment_method_id) {

    localError.value = 'يجب تحديد طريقة الدفع.'; return false;

  }

  if (!localForm.value.account_id) {

    localError.value = 'طريقة الدفع المختارة ليس لها حساب مرتبط. يرجى اختيار طريقة دفع أخرى.'; return false;

  }

  if (!localForm.value.amount || Number(localForm.value.amount) <= 0) {

    localError.value = 'يجب إدخال مبلغ صحيح أكبر من صفر.'; return false;

  }

  return true;

};

const handleSave = () => {

  localError.value = '';

  if (!validateForm()) return;

  const payload = { ...localForm.value };

  if (payload.type === 'receipt') {

    payload.supplier_id = null; payload.expense_account_id = null; payload.purchase_id = null;

    if (!payload.sale_id) delete payload.sale_id;

  } else {

    payload.customer_id = null; payload.sale_id = null;

    if (payload.payment_to_type === 'supplier') { payload.expense_account_id = null; if (!payload.purchase_id) delete payload.purchase_id; }

    else { payload.supplier_id = null; payload.purchase_id = null; }

  }

  emit('save', payload);

};

watch(() => props.initialData, (nv) => { localForm.value = JSON.parse(JSON.stringify(nv)); }, { deep: true });

watch(() => props.serverError, (val) => { if (val) localError.value = ''; });

</script>

<style scoped>

.form-input-v3 { @apply h-10 w-full bg-white border border-slate-200 rounded-md px-3 text-xs font-medium focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all; }

.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.animate-fadeIn { animation: fadeIn 0.3s ease-out; }

@keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

</style>