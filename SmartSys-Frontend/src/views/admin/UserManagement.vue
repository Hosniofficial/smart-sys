<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة الموظفين والصلاحيات"
        description="أدر حسابات الموظفين، وحدّد أدوارهم وصلاحيات الوصول إلى وظائف النظام المختلفة."
        :branches="[]"
        :selected-branch="null"
      >
        <template #controls>
          <button @click="openCreate" class="h-9 px-6 bg-blue-600 text-white rounded-md text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-user-plus text-[10px]"></i> إضافة موظف جديد
          </button>
        </template>
      </PageHeader>

      <!-- KPI Cards -->
      <section class="grid grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي الموظفين', val: total, icon: 'fa-users', color: 'text-slate-600', bg: 'bg-slate-100' },
          { label: 'نشط حالياً', val: activeCount, icon: 'fa-check-circle', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'موقوف', val: inactiveCount, icon: 'fa-user-slash', color: 'text-rose-600', bg: 'bg-rose-50' },
          { label: 'الأدوار المعرفة', val: roles.length, icon: 'fa-shield-halved', color: 'text-blue-600', bg: 'bg-blue-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900 font-mono">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Search & Filters -->
      <section class="bg-white border border-slate-200 rounded-xl p-4 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-sm">
        <div class="relative flex-grow group max-w-xl">
          <input 
            type="text" 
            v-model="search" 
            @input="debouncedSearch"
            class="h-9 w-full bg-slate-50 border border-slate-100 rounded-md pr-9 pl-4 text-xs font-bold text-slate-700 focus:bg-white focus:border-blue-500 outline-none transition-all" 
            placeholder="بحث بالاسم، البريد، أو اسم المستخدم..."
          />
          <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
        </div>
        
        <div class="flex items-center gap-2">
          <select v-model="filterRole" @change="loadUsers(1)" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-[11px] font-bold text-slate-600 hover:border-slate-300 focus:ring-4 focus:ring-blue-500/5 outline-none transition-all">
            <option value="">جميع الأدوار</option>
            <option v-for="r in roles" :key="r.id" :value="r.id">{{ roleAr(r.name) }}</option>
          </select>
          <select v-model="filterStatus" @change="loadUsers(1)" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-[11px] font-bold text-slate-600 hover:border-slate-300 focus:ring-4 focus:ring-blue-500/5 outline-none transition-all">
            <option value="">جميع الحالات</option>
            <option value="active">نشط</option>
            <option value="inactive">موقوف</option>
          </select>
        </div>
      </section>

      <!-- Main User Table -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الموظف / الهوية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">البريد الإلكتروني</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الفرع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الدور الوظيفي</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">آخر ظهور</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <!-- Loading Skeleton -->
              <template v-if="isLoading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 7" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>

              <!-- Empty State -->
              <tr v-else-if="!users.length">
                <td colspan="7" class="py-24 text-center text-slate-300">
                   <i class="fas fa-users-slash text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا يوجد موظفون متاحون حالياً</p>
                </td>
              </tr>

              <!-- Data Rows -->
              <tr v-else v-for="u in users" :key="u.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center gap-3">
                    <div :class="['w-9 h-9 rounded-lg flex items-center justify-center text-white font-bold text-xs shadow-sm border border-white/20 uppercase transition-transform group-hover:scale-110', avatarColor(u.id)]">
                      {{ (u.name || u.username || '?')[0] }}
                    </div>
                    <div>
                      <span class="text-xs font-bold text-slate-900 block mb-0.5">{{ u.name || u.username }}</span>
                      <span class="text-[9px] font-mono font-bold text-slate-400 uppercase tracking-tighter">@{{ u.username }}</span>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-4 text-slate-500 font-medium">{{ u.email || '—' }}</td>
                <td class="px-4 py-4 text-center text-[10px] font-bold text-slate-500">
                  {{ u.branch_name || 'بدون فرع' }}
                </td>
                <td class="px-4 py-4 text-center">
                  <div class="flex flex-wrap justify-center gap-1">
                    <span
                      v-for="role in assignedRoles(u)"
                      :key="role.id"
                      class="px-2 py-0.5 rounded border border-slate-200 bg-slate-50 text-[9px] font-bold text-slate-600"
                    >
                      {{ roleAr(role.name) }}
                    </span>
                    <span v-if="!assignedRoles(u).length" class="text-[9px] text-rose-500">بدون دور</span>
                  </div>
                </td>
                <td class="px-4 py-4 text-center">
                  <button @click="toggleStatus(u)" :disabled="savingId === u.id" class="active:scale-95 transition-transform disabled:opacity-50">
                    <span :class="[u.status === 'active' ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : 'text-rose-600 bg-rose-50 border-rose-100']" class="px-2.5 py-0.5 rounded text-[9px] font-bold border uppercase tracking-tighter">
                      {{ u.status === 'active' ? 'نشط' : 'موقوف' }}
                    </span>
                  </button>
                </td>
                <td class="px-4 py-4 text-center text-[10px] font-mono font-bold text-slate-400">
                  {{ u.last_login ? formatDate(u.last_login) : 'لم يسجل دخول' }}
                </td>
                <td class="px-6 py-4 text-center">
                  <div class="flex items-center justify-center gap-1.5">
                    <button @click="openEdit(u)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center shadow-sm">
                      <i class="fas fa-pen text-[10px]"></i>
                    </button>
                    <button
                      v-if="canAssignPermissions && !u.is_owner"
                      @click="openPermissionOverrides(u)"
                      title="تخصيص صلاحيات هذا المستخدم"
                      class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-violet-600 hover:border-violet-200 transition-all flex items-center justify-center shadow-sm"
                    >
                      <i class="fas fa-shield-halved text-[10px]"></i>
                    </button>
                    <button @click="confirmDelete(u)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-all flex items-center justify-center shadow-sm">
                      <i class="fas fa-trash-alt text-[10px]"></i>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ page }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ total }}</span> موظف
          </div>
          <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
              <span class="text-[10px] font-bold text-slate-400 uppercase">النتائج:</span>
              <select v-model.number="perPage" @change="loadUsers(1)" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                <option :value="10">10</option>
                <option :value="20">20</option>
                <option :value="50">50</option>
              </select>
            </div>
            <PaginationControls
              :page="page"
              :total-pages="totalPages"
              @previous="loadUsers(page - 1)"
              @next="loadUsers(page + 1)"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <BaseModal
      :show="modal.open"
      @close="modal.open = false"
      max-width="lg"
      align="start"
      :close-on-click-outside="false"
    >
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-slate-900 rounded-lg flex items-center justify-center text-white">
            <i :class="[modal.mode === 'create' ? 'fas fa-user-plus' : 'fas fa-user-edit', 'text-xs']"></i>
          </div>
          <h3 class="text-sm font-bold text-slate-900 uppercase tracking-tight">
            {{ modal.mode === 'create' ? 'تسجيل موظف جديد' : 'تحديث ملف الموظف' }}
          </h3>
        </div>
      </template>

      <div class="space-y-6" dir="rtl">
        <div
          v-if="modal.error"
          role="alert"
          aria-live="polite"
          class="p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-[11px] font-bold flex items-start gap-2"
        >
          <i class="fas fa-exclamation-circle mt-0.5"></i>
          <span>{{ modal.error }}</span>
        </div>

        <div class="space-y-6">
          <div class="space-y-1.5 group">
            <label class="metadata-label">الاسم الكامل <span class="text-rose-500">*</span></label>
            <input v-model="form.name" type="text" class="filter-input-v2 h-10 font-bold" placeholder="أدخل الاسم الرسمي..." />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div class="space-y-1.5">
              <label class="metadata-label">اسم المستخدم</label>
              <input v-model="form.username" type="text" class="filter-input-v2 h-10 font-mono" placeholder="username" @input="modal.error = ''" />
            </div>
            <div class="space-y-1.5">
              <label class="metadata-label">البريد الإلكتروني</label>
              <input v-model="form.email" type="email" class="filter-input-v2 h-10" placeholder="mail@example.com" @input="modal.error = ''" />
            </div>
          </div>
          <div v-if="modal.mode === 'create'" class="space-y-1.5">
            <label class="metadata-label">كلمة المرور</label>
            <input v-model="form.password" type="password" class="filter-input-v2 h-10 font-mono tracking-widest" placeholder="••••••••" />
          </div>
        </div>

        <div class="space-y-1.5 pt-4 border-t border-slate-50">
          <label class="metadata-label">الفرع</label>
          <select v-model="form.branch_id" class="filter-input-v2 h-10 appearance-none font-bold">
            <option :value="null">بدون فرع محدد</option>
            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
          </select>
        </div>

        <div class="space-y-3 pt-4 border-t border-slate-50">
          <div class="space-y-1">
            <label class="metadata-label">الأدوار والصلاحيات <span class="text-rose-500">*</span></label>
            <p class="text-[10px] leading-5 text-slate-500">
              اختر الأدوار المناسبة لمهام الموظف؛ صلاحيات الأدوار المختارة تتجمع. «مسؤول المنشأة» يملك صلاحيات إدارية واسعة.
            </p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <label
              v-for="role in roles"
              :key="role.id"
              class="flex items-center gap-2 p-3 rounded-lg bg-slate-50 border border-slate-100 cursor-pointer hover:bg-white has-[:checked]:border-blue-500"
            >
              <input v-model="form.roles" type="checkbox" :value="role.id" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-0" />
              <span class="text-[11px] font-bold text-slate-700">{{ roleAr(role.name) }}</span>
            </label>
          </div>
          <p v-if="!form.roles.length" class="text-[10px] font-bold text-rose-600">يجب اختيار دور واحد على الأقل.</p>
        </div>

        <div class="pt-4 border-t border-slate-50">
          <div class="space-y-1.5">
            <label class="metadata-label">حالة الحساب</label>
            <select v-model="form.status" class="filter-input-v2 h-10 appearance-none font-bold">
              <option value="active">نشط / مفعل</option>
              <option value="inactive">موقوف مؤقتاً</option>
            </select>
          </div>
        </div>

        <div v-if="modal.mode === 'edit'" class="space-y-1.5 pt-4 border-t border-slate-50">
          <label class="metadata-label text-blue-600">تغيير كلمة المرور (اختياري)</label>
          <input v-model="form.password" type="password" class="filter-input-v2 h-10 font-mono tracking-widest" placeholder="اتركها فارغة للإبقاء على الحالية" />
        </div>
      </div>

      <template #footer>
        <button @click="modal.open = false" class="px-6 h-10 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">
          إلغاء
        </button>
        <LoadingButton
          :text="modal.mode === 'create' ? 'إضافة الموظف' : 'حفظ التعديلات'"
          loadingText="جارٍ الحفظ..."
          :isLoading="modal.saving"
          @click="saveUser"
          class="px-10 h-10 rounded-md text-[11px] tracking-widest shadow-lg shadow-blue-200"
        />
      </template>
    </BaseModal>

    <!-- Permission Overrides Modal -->
    <BaseModal
      :show="permissionModal.open"
      @close="permissionModal.open = false"
      max-width="5xl"
      align="start"
      :close-on-click-outside="false"
    >
      <template #header>
        <div class="flex items-center gap-3">
          <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 text-violet-600">
            <i class="fas fa-shield-halved text-sm"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900">تخصيص صلاحيات المستخدم</h3>
            <p class="mt-1 text-[10px] text-slate-500">{{ permissionModal.user?.name }}</p>
          </div>
        </div>
      </template>

      <div class="space-y-4" dir="rtl">
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-[10px] leading-5 text-amber-900">
          <strong>منح</strong> يضيف صلاحية لهذا المستخدم، و<strong>منع</strong> يسحبها حتى لو منحها أحد أدواره، و<strong>افتراضي</strong> يعيد القرار للأدوار. صلاحيات إدارة الأدوار محمية ولا يمكن تخصيصها من هنا.
        </div>
        <p v-if="permissionModal.error" role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-[11px] font-bold text-rose-700">
          {{ permissionModal.error }}
        </p>
        <div v-if="permissionModal.loading" class="py-12 text-center text-xs text-slate-400">جاري تحميل الصلاحيات...</div>
        <template v-else>
          <div class="max-h-[55vh] space-y-4 overflow-y-auto pl-1">
            <section v-for="group in userPermissionGroups" :key="group.name" class="overflow-hidden rounded-lg border border-slate-200">
              <header class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-[11px] font-bold text-slate-700">
                {{ permissionCategoryLabel(group.name) }}
              </header>
              <div class="divide-y divide-slate-100">
                <div v-for="permission in group.items" :key="permission.name" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                  <div class="min-w-0">
                    <p class="break-words text-[11px] font-bold text-slate-800">{{ permission.display_name || permission.description || permission.name }}</p>
                    <p class="mt-0.5 font-mono text-[9px] text-slate-400">{{ permission.name }}</p>
                    <p class="mt-1 text-[9px] text-slate-500">
                      من الدور: {{ permission.role_granted ? 'ممنوحة' : 'غير ممنوحة' }}
                      <span v-if="permission.override" class="mr-2 font-bold text-violet-700">
                        · استثناء حالي: {{ permission.override.effect === 'allow' ? 'منح' : 'منع' }}
                      </span>
                    </p>
                    <p v-if="permission.override?.reason" class="mt-0.5 text-[9px] text-slate-400">السبب: {{ permission.override.reason }}</p>
                  </div>
                  <select
                    v-model="permission.overrideMode"
                    :disabled="isProtectedPermission(permission.name)"
                    class="h-9 w-full rounded-md border border-slate-200 bg-white px-2 text-[10px] font-bold outline-none focus:border-blue-500 disabled:bg-slate-100 sm:w-32"
                  >
                    <option value="inherit">افتراضي الدور</option>
                    <option value="allow">منح للمستخدم</option>
                    <option value="deny">منع المستخدم</option>
                  </select>
                </div>
              </div>
            </section>
          </div>
          <label class="block space-y-1.5">
            <span class="text-[10px] font-bold text-slate-500">سبب التعديل (مطلوب لسجل التدقيق)</span>
            <textarea v-model="permissionModal.reason" maxlength="500" rows="2" class="filter-input-v2 h-auto py-2" placeholder="اكتب سبب منح أو منع الصلاحيات"></textarea>
          </label>
        </template>
      </div>

      <template #footer>
        <button @click="permissionModal.open = false" class="h-10 px-5 text-xs font-bold text-slate-500">إلغاء</button>
        <LoadingButton
          text="حفظ التخصيصات"
          loadingText="جاري الحفظ..."
          :isLoading="permissionModal.saving"
          type="button"
          @click="savePermissionOverrides"
          :disabled="permissionModal.loading || !permissionModal.reason.trim()"
          class="h-10 rounded-md px-6 text-xs"
        />
      </template>
    </BaseModal>

    <!-- Delete Confirmation Modal -->
    <BaseModal
      :show="deleteModal.open"
      @close="deleteModal.open = false"
      max-width="sm"
      align="center"
      :close-on-click-outside="false"
      :show-close-button="false"
      :z-index="130"
    >
      <div class="text-center space-y-6" dir="rtl">
        <div class="w-16 h-16 rounded-full bg-rose-50 text-rose-500 flex items-center justify-center mx-auto shadow-inner border border-rose-100">
          <i class="fas fa-trash-alt text-2xl"></i>
        </div>
        <div>
          <h3 class="text-base font-bold text-slate-900 uppercase">تأكيد حذف الموظف</h3>
          <p class="text-[10px] text-slate-400 mt-2 leading-relaxed">
            أنت على وشك حذف حساب <strong class="text-slate-700">{{ deleteModal.user?.name }}</strong> نهائياً. لا يمكن التراجع عن هذا الإجراء.
          </p>
        </div>
      </div>

      <template #footer>
        <button @click="deleteModal.open = false" class="flex-1 h-10 text-xs font-bold text-slate-500">إلغاء</button>
        <LoadingButton
          text="تأكيد الحذف"
          :isLoading="deleteModal.saving"
          loadingText="جاري الحذف..."
          variant="danger"
          class="flex-[2] h-10 rounded-lg"
          @click="deleteUser"
        />
      </template>
    </BaseModal>

    <!-- Global Toast -->
    <transition name="slide-up">
      <div v-if="toast.show" :class="[toast.type === 'success' ? 'bg-slate-900 border-emerald-500' : 'bg-rose-600 border-white/20']"
           class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[200] px-6 py-3 rounded-xl shadow-2xl text-white font-bold text-xs flex items-center gap-3 border transition-all">
        <i :class="toast.type === 'success' ? 'fas fa-check-circle text-emerald-400' : 'fas fa-times-circle'"></i>
        {{ toast.message }}
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import LoadingButton from '@/components/ui/LoadingButton.vue'
import BaseModal from '@/components/BaseModal.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import { useAuthStore } from '@/stores/auth/authStore'
import { useBranchStore } from '@/stores/branch/branchStore'
import { useRoleStore } from '@/stores/role/roleStore'
import { useRbacStore } from '@/stores/rbac/rbacStore'
import { useBreadcrumb } from '@/composables/useBreadcrumb'
import PageHeader from '@/components/PageHeader.vue'

const { breadcrumb } = useBreadcrumb()
const authStore = useAuthStore()
const rbacStore = useRbacStore()
const roleStore = useRoleStore()
const branchStore = useBranchStore()

// ─── State ────────────────────────────────────────────────────────────────────
const users = ref([])
const roles = ref([])
const branches = computed(() => branchStore.branches)
const isLoading = ref(false)
const savingId = ref(null)

// ─── Pagination ───────────────────────────────────────────────────────────────
const page = ref(1)
const perPage = ref(20)
const total = ref(0)
const totalPages = computed(() => Math.ceil(total.value / perPage.value) || 1)

// ─── KPI Counts ───────────────────────────────────────────────────────────────
const activeCount = ref(0)
const inactiveCount = ref(0)

// ─── Filters ──────────────────────────────────────────────────────────────────
const search = ref('')
const filterRole = ref('')
const filterStatus = ref('')
let searchTimeout = null

// ─── Modals ───────────────────────────────────────────────────────────────────
const modal = reactive({ open: false, mode: 'create', saving: false, error: '', userId: null })
const form = reactive({ name: '', username: '', email: '', password: '', roles: [], branch_id: null, status: 'active' })
const deleteModal = reactive({ open: false, saving: false, user: null })
const permissionModal = reactive({
  open: false,
  loading: false,
  saving: false,
  user: null,
  permissions: [],
  reason: '',
  error: '',
})
const toast = reactive({ show: false, type: 'success', message: '' })

// ─── Computed ─────────────────────────────────────────────────────────────────
const canAssignPermissions = computed(() => authStore.user?.permissions?.includes('permission.assign') === true)
const protectedPermissionNames = new Set([
  'role.view', 'role.edit', 'role.create', 'role.delete', 'permission.view', 'permission.assign',
])
const permissionCategories = {
  rbac: 'إدارة الأدوار والصلاحيات',
  sales_management: 'إدارة المبيعات',
  purchase_management: 'إدارة المشتريات',
  inventory_management: 'إدارة المخزون',
  pos: 'نقطة البيع',
  payments: 'المدفوعات',
  reports: 'التقارير',
  system: 'النظام',
  security: 'الأمان',
  accounting: 'المحاسبة',
}
const userPermissionGroups = computed(() => {
  const groups = new Map()
  for (const permission of permissionModal.permissions) {
    const category = permission.category || 'other'
    if (!groups.has(category)) groups.set(category, [])
    groups.get(category).push(permission)
  }
  return [...groups].map(([name, items]) => ({ name, items }))
})

const roleLabels = {
  super_admin: 'مدير النظام الرئيسي',
  admin: 'مدير النظام',
  manager: 'مدير الفروع',
  cashier: 'كاشير',
  inventory_clerk: 'أمين مخزن',
  finance_officer: 'مسؤول مالي',
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
const roleAr = (name) => roleLabels[name] || name
const permissionCategoryLabel = (name) => permissionCategories[name] || name
const isProtectedPermission = (name) => protectedPermissionNames.has(name)
const avatarColors = ['bg-blue-600', 'bg-violet-600', 'bg-emerald-600', 'bg-amber-600', 'bg-rose-600', 'bg-cyan-600', 'bg-indigo-600', 'bg-pink-600']
const avatarColor = (id) => avatarColors[id % avatarColors.length]

const assignedRoleIds = (user) => {
  if (typeof user.role_ids === 'string' && user.role_ids) {
    return user.role_ids.split(',').map(Number).filter(Number.isInteger)
  }
  return user.role_id ? [Number(user.role_id)] : []
}
const assignedRoles = (user) => {
  const roleIds = new Set(assignedRoleIds(user))
  return roles.value.filter((role) => roleIds.has(Number(role.id)))
}
const formatDate = (dt) => {
  if (!dt) return ''
  return new Date(dt).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' })
}
const showToast = (message, type = 'success') => {
  toast.message = message
  toast.type = type
  toast.show = true
  setTimeout(() => { toast.show = false }, 3000)
}

// ─── Debounced Search ─────────────────────────────────────────────────────────
const debouncedSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    loadUsers(1)
  }, 500)
}

// ─── API ──────────────────────────────────────────────────────────────────────
const loadUsers = async (nextPage = page.value) => {
  isLoading.value = true
  try {
    const params = {
      page: nextPage,
      limit: perPage.value,
      search: search.value.trim(),
      role_id: filterRole.value || '',
      status: filterStatus.value || '',
    }

    const response = await rbacStore.listUsers(params)

    users.value = response.items || []
    total.value = response.total || 0
    page.value = response.page || nextPage

    // جلب KPI counts منفصل (active/inactive من كل البيانات)
    const [activeRes, inactiveRes] = await Promise.allSettled([
      rbacStore.listUsers({ page: 1, limit: 1, status: 'active' }),
      rbacStore.listUsers({ page: 1, limit: 1, status: 'inactive' }),
    ])

    activeCount.value = activeRes.status === 'fulfilled' ? (activeRes.value.total || 0) : 0
    inactiveCount.value = inactiveRes.status === 'fulfilled' ? (inactiveRes.value.total || 0) : 0

  } catch (e) {
    showToast('فشل تحميل البيانات', 'error')
  } finally {
    isLoading.value = false
  }
}

const loadRoles = async () => {
  try {
    const roleList = await roleStore.fetchRoles()
    roles.value = roleList.filter(r => r.name !== 'super_admin')
  } catch (e) {
    showToast('فشل تحميل الأدوار', 'error')
  }
}

const toggleStatus = async (user) => {
  savingId.value = user.id
  const newStatus = user.status === 'active' ? 'inactive' : 'active'
  try {
    await rbacStore.updateUser(user.id, { status: newStatus })
    user.status = newStatus
    showToast(newStatus === 'active' ? 'تم تفعيل الحساب' : 'تم إيقاف الحساب')
    // إعادة تحميل KPI counts
    loadUsers(page.value)
  } catch (e) {
    showToast(e?.response?.data?.message || 'فشل تغيير الحالة', 'error')
  } finally {
    savingId.value = null
  }
}

const openCreate = () => {
  Object.assign(form, { name: '', username: '', email: '', password: '', roles: [], branch_id: null, status: 'active' })
  Object.assign(modal, { open: true, mode: 'create', saving: false, error: '', userId: null })
}

const openEdit = (user) => {
  Object.assign(form, {
    name: user.name || '',
    username: user.username || '',
    email: user.email || '',
    password: '',
    roles: assignedRoleIds(user),
    branch_id: user.branch_id ? Number(user.branch_id) : null,
    status: user.status || 'active'
  })
  Object.assign(modal, { open: true, mode: 'edit', saving: false, error: '', userId: user.id })
}

const saveUser = async () => {
  modal.error = ''
  if (modal.mode === 'create') {
    if (!form.username || !form.email || !form.password)
      return (modal.error = 'اسم المستخدم والبريد وكلمة المرور مطلوبة')
  }
  if (!form.roles.length) return (modal.error = 'يجب اختيار دور واحد على الأقل')

  modal.saving = true
  try {
    const changingOwnPassword = modal.mode === 'edit' &&
      !!form.password &&
      Number(modal.userId) === Number(authStore.user?.id)

    if (modal.mode === 'create') {
      await rbacStore.createUser({
        name: form.name,
        username: form.username,
        email: form.email,
        password: form.password,
        roles: form.roles,
        branch_id: form.branch_id,
        status: form.status
      })
    } else {
      const payload = {
        name: form.name,
        username: form.username,
        email: form.email,
        roles: form.roles,
        branch_id: form.branch_id,
        status: form.status
      }
      if (form.password) payload.password = form.password
      await rbacStore.updateUser(modal.userId, payload)
    }

    modal.open = false

    if (changingOwnPassword) {
      showToast('تم تغيير كلمة المرور. يرجى تسجيل الدخول مجددًا.')
      setTimeout(() => {
        authStore.clearAuthData()
        window.location.replace('/')
      }, 1200)
      return
    }

    await loadUsers(page.value)
    showToast(modal.mode === 'create' ? 'تم إضافة الموظف بنجاح' : 'تم تحديث البيانات')
  } catch (e) {
    modal.error = e?.response?.data?.message || e?.response?.data?.error || 'فشل الحفظ'
  } finally {
    modal.saving = false
  }
}

const confirmDelete = (user) => {
  deleteModal.user = user
  deleteModal.open = true
  deleteModal.saving = false
}

const deleteUser = async () => {
  deleteModal.saving = true
  try {
    await rbacStore.deleteUser(deleteModal.user.id)
    deleteModal.open = false
    await loadUsers(page.value)
    showToast('تم حذف الموظف')
  } catch (e) {
    showToast(e?.response?.data?.message || 'فشل الحذف', 'error')
    deleteModal.open = false
  } finally {
    deleteModal.saving = false
  }
}

const openPermissionOverrides = async (user) => {
  Object.assign(permissionModal, {
    open: true,
    loading: true,
    saving: false,
    user,
    permissions: [],
    reason: '',
    error: '',
  })
  try {
    const result = await rbacStore.getUserPermissions(user.id)
    permissionModal.permissions = result.permissions.map(permission => ({
      ...permission,
      overrideMode: permission.override?.effect || 'inherit',
    }))
  } catch (e) {
    permissionModal.error = e?.response?.data?.message || 'فشل تحميل الصلاحيات'
  } finally {
    permissionModal.loading = false
  }
}

const savePermissionOverrides = async () => {
  if (!permissionModal.reason.trim()) return
  permissionModal.saving = true
  permissionModal.error = ''
  try {
    const overrides = permissionModal.permissions
      .filter(p => p.overrideMode !== 'inherit')
      .map(p => ({
        permission_name: p.name,
        effect: p.overrideMode,
      }))
    await rbacStore.setUserPermissionOverrides(permissionModal.user.id, {
      overrides,
      reason: permissionModal.reason.trim(),
    })
    permissionModal.open = false
    showToast('تم حفظ تخصيصات الصلاحيات')
  } catch (e) {
    permissionModal.error = e?.response?.data?.message || 'فشل حفظ التخصيصات'
  } finally {
    permissionModal.saving = false
  }
}

// ─── Lifecycle ────────────────────────────────────────────────────────────────
onMounted(async () => {
  await Promise.all([
    branchStore.fetchBranches(),
    loadRoles(),
  ])
  await loadUsers(1)
})
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

.metadata-label {
  @apply text-[10px] font-bold text-slate-400 uppercase tracking-widest;
}

.filter-input-v2 {
  @apply w-full rounded-md border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 outline-none transition-all focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10;
}

.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.3s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  opacity: 0;
  transform: translate(-50%, 20px);
}
</style>
