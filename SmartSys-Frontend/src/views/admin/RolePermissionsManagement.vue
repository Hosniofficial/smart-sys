<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">

    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة صلاحيات الأدوار"
        description="تحديد صلاحيات كل دور بما يتوافق مع متطلبات العمل داخل المنشأة."
        :branches="[]"
        :selected-branch="null"
      />

      <!-- KPI Cards -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'عدد الأدوار',         val: roles.length,                icon: 'fa-user-tag',    color: 'text-blue-600',    bg: 'bg-blue-50' },
          { label: 'الصلاحيات المفعّلة',  val: selectedPermissions.length,  icon: 'fa-check-circle', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'إجمالي الصلاحيات',   val: permissions.length,           icon: 'fa-list',         color: 'text-indigo-600',  bg: 'bg-indigo-50' },
          { label: 'المجموعات',           val: permissionGroups.length,      icon: 'fa-layer-group',  color: 'text-amber-600',   bg: 'bg-amber-50' },
        ]" :key="kpi.label"
          class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Role Selector Panel -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="p-6 space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-4 items-end">
            <label class="space-y-1.5">
              <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">اختر الدور</span>
              <select
                v-model="selectedRoleId"
                :disabled="isLoading || isSaving || hasUnsavedChanges"
                class="filter-input appearance-none disabled:cursor-not-allowed disabled:bg-slate-50"
              >
                <option value="" disabled>اختر الدور</option>
                <option v-for="role in roles" :key="role.id" :value="String(role.id)">
                  {{ roleLabel(role) }}
                </option>
              </select>
            </label>
            <button
              type="button"
              :disabled="isLoading || !selectedRoleId"
              @click="hasUnsavedChanges ? discardChanges() : loadRolePermissions()"
              class="h-9 px-4 rounded-md border border-slate-200 text-slate-600 text-[11px] font-bold hover:bg-slate-50 transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
            >
              <i :class="['fas', hasUnsavedChanges ? 'fa-xmark' : 'fa-rotate-left']"></i>
              {{ hasUnsavedChanges ? 'إلغاء التغييرات' : 'إعادة تحميل' }}
            </button>
          </div>

          <p v-if="selectedRole" class="text-[11px] text-slate-500 leading-5">
            {{ roleDescription(selectedRole) }}
          </p>

          <div v-if="hasUnsavedChanges" class="flex items-center gap-2 p-3 rounded-lg bg-amber-50 border border-amber-100">
            <i class="fas fa-exclamation-triangle text-amber-500 text-xs"></i>
            <p class="text-[10px] font-bold text-amber-700">
              توجد تغييرات غير محفوظة. احفظها أو ألغها قبل اختيار دور آخر.
            </p>
          </div>
        </div>
      </div>

      <!-- Error / Feedback Banners -->
      <transition name="slide-down">
        <div v-if="loadError" class="p-4 rounded-xl border-l-4 border-rose-400 bg-rose-50 flex items-start gap-3">
          <i class="fas fa-exclamation-circle text-rose-500 mt-0.5"></i>
          <p class="text-xs font-bold text-rose-700">{{ loadError }}</p>
        </div>
      </transition>

      <transition name="slide-down">
        <div v-if="feedback" class="p-4 rounded-xl border-l-4 border-emerald-400 bg-emerald-50 flex items-start gap-3">
          <i class="fas fa-check-circle text-emerald-500 mt-0.5"></i>
          <p class="text-xs font-bold text-emerald-700">{{ feedback }}</p>
        </div>
      </transition>

      <!-- Loading State -->
      <div v-if="isLoading" class="bg-white border border-slate-200 rounded-xl p-12 text-center">
        <div class="w-8 h-8 border-2 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-3"></div>
        <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">جارٍ تحميل صلاحيات الدور...</p>
      </div>

      <!-- Permissions Content -->
      <template v-else-if="selectedRole">

        <!-- Permission Groups -->
        <section
          v-for="group in permissionGroups"
          :key="group.name"
          class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm"
        >
          <!-- Group Header -->
          <header class="flex items-center justify-between bg-slate-50 border-b border-slate-200 px-5 py-3">
            <h2 class="text-[10px] font-bold text-slate-700 uppercase tracking-widest">
              {{ categoryLabel(group.name) }}
            </h2>
            <div class="flex items-center gap-3">
              <span class="text-[10px] font-bold text-slate-400">
                {{ group.items.filter(item => selectedPermissions.includes(item.name)).length }}
                /
                {{ group.items.length }}
              </span>
              <!-- Progress mini-bar -->
              <div class="w-20 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                <div
                  class="h-full bg-blue-600 rounded-full transition-all"
                  :style="{
                    width: group.items.length
                      ? `${(group.items.filter(i => selectedPermissions.includes(i.name)).length / group.items.length) * 100}%`
                      : '0%'
                  }"
                ></div>
              </div>
            </div>
          </header>

          <!-- Permission Items Grid -->
          <div class="grid gap-2 p-4 sm:grid-cols-2 xl:grid-cols-3">
            <label
              v-for="permission in group.items"
              :key="permission.id"
              class="flex min-w-0 items-start gap-3 rounded-lg border p-3 transition-all"
              :class="[
                selectedPermissions.includes(permission.name)
                  ? 'bg-emerald-50/50 border-emerald-100'
                  : 'bg-white border-slate-100',
                isProtected(permission.name) || (!canGrant(permission.name) && !selectedPermissions.includes(permission.name))
                  ? 'cursor-not-allowed opacity-60'
                  : 'cursor-pointer hover:border-blue-200 hover:bg-blue-50/30'
              ]"
            >
              <input
                v-model="selectedPermissions"
                type="checkbox"
                :value="permission.name"
                :disabled="isProtected(permission.name) || (!canGrant(permission.name) && !selectedPermissions.includes(permission.name))"
                class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
              />
              <span class="min-w-0 flex-1">
                <span class="block break-words text-[11px] font-bold text-slate-800">
                  {{ permissionLabel(permission) }}
                </span>
                <span class="mt-0.5 block font-mono text-[9px] text-slate-400">{{ permission.name }}</span>
                <div class="mt-1.5 flex flex-wrap gap-1">
                  <span v-if="basePermissions.includes(permission.name)"
                    class="inline-flex rounded px-1.5 py-0.5 text-[8px] font-bold bg-slate-100 text-slate-500">
                    من قالب الدور
                  </span>
                  <span v-else-if="selectedPermissions.includes(permission.name)"
                    class="inline-flex rounded px-1.5 py-0.5 text-[8px] font-bold bg-blue-100 text-blue-700">
                    إضافة لهذا المستأجر
                  </span>
                  <span v-if="isProtected(permission.name)"
                    class="inline-flex rounded px-1.5 py-0.5 text-[8px] font-bold bg-amber-100 text-amber-800">
                    RBAC محمية
                  </span>
                  <span v-else-if="!canGrant(permission.name)"
                    class="inline-flex rounded px-1.5 py-0.5 text-[8px] font-bold bg-slate-100 text-slate-500">
                    لا تملك هذه الصلاحية
                  </span>
                </div>
              </span>
            </label>
          </div>
        </section>

        <!-- Save Bar — Sticky Bottom -->
        <div class="sticky bottom-4 z-30">
          <div class="bg-white/95 backdrop-blur border border-slate-200 rounded-xl shadow-xl p-4 flex flex-col sm:flex-row gap-3 sm:items-end">
            <label class="flex-1 space-y-1.5">
              <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                سبب التعديل <span class="text-rose-500">*</span>
                <span class="normal-case font-normal text-slate-300 mr-1">(مطلوب لسجل التدقيق)</span>
              </span>
              <input
                v-model="reason"
                maxlength="500"
                class="filter-input"
                placeholder="مثال: فصل مهام موظفي المخزون عن المبيعات"
              />
            </label>
            <div class="flex items-center gap-3 shrink-0">
              <span v-if="hasUnsavedChanges" class="text-[10px] font-bold text-amber-600">
                <i class="fas fa-circle text-[6px] ml-1 animate-pulse"></i>تغييرات غير محفوظة
              </span>
              <LoadingButton
                type="button"
                text="حفظ صلاحيات الدور"
                loadingText="جارٍ الحفظ..."
                icon="fas fa-save"
                :isLoading="isSaving"
                :disabled="!reason.trim() || !hasUnsavedChanges"
                @click="saveRolePermissions"
                class="h-10 rounded-md px-6 text-xs shadow-sm shadow-blue-900/20"
              />
            </div>
          </div>
        </div>

      </template>

      <!-- No Role Selected -->
      <div v-else-if="!isLoading" class="bg-white border border-slate-200 rounded-xl p-12 text-center">
        <i class="fas fa-user-tag text-3xl text-slate-200 mb-4"></i>
        <p class="text-xs font-bold uppercase tracking-widest text-slate-300">
          {{ roles.length ? 'اختر دوراً من القائمة أعلاه' : 'لا توجد أدوار متاحة لإدارة صلاحياتها' }}
        </p>
      </div>

    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import LoadingButton from '@/components/ui/LoadingButton.vue'
import { useAuthStore } from '@/stores/auth/authStore'
import { useRoleStore } from '@/stores/role/roleStore'
import { useRbacStore } from '@/stores/rbac/rbacStore'
import { useBreadcrumb } from '@/composables/useBreadcrumb'

// ─── Stores & Composables ─────────────────────────────────────────────────────
const authStore  = useAuthStore()
const roleStore  = useRoleStore()
const rbacStore  = useRbacStore()
const { breadcrumb } = useBreadcrumb()

// ─── State ────────────────────────────────────────────────────────────────────
const roles              = ref([])
const permissions        = ref([])
const selectedRoleId     = ref('')
const selectedPermissions = ref([])
const savedPermissions   = ref([])
const basePermissions    = ref([])
const reason             = ref('')
const isLoading          = ref(false)
const isSaving           = ref(false)
const loadError          = ref('')
const feedback           = ref('')

// ─── Computed ─────────────────────────────────────────────────────────────────
const hasUnsavedChanges = computed(() => {
  const selected = [...selectedPermissions.value].sort()
  const saved    = [...savedPermissions.value].sort()
  return selected.length !== saved.length || selected.some((name, i) => name !== saved[i])
})

const selectedRole = computed(() =>
  roles.value.find(r => String(r.id) === selectedRoleId.value) || null
)

const permissionGroups = computed(() => {
  const groups = new Map()
  for (const p of permissions.value) {
    const cat = p.category || 'other'
    if (!groups.has(cat)) groups.set(cat, [])
    groups.get(cat).push(p)
  }
  return [...groups].map(([name, items]) => ({ name, items }))
})

// ─── Labels / Meta ────────────────────────────────────────────────────────────
const protectedNames = new Set([
  'role.view', 'role.edit', 'role.create', 'role.delete',
  'permission.view', 'permission.assign',
])

const roleLabels = {
  super_admin: 'مدير المنصة', admin: 'مسؤول المنشأة', manager: 'مدير الفروع', cashier: 'كاشير',
  inventory_clerk: 'مسؤول المخزون', finance_officer: 'مسؤول مالي',
  branch_manager: 'مدير الفرع',
}

const roleDescriptions = {
  admin:           'إدارة النظام والمستخدمين على مستوى المنشأة.',
  manager:         'إدارة العمليات والموظفين على مستوى الفروع.',
  cashier:         'تنفيذ عمليات البيع وإدارة جلسة نقطة البيع.',
  inventory_clerk: 'إدارة المنتجات وحركة المخزون.',
  finance_officer: 'إدارة العمليات المالية ومراجعة الحسابات.',
}

const categoryLabels = {
  rbac:                   'إدارة الأدوار والصلاحيات',
  accounting:             'المحاسبة',
  accounting_management:  'مساهمات المالك',
  branch_management:      'إدارة الفروع',
  catalog:                'تصنيفات المنتجات',
  contacts:               'العملاء والموردون',
  documents:              'المستندات',
  inventory_management:   'إدارة المخزون',
  permission_management:  'إدارة الصلاحيات',
  pos:                    'نقطة البيع',
  product_management:     'إدارة المنتجات',
  product_pricing:        'أسعار المنتجات والتكلفة',
  purchase_management:    'إدارة المشتريات',
  report_management:      'التقارير',
  returns_management:     'إدارة المرتجعات',
  role_management:        'إدارة الأدوار',
  sales_approval:         'موافقات المبيعات',
  sales_management:       'إدارة المبيعات',
  payments:               'المدفوعات',
  system:                 'النظام',
  security:               'الأمان',
  settings:               'الإعدادات',
  user_management:        'إدارة المستخدمين',
  vouchers:               'سندات القبض والصرف',
  warranty:               'الضمان',
  other:                  'صلاحيات أخرى',
}

const roleLabel       = role => roleLabels[role.role_code || role.name] || role.display_name || role.name
const roleDescription = role => roleDescriptions[role.role_code || role.name] || roleLabel(role)
const categoryLabel   = name => categoryLabels[name] || name
const permissionLabel = p => p.display_name || p.name
const isProtected     = name => protectedNames.has(name)
const canGrant        = name => (authStore.user?.permissions || []).includes(name)

// ─── API ──────────────────────────────────────────────────────────────────────
const loadRolePermissions = async () => {
  if (!selectedRoleId.value) return false
  isLoading.value = true
  loadError.value = ''
  feedback.value  = ''
  reason.value    = ''
  try {
    const data = await rbacStore.getRolePermissions(selectedRoleId.value)
    selectedPermissions.value = data.permissions
    savedPermissions.value    = [...data.permissions]
    basePermissions.value     = data.base_permissions
    return true
  } catch (err) {
    loadError.value = err.response?.data?.message || err.message || 'تعذر تحميل صلاحيات الدور.'
    return false
  } finally {
    isLoading.value = false
  }
}

const discardChanges = () => {
  selectedPermissions.value = [...savedPermissions.value]
  reason.value    = ''
  loadError.value = ''
  feedback.value  = ''
}

const saveRolePermissions = async () => {
  if (!selectedRoleId.value || !reason.value.trim() || !hasUnsavedChanges.value || isSaving.value) return
  isSaving.value  = true
  loadError.value = ''
  feedback.value  = ''
  try {
    await rbacStore.updateRolePermissions(selectedRoleId.value, {
      permissions: selectedPermissions.value,
      reason: reason.value.trim(),
    })
    savedPermissions.value = [...selectedPermissions.value]
    const refreshed = await loadRolePermissions()
    feedback.value = refreshed
      ? 'تم حفظ صلاحيات الدور وتسجيل التغيير في سجل التدقيق.'
      : 'تم حفظ التغييرات، لكن تعذر إعادة تحميل الصلاحيات.'
  } catch (err) {
    loadError.value = err.response?.data?.message || err.message || 'تعذر حفظ صلاحيات الدور.'
  } finally {
    isSaving.value = false
  }
}

const loadPage = async () => {
  isLoading.value = true
  loadError.value = ''
  try {
    const [roleData, permissionData] = await Promise.all([
      roleStore.fetchRoles(),
      rbacStore.listPermissions(),
    ])

    // ترتيب الأدوار: admin أولاً، ثم الباقي حسب الـ id
    const filteredRoles = roleData.filter(r => r.name !== 'super_admin')
    const adminRole = filteredRoles.find(r => r.name === 'admin')
    const otherRoles = filteredRoles.filter(r => r.name !== 'admin').sort((a, b) => a.id - b.id)

    roles.value = adminRole ? [adminRole, ...otherRoles] : otherRoles
    permissions.value = permissionData

    if (!selectedRoleId.value && roles.value.length) {
      selectedRoleId.value = String(roles.value[0].id)
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || err.message || 'تعذر تحميل بيانات الصلاحيات.'
  } finally {
    isLoading.value = false
  }
}

// ─── Watchers & Mount ─────────────────────────────────────────────────────────
watch(selectedRoleId, roleId => { if (roleId) loadRolePermissions() })
onMounted(loadPage)
</script>

<style scoped>
@keyframes loading {
  0%   { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
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
</style>
