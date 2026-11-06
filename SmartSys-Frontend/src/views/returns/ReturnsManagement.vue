<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-rose-100" dir="rtl">
    
    <PageLoadingBar :active="isLoading || isLoadingDetails" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة المرتجعات"
        description="سجّل عمليات الإرجاع وراجعها، وتابع تحديث أرصدة العملاء والحسابات المالية المرتبطة بها."
        :branches="branches"
        :selectedBranch="branchStore.selectedBranchId"
        branch-selection-mode="optional"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <button v-if="canCreateReturn" @click="openCreateForm"
            class="h-9 px-6 rounded-md bg-rose-600 text-white text-xs font-bold shadow-lg shadow-rose-900/20 hover:bg-rose-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-plus text-[10px]"></i>
            تسجيل مرتجع جديد
          </button>
          <button
            type="button"
            :disabled="isLoading"
            :aria-busy="isLoading"
            class="h-9 w-9 rounded-md border border-slate-200 bg-white p-0 text-slate-500 shadow-sm transition-all hover:border-blue-200 hover:bg-blue-50 hover:text-blue-600 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60"
            aria-label="تحديث المرتجعات"
            title="تحديث المرتجعات"
            @click="manualRefresh"
          >
            <i class="fas fa-sync-alt text-xs transition-transform duration-500" :class="{ 'animate-spin': isLoading }" aria-hidden="true"></i>
          </button>
        </template>
      </PageHeader>

      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي العمليات', val: stats.count, icon: 'fa-list-ol', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'إجمالي قيمة المرتجعات شامل الضريبة', val: formatPrice(stats.total), icon: 'fa-money-bill-wave', color: 'text-rose-600', bg: 'bg-rose-50' },
          { label: 'إجمالي ضريبة المرتجعات', val: formatPrice(stats.tax), icon: 'fa-percent', color: 'text-indigo-600', bg: 'bg-indigo-50' },
          { label: 'قيمة مرتجعات اليوم شامل الضريبة', val: formatPrice(stats.todayTotal), icon: 'fa-chart-line', color: 'text-emerald-600', bg: 'bg-emerald-50' }
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

      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        
        <div class="p-6 bg-slate-50/30 border-b border-slate-100 space-y-6">
          <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
            
            <div class="lg:col-span-5 space-y-1.5 relative group">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">بحث سريع</label>
              <div class="relative">
                <input ref="searchInputRef" v-model="search" type="text" class="filter-input-v2 h-10 text-[11px]" style="padding-right: 2.25rem;" placeholder="رقم المرتجع أو الفاتورة..." @focus="showSearchDropdown = true" @blur="handleSearchBlur" />
                <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs pointer-events-none"></i>
                
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
                        <div v-if="searchResults.length" class="px-4 py-2 bg-slate-50 border-b border-slate-100">
                          <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ searchResults.length }} نتيجة</p>
                        </div>
                        <div v-if="!searchResults.length" class="px-4 py-8 text-center text-slate-400">
                          <i class="fas fa-search text-xl mb-2 opacity-30"></i>
                          <p class="text-[11px] font-bold">لا توجد نتائج مطابقة</p>
                        </div>
                        <div v-for="result in searchResults.slice(0, 20)" :key="result.id" @click="selectSearchResult(result)" class="px-4 py-3 hover:bg-slate-50 cursor-pointer border-b border-slate-50 flex items-center justify-between group">
                          <div>
                            <p class="text-xs font-bold text-slate-900">{{ result.returnNumber || 'RET-' + result.id }}</p>
                            <p class="text-[10px] text-slate-400">{{ formatDate(result.returnDate || result.createdAt) }}</p>
                          </div>
                          <p class="text-xs font-bold text-rose-600">{{ formatPrice(result.grandTotal || result.totalAmount || 0) }}</p>
                        </div>
                        <!-- رسالة "المزيد" إذا تجاوزت 20 -->
                        <div v-if="searchResults.length > 20" class="px-4 py-2 text-center bg-slate-50 border-t border-slate-100">
                          <p class="text-[10px] text-slate-500">و {{ searchResults.length - 20 }} نتيجة أخرى — حدّد البحث للتضييق</p>
                        </div>
                      </template>
                    </div>
                  </transition>
                </Teleport>
              </div>
            </div>

            <div class="lg:col-span-3 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">نوع المرتجع</label>
              <select v-model="activeType" class="filter-input-v2 h-10 appearance-none font-bold">
                <option v-for="type in returnTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
              </select>
            </div>

            <div class="lg:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">من تاريخ</label>
              <div class="relative">
                <input ref="dateFromRef" type="date" v-model="dateFrom" class="filter-input-v2 h-10" style="padding-left: 2rem;" />
                <i @click="dateFromRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>
            </div>

            <div class="lg:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">إلى تاريخ</label>
              <div class="relative">
                <input ref="dateToRef" type="date" v-model="dateTo" class="filter-input-v2 h-10" style="padding-left: 2rem;" />
                <i @click="dateToRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>
            </div>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">رقم المرتجع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفاتورة الأصلية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الوقت والتاريخ</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">عدد الأصناف</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">إجمالي المرتجع</th>
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
              <tr v-else-if="!returns.length">
                <td colspan="7" class="py-20 text-center text-slate-300">
                   <i class="fas fa-box-open text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد فواتير مرتجعات</p>
                </td>
              </tr>
              <tr v-for="r in returns" :key="r.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-6 py-4 text-xs font-bold text-slate-900 font-mono tracking-wider">{{ r.returnNumber || 'RET-' + r.id.toString().padStart(4, '0') }}</td>
                <td class="px-4 py-4 text-[10px] font-mono font-bold text-slate-400">#{{ r.invoiceNumber || r.id }}</td>
                <td class="px-4 py-4 text-[10px] font-mono text-slate-400 whitespace-nowrap">{{ formatReturnDateTime(r.returnDate, r.createdAt) }}</td>
                <td class="px-4 py-4 text-center">
                  <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">{{ Number(r.itemsCount || 0) }}</span>
                </td>
                <td class="px-4 py-4 text-xs font-bold text-rose-600">{{ formatPrice(r.grandTotal || r.totalAmount || 0) }}</td>
                <td class="px-4 py-4 text-center">
                  <span
                    class="px-2 py-0.5 rounded text-[9px] font-bold border"
                    :class="returnStatusClass(r.status)"
                  >{{ returnStatusLabel(r.status, r.statusLabel) }}</span>
                </td>
                <td class="px-6 py-4 text-center">
                  <button @click="viewReturnDetails(r)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-all flex items-center justify-center mx-auto shadow-sm">
                    <i class="fas fa-eye text-[10px]"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ currentPage }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ totalCount }}</span> سجل
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
               <select v-model.number="pageSize" @change="currentPage = 1; loadReturns();" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
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
      :show="showDetailsModal && !!selectedReturn"
      @close="showDetailsModal = false"
      :title="`تفاصيل المرتجع ${selectedReturn?.returnNumber || 'RET-' + selectedReturn?.id}`"
      icon="fas fa-undo"
    >

      <div class="space-y-6">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          <div v-for="info in returnDetailFacts" :key="info.l" class="min-w-0 space-y-1 rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-2">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wide">{{ info.l }}</p>
            <p class="break-words text-[11px] font-bold leading-4 text-slate-800">{{ info.v }}</p>
          </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-100 shadow-sm">
          <table class="w-full text-right text-xs">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-100">
                <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">الصنف</th>
                <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase text-center">الكمية</th>
                <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase text-center">سعر الوحدة قبل الضريبة</th>
                <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">صافي الصنف قبل الضريبة</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="item in selectedReturn?.items" :key="item.id" class="hover:bg-blue-50/20 transition-all">
                <td class="px-4 py-3">
                  <p class="font-bold text-slate-800">{{ item.productName }}</p>
                  <p v-if="item.productCode" class="text-[9px] text-slate-400 font-mono">{{ item.productCode }}</p>
                </td>
                <td class="px-4 py-3 text-center font-bold text-slate-500">{{ item.quantity }}</td>
                <td class="px-4 py-3 text-center text-slate-700 font-mono font-bold">{{ formatPrice(item.unitPrice) }}</td>
                <td class="px-4 py-3 font-bold font-mono text-slate-900">{{ formatPrice(item.subtotal ?? item.quantity * item.unitPrice) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="-mt-4 text-[10px] leading-relaxed text-slate-500">
          مبالغ الأصناف أعلاه قبل الضريبة. إجمالي المرتجع هو المبلغ النهائي شاملًا الضريبة.
        </p>

        <div class="flex flex-col md:flex-row gap-6">
          <div v-if="selectedReturn?.notes" class="flex-1 p-4 bg-amber-50 border border-amber-100 rounded-xl">
            <p class="text-[10px] font-bold text-amber-700 uppercase tracking-widest mb-2">سبب الإرجاع</p>
            <p class="text-xs text-amber-800 whitespace-pre-wrap">{{ selectedReturn.notes }}</p>
          </div>
          <div class="w-full md:w-72 space-y-2.5 bg-slate-50 border border-slate-200 p-5 rounded-xl">
            <div class="flex justify-between text-[11px] font-medium text-slate-500">
              <span>إجمالي المرتجع (شامل الضريبة)</span>
              <span class="font-mono font-bold text-slate-700">{{ formatPrice(selectedReturn?.grandTotal || selectedReturn?.totalAmount || 0) }}</span>
            </div>
            <div v-if="Number(selectedReturn?.taxAmount) > 0" class="flex justify-between text-[11px] font-medium text-slate-500">
              <span>الضريبة (ضمن إجمالي المرتجع)</span>
              <span class="font-mono font-bold text-indigo-600">{{ formatPrice(selectedReturn?.taxAmount || 0) }}</span>
            </div>
            <div v-if="Number(selectedReturn?.discountValue) > 0 || Number(selectedReturn?.discountAmount) > 0" class="flex justify-between text-[11px] font-medium text-slate-500 pb-2.5 border-b border-slate-200">
              <span>الخصم</span>
              <span class="font-mono font-bold text-rose-500">- {{ formatPrice(selectedReturn?.discountValue || selectedReturn?.discountAmount || 0) }}</span>
            </div>
            <div class="flex justify-between items-center bg-indigo-600 text-white px-4 py-3 rounded-lg">
              <span class="text-[11px] font-bold uppercase tracking-widest opacity-80">صافي التسوية</span>
              <span class="text-xl font-bold font-mono tracking-tighter">{{ formatPrice(returnNetSettlementAmount) }}</span>
            </div>
            <div class="pt-1 space-y-2">
              <div v-if="returnOffsetAmount > 0" class="flex justify-between text-[11px] font-medium text-slate-500">
                <span>المبلغ المُقاصّ</span>
                <span class="font-mono font-bold text-slate-700">{{ formatPrice(returnOffsetAmount) }}</span>
              </div>
              <div v-if="returnCashRefundAmount > 0" class="flex justify-between text-[11px] font-medium text-slate-500">
                <span>{{ Number(selectedReturn?.isCash) === 1 ? 'المبلغ المسترد نقدًا' : 'المبلغ المسترد' }}</span>
                <span class="font-mono font-bold text-emerald-600">{{ formatPrice(returnCashRefundAmount) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="mr-auto flex flex-row flex-wrap items-center gap-3">
          <button @click="printReturnDetails" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md border border-indigo-200 px-4 text-xs font-bold text-indigo-700 hover:bg-indigo-50"><i class="fas fa-print"></i> طباعة المرتجع</button>
          <button @click="showDetailsModal = false" class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap px-4 text-xs font-bold text-slate-500 transition-colors hover:text-slate-800">إغلاق</button>
        </div>
      </template>
    </HistoryDetailsModal>

    <BaseModal
      :show="showCreateForm && canCreateReturn"
      maxWidth="5xl"
      align="start"
      variant="standard"
      @close="closeCreateForm"
    >
      <template #header>
        <div class="flex min-w-0 items-center gap-3 pl-10">
          <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-rose-600 text-xs text-white shadow-sm">
            <i class="fas fa-undo"></i>
          </div>
          <div class="min-w-0">
            <div class="mb-1 flex flex-wrap items-center gap-2">
              <h3 class="truncate text-sm font-bold text-slate-900 sm:text-base">
                تسجيل مرتجع {{ createReturnType === 'sales' ? 'مبيعات' : 'مشتريات' }}
              </h3>
              <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[9px] font-bold text-rose-600">
                {{ createReturnType === 'sales' ? 'مبيعات' : 'مشتريات' }}
              </span>
            </div>
            <p class="mt-1 text-[10px] font-medium text-slate-500">ابحث عن الفاتورة، راجع الأصناف والتسوية، ثم أكد التسجيل.</p>
          </div>
        </div>
      </template>
      <div class="mb-5 rounded-xl border border-slate-200 bg-slate-50/70 p-3.5">
        <div class="mb-2.5 flex items-center justify-between gap-3">
          <div>
            <p class="text-xs font-bold text-slate-800">نوع المرتجع</p>
            <p class="mt-1 text-[10px] text-slate-500">حدد نوع العملية قبل اختيار الفاتورة.</p>
          </div>
          <span class="hidden rounded-full bg-white px-2.5 py-1 text-[10px] font-bold text-slate-500 shadow-sm sm:inline-flex">
            الخطوة 1 من 2
          </span>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <button
            v-for="returnType in returnTypes"
            :key="returnType.id"
            type="button"
            :class="createReturnType === returnType.id
              ? 'border-rose-500 bg-white text-rose-700 ring-2 ring-rose-100'
              : 'border-slate-200 bg-white text-slate-600 hover:border-rose-200 hover:bg-rose-50/40'"
            class="flex items-center gap-3 rounded-xl border px-4 py-3 text-right transition-all active:scale-[.99]"
            @click="selectCreateReturnType(returnType.id)"
          >
            <span
              :class="createReturnType === returnType.id ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-400'"
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-xs transition-colors"
            >
              <i :class="returnType.icon"></i>
            </span>
            <span class="min-w-0 flex-1">
              <span class="block text-xs font-bold">{{ returnType.name }}</span>
              <span class="mt-1 block text-[10px] text-slate-400">
                {{ returnType.id === 'sales' ? 'إرجاع أصناف من فاتورة بيع' : 'إرجاع أصناف إلى المورد' }}
              </span>
            </span>
            <i v-if="createReturnType === returnType.id" class="fas fa-check-circle text-rose-600"></i>
          </button>
        </div>
      </div>
      <ReturnForm
        v-if="showCreateForm && canCreateReturn"
        ref="returnFormRef"
        :key="createReturnFormKey"
        :type="createReturnType"
        :submit-in-footer="true"
        :can-refund-excess="canRefundExcess"
        :branch-id="branchId"
        @returnSuccess="handleReturnSuccess"
      />
      <template #footer>
        <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p class="flex items-start gap-2 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-[10px] leading-5 text-amber-800">
            <i class="fas fa-info-circle mt-0.5 shrink-0 text-amber-600"></i>
            <span>راجع الأصناف والمبالغ قبل التأكيد. لا يُسجّل المرتجع إلا بعد اعتمادك.</span>
          </p>
          <div class="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row sm:items-center">
            <button @click="closeCreateForm" class="h-10 w-full rounded-lg border border-slate-200 bg-white px-5 text-xs font-bold text-slate-600 transition-all hover:border-slate-300 hover:bg-slate-50 active:scale-95 sm:w-auto">
              إلغاء
            </button>
            <LoadingButton
              text="تأكيد وتسجيل المرتجع"
              :isLoading="returnFormRef?.isSubmittingReturn || false"
              loadingText="جاري التسجيل..."
              icon="fas fa-check-circle"
              variant="primary"
              loaderType="dots"
              :disabled="!returnFormRef?.canSubmitReturn"
              @click="returnFormRef?.submitReturn()"
              class="h-10 w-full !rounded-lg !bg-rose-600 shadow-md shadow-rose-900/15 hover:!bg-rose-700 active:scale-[.98] sm:w-auto sm:min-w-44"
            />
          </div>
        </div>
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import { useRoute, useRouter } from 'vue-router';
import ReturnForm from '@/components/ReturnForm.vue';
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue';
import { useToast } from '@/composables/useToast';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { useReturnStore } from '@/stores/return/returnStore';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import { printDocument } from '@/utils';
import { buildReturnHtml } from '@/utils/printTemplates';
import { resolvePrintOptions } from '@/utils/printTemplates/branchPrintSettings';
import {
  formatDateUsingNativeLocale,
  formatDateTimeUsingNativeLocale,
  formatReturnDateTime
} from '@/utils/formatters/date';
import { useTableFilters } from '@/composables/useTableFilters';
import { useSearchDropdown } from '@/composables/useSearchDropdown';

// Services & Router
const router = useRouter();
const route = useRoute();
const { showToast } = useToast();
const { breadcrumb } = useBreadcrumb();
const authStore = useAuthStore();
const branchStore = useBranchStore();
const customerStore = useCustomerStore();
const supplierStore = useSupplierStore();
const returnStore = useReturnStore();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();

// Branches computed from store
const branches = computed(() => branchStore.branches);
const canCreateReturn = computed(() =>
  authStore.isSuperAdmin || authStore.user?.permissions?.includes('return.create') === true
);
const canRefundExcess = computed(() =>
  authStore.isSuperAdmin || authStore.user?.permissions?.includes('return.approve') === true
);

const onBranchChange = (newBranchId) => {
  branchStore.setSelectedBranch(newBranchId);
  currentPage.value = 1;
  loadReturns();
};

const manualRefresh = () => loadReturns(true);

// Initialize Composables
const {
  dateFrom,
  dateTo,
  perPage: pageSize,
  page: currentPage,
  branchId,
  isExempt: exemptFromBranch,
  dateFromRef,
  dateToRef,
  handleBranchChange,
  nextPage,
  previousPage,
  goToPage,
  loadFromLocalStorage: loadFiltersFromLocalStorage,
  saveToLocalStorage: saveFiltersToLocalStorage,
  totalCount,
  totalPages,
} = useTableFilters('returns_mgmt_filters', {
  initialPageSize: 20,
  onFilterChange: () => loadReturns(),
});

const {
  search,
  searchInputRef,
  showSearchDropdown,
  searchResults,
  isLoadingSearch,
  searchDropdownPosition,
  handleSearchBlur,
  selectSearchResult,
} = useSearchDropdown({
  onSearch: (query) => performSearch(query),
  onSelectResult: (result) => handleSearchResultSelect(result),
  onClear: () => { currentPage.value = 1; loadReturns(); },
});

// Reactive State: General
const activeType = ref('sales');
const returns = ref([]);
const totalAmountFromAPI = ref(0);
const showCreateForm = ref(false);
const returnFormRef = ref(null);
const createReturnType = ref('sales');
const createReturnFormKey = ref(0);
const showDetailsModal = ref(false);
const selectedReturn = ref(null);
const isLoading = ref(false);
const isLoadingDetails = ref(false);

// Reactive State
const returnTypes = [
  { id: 'sales', name: 'مرتجعات المبيعات', icon: 'fas fa-shopping-cart' },
  { id: 'purchases', name: 'مرتجعات المشتريات', icon: 'fas fa-truck' }
];

const openCreateForm = () => {
  createReturnType.value = activeType.value;
  createReturnFormKey.value += 1;
  showCreateForm.value = true;
};

const selectCreateReturnType = (type) => {
  if (createReturnType.value === type) return;
  createReturnType.value = type;
  returnFormRef.value = null;
  createReturnFormKey.value += 1;
};

const isPurchaseReturn = computed(() => {
  const type = String(selectedReturn.value?.returnType || selectedReturn.value?.type || '').toLowerCase();
  return type === 'purchase' || type === 'purchase_return' || selectedReturn.value?.returnTypeLabel?.includes('مشتريات');
});
const returnCashRefundAmount = computed(() => Math.max(
  0,
  Number(selectedReturn.value?.cashRefundAmount ?? selectedReturn.value?.paidAmount ?? 0)
));
const returnOffsetAmount = computed(() => Math.max(0, Number(selectedReturn.value?.offsetAmount ?? 0)));
const returnNetSettlementAmount = computed(() => Number(
  selectedReturn.value?.netSettlementAmount ?? (returnCashRefundAmount.value + returnOffsetAmount.value)
));
const returnRefundChannel = computed(() => {
  if (Number(selectedReturn.value?.isCash) === 1) return 'نقدًا';
  const method = String(selectedReturn.value?.refundMethod || '').toLowerCase();
  const labels = {
    cash: 'نقدًا',
    bank: 'تحويل بنكي',
    bankTransfer: 'تحويل بنكي',
    card: 'بطاقة ائتمان',
    wallet: 'محفظة إلكترونية',
    credit: 'رصيد آجل',
    other: 'طريقة دفع أخرى'
  };
  const label = labels[method];
  return label ? `عبر ${label}` : 'عبر طريقة الدفع';
});
const returnSettlementLabel = computed(() => {
  const hasCashRefund = returnCashRefundAmount.value > 0.005;
  const hasOffset = returnOffsetAmount.value > 0.005;
  const settlementTotal = Number(selectedReturn.value?.grandTotal ?? selectedReturn.value?.totalAmount ?? 0);
  const isFullySettled = settlementTotal > 0
    && Math.abs(returnCashRefundAmount.value + returnOffsetAmount.value - settlementTotal) <= 0.01;
  const cashChannel = returnRefundChannel.value;

  if (hasOffset && hasCashRefund) {
    return isFullySettled
      ? (isPurchaseReturn.value
        ? `مقاصة مع حساب المورد ثم استرداد المتبقي ${cashChannel}`
        : `مقاصة مع رصيد العميل ثم رد المتبقي ${cashChannel}`)
      : (isPurchaseReturn.value
        ? `مقاصة مع حساب المورد واسترداد جزئي ${cashChannel}`
        : `مقاصة مع رصيد العميل ورد جزئي ${cashChannel}`);
  }
  if (hasCashRefund) {
    const extent = isFullySettled ? 'كامل' : 'جزئي';
    if (returnRefundChannel.value === 'نقدًا') {
      return isPurchaseReturn.value ? `استرداد نقدي ${extent}` : `رد نقدي ${extent}`;
    }
    return isPurchaseReturn.value ? `استرداد ${extent} ${cashChannel}` : `رد ${extent} ${cashChannel}`;
  }
  if (hasOffset) {
    if (isPurchaseReturn.value) {
      return isFullySettled ? 'تسوية كاملة على حساب المورد' : 'تسوية جزئية على حساب المورد';
    }
    return isFullySettled ? 'إضافة كامل المبلغ إلى رصيد العميل' : 'إضافة جزئية إلى رصيد العميل';
  }
  return 'لا توجد تسوية مالية مسجلة';
});
const returnDetailFacts = computed(() => {
  const purchaseReturn = isPurchaseReturn.value;
  const partyName = [
    selectedReturn.value?.partyName,
    purchaseReturn ? selectedReturn.value?.supplierName : selectedReturn.value?.customerName,
    purchaseReturn ? selectedReturn.value?.supplier?.name : selectedReturn.value?.customer?.name
  ].find((name) => {
    const normalizedName = String(name ?? '').trim();
    return normalizedName && !['-', '—', 'null', 'undefined'].includes(normalizedName.toLowerCase());
  });
  return [
    { l: purchaseReturn ? 'المورد' : 'العميل', v: partyName || (purchaseReturn ? 'غير محدد' : 'عميل نقدي') },
    { l: 'الفاتورة الأصلية', v: selectedReturn.value?.originalInvoiceNumber || selectedReturn.value?.invoiceNumber || 'غير محدد' },
    { l: 'الوقت والتاريخ', v: formatReturnDateTime(selectedReturn.value?.returnDate, selectedReturn.value?.createdAt) },
    { l: 'الفرع', v: selectedReturn.value?.branchName || branches.value.find(branch => String(branch.id) === String(selectedReturn.value?.branchId))?.name || '-' },
    { l: 'الكاشير', v: selectedReturn.value?.createdByName || 'النظام' },
    { l: 'نوع العملية', v: selectedReturn.value?.returnTypeLabel || (purchaseReturn ? 'مرتجع مشتريات' : 'مرتجع مبيعات') },
    { l: 'طريقة التسوية', v: returnSettlementLabel.value }
  ];
});

// Computed Logic
const stats = computed(() => {
  const isToday = (someDate) => {
    const today = new Date();
    const d = new Date(someDate);
    return d.getDate() === today.getDate() &&
           d.getMonth() === today.getMonth() &&
           d.getFullYear() === today.getFullYear();
  };
  const todayReturns = returns.value.filter(r => isToday(r.createdAt));
  const todayTotal = todayReturns.reduce((sum, r) => sum + parseFloat(r.grandTotal || r.totalAmount || r.total || r.amount || 0), 0);
  const totalVal = totalAmountFromAPI.value || returns.value.reduce((sum, r) => sum + parseFloat(r.grandTotal || r.totalAmount || r.total || r.amount || 0), 0);
  const tax = returns.value.reduce((sum, r) => sum + parseFloat(r.taxAmount || 0), 0);
  return {
    count: totalCount.value || returns.value.length,
    total: totalVal,
    tax,
    today: todayReturns.length,
    todayTotal: todayTotal
  };
});

// Formatting Helpers
const formatPrice = formatCurrencyLocale;
const returnStatusLabel = (status, apiLabel = null) => {
  const labels = {
    approved: 'معتمدة',
    pending: 'قيد الانتظار',
    pendingApproval: 'تحت الاعتماد',
    rejected: 'مرفوضة',
    canceled: 'ملغاة',
    cancelled: 'ملغاة',
  };
  return labels[String(status || '').toLowerCase()] || apiLabel || 'غير معروفة';
};
const returnStatusClass = (status) => {
  const classes = {
    approved: 'bg-emerald-50 text-emerald-700 border-emerald-100',
    pending: 'bg-amber-50 text-amber-700 border-amber-100',
    pendingApproval: 'bg-amber-50 text-amber-700 border-amber-100',
    rejected: 'bg-rose-50 text-rose-700 border-rose-100',
    canceled: 'bg-slate-100 text-slate-600 border-slate-200',
    cancelled: 'bg-slate-100 text-slate-600 border-slate-200',
  };
  return classes[String(status || '').toLowerCase()] || 'bg-slate-100 text-slate-600 border-slate-200';
};
const formatDate = (date) => formatDateUsingNativeLocale(date, 'en-US', { day: '2-digit', month: '2-digit', year: 'numeric' });
const formatDateTime = (date) => formatDateTimeUsingNativeLocale(date, 'en-US', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
// API: Load Returns List
const loadReturns = async (forceRefresh = false) => {
  isLoading.value = true;
  try {
    const response = await returnStore.fetchReturnsList({
      type: activeType.value,
      branchId: branchId.value || undefined,
      page: currentPage.value,
      perPage: pageSize.value,
      search: search.value || undefined,
      dateFrom: dateFrom.value || undefined,
      dateTo: dateTo.value || undefined,
      force: forceRefresh
    });
    if (response?.status === 'success') {
      const resData = response.data;
      const list = Array.isArray(resData) ? resData : (resData?.items || []);
      returns.value = list;
      totalCount.value = resData?.total || list.length;
      totalAmountFromAPI.value = list.reduce((sum, r) => sum + (Number(r.totalAmount) || 0), 0);
    } else {
      throw new Error(response?.message || 'Failed to load returns');
    }
  } catch (error) {
    console.error('Error loading returns:', error);
    showToast(error.message || 'فشل في تحميل المرتجعات', 'error');
    returns.value = [];
    totalCount.value = 0;
  } finally {
    isLoading.value = false;
  }
};

// Handle Return Success
const handleReturnSuccess = () => {
  showCreateForm.value = false;
  returnFormRef.value = null;
  loadReturns(true);
  
  if (typeof productStore !== 'undefined' && productStore.invalidateCache) {
    productStore.invalidateCache();
  }

  window.dispatchEvent(new CustomEvent('pos:return-recorded', {
    detail: {
      returnId: Date.now(),
      saleId: route.query.saleId,
    }
  }));

  // Only navigate away if explicitly coming from another page
  if (!route.query.returnFrom) return;

  const destinations = {
    cashier: { name: 'SalesPoint' },
    'cashier': { name: 'CashierDashboard' },
    'admin-sales': { name: 'SalesHistory' },
    'sale-details': { name: 'SalesHistory', query: { id: route.query.saleId } },
  };

  const dest = destinations[route.query.returnFrom] ?? { name: 'CashierDashboard' };
  router.push(dest).catch(() => {});
};

const closeCreateForm = () => {
  showCreateForm.value = false;
  returnFormRef.value = null;
};

// Search with Dropdown Results
const performSearch = async (query) => {
  if (!query.trim()) {
    searchResults.value = [];
    return;
  }
  
  isLoadingSearch.value = true;
  try {
    const response = await returnStore.fetchReturnsList({
      type: activeType.value,
      branchId: branchId.value || undefined,
      search: query.trim(),
      perPage: 50 // Get more results for dropdown
    });
    if (response?.status === 'success') {
      const resData = response.data;
      searchResults.value = Array.isArray(resData) ? resData : (resData?.items || []);
    } else {
      searchResults.value = [];
    }
  } catch (error) {
    console.error('Search error:', error);
    searchResults.value = [];
  } finally {
    isLoadingSearch.value = false;
  }
};

const handleSearchResultSelect = (result) => {
  returns.value = [result]; // Show only the selected result
  totalCount.value = 1;
};

watch(activeType, () => { currentPage.value = 1; search.value = ''; searchResults.value = []; loadReturns(); showCreateForm.value = false; });
watch([dateFrom, dateTo, pageSize], () => {
  if (currentPage.value !== 1) {
    currentPage.value = 1;
    return;
  }
  loadReturns();
});

onMounted(async () => {
  await branchStore.initialize?.();
  await fetchSettings();
  loadFiltersFromLocalStorage(); // Load filters from localStorage

  const userBranchId = authStore?.user?.branchId;
  if (!exemptFromBranch.value && !userBranchId) {
    showToast('حسابك غير مرتبط بفرع. يرجى التواصل مع الإدارة لتعيين فرع لحسابك.', 'error');
    return;
  }

  loadReturns();
});

// View Return Details
const viewReturnDetails = async (r) => {
  selectedReturn.value = r;
  showDetailsModal.value = true;
  isLoadingDetails.value = true;
  try {
    const type = activeType.value === 'sales' ? 'sales' : 'purchase';
    const res = await returnStore.fetchReturnDetails(r.id, type);
    if (res?.status === 'success' && res.data) {
      selectedReturn.value = { ...r, ...res.data };
    }
  } catch (error) {
    showToast(error?.response?.data?.message || error?.message || 'فشل في تحميل تفاصيل المرتجع', 'error');
  }
  finally { isLoadingDetails.value = false; }
};

// Print Return Details
const printReturnDetails = async () => {
  if (!selectedReturn.value) return;
  try {
    await printDocument(buildReturnHtml(selectedReturn.value, await resolvePrintOptions(selectedReturn.value, branchStore.selectedBranchId)));
  } catch (error) {
    showToast(error?.response?.data?.message || error.message || 'تعذر تحميل إعدادات الطباعة لهذا الفرع.', 'error');
  }
};
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes modalIn {
  from { opacity: 0; transform: scale(0.98) translateY(10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

/* Form Inputs */
/* Pagination */
/* Scrollbar */
.custom-scroll::-webkit-scrollbar {
  width: 5px;
}

.custom-scroll::-webkit-scrollbar-thumb {
  @apply bg-slate-200 rounded-full;
}

.animate-fadeIn {
  animation: fadeIn 0.4s ease-out;
}

.animate-modalIn {
  animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Transition Classes */
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