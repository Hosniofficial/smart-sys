<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">

    <!-- Top Progress Bar -->
    <PageLoadingBar :active="loading || loadingDetails" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: 'العمليات', current: 'صيانة الأجهزة' }"
        title="صيانة الأجهزة"
        description="أدر عمليات استلام الأجهزة وإصلاحها، وفوترة قطع الغيار وأجور الصيانة، وتوثيق التسليم."
        :branches="canSelectBranch ? branches : []"
        :selectedBranch="selectedBranchId"
        :hasExplicitSelection="hasExplicitBranchSelection"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <button
            @click="filters.showFilters.value = !filters.showFilters.value"
            :class="[filters.showFilters.value ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 border-slate-200']"
            class="h-9 px-4 rounded-md border text-xs font-bold transition-all flex items-center gap-2 shadow-sm"
          >
            <i class="fas fa-filter text-[10px]"></i>
            {{ filters.showFilters.value ? 'إخفاء أدوات التصفية' : 'تصفية النتائج' }}
          </button>
          <button class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold hover:bg-blue-700 transition-all shadow-sm flex items-center gap-2" @click="showCreate = true">
            <i class="fas fa-plus text-[10px]"></i> استلام جهاز جديد
          </button>
        </template>
      </PageHeader>

      <!-- KPI Cards -->
      <section class="grid grid-cols-2 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        <div v-for="kpi in kpiCards" :key="kpi.label"
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

      <!-- Filters Panel -->
      <transition name="slide-down">
        <div v-if="filters.showFilters.value" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="p-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">بحث سريع</label>
                <div class="relative">
                  <input v-model="filters.searchQuery.value" type="text" class="filter-input" style="padding-right: 2rem;"
                    placeholder="رقم الأمر أو العميل أو الرقم التسلسلي..." />
                  <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">من تاريخ</label>
                <div class="relative">
                  <input ref="dateFromRef" type="date" v-model="filters.dateFrom.value" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="dateFromRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer text-slate-300 hover:text-slate-500 transition-colors text-[10px]"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">إلى تاريخ</label>
                <div class="relative">
                  <input ref="dateToRef" type="date" v-model="filters.dateTo.value" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="dateToRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer text-slate-300 hover:text-slate-500 transition-colors text-[10px]"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">حالة الأمر</label>
                <select v-model="filters.statusFilter.value" class="filter-input appearance-none">
                  <option value="">كل الحالات</option>
                  <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
              </div>
            </div>

            <div class="flex justify-end pt-4 border-t border-slate-50">
              <button @click="filters.clearAllFilters()" class="h-9 px-4 rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all">
                إعادة تعيين الفلاتر
              </button>
            </div>
          </div>
        </div>
      </transition>

      <!-- Active Filter Chips -->
      <div v-if="filters.hasActiveFilters.value || (canSelectBranch && selectedBranchId !== null)" class="flex flex-wrap gap-2">
        <div v-for="chip in activeChips.filter(c => c.show)" :key="chip.label"
          class="inline-flex items-center gap-2 px-2.5 py-1 bg-blue-50 border border-blue-100 rounded-md text-[10px] font-bold text-blue-700">
          {{ chip.label }}
          <i @click="chip.clear" class="fas fa-times cursor-pointer hover:text-blue-900 opacity-60"></i>
        </div>
      </div>

      <!-- Main Data Table -->
      <div class="bg-white rounded-xl overflow-hidden shadow-sm border border-slate-200 relative">

        <!-- Error Banner -->
        <div v-if="error" class="m-4 p-3 rounded-lg border-l-4 border-rose-400 bg-rose-50 flex items-center gap-3">
          <i class="fas fa-exclamation-circle text-rose-500"></i>
          <p class="text-sm text-rose-700">{{ error }}</p>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">رقم الأمر</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">العميل</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الجهاز</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest hidden md:table-cell">العطل</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th @click="toggleSort('received_at')" class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest cursor-pointer group">
                  تاريخ الاستلام
                  <i :class="filters.sortKey.value === 'received_at' ? (filters.sortAsc.value ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'"
                    class="fas ml-1 opacity-20 group-hover:opacity-100 transition-opacity"></i>
                </th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <!-- Loading Skeleton -->
              <template v-if="loading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 7" :key="m" class="px-4 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>

              <!-- Empty State -->
              <tr v-else-if="!orders.length">
                <td colspan="7" class="py-20 text-center">
                  <i class="fas fa-tools text-3xl mb-4 text-slate-200"></i>
                  <p class="text-xs font-bold uppercase tracking-widest text-slate-300">لا توجد أوامر صيانة مطابقة</p>
                </td>
              </tr>

              <!-- Data Rows -->
              <tr v-else v-for="order in orders" :key="order.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-4 py-3 text-[11px] font-bold font-mono text-slate-900">{{ order.order_number }}</td>
                <td class="px-4 py-3">
                  <p class="text-[11px] font-bold text-slate-700 truncate max-w-[130px]">{{ order.customer_name }}</p>
                  <p class="text-[10px] text-slate-400 font-mono mt-0.5">{{ order.customer_phone }}</p>
                </td>
                <td class="px-4 py-3 text-[11px] text-slate-600 truncate max-w-[150px]">
                  {{ [order.brand, order.model, order.device_type].filter(Boolean).join(' · ') }}
                </td>
                <td class="px-4 py-3 text-[10px] text-slate-500 max-w-[180px] truncate hidden md:table-cell">{{ order.issue_description }}</td>
                <td class="px-4 py-3 text-center">
                  <span :class="getStatusBadgeClass(order.status)"
                    class="px-2 py-0.5 rounded text-[8px] font-bold border">
                    {{ statusLabel(order.status) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-[10px] font-mono text-slate-400">{{ formatDateTime(order.received_at) }}</td>
                <td class="px-4 py-3 text-center">
                  <button @click="openOrder(order.id)" :disabled="loadingDetails"
                    class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center mx-auto disabled:opacity-50"
                    :aria-label="`فتح تفاصيل أمر الصيانة ${order.order_number}`">
                    <i class="fas fa-eye text-[10px]"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ filters.total.value }}</span> أمر
          </div>
          <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
              <span class="text-[10px] font-bold text-slate-400 uppercase">النتائج:</span>
              <select v-model.number="filters.perPage.value" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                <option :value="10">10</option>
                <option :value="20">20</option>
                <option :value="50">50</option>
              </select>
            </div>
            <PaginationControls
              :page="filters.page.value"
              :total-pages="totalPages"
              @previous="filters.previousPage()"
              @next="filters.nextPage(totalPages)"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         نافذة إنشاء أمر صيانة جديد
    ═══════════════════════════════════════════════════ -->
    <HistoryDetailsModal
      :show="showCreate"
      @close="showCreate = false"
      title="استلام جهاز للصيانة"
      icon="fas fa-tools"
      maxWidth="3xl"
    >
      <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="createOrder">
        <label class="space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفرع <span class="text-rose-500">*</span></span>
          <select v-model="createForm.branch_id" class="filter-input" required>
            <option value="" disabled>اختر الفرع</option>
            <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option>
          </select>
        </label>
        <label ref="customerSearchContainer" class="space-y-1.5 relative">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">العميل <span class="text-rose-500">*</span></span>
          <div class="relative">
            <input
              v-model="customerQuery"
              type="search"
              class="filter-input pl-8"
              required
              autocomplete="off"
              placeholder="ابحث باسم العميل أو الهاتف..."
              @focus="showCustomerDropdown = true"
            />
            <button
              v-if="customerQuery"
              type="button"
              class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700"
              aria-label="مسح العميل"
              @click="clearCustomerSelection"
            >
              <i class="fas fa-times text-[10px]"></i>
            </button>
          </div>
          <div
            v-if="showCustomerDropdown && filteredCustomers.length"
            class="absolute z-20 mt-1 max-h-52 w-full overflow-y-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg"
          >
            <button
              v-for="customer in filteredCustomers"
              :key="customer.id"
              type="button"
              class="block w-full px-3 py-2 text-right text-xs font-bold text-slate-700 hover:bg-blue-50"
              @mousedown.prevent="selectCustomer(customer)"
            >
              {{ customer.name }}
            </button>
          </div>
          <p v-if="createForm.customer_id && selectedCustomer" class="mt-1 text-[10px] font-bold text-emerald-600">
            تم اختيار: {{ selectedCustomer.name }}
          </p>
        </label>
        <label class="space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">نوع الجهاز <span class="text-rose-500">*</span></span>
          <input v-model.trim="createForm.device_type" class="filter-input" required placeholder="هاتف، حاسوب، شاشة…" />
        </label>
        <label class="space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الشركة المصنعة</span>
          <input v-model.trim="createForm.brand" class="filter-input" />
        </label>
        <label class="space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الموديل</span>
          <input v-model.trim="createForm.model" class="filter-input" />
        </label>
        <label class="space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الرقم التسلسلي / IMEI</span>
          <input v-model.trim="createForm.serial_number" class="filter-input" />
        </label>
        <label class="space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الملحقات المستلمة</span>
          <input v-model.trim="createForm.accessories" class="filter-input" placeholder="شاحن، حقيبة…" />
        </label>
        <label class="space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">التكلفة التقديرية</span>
          <input v-model.number="createForm.estimated_cost" class="filter-input" type="number" min="0" step="0.01" />
        </label>
        <label class="space-y-1.5 sm:col-span-2">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">وصف العطل <span class="text-rose-500">*</span></span>
          <textarea v-model.trim="createForm.issue_description" class="filter-input h-20 py-2 resize-none" required />
        </label>
        <label class="space-y-1.5 sm:col-span-2">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">حالة الجهاز عند الاستلام</span>
          <textarea v-model.trim="createForm.condition_received" class="filter-input h-16 py-2 resize-none" />
        </label>
        <label class="space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">موعد الإنجاز المتوقع</span>
          <input v-model="createForm.expected_completion_date" class="filter-input" type="date" />
        </label>
        <div v-if="dialogError" class="sm:col-span-2 p-3 rounded-lg bg-rose-50 border border-rose-100 text-sm text-rose-700">{{ dialogError }}</div>
      </form>

      <template #footer>
        <div class="mr-auto flex items-center gap-3 w-full">
          <div v-if="dialogError" class="flex-1 text-[11px] font-bold text-rose-600 truncate">
            <i class="fas fa-exclamation-circle ml-1"></i>{{ dialogError }}
          </div>
          <div class="flex items-center gap-3" :class="{ 'mr-auto': !dialogError }">
            <button type="button" @click="showCreate = false"
              class="inline-flex h-10 items-center justify-center px-4 text-xs font-bold text-slate-500 hover:text-slate-800 transition-colors">
              إلغاء
            </button>
            <LoadingButton
              type="button"
              text="تأكيد الاستلام"
              loadingText="جارٍ الحفظ..."
              icon="fas fa-check"
              :isLoading="saving"
              @click="createOrder"
              class="h-10 rounded-md px-5 text-xs"
            />
          </div>
        </div>
      </template>
    </HistoryDetailsModal>

    <!-- ═══════════════════════════════════════════════════
         نافذة تفاصيل / تعديل أمر الصيانة
    ═══════════════════════════════════════════════════ -->
    <HistoryDetailsModal
      :show="!!selected"
      @close="selected = null"
      :title="selected ? `أمر صيانة ${selected.order_number}` : ''"
      icon="fas fa-wrench"
      maxWidth="5xl"
    >
      <div v-if="selected" class="space-y-6">

        <!-- معلومات أساسية -->
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          <div v-for="info in orderInfoFacts" :key="info.l"
            class="min-w-0 space-y-1 rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-2">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wide">{{ info.l }}</p>
            <p class="break-words text-[11px] font-bold leading-4 text-slate-800">{{ info.v }}</p>
          </div>
        </div>

        <!-- محتوى التعديل في grid -->
        <div class="grid gap-5 lg:grid-cols-2">

          <!-- العمود الأيسر: معلومات الجهاز + التعديلات -->
          <div class="space-y-4">
            <!-- بطاقة معلومات الجهاز -->
            <div class="rounded-xl bg-slate-50 border border-slate-100 p-4 space-y-2 text-[11px]">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">بيانات الجهاز</p>
              <div class="grid grid-cols-2 gap-2">
                <div><span class="text-slate-400">الجهاز: </span><span class="font-bold text-slate-700">{{ [selected.brand, selected.model, selected.device_type].filter(Boolean).join(' · ') }}</span></div>
                <div><span class="text-slate-400">الرقم التسلسلي: </span><span class="font-bold text-slate-700">{{ selected.serial_number || '—' }}</span></div>
                <div><span class="text-slate-400">الملحقات: </span><span class="font-bold text-slate-700">{{ selected.accessories || '—' }}</span></div>
                <div><span class="text-slate-400">الحالة عند الاستلام: </span><span class="font-bold text-slate-700">{{ selected.condition_received || '—' }}</span></div>
              </div>
              <div class="pt-2 border-t border-slate-200 mt-2">
                <span class="text-slate-400">العطل: </span><span class="font-bold text-slate-700">{{ selected.issue_description }}</span>
              </div>
            </div>

            <!-- تحديث الحالة -->
            <label class="space-y-1.5">
              <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الحالة</span>
              <select v-model="editForm.status" class="filter-input" :disabled="selected.status === 'delivered' || selected.status === 'cancelled'">
                <option :value="selected.status">{{ statusLabel(selected.status) }}</option>
                <option v-for="item in nextStatuses(selected.status)" :key="item" :value="item">{{ statusLabel(item) }}</option>
              </select>
            </label>

            <label class="space-y-1.5">
              <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">التشخيص</span>
              <textarea v-model.trim="editForm.diagnosis" class="filter-input h-20 py-2 resize-none" />
            </label>

            <label class="space-y-1.5">
              <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الإصلاح المنفذ</span>
              <textarea v-model.trim="editForm.resolution" class="filter-input h-20 py-2 resize-none" />
            </label>

            <label class="space-y-1.5">
              <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">موعد الإنجاز المتوقع</span>
              <input v-model="editForm.expected_completion_date" class="filter-input" type="date" />
            </label>

            <!-- أجرة الصيانة -->
            <div class="grid grid-cols-2 gap-3">
              <label class="space-y-1.5">
                <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">منتج أجرة الصيانة</span>
                <select v-model="editForm.labor_product_id" class="filter-input" :disabled="!!selected.invoice_id">
                  <option value="">بدون</option>
                  <option v-for="product in serviceProducts" :key="product.id" :value="String(product.id)">{{ product.name }}</option>
                </select>
              </label>
              <label class="space-y-1.5">
                <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">أجرة الصيانة</span>
                <input v-model.number="editForm.labor_amount" class="filter-input" type="number" min="0" step="0.01" :disabled="!!selected.invoice_id" />
              </label>
            </div>
          </div>

          <!-- العمود الأيمن: قطع الغيار -->
          <div class="space-y-4">
            <div class="flex items-center justify-between">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">قطع الغيار</p>
              <span class="text-[10px] text-slate-400">تُخصم من المخزون عند إصدار الفاتورة</span>
            </div>

            <div v-if="!editForm.parts.length" class="rounded-xl border border-dashed border-slate-200 p-6 text-center text-slate-300">
              <i class="fas fa-cogs text-2xl mb-2"></i>
              <p class="text-[11px] font-bold">لا توجد قطع غيار مضافة</p>
            </div>

            <div v-for="(part, index) in editForm.parts" :key="index"
              class="rounded-xl border border-slate-200 bg-slate-50/50 p-3 space-y-2">
              <div class="flex items-center justify-between mb-1">
                <span class="text-[10px] font-bold text-slate-400 uppercase">قطعة #{{ index + 1 }}</span>
                <button :disabled="!!selected.invoice_id" @click="editForm.parts.splice(index, 1)"
                  class="w-6 h-6 rounded text-rose-400 hover:text-rose-600 hover:bg-rose-50 disabled:opacity-30 transition-all flex items-center justify-center">
                  <i class="fas fa-trash-alt text-[9px]"></i>
                </button>
              </div>
              <select v-model="part.product_id" class="filter-input" :disabled="!!selected.invoice_id" @change="setPartDefaults(part)">
                <option value="">اختر قطعة الغيار</option>
                <option v-for="product in stockProducts" :key="product.id" :value="String(product.id)">{{ product.name }}</option>
              </select>
              <div class="grid grid-cols-3 gap-2">
                <select v-model="part.unit_id" class="filter-input col-span-1" :disabled="!!selected.invoice_id">
                  <option value="">الوحدة</option>
                  <option v-for="unit in unitsFor(part.product_id)" :key="unit.unit_id" :value="String(unit.unit_id)">{{ unit.unit_name }}</option>
                </select>
                <input v-model.number="part.quantity" class="filter-input col-span-1" type="number" min="0.0001" step="0.0001" placeholder="الكمية" aria-label="الكمية" :disabled="!!selected.invoice_id" />
                <input v-model.number="part.sale_price" class="filter-input col-span-1" type="number" min="0" step="0.01" placeholder="السعر" aria-label="السعر" :disabled="!!selected.invoice_id" />
              </div>
            </div>

            <button class="w-full h-9 rounded-md border border-slate-200 text-slate-600 text-[11px] font-bold hover:bg-slate-50 transition-all disabled:opacity-40"
              :disabled="!!selected.invoice_id" @click="addPart">
              <i class="fas fa-plus text-[9px] ml-1"></i> إضافة قطعة
            </button>

            <!-- إجمالي القطع -->
            <div class="flex justify-between items-center bg-blue-600 text-white px-4 py-3 rounded-lg">
              <span class="text-[11px] font-bold uppercase tracking-widest opacity-80">إجمالي القطع + الأجرة</span>
              <span class="text-lg font-bold font-mono tracking-tighter">{{ formatPrice(partsTotal + Number(editForm.labor_amount || 0)) }}</span>
            </div>

            <!-- رابط الفاتورة إن وُجدت -->
            <div v-if="selected.invoice_id" class="p-4 rounded-xl bg-emerald-50 border border-emerald-100">
              <p class="text-[10px] font-bold text-emerald-700 uppercase tracking-widest mb-1">الفاتورة مُصدرة</p>
              <p class="text-xs text-emerald-800">
                رقم الفاتورة: <span class="font-bold">{{ selected.invoice_number || selected.invoice_id }}</span>
              </p>
              <RouterLink
                class="mt-2 inline-flex items-center gap-1.5 text-[11px] font-bold text-blue-700 hover:underline"
                :to="{ path: '/sales/history', query: { id: selected.invoice_id } }">
                <i class="fas fa-external-link-alt text-[9px]"></i>
                فتح سجل الفاتورة وتحصيلها
              </RouterLink>
            </div>

            <!-- زر إصدار الفاتورة -->
            <button
              v-else-if="selected.status === 'ready'"
              :disabled="saving"
              @click="issueInvoice"
              class="w-full h-10 rounded-md bg-emerald-600 text-white text-[11px] font-bold hover:bg-emerald-700 transition-all disabled:opacity-50 flex items-center justify-center gap-2">
              <i class="fas fa-file-invoice text-[10px]"></i>
              إصدار فاتورة قطع الغيار وأجرة الصيانة
            </button>
          </div>
        </div>

        <!-- رسالة خطأ -->
        <div v-if="dialogError" class="p-3 rounded-lg bg-rose-50 border border-rose-100 text-sm text-rose-700">{{ dialogError }}</div>
      </div>

      <template #footer>
        <div class="mr-auto flex flex-wrap items-center gap-3">
          <button @click="selected = null"
            class="inline-flex h-10 items-center justify-center px-4 text-xs font-bold text-slate-500 hover:text-slate-800 transition-colors">
            إغلاق
          </button>
          <LoadingButton
            type="button"
            text="حفظ التحديثات"
            loadingText="جارٍ الحفظ..."
            icon="fas fa-save"
            :isLoading="saving"
            :disabled="!selected || selected.status === 'delivered' || selected.status === 'cancelled'"
            @click="saveOrder"
            class="h-10 rounded-md px-5 text-xs"
          />
        </div>
      </template>
    </HistoryDetailsModal>

  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue'
import PaginationControls from '@/components/ui/PaginationControls.vue'
import LoadingButton from '@/components/ui/LoadingButton.vue'
import RepairOrdersService from '@/services/repairOrders'
import { useHistoryFilters } from '@/composables/useHistoryFilters'
import { useBranchIsolation } from '@/composables/useBranchIsolation'
import { useToast } from '@/composables/useToast'
import { useCompanyCurrency } from '@/composables/useCompanyCurrency'
import AlertService from '@/services/AlertService'
import { useAuthStore } from '@/stores/auth/authStore'
import { useBranchStore } from '@/stores/branch/branchStore'
import { useCustomerStore } from '@/stores/customer/customerStore'
import { useProductStore } from '@/stores/product/productStore'

// ─── Stores & Composables ────────────────────────────────────────────────────
const authStore     = useAuthStore()
const branchStore   = useBranchStore()
const customerStore = useCustomerStore()
const productStore  = useProductStore()
const branchIsolation = useBranchIsolation()
const { showToast } = useToast()
const { formatCurrencyLocale } = useCompanyCurrency()
const formatPrice = (amount) => formatCurrencyLocale(amount, 2)

// ─── Filters (reuse composable like SalesHistory) ────────────────────────────
const filters = useHistoryFilters('repairs_hist_filters')
filters.loadFromLocalStorage()

const dateFromRef = ref(null)
const dateToRef   = ref(null)

// ─── Branch Logic ─────────────────────────────────────────────────────────────
const branches = computed(() => {
  if (!branchIsolation.isExempt.value && authStore.user?.branch_id) {
    return branchStore.branches.filter(b => String(b.id) === String(authStore.user.branch_id))
  }
  return branchStore.branches
})
const canSelectBranch = computed(() => branchIsolation.isExempt.value)
const selectedBranchId = computed(() => branchStore.selectedBranchId)
const userChoseBranch = ref(localStorage.getItem('selectedBranchId') !== null)
const hasExplicitBranchSelection = computed(() => userChoseBranch.value)

const onBranchChange = (newBranchId) => {
  if (!canSelectBranch.value) return
  branchStore.setSelectedBranch(newBranchId)
  userChoseBranch.value = true
  filters.page.value = 1
  loadOrders()
}

// ─── Data ─────────────────────────────────────────────────────────────────────
const customers     = ref([])
const customerQuery = ref('')
const showCustomerDropdown = ref(false)
const customerSearchContainer = ref(null)
const products      = ref([])
const orders        = ref([])
const statusCounts  = ref({ in_repair: 0, ready: 0, delivered: 0 })

const loading        = ref(false)
const loadingDetails = ref(false)
const saving         = ref(false)
const error          = ref('')
const dialogError    = ref('')

const showCreate = ref(false)
const selected   = ref(null)

let searchTimer = null

// ─── Statuses ─────────────────────────────────────────────────────────────────
const statuses = [
  { value: 'received',         label: 'تم الاستلام' },
  { value: 'diagnosing',       label: 'قيد التشخيص' },
  { value: 'waiting_approval', label: 'بانتظار موافقة العميل' },
  { value: 'waiting_parts',    label: 'بانتظار قطع الغيار' },
  { value: 'in_repair',        label: 'قيد الإصلاح' },
  { value: 'ready',            label: 'جاهز للتسليم' },
  { value: 'delivered',        label: 'تم التسليم' },
  { value: 'cancelled',        label: 'ملغي' },
]

const transitions = {
  received:         ['diagnosing', 'cancelled'],
  diagnosing:       ['waiting_approval', 'waiting_parts', 'in_repair', 'cancelled'],
  waiting_approval: ['waiting_parts', 'in_repair', 'cancelled'],
  waiting_parts:    ['in_repair', 'cancelled'],
  in_repair:        ['waiting_parts', 'ready', 'cancelled'],
  ready:            ['in_repair', 'delivered', 'cancelled'],
  delivered:        [],
  cancelled:        [],
}

// ─── KPI Cards ────────────────────────────────────────────────────────────────
const kpiCards = computed(() => [
  {
    label: 'إجمالي الأوامر',
    val: filters.total.value,
    icon: 'fa-clipboard-list',
    color: 'text-blue-600',
    bg: 'bg-blue-50',
  },
  {
    label: 'قيد الإصلاح',
    val: statusCounts.value.in_repair,
    icon: 'fa-tools',
    color: 'text-amber-600',
    bg: 'bg-amber-50',
  },
  {
    label: 'جاهز للتسليم',
    val: statusCounts.value.ready,
    icon: 'fa-check-circle',
    color: 'text-emerald-600',
    bg: 'bg-emerald-50',
  },
  {
    label: 'تم التسليم',
    val: statusCounts.value.delivered,
    icon: 'fa-handshake',
    color: 'text-indigo-600',
    bg: 'bg-indigo-50',
  },
])

// ─── Pagination ───────────────────────────────────────────────────────────────
const totalPages = computed(() => Math.max(1, Math.ceil(filters.total.value / filters.perPage.value)))

// ─── Active Filter Chips ──────────────────────────────────────────────────────
const activeChips = computed(() => [
  { show: !!filters.searchQuery.value,  label: filters.searchQuery.value,          clear: () => { filters.searchQuery.value = '' } },
  { show: !!filters.dateFrom.value,     label: `من: ${filters.dateFrom.value}`,    clear: () => { filters.dateFrom.value = '' } },
  { show: !!filters.dateTo.value,       label: `إلى: ${filters.dateTo.value}`,    clear: () => { filters.dateTo.value = '' } },
  { show: !!filters.statusFilter.value, label: statusLabel(filters.statusFilter.value), clear: () => { filters.statusFilter.value = '' } },
  {
    show: canSelectBranch.value && selectedBranchId.value !== null,
    label: `الفرع: ${branches.value.find(b => String(b.id) === String(selectedBranchId.value))?.name || selectedBranchId.value}`,
    clear: () => onBranchChange(null),
  },
])

// ─── Computed Products ────────────────────────────────────────────────────────
const stockProducts   = computed(() => products.value.filter(p => p.product_type === 'stock'))
const serviceProducts = computed(() => products.value.filter(p => p.product_type === 'service'))
const partsTotal      = computed(() =>
  editForm.parts.reduce((sum, part) => sum + (Number(part.quantity) || 0) * (Number(part.sale_price) || 0), 0)
)

// ─── Order Detail Info Facts ──────────────────────────────────────────────────
const orderInfoFacts = computed(() => {
  if (!selected.value) return []
  return [
    { l: 'العميل',             v: selected.value.customer_name || '—' },
    { l: 'الهاتف',             v: selected.value.customer_phone || '—' },
    { l: 'الجهاز',             v: [selected.value.brand, selected.value.model, selected.value.device_type].filter(Boolean).join(' · ') || '—' },
    { l: 'تاريخ الاستلام',    v: formatDateTime(selected.value.received_at) },
    { l: 'الحالة',             v: statusLabel(selected.value.status) },
    { l: 'موعد الإنجاز',      v: selected.value.expected_completion_date || '—' },
  ]
})

// ─── Forms ────────────────────────────────────────────────────────────────────
const emptyCreate = () => ({
  branch_id: branchIsolation.isExempt.value
    ? (branchStore.selectedBranchId ? String(branchStore.selectedBranchId) : '')
    : (authStore.user?.branch_id ? String(authStore.user.branch_id) : ''),
  customer_id: '', device_type: '', brand: '', model: '', serial_number: '',
  accessories: '', issue_description: '', condition_received: '',
  estimated_cost: 0, expected_completion_date: '',
})

const createForm = reactive(emptyCreate())
const editForm   = reactive({
  status: '', diagnosis: '', resolution: '',
  labor_product_id: '', labor_amount: 0,
  expected_completion_date: '', parts: [],
})

const selectedCustomer = computed(() =>
  customers.value.find(customer => String(customer.id) === String(createForm.customer_id)) || null
)

const filteredCustomers = computed(() => {
  const query = customerQuery.value.trim().toLowerCase()
  if (!query) return customers.value.slice(0, 20)
  return customers.value
    .filter(customer => {
      const name = String(customer.name || '').toLowerCase()
      const phone = String(customer.phone || '').toLowerCase()
      return name.includes(query) || phone.includes(query)
    })
    .slice(0, 20)
})

function selectCustomer(customer) {
  createForm.customer_id = String(customer.id)
  customerQuery.value = customer.name || ''
  showCustomerDropdown.value = false
}

function clearCustomerSelection() {
  createForm.customer_id = ''
  customerQuery.value = ''
  showCustomerDropdown.value = false
}

function closeCustomerDropdownOnOutsideClick(event) {
  if (!customerSearchContainer.value?.contains(event.target)) {
    showCustomerDropdown.value = false
  }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function formatDateTime(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ar-EG', {
    dateStyle: 'medium', timeStyle: 'short',
  }).format(new Date(value))
}

function statusLabel(status) {
  return statuses.find(s => s.value === status)?.label || status || '—'
}

function getStatusBadgeClass(status) {
  if (status === 'ready' || status === 'delivered')
    return 'bg-emerald-50 text-emerald-600 border-emerald-100'
  if (status === 'cancelled')
    return 'bg-rose-50 text-rose-600 border-rose-100'
  if (status === 'waiting_parts' || status === 'waiting_approval')
    return 'bg-amber-50 text-amber-600 border-amber-100'
  return 'bg-blue-50 text-blue-600 border-blue-100'
}

function nextStatuses(status) {
  return transitions[status] || []
}

function unitsFor(productId) {
  const product = products.value.find(p => String(p.id) === String(productId))
  return product?.unit_id
    ? [{ unit_id: product.unit_id, unit_name: product.unit_name, is_main_unit: 1 }]
    : []
}

function setPartDefaults(part) {
  const product = products.value.find(p => String(p.id) === String(part.product_id))
  if (!product) return
  part.unit_id   = String(product.unit_id || '')
  part.sale_price = Number(product.sale_price) || 0
}

function addPart() {
  editForm.parts.push({ product_id: '', unit_id: '', quantity: 1, sale_price: 0 })
}

function toggleSort(key) {
  if (filters.sortKey.value === key) {
    filters.sortAsc.value = !filters.sortAsc.value
  } else {
    filters.sortKey.value = key
    filters.sortAsc.value = true
  }
  loadOrders()
}

// ─── API: Load Orders ─────────────────────────────────────────────────────────
async function loadOrders() {
  loading.value = true
  error.value = ''
  try {
    const params = {
      page:  filters.page.value,
      limit: filters.perPage.value,
    }

    // Branch isolation
    if (!canSelectBranch.value) {
      try {
        params.branch_id = branchIsolation.getRequiredBranchId()
      } catch (e) {
        orders.value = []
        filters.total.value = 0
        showToast(e.message || 'لم يتم تعيين الفرع.', 'error')
        return
      }
    } else if (branchStore.selectedBranchId) {
      params.branch_id = branchStore.selectedBranchId
    }

    if (filters.statusFilter.value)       params.status     = filters.statusFilter.value
    if (filters.searchQuery.value.trim()) params.q          = filters.searchQuery.value.trim()
    if (filters.dateFrom.value)           params.from_date  = filters.dateFrom.value
    if (filters.dateTo.value)             params.to_date    = filters.dateTo.value

    // ── Main paginated request ────────────────────────────────────────
    const result = await RepairOrdersService.list(params)
    orders.value        = result.items  ?? []
    filters.total.value = result.total  ?? orders.value.length

    // ── KPI counts: جلب بدون فلاتر الحالة والصفحة لتعكس الواقع الكامل
    const kpiParams = { ...params }
    delete kpiParams.status
    delete kpiParams.page
    delete kpiParams.limit
    kpiParams.limit = 1  // نريد فقط الـ total لكل حالة بأقل تحميل ممكن

    const [r1, r2, r3] = await Promise.allSettled([
      RepairOrdersService.list({ ...kpiParams, status: 'in_repair' }),
      RepairOrdersService.list({ ...kpiParams, status: 'ready' }),
      RepairOrdersService.list({ ...kpiParams, status: 'delivered' }),
    ])
    statusCounts.value = {
      in_repair: r1.status === 'fulfilled' ? (r1.value.total ?? 0) : 0,
      ready:     r2.status === 'fulfilled' ? (r2.value.total ?? 0) : 0,
      delivered: r3.status === 'fulfilled' ? (r3.value.total ?? 0) : 0,
    }
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'تعذر تحميل أوامر الصيانة.'
  } finally {
    loading.value = false
  }
}

// ─── API: Open Order Details ──────────────────────────────────────────────────
async function openOrder(id) {
  loadingDetails.value = true
  dialogError.value = ''
  try {
    selected.value = await RepairOrdersService.get(id)
    Object.assign(editForm, {
      status:                   selected.value.status,
      diagnosis:                selected.value.diagnosis || '',
      resolution:               selected.value.resolution || '',
      labor_product_id:         selected.value.labor_product_id ? String(selected.value.labor_product_id) : '',
      labor_amount:             Number(selected.value.labor_amount) || 0,
      expected_completion_date: selected.value.expected_completion_date || '',
      parts: (selected.value.parts || []).map(p => ({
        product_id: String(p.product_id),
        unit_id:    String(p.unit_id),
        quantity:   Number(p.quantity),
        sale_price: Number(p.sale_price),
      })),
    })
  } catch (err) {
    error.value = err.response?.data?.message || err.message
  } finally {
    loadingDetails.value = false
  }
}

// ─── API: Create Order ────────────────────────────────────────────────────────
async function createOrder() {
  // Guard against double-click
  if (saving.value) return

  // Clear previous errors
  dialogError.value = ''

  // Validate required fields (show error clearly in footer)
  if (!createForm.branch_id) {
    dialogError.value = 'الفرع مطلوب.'
    return
  }
  if (!createForm.customer_id) {
    dialogError.value = 'العميل مطلوب.'
    return
  }
  if (!createForm.device_type?.trim()) {
    dialogError.value = 'نوع الجهاز مطلوب.'
    return
  }
  if (!createForm.issue_description?.trim()) {
    dialogError.value = 'وصف العطل مطلوب.'
    return
  }

  saving.value = true
  try {
    const payload = {
      branch_id:         Number(createForm.branch_id),
      customer_id:       Number(createForm.customer_id),
      device_type:       createForm.device_type.trim(),
      issue_description:  createForm.issue_description.trim(),
      estimated_cost:    Number(createForm.estimated_cost) || 0,
    }

    // Add optional fields only if they have values
    if (createForm.brand?.trim())              payload.brand = createForm.brand.trim()
    if (createForm.model?.trim())              payload.model = createForm.model.trim()
    if (createForm.serial_number?.trim())     payload.serial_number = createForm.serial_number.trim()
    if (createForm.accessories?.trim())       payload.accessories = createForm.accessories.trim()
    if (createForm.condition_received?.trim()) payload.condition_received = createForm.condition_received.trim()
    if (createForm.expected_completion_date)  payload.expected_completion_date = createForm.expected_completion_date

    const created = await RepairOrdersService.create(payload)

    // Close modal and reset form
    showCreate.value = false
    Object.assign(createForm, emptyCreate())
    clearCustomerSelection()

    // Reload list
    await loadOrders()

    // Open the new order's details (non-blocking — don't fail the whole flow if this errors)
    try {
      await openOrder(created.id)
    } catch (openErr) {
      console.warn('Failed to open order details after creation:', openErr)
    }

    showToast('تم استلام الجهاز بنجاح', 'success')
  } catch (err) {
    dialogError.value = err.response?.data?.message || err.message || 'تعذر تسجيل استلام الجهاز.'
  } finally {
    saving.value = false
  }
}

// ─── API: Save Order Updates ──────────────────────────────────────────────────
async function saveOrder() {
  if (!selected.value) return
  saving.value = true
  dialogError.value = ''
  try {
    const payload = {
      status:                   editForm.status,
      diagnosis:                editForm.diagnosis,
      resolution:               editForm.resolution,
      expected_completion_date: editForm.expected_completion_date,
    }
    if (!selected.value.invoice_id) {
      payload.labor_product_id = editForm.labor_product_id || null
      payload.labor_amount     = Number(editForm.labor_amount) || 0
      payload.parts = editForm.parts.filter(p => p.product_id).map(p => ({
        product_id: Number(p.product_id),
        unit_id:    Number(p.unit_id),
        quantity:   Number(p.quantity),
        sale_price: Number(p.sale_price),
      }))
    }
    await RepairOrdersService.update(selected.value.id, payload)
    await openOrder(selected.value.id)
    await loadOrders()
    showToast('تم حفظ التحديثات بنجاح', 'success')
  } catch (err) {
    dialogError.value = err.response?.data?.message || err.message
  } finally {
    saving.value = false
  }
}

// ─── API: Issue Invoice ───────────────────────────────────────────────────────
async function issueInvoice() {
  if (!selected.value) return
  const confirmed = await AlertService.confirm(
    'سيتم إنشاء فاتورة بيع وخصم قطع الغيار من مخزون هذا الفرع. هل تريد المتابعة؟',
    'تأكيد إصدار الفاتورة',
    { confirmText: 'إصدار الفاتورة', cancelText: 'إلغاء' }
  )
  if (!confirmed) return
  saving.value = true
  dialogError.value = ''
  try {
    await RepairOrdersService.createInvoice(selected.value.id)
    await openOrder(selected.value.id)
    await loadOrders()
    showToast('تم إصدار الفاتورة بنجاح', 'success')
  } catch (err) {
    dialogError.value = err.response?.data?.message || err.message
  } finally {
    saving.value = false
  }
}

// ─── Watchers ─────────────────────────────────────────────────────────────────
let isMounting = true

watch(
  [() => filters.dateFrom.value, () => filters.dateTo.value, () => filters.statusFilter.value, () => filters.perPage.value],
  () => {
    if (isMounting) return
    filters.page.value = 1
    loadOrders()
  }
)

watch(() => filters.page.value, () => {
  if (isMounting) return
  loadOrders()
})

watch(() => filters.searchQuery.value, () => {
  if (isMounting) return
  if (searchTimer) clearTimeout(searchTimer)
  filters.page.value = 1
  searchTimer = setTimeout(() => loadOrders(), 400)
})

// ─── Mount ────────────────────────────────────────────────────────────────────
onMounted(async () => {
  document.addEventListener('pointerdown', closeCustomerDropdownOnOutsideClick)
  try {
    await branchStore.fetchBranches()
    const branchId = branchStore.selectedBranchId

    const [customerResult, productResult] = await Promise.all([
      customerStore.fetchCustomers({ force: true }),
      productStore.fetchProducts({ branchId: branchId || null, force: true }),
    ])

    customers.value = Array.isArray(customerResult.data) ? customerResult.data : []
    products.value  = Array.isArray(productResult.data) ? productResult.data : []

    if (!createForm.branch_id && branchId) createForm.branch_id = String(branchId)
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'تعذر تحميل البيانات.'
  }

  isMounting = false
  await loadOrders()
})

onUnmounted(() => {
  document.removeEventListener('pointerdown', closeCustomerDropdownOnOutsideClick)
  if (searchTimer) clearTimeout(searchTimer)
})
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
