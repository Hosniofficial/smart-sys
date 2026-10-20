<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="loading || actionLoading || securityLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8 animate-fadeIn">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة الاشتراكات"
        description="متابعة تراخيص المستأجرين، صلاحية الباقات، والتدقيق الأمني."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <div class="flex items-center gap-2">
            <button 
              @click="load" 
              :disabled="loading" 
              class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-700 text-xs font-bold transition-all flex items-center gap-2 shadow-sm hover:bg-slate-50 disabled:opacity-50"
            >
              <i class="fas fa-sync-alt text-[10px]" :class="{'animate-spin': loading}"></i>
              <span>{{ loading ? 'جاري التحميل...' : 'تحديث البيانات' }}</span>
            </button>
            <button 
              @click="exportCsv" 
              :disabled="!Array.isArray(rows) || rows.length === 0" 
              class="h-9 px-4 rounded-md bg-white border border-slate-200 text-emerald-600 text-xs font-bold transition-all flex items-center gap-2 shadow-sm hover:bg-emerald-50 hover:border-emerald-200 disabled:opacity-40"
            >
              <i class="fas fa-file-csv text-xs"></i>
              <span>تصدير CSV</span>
            </button>
          </div>
        </template>
      </PageHeader>

      <!-- KPI Summary Section -->
      <section class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <div class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">إجمالي الاشتراكات</p>
            <p class="text-2xl font-bold font-mono tracking-tighter text-slate-900">{{ (Array.isArray(rows) ? rows.length : 0) }}</p>
          </div>
          <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm bg-blue-50 text-blue-600 opacity-80 group-hover:opacity-100 transition-opacity">
            <i class="fas fa-id-card"></i>
          </div>
        </div>

        <div class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">تراخيص نشطة</p>
            <p class="text-2xl font-bold font-mono tracking-tighter text-emerald-600">{{ summary.active }}</p>
          </div>
          <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm bg-emerald-50 text-emerald-600 opacity-80 group-hover:opacity-100 transition-opacity">
            <i class="fas fa-check-double"></i>
          </div>
        </div>

        <div class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">تراخيص منتهية</p>
            <p class="text-2xl font-bold font-mono tracking-tighter text-rose-600">{{ summary.expired }}</p>
          </div>
          <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm bg-rose-50 text-rose-600 opacity-80 group-hover:opacity-100 transition-opacity">
            <i class="fas fa-hourglass-end"></i>
          </div>
        </div>
      </section>

      <!-- Filters Section -->
      <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">باقة الاشتراك</label>
            <select v-model="filters.plan" class="filter-input appearance-none">
              <option value="">كل الخطط</option>
              <option value="trial">التجريبية (Trial)</option>
              <option value="monthly">الشهرية (Monthly)</option>
              <option value="yearly">السنوية (Yearly)</option>
            </select>
          </div>

          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">حالة الاشتراك</label>
            <select v-model="filters.status" class="filter-input appearance-none">
              <option value="">كل الحالات</option>
              <option value="trial">تجريبي</option>
              <option value="active">نشط</option>
              <option value="expired">منتهي</option>
              <option value="cancelled">ملغى</option>
              <option value="pending">قيد الانتظار</option>
            </select>
          </div>

          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">مستوى المخاطر</label>
            <select v-model="filters.risk_level" class="filter-input appearance-none">
              <option value="">كل المستويات</option>
              <option value="low">منخفض (Low Risk)</option>
              <option value="medium">متوسط (Medium)</option>
              <option value="high">مرتفع (High Risk)</option>
            </select>
          </div>

          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">رقم المستأجر (Tenant ID)</label>
            <div class="relative">
              <input v-model.number="filters.tenant_id" type="number" min="1" class="filter-input pr-8 font-mono" placeholder="مثال: 101" />
              <i class="fas fa-hashtag absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-3 border-t border-slate-50">
          <button 
            @click="resetFilters" 
            :disabled="loading" 
            class="h-9 px-4 rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all disabled:opacity-50"
          >
            إعادة تعيين
          </button>
          <button 
            @click="applyFilters" 
            :disabled="loading" 
            class="h-9 px-5 bg-slate-900 text-white rounded-md text-xs font-bold shadow-sm hover:bg-black transition-all active:scale-95 flex items-center gap-2 disabled:opacity-50"
          >
            <i class="fas fa-filter text-[10px]"></i>
            <span>تطبيق التصفية</span>
          </button>
        </div>
      </div>

      <!-- Subscriptions Data Table Card -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative min-h-[400px]">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">#</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المستأجر</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الخطة / الباقة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">التحقق من البريد</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ البداية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ الانتهاء</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">السداد</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">المخاطر</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <!-- Skeleton loading for table -->
              <template v-if="loading">
                <tr v-for="row in 6" :key="row" class="animate-pulse">
                  <td class="px-6 py-4 text-center"><div class="h-3 bg-slate-100 rounded w-6 mx-auto"></div></td>
                  <td class="px-4 py-4"><div class="h-3 bg-slate-100 rounded w-16"></div></td>
                  <td class="px-4 py-4"><div class="h-3 bg-slate-100 rounded w-24"></div></td>
                  <td class="px-4 py-4 text-center"><div class="h-4 bg-slate-100 rounded-full w-14 mx-auto"></div></td>
                  <td class="px-4 py-4 text-center"><div class="h-4 bg-slate-100 rounded-full w-12 mx-auto"></div></td>
                  <td class="px-4 py-4"><div class="h-3 bg-slate-100 rounded w-20"></div></td>
                  <td class="px-4 py-4"><div class="h-3 bg-slate-100 rounded w-20"></div></td>
                  <td class="px-4 py-4 text-center"><div class="h-3 bg-slate-100 rounded w-12 mx-auto"></div></td>
                  <td class="px-4 py-4 text-center"><div class="h-4 bg-slate-100 rounded w-10 mx-auto"></div></td>
                  <td class="px-6 py-4 text-center"><div class="h-7 bg-slate-100 rounded w-36 mx-auto"></div></td>
                </tr>
              </template>
              
              <!-- Empty State -->
              <tr v-else-if="pagedRows.length === 0">
                <td colspan="9" class="py-24 text-center text-slate-300">
                  <i class="fas fa-id-card text-3xl mb-3 opacity-20"></i>
                  <p class="text-xs font-bold uppercase tracking-widest">لا توجد اشتراكات مسجلة</p>
                </td>
              </tr>

              <!-- Data Rows -->
              <tr v-for="s in pagedRows" :key="s.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-6 py-4 text-center text-slate-400 font-mono text-xs font-bold">{{ s.id }}</td>
                <td class="px-4 py-4">
                  <div class="flex flex-col">
                    <span class="text-xs font-bold text-slate-900">{{ s.tenant_name || 'بدون اسم' }}</span>
                    <span class="text-[9px] text-slate-400 uppercase font-bold font-mono tracking-wider mt-0.5">#{{ s.tenant_id }}</span>
                    <span v-if="s.tenant_email" class="text-[8px] text-slate-500 font-mono mt-0.5 truncate max-w-[120px]">{{ s.tenant_email }}</span>
                  </div>
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-col">
                    <span class="text-xs font-bold text-slate-900">{{ s.plan_name }}</span>
                    <span class="text-[9px] text-slate-400 uppercase font-bold font-mono tracking-wider mt-0.5">{{ s.plan_code }}</span>
                  </div>
                </td>
                <td class="px-4 py-4 text-center">
                  <span :class="['px-2.5 py-0.5 rounded-full text-[9px] font-bold border inline-flex items-center gap-1.5', badgeClass(s.status)]">
                    <span class="w-1 h-1 rounded-full bg-current"></span>
                    {{ s.status }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center">
                  <span v-if="s.email_verified" class="px-2 py-0.5 rounded-full text-[9px] font-bold border bg-emerald-50 text-emerald-600 border-emerald-100 inline-flex items-center gap-1">
                    <i class="fas fa-check text-[8px]"></i>
                    <span>مُتحقّق</span>
                  </span>
                  <span v-else class="px-2 py-0.5 rounded-full text-[9px] font-bold border bg-amber-50 text-amber-600 border-amber-100 inline-flex items-center gap-1">
                    <i class="fas fa-clock text-[8px]"></i>
                    <span>بانتظار</span>
                  </span>
                </td>
                <td class="px-4 py-4 text-[10px] font-bold text-slate-500 font-mono tracking-tighter">{{ formatDate(s.start_date) }}</td>
                <td class="px-4 py-4 text-[10px] font-bold text-slate-500 font-mono tracking-tighter">{{ formatDate(s.end_date) }}</td>
                <td class="px-4 py-4 text-center">
                  <span class="text-[10px] font-bold uppercase text-slate-400 font-mono">{{ s.payment_status || '-' }}</span>
                </td>
                <td class="px-4 py-4 text-center">
                  <span v-if="s.risk_score != null" :class="['px-2 py-0.5 rounded text-[9px] font-bold font-mono border', riskBadgeClass(s.risk_score)]">
                     {{ s.risk_score }}/10
                  </span>
                  <span v-else class="text-slate-300 font-mono text-xs">—</span>
                </td>
                <td class="px-6 py-4 text-center">
                  <div class="flex items-center justify-center gap-1">
                    <button @click="openActivate(s)" class="w-7 h-7 rounded border border-slate-200 text-slate-400 hover:text-emerald-600 hover:border-emerald-200 hover:bg-emerald-50 transition-all flex items-center justify-center" title="تفعيل">
                      <i class="fas fa-play text-[9px]"></i>
                    </button>
                    <button @click="openExpire(s)" class="w-7 h-7 rounded border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 hover:bg-rose-50 transition-all flex items-center justify-center" title="إيقاف">
                      <i class="fas fa-stop text-[9px]"></i>
                    </button>
                    <button @click="openExtend(s)" class="w-7 h-7 rounded border border-slate-200 text-slate-400 hover:text-amber-600 hover:border-amber-200 hover:bg-amber-50 transition-all flex items-center justify-center" title="تمديد">
                      <i class="fas fa-calendar-plus text-[9px]"></i>
                    </button>
                    <button @click="viewSecurityDetails(s)" class="w-7 h-7 rounded border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 hover:bg-blue-50 transition-all flex items-center justify-center" title="فحص أمني">
                      <i class="fas fa-shield-halved text-[9px]"></i>
                    </button>
                    <button @click="openChangePlan(s)" class="w-7 h-7 rounded border border-slate-200 text-slate-400 hover:text-indigo-600 hover:border-indigo-200 hover:bg-indigo-50 transition-all flex items-center justify-center" title="تغيير الخطة">
                      <i class="fas fa-shuffle text-[9px]"></i>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Footer -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ page }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span> إجمالي <span class="text-slate-900">{{ rows.length }}</span> سجل
          </div>
          <div class="flex items-center gap-1">
            <button @click="page--" :disabled="page <= 1" class="pagination-btn-v2">
              <i class="fas fa-chevron-right text-[10px]"></i>
            </button>
            <div class="px-3 h-8 bg-white border border-slate-200 rounded flex items-center text-[10px] font-bold text-slate-700 font-mono shadow-sm">
              {{ page }} / {{ totalPages }}
            </div>
            <button @click="page++" :disabled="page >= totalPages" class="pagination-btn-v2">
              <i class="fas fa-chevron-left text-[10px]"></i>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Activate Modal -->
    <BaseModal :show="dialogs.activate" @close="closeDialogs" maxWidth="md">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-emerald-600 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-bolt-lightning"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">تفعيل الاشتراك #{{ current?.id }}</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">اعتماد ترخيص المستأجر</p>
          </div>
        </div>
      </template>

      <div class="space-y-2">
        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">تعيين خطة مخصصة (اختياري)</label>
        <select v-model="form.plan" class="filter-input appearance-none">
          <option value="">بدون تغيير (الافتراضية)</option>
          <option value="monthly">monthly</option>
          <option value="yearly">yearly</option>
        </select>
      </div>

      <template #footer>
        <button @click="closeDialogs" class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">إلغاء</button>
        <button 
          @click="submitActivate" 
          :disabled="actionLoading" 
          class="px-6 h-9 rounded-md bg-emerald-600 text-white text-xs font-bold shadow-sm hover:bg-emerald-700 transition-all active:scale-95 flex items-center justify-center gap-2"
        >
          <BaseSpinner v-if="actionLoading" :size="14" color="#fff" />
          <span>تأكيد التفعيل</span>
        </button>
      </template>
    </BaseModal>

    <!-- Expire Modal -->
    <BaseModal :show="dialogs.expire" @close="closeDialogs" maxWidth="md">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-rose-600 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-stop"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">إيقاف الاشتراك #{{ current?.id }}</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">تعطيل وصول المستأجر</p>
          </div>
        </div>
      </template>

      <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 flex items-center gap-3">
        <i class="fas fa-triangle-exclamation text-rose-600 text-sm shrink-0"></i>
        <p class="text-xs font-semibold text-rose-900 leading-relaxed">
          تحذير: سيتم تعطيل وصول المستأجر إلى النظام بشكل فوري عند التأكيد.
        </p>
      </div>

      <template #footer>
        <button @click="closeDialogs" class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">تراجع</button>
        <button 
          @click="submitExpire" 
          :disabled="actionLoading" 
          class="px-6 h-9 rounded-md bg-rose-600 text-white text-xs font-bold shadow-sm hover:bg-rose-700 transition-all active:scale-95 flex items-center justify-center gap-2"
        >
          <BaseSpinner v-if="actionLoading" :size="14" color="#fff" />
          <span>إيقاف الآن</span>
        </button>
      </template>
    </BaseModal>

    <!-- Extend Modal -->
    <BaseModal :show="dialogs.extend" @close="closeDialogs" maxWidth="md">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-amber-500 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-calendar-plus"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">تمديد اشتراك #{{ current?.id }}</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">إضافة فترة صلاحية جديدة</p>
          </div>
        </div>
      </template>

      <div class="space-y-2">
        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">عدد أيام التمديد الإضافية</label>
        <input v-model.number="form.days" type="number" min="1" class="filter-input font-mono text-center text-base" />
        <p class="text-[10px] text-slate-400 font-bold text-center">سيتم إضافة هذه الأيام لتاريخ الانتهاء الحالي تلقائياً</p>
      </div>

      <template #footer>
        <button @click="closeDialogs" class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">إلغاء</button>
        <button 
          @click="submitExtend" 
          :disabled="actionLoading || !form.days" 
          class="px-6 h-9 rounded-md bg-amber-500 text-white text-xs font-bold shadow-sm hover:bg-amber-600 transition-all active:scale-95 flex items-center justify-center gap-2"
        >
          <BaseSpinner v-if="actionLoading" :size="14" color="#fff" />
          <span>تأكيد التمديد</span>
        </button>
      </template>
    </BaseModal>

    <!-- Security Modal -->
    <BaseModal :show="dialogs.security" @close="closeDialogs" maxWidth="2xl">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-slate-900 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-shield-halved"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">التدقيق الأمني للاشتراك #{{ current?.id }}</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">فحص مؤشرات المخاطر والمصادقة</p>
          </div>
        </div>
      </template>

      <div class="space-y-5">
        <div class="grid grid-cols-2 gap-4">
          <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center space-y-1">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">مؤشر المخاطر</p>
            <span :class="['px-3 py-1 rounded text-lg font-bold font-mono inline-block border', riskBadgeClass(current?.risk_score)]">
              {{ current?.risk_score || 0 }}/10
            </span>
          </div>
          <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center space-y-1">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">آخر فحص تلقائي</p>
            <p class="text-xs font-bold text-slate-800 font-mono mt-2">{{ formatDate(current?.last_security_check) }}</p>
          </div>
        </div>

        <div v-if="current?.security_flags" class="bg-amber-50 border border-amber-200 p-4 rounded-xl space-y-1">
          <h4 class="text-xs font-bold text-amber-800 flex items-center gap-2">
            <i class="fas fa-triangle-exclamation text-[11px]"></i> تنبيهات رصدها النظام:
          </h4>
          <p class="text-xs font-medium text-amber-700 leading-relaxed italic">{{ current.security_flags }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
          <button 
            @click="refreshSecurityData" 
            :disabled="securityLoading" 
            class="h-9 px-4 bg-slate-900 text-white rounded-md font-bold text-xs hover:bg-black transition-all active:scale-95 flex items-center justify-center gap-2 shadow-sm disabled:opacity-50"
          >
            <BaseSpinner v-if="securityLoading" :size="14" color="#fff" />
            <i v-else class="fas fa-shield-virus text-[10px]"></i>
            <span>تشغيل فحص فوري</span>
          </button>
          <button 
            @click="blockSubscription" 
            :disabled="securityLoading" 
            class="h-9 px-4 bg-rose-50 text-rose-600 border border-rose-200 rounded-md font-bold text-xs hover:bg-rose-600 hover:text-white transition-all active:scale-95 flex items-center justify-center gap-2 shadow-sm disabled:opacity-50"
          >
            <i class="fas fa-ban text-[10px]"></i>
            <span>حظر المستأجر نهائياً</span>
          </button>
        </div>
      </div>

      <template #footer>
        <button @click="closeDialogs" class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">إغلاق</button>
      </template>
    </BaseModal>

    <!-- Change Plan Modal -->
    <BaseModal :show="dialogs.changePlan" @close="closeDialogs" maxWidth="md">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-shuffle"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">تعديل باقة المستأجر #{{ current?.id }}</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">ترقية أو تغيير نوع الخطة</p>
          </div>
        </div>
      </template>

      <div class="space-y-4">
        <div class="p-3.5 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-between">
          <p class="text-[10px] font-bold text-blue-500 uppercase tracking-widest">الباقة الحالية</p>
          <span class="text-xs font-bold text-blue-900 font-mono">{{ current?.plan_code }} — {{ current?.plan_name }}</span>
        </div>

        <div class="space-y-1.5">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">اختر الباقة الجديدة</label>
          <select v-model="form.newPlan" class="filter-input appearance-none" required>
            <option value="">-- اختر خطة --</option>
            <option value="trial">Trial (تجريبي)</option>
            <option value="monthly">Monthly (شهري)</option>
            <option value="yearly">Yearly (سنوي)</option>
          </select>
        </div>

        <div class="space-y-2 pt-2 border-t border-slate-50">
          <label class="flex items-center gap-2.5 p-2 rounded-lg hover:bg-slate-50 transition-all cursor-pointer">
            <input type="checkbox" v-model="form.prorate" class="rounded border-slate-300 text-indigo-600 focus:ring-0 w-4 h-4" />
            <span class="text-xs font-bold text-slate-700">احتساب تناسبي (Prorate)</span>
          </label>
          <label class="flex items-center gap-2.5 p-2 rounded-lg hover:bg-slate-50 transition-all cursor-pointer">
            <input type="checkbox" v-model="form.extendPeriod" class="rounded border-slate-300 text-indigo-600 focus:ring-0 w-4 h-4" />
            <span class="text-xs font-bold text-slate-700">تمديد فترة الصلاحية آلياً</span>
          </label>
        </div>
      </div>

      <template #footer>
        <button @click="closeDialogs" class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">إلغاء</button>
        <button 
          @click="submitChangePlan" 
          :disabled="actionLoading || !form.newPlan" 
          class="px-6 h-9 rounded-md bg-indigo-600 text-white text-xs font-bold shadow-sm hover:bg-indigo-700 transition-all active:scale-95 flex items-center justify-center gap-2"
        >
          <BaseSpinner v-if="actionLoading" :size="14" color="#fff" />
          <span>تحديث الباقة</span>
        </button>
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import BaseModal from '@/components/BaseModal.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useAdminStore } from '@/stores/admin/adminStore'
import { useAuthStore } from '@/stores/auth/authStore'
import { useBreadcrumb } from '@/composables/useBreadcrumb'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
import apiClient from '@/config/axios'

// --- State & Stores (Strictly Preserved) ---
const adminStore = useAdminStore()
const { breadcrumb } = useBreadcrumb()
const rows = ref([])
const loading = computed(() => adminStore.loading)
const actionLoading = computed(() => adminStore.actionLoading)
const securityLoading = ref(false)
const page = ref(1)
const pageSize = ref(10)

const filters = ref({ plan: '', status: '', tenant_id: '', risk_level: '' })
const dialogs = ref({ activate: false, expire: false, extend: false, security: false, changePlan: false })
const current = ref(null)
const form = ref({ plan: '', days: 30, newPlan: '', prorate: false, extendPeriod: false })

const pagedRows = computed(() => {
  if (!Array.isArray(rows.value)) return []
  const start = (page.value - 1) * pageSize.value
  return rows.value.slice(start, start + pageSize.value)
})

const totalPages = computed(() => {
  if (!Array.isArray(rows.value)) return 1
  return Math.max(1, Math.ceil(rows.value.length / pageSize.value))
})

const summary = computed(() => {
  if (!Array.isArray(rows.value)) return { active: 0, expired: 0 }
  return {
    active: rows.value.filter(r => r.status === 'active').length,
    expired: rows.value.filter(r => r.status === 'expired').length,
  }
})

function formatDate(d) {
  if (!d) return '-'
  try { return new Date(d).toLocaleString('en-US') } catch { return d }
}

function badgeClass(status) {
  if (status === 'active') return 'bg-emerald-50 text-emerald-600 border-emerald-100'
  if (status === 'trial') return 'bg-blue-50 text-blue-600 border-blue-100'
  if (status === 'expired') return 'bg-rose-50 text-rose-600 border-rose-100'
  return 'bg-slate-50 text-slate-600 border-slate-200'
}

function riskBadgeClass(score) {
  if (score >= 7) return 'bg-rose-50 text-rose-600 border-rose-100'
  if (score >= 4) return 'bg-amber-50 text-amber-600 border-amber-100'
  return 'bg-emerald-50 text-emerald-600 border-emerald-100'
}

async function load() {
  const params = { ...filters.value }
  if (!params.tenant_id) delete params.tenant_id
  const result = await adminStore.fetchSubscriptions(params)
  if (result.status === 'success') {
    // تأكد من أن البيانات هي array
    rows.value = Array.isArray(result.data) ? result.data : []
    page.value = 1
  } else {
    rows.value = []
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
}

function applyFilters() { load() }
function resetFilters() { filters.value = { plan: '', status: '', tenant_id: '', risk_level: '' }; load() }

function openActivate(s) { current.value = s; form.value = { plan: '', days: 30, newPlan: '', prorate: false, extendPeriod: false }; dialogs.value.activate = true }
function openExpire(s) { current.value = s; dialogs.value.expire = true }
function openExtend(s) { current.value = s; form.value = { ...form.value, days: 30, newPlan: '', prorate: false, extendPeriod: false }; dialogs.value.extend = true }
function viewSecurityDetails(s) { current.value = s; dialogs.value.security = true }
function openChangePlan(s) { current.value = s; form.value = { ...form.value, newPlan: '', prorate: false, extendPeriod: false }; dialogs.value.changePlan = true }
function closeDialogs() { dialogs.value = { activate: false, expire: false, extend: false, security: false, changePlan: false }; current.value = null }

async function refreshSecurityData() {
  if (!current.value) return
  securityLoading.value = true
  try {
    const res = await apiClient.post(`/admin/subscriptions/${current.value.id}/security-check`)
    if (res.data?.data) {
      Object.assign(current.value, res.data.data)
      if (typeof window?.showToast === 'function') window.showToast('تم تحديث البيانات الأمنية', 'success')
    }
  } catch (e) {
    if (typeof window?.showToast === 'function') window.showToast(e?.message || 'فشل التحديث', 'error')
  } finally { securityLoading.value = false }
}

async function blockSubscription() {
  if (!current.value) return
  if (!confirm(`هل أنت متأكد من حظر اشتراك #${current.value.id}؟`)) return
  const result = await adminStore.blockSubscription(current.value.id)
  if (result.status === 'success') {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'success')
    closeDialogs()
    await load()
  } else {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
}

async function submitActivate() {
  if (!current.value) return
  const result = await adminStore.activateSubscription(current.value.id, form.value.plan)
  if (result.status === 'success') {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'success')
    closeDialogs(); await load()
  } else {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
}

async function submitExpire() {
  if (!current.value) return
  const result = await adminStore.expireSubscription(current.value.id)
  if (result.status === 'success') {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'success')
    closeDialogs(); await load()
  } else {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
}

async function submitChangePlan() {
  if (!current.value || !form.value.newPlan) return
  const result = await adminStore.changeSubscriptionPlan(
    current.value.id,
    form.value.newPlan,
    form.value.prorate,
    form.value.extendPeriod
  )
  if (result.status === 'success') {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'success')
    closeDialogs(); await load()
  } else {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
}

async function submitExtend() {
  if (!current.value || !form.value.days || form.value.days <= 0) return
  const result = await adminStore.extendSubscription(current.value.id, form.value.days)
  if (result.status === 'success') {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'success')
    closeDialogs(); await load()
  } else {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
}

function exportCsv() {
  if (!Array.isArray(rows.value) || rows.value.length === 0) return
  const headers = ['id', 'tenant_id', 'plan_code', 'plan_name', 'status', 'start_date', 'end_date', 'payment_status']
  const csv = [headers.join(',')]
  rows.value.forEach(r => {
    csv.push([
      r.id, r.tenant_id, r.plan_code, r.plan_name, r.status, r.start_date, r.end_date, r.payment_status || ''
    ].map(v => `"${(v ?? '').toString().replaceAll('"', '""')}"`).join(','))
  })
  const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'subscriptions.csv'
  a.click()
  URL.revokeObjectURL(url)
}

onMounted(() => load())
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
</style>