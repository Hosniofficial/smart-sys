<template>
  <main class="min-h-screen bg-slate-50 p-4 text-slate-900 sm:p-8" dir="rtl">
    <div class="mx-auto max-w-7xl space-y-6">
      <PageHeader
        :breadcrumb="{ parent: 'العمليات', current: 'صيانة الأجهزة' }"
        title="صيانة الأجهزة"
        description="أدر عمليات استلام الأجهزة وإصلاحها، وفوترة قطع الغيار وأجور الصيانة، وتوثيق التسليم."
        :branches="branches"
        :selected-branch="branchStore.selectedBranchId"
        @branch-changed="loadOrders"
      >
        <template #controls>
          <button class="btn-primary" @click="showCreate = true">استلام جهاز جديد</button>
        </template>
      </PageHeader>

      <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div v-for="item in statusCards" :key="item.status" class="rounded-xl border border-slate-200 bg-white p-4">
          <p class="text-xs text-slate-500">{{ item.label }}</p>
          <p class="mt-2 text-2xl font-bold">{{ orders.filter(order => order.status === item.status).length }}</p>
        </div>
      </section>

      <section class="rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
          <input v-model="search" class="field flex-1" placeholder="بحث برقم الأمر أو العميل أو الهاتف أو الرقم التسلسلي" @keyup.enter="loadOrders" />
          <select v-model="statusFilter" class="field sm:w-56" @change="loadOrders">
            <option value="">كل الحالات</option>
            <option v-for="item in statuses" :key="item.value" :value="item.value">{{ item.label }}</option>
          </select>
          <button class="btn-secondary" :disabled="loading" @click="loadOrders">تحديث</button>
        </div>
        <p v-if="error" class="m-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[850px] text-right text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500">
              <tr>
                <th class="p-4">رقم الأمر</th><th class="p-4">العميل</th><th class="p-4">الجهاز</th>
                <th class="p-4">العطل</th><th class="p-4">الحالة</th><th class="p-4">تاريخ الاستلام</th><th class="p-4"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="order in orders" :key="order.id" class="border-t border-slate-100">
                <td class="p-4 font-mono font-bold">{{ order.order_number }}</td>
                <td class="p-4"><div class="font-semibold">{{ order.customer_name }}</div><div class="text-xs text-slate-500">{{ order.customer_phone }}</div></td>
                <td class="p-4">{{ [order.brand, order.model, order.device_type].filter(Boolean).join(' · ') }}</td>
                <td class="max-w-xs truncate p-4">{{ order.issue_description }}</td>
                <td class="p-4"><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusStyle(order.status)">{{ statusLabel(order.status) }}</span></td>
                <td class="p-4">{{ formatDate(order.received_at) }}</td>
                <td class="p-4"><button class="font-semibold text-blue-700 hover:underline" @click="openOrder(order.id)">التفاصيل</button></td>
              </tr>
              <tr v-if="!loading && orders.length === 0"><td colspan="7" class="p-12 text-center text-slate-400">لا توجد أوامر صيانة مطابقة.</td></tr>
              <tr v-if="loading"><td colspan="7" class="p-12 text-center text-slate-400">جارٍ تحميل أوامر الصيانة…</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <div v-if="showCreate" class="overlay" @click.self="showCreate = false">
      <section class="dialog">
        <header class="dialog-head"><h2>استلام جهاز للصيانة</h2><button @click="showCreate = false">إغلاق</button></header>
        <form class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="createOrder">
          <label class="form-label">الفرع *
            <select v-model="createForm.branch_id" class="field" required>
              <option value="" disabled>اختر الفرع</option><option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option>
            </select>
          </label>
          <label class="form-label">العميل *
            <select v-model="createForm.customer_id" class="field" required>
              <option value="" disabled>اختر العميل</option><option v-for="customer in customers" :key="customer.id" :value="String(customer.id)">{{ customer.name }} — {{ customer.phone }}</option>
            </select>
          </label>
          <label class="form-label">نوع الجهاز *<input v-model.trim="createForm.device_type" class="field" required placeholder="هاتف، حاسوب، شاشة…" /></label>
          <label class="form-label">الشركة المصنعة<input v-model.trim="createForm.brand" class="field" /></label>
          <label class="form-label">الموديل<input v-model.trim="createForm.model" class="field" /></label>
          <label class="form-label">الرقم التسلسلي / IMEI<input v-model.trim="createForm.serial_number" class="field" /></label>
          <label class="form-label">الملحقات المستلمة<input v-model.trim="createForm.accessories" class="field" placeholder="شاحن، حقيبة…" /></label>
          <label class="form-label">التكلفة التقديرية<input v-model.number="createForm.estimated_cost" class="field" type="number" min="0" step="0.01" /></label>
          <label class="form-label sm:col-span-2">وصف العطل *
            <textarea v-model.trim="createForm.issue_description" class="field min-h-20" required />
          </label>
          <label class="form-label sm:col-span-2">حالة الجهاز عند الاستلام<textarea v-model.trim="createForm.condition_received" class="field min-h-16" /></label>
          <label class="form-label">موعد الإنجاز المتوقع<input v-model="createForm.expected_completion_date" class="field" type="date" /></label>
          <p v-if="dialogError" class="sm:col-span-2 rounded bg-red-50 p-3 text-sm text-red-700">{{ dialogError }}</p>
          <footer class="flex justify-end gap-2 sm:col-span-2">
            <button type="button" class="btn-secondary" @click="showCreate = false">إلغاء</button>
            <button class="btn-primary" :disabled="saving">{{ saving ? 'جارٍ الحفظ…' : 'تأكيد الاستلام' }}</button>
          </footer>
        </form>
      </section>
    </div>

    <div v-if="selected" class="overlay" @click.self="selected = null">
      <section class="dialog max-w-4xl">
        <header class="dialog-head">
          <div><h2>أمر {{ selected.order_number }}</h2><p class="mt-1 text-xs text-slate-500">{{ selected.customer_name }} · {{ selected.customer_phone }}</p></div>
          <button @click="selected = null">إغلاق</button>
        </header>
        <div class="grid gap-5 p-5 lg:grid-cols-2">
          <div class="space-y-3">
            <div class="rounded-lg bg-slate-50 p-4 text-sm">
              <p class="font-bold">{{ [selected.brand, selected.model, selected.device_type].filter(Boolean).join(' · ') }}</p>
              <p class="mt-2 text-slate-600">الرقم التسلسلي: {{ selected.serial_number || '—' }}</p>
              <p class="mt-2 text-slate-600">الملحقات: {{ selected.accessories || '—' }}</p>
              <p class="mt-2 text-slate-600">العطل: {{ selected.issue_description }}</p>
              <p class="mt-2 text-slate-600">الحالة عند الاستلام: {{ selected.condition_received || '—' }}</p>
            </div>
            <label class="form-label">الحالة
              <select v-model="editForm.status" class="field">
                <option :value="selected.status">{{ statusLabel(selected.status) }}</option>
                <option v-for="item in nextStatuses(selected.status)" :key="item" :value="item">{{ statusLabel(item) }}</option>
              </select>
            </label>
            <label class="form-label">التشخيص<textarea v-model.trim="editForm.diagnosis" class="field min-h-16" /></label>
            <label class="form-label">الإصلاح المنفذ<textarea v-model.trim="editForm.resolution" class="field min-h-16" /></label>
            <label class="form-label">موعد الإنجاز المتوقع<input v-model="editForm.expected_completion_date" class="field" type="date" /></label>
            <label class="form-label">منتج أجرة الصيانة (مصنف كخدمة)
              <select v-model="editForm.labor_product_id" class="field" :disabled="!!selected.invoice_id">
                <option value="">بدون</option><option v-for="product in serviceProducts" :key="product.id" :value="String(product.id)">{{ product.name }}</option>
              </select>
            </label>
            <label class="form-label">أجرة الصيانة<input v-model.number="editForm.labor_amount" class="field" type="number" min="0" step="0.01" :disabled="!!selected.invoice_id" /></label>
          </div>
          <div class="space-y-3">
            <div class="flex items-center justify-between"><h3 class="font-bold">قطع الغيار</h3><span class="text-xs text-slate-500">تخصم من المخزون عند إصدار الفاتورة</span></div>
            <div v-for="(part, index) in editForm.parts" :key="index" class="grid grid-cols-12 gap-2 rounded-lg border border-slate-200 p-3">
              <select v-model="part.product_id" class="field col-span-12" :disabled="!!selected.invoice_id" @change="setPartDefaults(part)">
                <option value="">اختر قطعة الغيار</option><option v-for="product in stockProducts" :key="product.id" :value="String(product.id)">{{ product.name }}</option>
              </select>
              <select v-model="part.unit_id" class="field col-span-4" :disabled="!!selected.invoice_id">
                <option value="">الوحدة</option><option v-for="unit in unitsFor(part.product_id)" :key="unit.unit_id" :value="String(unit.unit_id)">{{ unit.unit_name }}</option>
              </select>
              <input v-model.number="part.quantity" class="field col-span-3" type="number" min="0.0001" step="0.0001" aria-label="الكمية" :disabled="!!selected.invoice_id" />
              <input v-model.number="part.sale_price" class="field col-span-3" type="number" min="0" step="0.01" aria-label="السعر" :disabled="!!selected.invoice_id" />
              <button class="col-span-2 text-red-600 disabled:opacity-40" :disabled="!!selected.invoice_id" @click="editForm.parts.splice(index, 1)">حذف</button>
            </div>
            <button class="btn-secondary w-full" :disabled="!!selected.invoice_id" @click="addPart">إضافة قطعة</button>
            <div class="rounded-lg bg-blue-50 p-3 text-sm font-semibold">إجمالي القطع: {{ partsTotal.toFixed(2) }}</div>
            <div v-if="selected.invoice_id" class="rounded-lg bg-emerald-50 p-3 text-sm">
              أصدرت الفاتورة رقم {{ selected.invoice_number || selected.invoice_id }}.
              <RouterLink class="mr-2 font-bold text-blue-700 underline" :to="{ path: '/sales/history', query: { id: selected.invoice_id } }">فتح سجل الفاتورة وتحصيلها</RouterLink>
            </div>
            <button v-else-if="selected.status === 'ready'" class="btn-primary w-full" :disabled="saving" @click="issueInvoice">إصدار فاتورة قطع الغيار وأجرة الصيانة</button>
          </div>
          <p v-if="dialogError" class="rounded bg-red-50 p-3 text-sm text-red-700 lg:col-span-2">{{ dialogError }}</p>
          <footer class="flex flex-wrap justify-end gap-2 lg:col-span-2">
            <button class="btn-secondary" @click="selected = null">إغلاق</button>
            <button class="btn-primary" :disabled="saving || selected.status === 'delivered' || selected.status === 'cancelled'" @click="saveOrder">{{ saving ? 'جارٍ الحفظ…' : 'حفظ التحديثات' }}</button>
          </footer>
        </div>
      </section>
    </div>
  </main>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import apiClient from '@/config/axios'
import PageHeader from '@/components/PageHeader.vue'
import RepairOrdersService from '@/services/repairOrders'
import { useBranchIsolation } from '@/composables/useBranchIsolation'
import { useAuthStore } from '@/stores/auth/authStore'
import { useBranchStore } from '@/stores/branch/branchStore'

const authStore = useAuthStore()
const branchStore = useBranchStore()
const branchIsolation = useBranchIsolation()
const branches = computed(() => {
  const assignedBranchId = authStore.user?.branch_id
  if (!branchIsolation.isExempt.value && assignedBranchId) {
    return branchStore.branches.filter(branch => String(branch.id) === String(assignedBranchId))
  }
  return branchStore.branches
})
const customers = ref([])
const products = ref([])
const orders = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const dialogError = ref('')
const search = ref('')
const statusFilter = ref('')
const showCreate = ref(false)
const selected = ref(null)
const statuses = [
  { value: 'received', label: 'تم الاستلام' }, { value: 'diagnosing', label: 'قيد التشخيص' },
  { value: 'waiting_approval', label: 'بانتظار موافقة العميل' }, { value: 'waiting_parts', label: 'بانتظار قطع الغيار' },
  { value: 'in_repair', label: 'قيد الإصلاح' }, { value: 'ready', label: 'جاهز للتسليم' },
  { value: 'delivered', label: 'تم التسليم' }, { value: 'cancelled', label: 'ملغي' }
]
const transitions = {
  received: ['diagnosing', 'cancelled'],
  diagnosing: ['waiting_approval', 'waiting_parts', 'in_repair', 'cancelled'],
  waiting_approval: ['waiting_parts', 'in_repair', 'cancelled'],
  waiting_parts: ['in_repair', 'cancelled'],
  in_repair: ['waiting_parts', 'ready', 'cancelled'],
  ready: ['in_repair', 'delivered', 'cancelled'],
  delivered: [],
  cancelled: []
}
const statusCards = [
  { status: 'received', label: 'مستلم' },
  { status: 'in_repair', label: 'قيد الإصلاح' },
  { status: 'ready', label: 'جاهز للتسليم' },
  { status: 'delivered', label: 'تم التسليم' }
]
const stockProducts = computed(() => products.value.filter(product => product.product_type === 'stock'))
const serviceProducts = computed(() => products.value.filter(product => product.product_type === 'service'))
const partsTotal = computed(() => editForm.parts.reduce((sum, part) => sum + (Number(part.quantity) || 0) * (Number(part.sale_price) || 0), 0))
const emptyCreate = () => ({
  branch_id: branchIsolation.isExempt.value
    ? (branchStore.selectedBranchId ? String(branchStore.selectedBranchId) : '')
    : (authStore.user?.branch_id ? String(authStore.user.branch_id) : ''),
  customer_id: '', device_type: '', brand: '', model: '', serial_number: '',
  accessories: '', issue_description: '', condition_received: '', estimated_cost: 0,
  expected_completion_date: ''
})
const createForm = reactive(emptyCreate())
const editForm = reactive({ status: '', diagnosis: '', resolution: '', labor_product_id: '', labor_amount: 0, expected_completion_date: '', parts: [] })

function unwrap(payload) {
  return payload?.data?.data ?? payload?.data ?? []
}

async function loadOrders() {
  loading.value = true
  error.value = ''
  try {
    const params = {}
    if (branchStore.selectedBranchId) params.branch_id = branchStore.selectedBranchId
    if (statusFilter.value) params.status = statusFilter.value
    if (search.value.trim()) params.q = search.value.trim()
    orders.value = await RepairOrdersService.list(params)
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'تعذر تحميل أوامر الصيانة.'
  } finally {
    loading.value = false
  }
}

function formatDate(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ar-EG', { dateStyle: 'medium' }).format(new Date(value))
}
function statusLabel(status) {
  return statuses.find(item => item.value === status)?.label || status
}
function statusStyle(status) {
  if (status === 'ready' || status === 'delivered') return 'bg-emerald-100 text-emerald-800'
  if (status === 'cancelled') return 'bg-red-100 text-red-700'
  if (status === 'waiting_parts' || status === 'waiting_approval') return 'bg-amber-100 text-amber-800'
  return 'bg-blue-100 text-blue-800'
}
function nextStatuses(status) { return transitions[status] || [] }
function unitsFor(productId) {
  const product = products.value.find(item => String(item.id) === String(productId))
  return product?.unit_id ? [{ unit_id: product.unit_id, unit_name: product.unit_name, is_main_unit: 1 }] : []
}
function setPartDefaults(part) {
  const product = products.value.find(item => String(item.id) === String(part.product_id))
  if (!product) return
  part.unit_id = String(product.unit_id || '')
  part.sale_price = Number(product.sale_price) || 0
}
function addPart() {
  editForm.parts.push({ product_id: '', unit_id: '', quantity: 1, sale_price: 0 })
}

async function openOrder(id) {
  dialogError.value = ''
  try {
    selected.value = await RepairOrdersService.get(id)
    Object.assign(editForm, {
      status: selected.value.status,
      diagnosis: selected.value.diagnosis || '',
      resolution: selected.value.resolution || '',
      labor_product_id: selected.value.labor_product_id ? String(selected.value.labor_product_id) : '',
      labor_amount: Number(selected.value.labor_amount) || 0,
      expected_completion_date: selected.value.expected_completion_date || '',
      parts: (selected.value.parts || []).map(part => ({
        product_id: String(part.product_id), unit_id: String(part.unit_id),
        quantity: Number(part.quantity), sale_price: Number(part.sale_price)
      }))
    })
  } catch (err) {
    error.value = err.response?.data?.message || err.message
  }
}

async function createOrder() {
  saving.value = true
  dialogError.value = ''
  try {
    const created = await RepairOrdersService.create({
      ...createForm,
      branch_id: Number(createForm.branch_id),
      customer_id: Number(createForm.customer_id),
      estimated_cost: Number(createForm.estimated_cost) || 0
    })
    showCreate.value = false
    Object.assign(createForm, emptyCreate())
    await loadOrders()
    await openOrder(created.id)
  } catch (err) {
    dialogError.value = err.response?.data?.message || err.message
  } finally {
    saving.value = false
  }
}

async function saveOrder() {
  if (!selected.value) return
  saving.value = true
  dialogError.value = ''
  try {
    const payload = {
      status: editForm.status,
      diagnosis: editForm.diagnosis,
      resolution: editForm.resolution,
      expected_completion_date: editForm.expected_completion_date
    }
    if (!selected.value.invoice_id) {
      payload.labor_product_id = editForm.labor_product_id || null
      payload.labor_amount = Number(editForm.labor_amount) || 0
      payload.parts = editForm.parts.filter(part => part.product_id).map(part => ({
        product_id: Number(part.product_id), unit_id: Number(part.unit_id),
        quantity: Number(part.quantity), sale_price: Number(part.sale_price)
      }))
    }
    await RepairOrdersService.update(selected.value.id, payload)
    await openOrder(selected.value.id)
    await loadOrders()
  } catch (err) {
    dialogError.value = err.response?.data?.message || err.message
  } finally {
    saving.value = false
  }
}

async function issueInvoice() {
  if (!selected.value || !window.confirm('سيتم إنشاء فاتورة بيع وخصم قطع الغيار من مخزون هذا الفرع. هل تريد المتابعة؟')) return
  saving.value = true
  dialogError.value = ''
  try {
    await RepairOrdersService.createInvoice(selected.value.id)
    await openOrder(selected.value.id)
    await loadOrders()
  } catch (err) {
    dialogError.value = err.response?.data?.message || err.message
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    await Promise.all([branchStore.fetchBranches(), apiClient.get('/customers').then(response => { customers.value = unwrap(response) })])
    const branchId = branchStore.selectedBranchId
    const response = await apiClient.get('/products', { params: branchId ? { branch_id: branchId } : {} })
    products.value = unwrap(response)
    if (!createForm.branch_id && branchId) createForm.branch_id = String(branchId)
    await loadOrders()
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'تعذر تحميل بيانات الصيانة.'
  }
})
</script>

<style scoped>
.field { @apply w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100; }
.form-label { @apply flex flex-col gap-1.5 text-xs font-semibold text-slate-600; }
.btn-primary { @apply rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50; }
.btn-secondary { @apply rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50; }
.overlay { @apply fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/50 p-3; }
.dialog { @apply max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-2xl; }
.dialog-head { @apply sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white p-5; }
</style>
