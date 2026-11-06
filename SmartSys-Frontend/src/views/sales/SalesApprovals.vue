<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-amber-100" dir="rtl">
    <PageLoadingBar :active="isLoading" />
    
    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <PageHeader
        :breadcrumb="breadcrumb"
        title="اعتماد الفواتير المعلقة"
        description="استعرض ووافق على الفواتير المعلقة في الانتظار"
        :branches="branches"
        :selectedBranch="selectedBranch"
        :hasExplicitSelection="hasExplicitBranchSelection"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <transition name="fade-scale">
            <div v-if="newInvoicesAlert" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-amber-500 text-white text-xs font-bold shadow-lg shadow-amber-900/20 animate-bounce">
              <i class="fas fa-bell"></i>
              طلب جديد بانتظاركم
            </div>
          </transition>

          <div class="flex items-center gap-0 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden divide-x divide-x-reverse divide-slate-100">
            <div class="flex items-center gap-2 px-3 py-2">
              <div :class="isPolling ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'" class="w-1.5 h-1.5 rounded-full shrink-0"></div>
              <div class="leading-none">
                <p class="text-[9px] font-bold uppercase tracking-widest" :class="isPolling ? 'text-emerald-600' : 'text-slate-400'">
                  {{ isPolling ? 'مزامنة مستمرة' : 'متوقفة' }}
                </p>
                <p v-if="lastUpdatedLabel" class="text-[9px] text-slate-400 mt-0.5">{{ lastUpdatedLabel }}</p>
              </div>
            </div>
            <div class="px-3 py-2 text-center leading-none">
              <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">المعلق</p>
              <p class="text-base font-bold text-slate-900 font-mono leading-none">{{ total }}</p>
            </div>
            <button
              type="button"
              :disabled="isLoading"
              :aria-busy="isLoading"
              class="h-full px-3 rounded-none border-0 bg-transparent text-slate-400 transition-all hover:bg-blue-50/50 hover:text-blue-600 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60"
              aria-label="تحديث الموافقات"
              title="تحديث الموافقات"
              @click="manualRefresh"
            >
              <i class="fas fa-sync-alt text-xs transition-transform duration-500" :class="{ 'animate-spin': isLoading }" aria-hidden="true"></i>
            </button>
          </div>
        </template>
      </PageHeader>

      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        
        <!-- Summary Cards (Total/Paid/Balance) -->
        <div v-if="rows.length > 0" class="grid grid-cols-3 gap-4 p-4 bg-white border-b border-slate-100">
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-200 text-center">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">إجمالي المعلق</p>
            <p class="text-base font-bold font-mono text-slate-900">{{ formatPrice(rows.reduce((sum, r) => sum + Number(r.netTotalAmount ?? r.totalAmount ?? 0), 0)) }}</p>
          </div>
          <div class="p-3 rounded-lg bg-blue-50 border border-blue-200 text-center">
            <p class="text-[9px] font-bold text-blue-600 uppercase tracking-widest mb-1">عدد الطلبات</p>
            <p class="text-base font-bold font-mono text-blue-900">{{ rows.length }}</p>
          </div>
          <div class="p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-center">
            <p class="text-[9px] font-bold text-emerald-600 uppercase tracking-widest mb-1">متوسط الفاتورة</p>
            <p class="text-base font-bold font-mono text-emerald-900">{{ formatPrice(rows.length > 0 ? rows.reduce((sum, r) => sum + Number(r.netTotalAmount ?? r.totalAmount ?? 0), 0) / rows.length : 0) }}</p>
          </div>
        </div>

        <div>
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-slate-50/50 border-b border-slate-200">
                  <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest w-20">الرقم</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الوقت والتاريخ</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">العميل</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الأصناف</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المبلغ</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الدفع</th>
                  <th class="px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراء</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium">
                <template v-if="isLoading">
                  <tr v-for="n in 5" :key="n" class="animate-pulse">
                    <td v-for="m in 7" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                  </tr>
                </template>
                
                <tr v-else-if="!rows.length">
                  <td colspan="7" class="py-24 text-center text-slate-300">
                     <i class="fas fa-check-double text-3xl mb-4 opacity-20"></i>
                     <p class="text-xs font-bold uppercase tracking-widest">لا توجد فواتير معلقة</p>
                  </td>
                </tr>

                <tr v-for="s in rows" :key="s.id" class="hover:bg-blue-50/20 transition-all group">
                  <td class="px-6 py-4 text-xs font-bold text-slate-900 font-mono">#{{ s.id }}</td>
                  <td class="px-4 py-4 text-[10px] font-mono text-slate-400">{{ formatDateTime(s.createdAt || s.sale?.createdAt) }}</td>
                  <td class="px-4 py-4 text-xs font-bold text-slate-700">{{ s.customerName || s.sale?.customerName || 'عميل نقدي' }}</td>
                  <td class="px-4 py-4 text-center">
                    <span class="px-2 py-0.5 bg-slate-100 rounded text-[10px] font-bold text-slate-500">
                      {{ s.totalItems ?? s.itemsCount ?? s.sale?.items?.length ?? '-' }}
                    </span>
                  </td>
                  <td class="px-4 py-4 text-xs font-bold text-blue-600 font-mono tracking-tighter">
                    {{ formatPrice(s.netTotalAmount ?? s.totalAmount ?? s.sale?.netTotalAmount) }}
                  </td>
                  <td class="px-4 py-4 text-center">
                    <span :class="[kindClass(s.paymentMethodKind ?? s.sale?.paymentMethodKind ?? s.paymentMethod?.kind)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                      {{ kindLabel(s.paymentMethodKind ?? s.sale?.paymentMethodKind ?? s.paymentMethod?.kind) }}
                    </span>
                  </td>
                  <td class="px-8 py-4 text-center">
                    <div class="flex items-center justify-center gap-1.5">
                      <button @click="viewDetails(s.id)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 transition-all flex items-center justify-center"><i class="fas fa-eye text-[10px]"></i></button>
                      <button @click="openAction('approve', s.id)" class="h-8 px-3 rounded-lg bg-emerald-600 text-white text-[9px] font-bold hover:bg-emerald-700 transition-all shadow-sm">اعتماد</button>
                      <button @click="openAction('reject', s.id)" class="h-8 px-3 rounded-lg border border-rose-200 text-rose-600 text-[9px] font-bold hover:bg-rose-50 transition-all">رفض</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
              صفحة <span class="text-slate-900">{{ page }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
              <span class="mx-2 text-slate-200">|</span>
              إجمالي <span class="text-slate-900">{{ total }}</span> طلب معلق
            </div>
            <div class="flex items-center gap-3">
               <div class="flex items-center gap-2">
                 <span class="text-[10px] font-bold text-slate-400 uppercase">النتائج:</span>
                 <select v-model.number="limit" @change="page = 1; fetchPending({ silent: false })" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                   <option :value="10">10</option>
                   <option :value="20">20</option>
                   <option :value="50">50</option>
                 </select>
               </div>
               <PaginationControls
                 :page="page"
                 :total-pages="totalPages"
                 @previous="page = Math.max(1, page - 1); fetchPending({ silent: false })"
                 @next="page = Math.min(totalPages, page + 1); fetchPending({ silent: false })"
               />
            </div>
          </div>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <transition name="fade">
        <div v-if="actionModal.open" class="fixed inset-0 z-[1000] flex items-start justify-center overflow-y-auto p-4 pt-20 md:pt-24 bg-slate-900/40 backdrop-blur-sm" dir="rtl" @click.self="closeAction">
          <div class="bg-white w-full max-w-lg max-h-[calc(100vh-6rem)] md:max-h-[calc(100vh-7rem)] rounded-2xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn flex flex-col">
          <div class="px-6 md:px-8 py-4 md:py-5 border-b border-slate-100 bg-slate-50/70 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
              <div :class="actionModal.mode === 'approve' ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-rose-100 text-rose-700 border-rose-200'" class="w-9 h-9 rounded-lg border flex items-center justify-center"><i :class="actionModal.mode === 'approve' ? 'fas fa-check-double' : 'fas fa-ban'" class="text-xs"></i></div>
              <div>
                <h3 class="text-sm font-bold text-slate-900">{{ actionModal.mode === 'approve' ? 'مراجعة واعتماد الفاتورة' : 'رفض الفاتورة' }}</h3>
                <p class="mt-1 text-[10px] font-medium text-slate-400">{{ actionModal.mode === 'approve' ? 'راجع بيانات التحصيل قبل تأكيد الاعتماد.' : 'سجّل سبب الرفض ليتمكن الكاشير من مراجعته.' }}</p>
              </div>
            </div>
            <button @click="closeAction" class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors"><i class="fas fa-times text-sm"></i></button>
          </div>

          <div class="p-6 md:p-8 space-y-6 overflow-y-auto">
            <div class="p-4 rounded-xl border border-blue-100 bg-blue-50/70 flex justify-between items-center">
               <div><p class="text-[9px] font-bold text-blue-500 uppercase mb-1">إجمالي الفاتورة شامل الضريبة</p><p class="text-xl font-bold font-mono tracking-tighter text-blue-700">{{ formatPrice(actionModal.invoiceTotal) }}</p></div>
               <div class="text-left"><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">رقم الطلب</p><p class="text-xs font-bold font-mono text-slate-700">#{{ actionModal.id }}</p></div>
            </div>
            <div v-if="actionModal.mode === 'reject'" class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4">
              <i class="fas fa-circle-exclamation mt-0.5 text-rose-500"></i>
              <p class="text-xs leading-relaxed text-rose-700">سيتم إرجاع الفاتورة إلى حالة مرفوضة ولن يتم ترحيلها للبيع. اكتب ملاحظة مختصرة توضح سبب القرار.</p>
            </div>
            <div v-if="actionModal.mode === 'approve'" class="space-y-6 animate-fadeIn">
               <div v-if="approvalPaymentMethodIsCredit" class="p-4 rounded-xl border border-amber-200 bg-amber-50 text-right space-y-2">
                  <p class="text-[10px] font-bold text-amber-600 uppercase tracking-widest">⚠️ فاتورة آجلة</p>
                  <p class="text-xs text-amber-700 leading-relaxed">هذه فاتورة بنظام الآجل (الاقتراض)، لا تتطلب تحصيل نقدي الآن. يمكن تحديث التاريخ المستحق لاحقاً.</p>
               </div>
               <div v-else class="p-4 rounded-xl border border-emerald-200 bg-emerald-50 text-right space-y-2">
                  <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">✓ فاتورة نقدية</p>
                  <p class="text-xs text-emerald-700 leading-relaxed">هذه فاتورة بدفع فوري (نقدي). يجب تحديد طريقة الدفع والمبلغ المستلم فعلياً.</p>
               </div>

               <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-4">
                 <p class="mb-3 text-[10px] font-bold uppercase tracking-widest text-slate-400">بيانات التحصيل</p>
                 <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                 <div class="space-y-1.5">
                   <label class="metadata-label">
                     طريقة الدفع
                     <span v-if="actionModal.paidAmount > 0" class="text-red-400">*</span>
                   </label>
                   <select v-model="actionModal.paymentMethodId" class="filter-input-v2 h-10 font-bold"><option value="">-- اختر --</option><option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">{{ pm.name }}</option></select>
                 </div>
                 <div class="space-y-1.5">
                   <label class="metadata-label">المبلغ المدفوع</label>
                   <input
                     v-model.number="actionModal.paidAmount"
                     type="number"
                     min="0"
                     step="0.01"
                     :max="actionModal.invoiceTotal"
                     :class="{ 'border-rose-300 text-rose-600 focus:border-rose-500': approvalPaymentError }"
                     class="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-left text-sm font-black text-emerald-600 outline-none transition-all focus:border-emerald-500"
                   />
                   <p v-if="approvalPaymentError" class="text-[10px] font-bold text-rose-600">{{ approvalPaymentError }}</p>
                 </div>
                 </div>
               </div>

               <div v-if="actionModal.paidAmount > 0" class="grid grid-cols-3 gap-3">
                  <div class="p-3 rounded-lg bg-white border border-slate-200 text-center">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">إجمالي الفاتورة</p>
                    <p class="text-sm font-bold font-mono text-slate-900">{{ formatPrice(actionModal.invoiceTotal) }}</p>
                  </div>
                  <div class="p-3 rounded-lg bg-white border border-slate-200 text-center">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">المستلم</p>
                    <p class="text-sm font-bold font-mono text-emerald-600">{{ formatPrice(actionModal.paidAmount) }}</p>
                  </div>
                  <div :class="[changeAmount >= 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200']" class="p-3 rounded-lg border text-center">
                    <p class="text-[9px] font-bold uppercase tracking-widest mb-1" :class="[changeAmount >= 0 ? 'text-emerald-600' : 'text-rose-600']">{{ changeAmount >= 0 ? 'الباقي' : 'الناقص' }}</p>
                    <p class="text-sm font-bold font-mono" :class="[changeAmount >= 0 ? 'text-emerald-600' : 'text-rose-600']">{{ formatPrice(Math.abs(changeAmount)) }}</p>
                  </div>
               </div>
            </div>

            <div class="space-y-1.5">
              <div class="flex items-center justify-between">
                <label class="metadata-label">ملاحظات القرار</label>
                <span class="text-[9px] text-slate-400">{{ actionModal.note.length }}/500</span>
              </div>
              <textarea v-model="actionModal.note" rows="3" maxlength="500" class="filter-input-v2 h-auto py-2 italic resize-none" :placeholder="actionModal.mode === 'approve' ? 'أضف ملاحظة اختيارية عن الاعتماد...' : 'اذكر سبب رفض الفاتورة...'"></textarea>
            </div>
          </div>

          <div class="px-6 md:px-8 py-4 md:py-5 bg-slate-50/70 border-t border-slate-200 flex justify-end gap-3 shrink-0">
             <button @click="closeAction" class="px-6 h-10 text-xs font-bold text-slate-500">إلغاء</button>
             <LoadingButton
               :text="actionModal.mode === 'approve' ? 'تأكيد الاعتماد' : 'تأكيد الرفض'"
               :isLoading="isSubmitting"
               loadingText="جاري..."
               :disabled="actionModal.mode === 'approve' && (approvalPaymentError || (actionModal.paidAmount > 0 && (!actionModal.paymentMethodId || approvalPaymentMethodIsCredit)))"
               :variant="actionModal.mode === 'approve' ? 'success' : 'danger'"
               loaderType="dots"
               @click="submitAction"
               class="px-10 h-10"
             />
          </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <Teleport to="body">
      <transition name="fade">
        <div v-if="printModal.open" class="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
          <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn p-8 text-center space-y-6">
           <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 border border-emerald-200 flex items-center justify-center mx-auto"><i class="fas fa-check-circle text-3xl"></i></div>
           <div><h3 class="text-base font-bold text-slate-900 uppercase">تم الاعتماد والتحصيل</h3><p class="text-[10px] text-slate-400 mt-1 font-medium">فاتورة رقم #{{ printModal.saleId }} بانتظار الطباعة</p></div>
           
           <div v-if="printModal.change > 0" class="p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 flex justify-between items-center">
              <span class="text-[9px] font-bold uppercase tracking-widest text-emerald-600">الباقي للعميل:</span>
              <span class="text-sm font-bold font-mono tracking-tighter">{{ formatPrice(printModal.change) }}</span>
           </div>

           <div class="flex gap-2">
              <button @click="printModal.open = false" class="flex-1 h-10 text-xs font-bold text-slate-500">تجاهل</button>
              <button @click="printApprovedSale" class="flex-2 px-8 h-10 bg-blue-600 text-white rounded-md text-xs font-bold shadow-lg shadow-blue-900/20 active:scale-95 transition-all flex items-center justify-center gap-2">
                 <i class="fas fa-print"></i> طباعة الفاتورة
              </button>
           </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <HistoryDetailsModal
      :show="showDetails && !!details"
      @close="showDetails = false"
      :title="`مراجعة أصناف الفاتورة ${details?.sale?.invoiceNumber || details?.invoiceNumber || '#' + (details?.sale?.id || details?.id || '')}`"
      icon="fas fa-clipboard-check"
    >
          <div class="space-y-6">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
              <div v-for="info in [
                { l: 'العميل', v: details?.sale?.customerName ?? details?.customerName ?? 'عميل نقدي' },
                { l: 'الوقت والتاريخ', v: formatDateTime(details?.sale?.createdAt ?? details?.createdAt) },
                { l: 'الكاشير', v: details?.sale?.createdByName ?? details?.createdByName ?? details?.sale?.userName ?? details?.userName ?? '-' },
                { l: 'طريقة الدفع', v: details?.sale?.paymentMethodName ?? details?.paymentMethodName ?? '-' },
                { l: 'إجمالي الفاتورة شامل الضريبة', v: formatPrice(getSaleGrossTotal(previewSale)), c: 'text-blue-600 font-bold' }
              ]" :key="info.l" class="min-w-0 space-y-1 rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-2">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wide">{{ info.l }}</p>
                <p :class="[info.c || 'text-slate-800', 'break-words text-[11px] font-bold leading-4']">{{ info.v }}</p>
              </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-100 shadow-sm">
              <table class="w-full min-w-[560px] text-right text-xs">
                <thead><tr class="bg-slate-50 border-b border-slate-100 font-bold text-slate-400 uppercase tracking-tighter"><th class="px-4 py-3">الصنف</th><th class="px-4 py-3 text-center">الكمية</th><th class="px-4 py-3 text-center">قبل الخصم</th><th class="px-4 py-3 text-center">الخصم</th><th class="px-4 py-3 text-center">الضريبة</th><th class="px-4 py-3 text-left">الصافي</th></tr></thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                  <tr v-for="it in (details?.items || details?.sale?.items || [])" :key="it.id">
                    <td class="px-4 py-3 text-slate-800 font-bold">{{ it.name || it.productName }}</td>
                    <td class="px-4 py-3 text-center font-bold text-slate-500">{{ it.quantity ?? it.qty }}</td>
                    <td class="px-4 py-3 text-center font-mono text-slate-700">{{ formatPrice(itemPrice(it) * itemQty(it)) }}</td>
                    <td class="px-4 py-3 text-center font-mono text-rose-500">{{ formatPrice(itemDiscount(it)) }}</td>
                    <td class="px-4 py-3 text-center font-mono text-indigo-500">{{ formatPrice(itemTax(it)) }}</td>
                    <td class="px-4 py-3 text-left font-bold font-mono text-slate-900">{{ formatPrice(itemTotal(it)) }}</td>
                  </tr>
                  <tr v-if="!(details?.items || details?.sale?.items || []).length">
                    <td colspan="6" class="px-4 py-8 text-center text-xs text-slate-400">لا توجد أصناف لعرضها.</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="flex justify-end">
              <div class="w-full rounded-xl border border-slate-200 bg-slate-50 p-5 text-slate-800 flex flex-col justify-center space-y-2.5 md:w-80">
                <div class="flex justify-between text-[11px] font-medium text-slate-500"><span>المجموع قبل الخصم</span><span class="font-mono font-bold">{{ formatPrice(previewSale.totalAmount ?? 0) }}</span></div>
                <div class="flex justify-between text-[11px] font-medium text-slate-500"><span>الخصم</span><span class="font-mono font-bold text-rose-500">- {{ formatPrice(previewSale.discountValue ?? 0) }}</span></div>
                <div class="flex justify-between text-[11px] font-medium text-slate-500 pb-2 border-b border-slate-200"><span>الضريبة ({{ Number(previewSale.taxRate || 0).toFixed(2).replace(/\.?0+$/, '') }}%)</span><span class="font-mono font-bold">{{ formatPrice(previewSale.taxAmount ?? 0) }}</span></div>
                <div class="flex justify-between items-center rounded-lg bg-blue-100 border border-blue-200 px-3 py-2.5 text-blue-800"><span class="text-[11px] font-bold">الإجمالي النهائي شامل الضريبة</span><span class="font-mono font-bold">{{ formatPrice(getSaleGrossTotal(previewSale)) }}</span></div>
                <div class="flex justify-between text-[11px] font-medium text-slate-500"><span>المدفوع</span><span class="font-mono font-bold text-emerald-600">{{ formatPrice(previewSale.actualPaidAmount ?? previewSale.paidAmount ?? 0) }}</span></div>
                <div class="flex justify-between text-[11px] font-medium text-slate-500"><span>المتبقي</span><span class="font-mono font-bold text-amber-600">{{ formatPrice(previewSale.remainingBalance ?? Math.max(0, getSaleGrossTotal(previewSale) - Number(previewSale.paidAmount || 0))) }}</span></div>
              </div>
            </div>
          </div>
      <template #footer>
            <div class="flex w-full items-center">
              <span class="rounded border border-amber-100 bg-amber-50 px-2 py-1 text-[10px] font-bold uppercase tracking-tighter text-amber-600">بانتظار القرار</span>
              <div dir="rtl" class="mr-auto flex flex-row-reverse flex-wrap items-center gap-3">
                <button @click="showDetails = false" class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap rounded-md border border-slate-200 bg-white px-6 text-xs font-bold text-slate-700 transition-all hover:bg-slate-50">إغلاق المعاينة</button>
              </div>
            </div>
          </template>
    </HistoryDetailsModal>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue'
import { useApprovalsStore } from '@/stores/approvals/approvalsStore'
import { useToast } from '@/composables/useToast'
import { useCompanyCurrency } from '@/composables/useCompanyCurrency'
import { useBreadcrumb } from '@/composables/useBreadcrumb'
import { useBranchStore } from '@/stores/branch/branchStore'
import { useSalesStore } from '@/stores/sales/salesStore'
import { usePaymentStore } from '@/stores/payment/paymentStore'
import { useProductStore } from '@/stores/product/productStore'
import { useBranchIsolation } from '@/composables/useBranchIsolation'
import LoadingButton from '@/components/ui/LoadingButton.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';

const { showToast } = useToast()
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency()
const { breadcrumb } = useBreadcrumb()
const branchStore = useBranchStore()
const branchIsolation = useBranchIsolation()
const salesStore = useSalesStore()
const paymentStore = usePaymentStore()
const productStore = useProductStore()
const paymentMethods = computed(() => paymentStore.paymentMethods || [])
const branches = computed(() => branchStore.branches);
const selectedBranch = computed(() => branchStore.selectedBranchId);

const userChoseBranch = ref(
  localStorage.getItem('selectedBranchId') !== null
  && localStorage.getItem('selectedBranchId') !== 'all'
);
const hasExplicitBranchSelection = computed(() => userChoseBranch.value && branchStore.selectedBranchId !== null);

const onBranchChange = (newBranchId) => {
  if (!branchIsolation.isExempt.value) return;
  branchStore.setSelectedBranch(newBranchId);
  userChoseBranch.value = (newBranchId !== null && newBranchId !== '' && newBranchId !== 'all');
  page.value = 1;
  manualRefresh(true);
};

const rows = ref([])
const page = ref(1)
const limit = ref(20)
const total = ref(0)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit.value)))
const isLoading = ref(false)
let listAbortCtrl = null

// Real-time polling
const POLL_INTERVAL = 15000
let pollTimer = null
let pollingBusy = false
const isPolling = ref(false)
const lastUpdatedAt = ref(null)
const newInvoicesAlert = ref(false)
let newInvoicesAlertTimer = null
let knownIds = new Set()
let lastSoundAt = 0

const lastUpdatedLabel = computed(() => {
  if (!lastUpdatedAt.value) return ''
  const now = new Date()
  const diff = Math.floor((now - lastUpdatedAt.value) / 1000)
  if (diff < 5) return 'الآن'
  if (diff < 60) return `منذ ${diff} ث`
  return lastUpdatedAt.value.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true })
})

const showDetails = ref(false)
const details = ref(null)
const isLoadingDetails = ref(false)
const previewSale = computed(() => details.value?.sale ?? details.value ?? {})

const actionModal = ref({ open: false, mode: 'approve', id: null, note: '', paymentMethodId: '', paidAmount: 0, invoiceTotal: 0 })
const isSubmitting = ref(false)
const printModal = ref({ open: false, saleId: null, invoiceTotal: 0, change: 0 })

const changeAmount = computed(() => (actionModal.value.paidAmount || 0) - (actionModal.value.invoiceTotal || 0))
const approvalPaymentMethodIsCredit = computed(() => {
  const selectedMethod = paymentMethods.value.find(method =>
    Number(method.id) === Number(actionModal.value.paymentMethodId)
  )
  return selectedMethod
    ? String(selectedMethod.kind || '').toLowerCase() === 'credit'
    : Boolean(actionModal.value.isCredit)
})
const approvalPaymentError = computed(() => {
  const amount = Number(actionModal.value.paidAmount)
  if (!Number.isFinite(amount) || amount < 0) return 'أدخل مبلغًا صحيحًا لا يقل عن صفر.'
  if (amount > actionModal.value.invoiceTotal) return 'لا يمكن أن يتجاوز المبلغ إجمالي الفاتورة شامل الضريبة.'
  if (amount > 0 && approvalPaymentMethodIsCredit.value) return 'اختر طريقة دفع فورية لتسجيل مبلغ مدفوع.'
  return ''
})

const getSaleGrossTotal = (sale) => {
  if (sale?.grandTotal !== undefined && sale?.grandTotal !== null) {
    return Math.max(0, Number(sale.grandTotal) || 0)
  }
  const net = Number(sale?.netTotalAmount ?? sale?.totalAmount ?? 0) || 0
  const tax = Number(sale?.taxAmount ?? 0) || 0
  return Math.max(0, Math.round((net + tax + Number.EPSILON) * 100) / 100)
}

const formatPrice = (amount) => formatCurrencyLocale(amount, 2)
const formatDateTime = (val) => {
  if (!val) return ''
  try {
    return new Date(val).toLocaleString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true })
  } catch { return String(val) }
}

const itemPrice = (it) => { const v = it?.price ?? it?.salePrice ?? it?.unitPrice; return Number(v ?? 0); }
const itemQty = (it) => Number((it?.quantity ?? it?.qty ?? 1))
const itemDiscount = (it) => Number(it?.discountValue ?? 0)
const itemTax = (it) => Number(it?.taxAmount ?? 0)
const itemTotal = (it) => {
  const v = it?.total ?? it?.netTotal
  if (v != null) return Number(v)
  return itemPrice(it) * itemQty(it)
}

const kindLabel = (k) => {
  const v = String(k || '').toLowerCase()
  switch (v) {
    case 'cash': return 'نقدي';
    case 'bank': return 'بنكي';
    case 'card': return 'بطاقة';
    case 'credit': return 'آجل';
    default: return 'أخرى';
  }
}
const kindClass = (k) => {
  const v = String(k || '').toLowerCase()
  if (v === 'cash') return 'bg-emerald-100 text-emerald-700';
  if (v === 'credit') return 'bg-amber-100 text-amber-700';
  if (v === 'bank' || v === 'card') return 'bg-sky-100 text-sky-700';
  return 'bg-slate-100 text-slate-500';
}

const fetchPending = async ({ silent = false } = {}) => {
  if (listAbortCtrl) listAbortCtrl.abort()
  listAbortCtrl = new AbortController()
  if (!silent) isLoading.value = true
  try {
    const approvalsStore = useApprovalsStore();
    approvalsStore.clearCache()
    const params = { page: page.value, limit: limit.value };
    if (branchIsolation.isExempt.value) {
      if (selectedBranch.value) params.branchId = String(selectedBranch.value);
    } else {
      params.branchId = branchIsolation.getRequiredBranchId();
    }
    const res = await approvalsStore.listPending(params, { skipLoader: silent })
    const newRows = res.data || []
    const newTotal = Number(res.meta?.total || newRows.length)

    // Detect new invoices by ID comparison (more accurate than count)
    const newIds = newRows.map(r => r.id)
    const hasNew = silent && knownIds.size > 0 && newIds.some(id => !knownIds.has(id))
    knownIds = new Set(newIds)

    if (hasNew) {
      newInvoicesAlert.value = true
      playAlertSound()
      clearTimeout(newInvoicesAlertTimer)
      newInvoicesAlertTimer = setTimeout(() => { newInvoicesAlert.value = false }, 5000)
    }
    rows.value = newRows
    total.value = newTotal
    lastUpdatedAt.value = new Date()
  } catch (e) {
    rows.value = [];
    total.value = 0;
    knownIds.clear();
    if (e.name !== 'CanceledError' && !silent)
      showToast(e?.response?.data?.message || 'فشل تحميل القائمة', 'error')
  } finally {
    if (!silent) isLoading.value = false
  }
}

const manualRefresh = (forceRefresh = false) => {
  newInvoicesAlert.value = false
  fetchPending({ silent: false, force: forceRefresh })
}

const playAlertSound = () => {
  const now = Date.now()
  if (now - lastSoundAt < 10000) return
  lastSoundAt = now
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.connect(gain); gain.connect(ctx.destination)
    osc.frequency.value = 880
    osc.type = 'sine'
    gain.gain.setValueAtTime(0.3, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4)
    osc.start(ctx.currentTime); osc.stop(ctx.currentTime + 0.4)
  } catch {}
}

const pollOnce = async () => {
  if (pollingBusy) return
  pollingBusy = true
  try {
    await fetchPending({ silent: true })
  } finally {
    pollingBusy = false
  }
}

const startPolling = () => {
  if (pollTimer) return
  isPolling.value = true
  pollTimer = setInterval(() => {
    if (document.hidden || actionModal.value.open || printModal.value.open) return
    pollOnce()
  }, POLL_INTERVAL)
}

const stopPolling = () => {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
  isPolling.value = false
}

const handleVisibilityChange = () => {
  if (document.hidden) {
    stopPolling()
  } else {
    fetchPending({ silent: true })
    startPolling()
  }
}

const openAction = (mode, id) => {
  const row = rows.value.find(r => r.id === id)
  const invoiceTotal = getSaleGrossTotal(row)
  const kind = String(row?.paymentMethodKind ?? row?.sale?.paymentMethodKind ?? row?.paymentMethod?.kind ?? '').toLowerCase()
  const isCredit = kind === 'credit'
  const defaultMethodId = isCredit ? '' : (row?.paymentMethodId || (paymentMethods.value[0]?.id ?? ''))
  const defaultPaid    = isCredit ? 0 : invoiceTotal
  actionModal.value = { open: true, mode, id, note: '', paymentMethodId: defaultMethodId, paidAmount: defaultPaid, invoiceTotal, isCredit }
}
const closeAction = () => { actionModal.value.open = false; }

const submitAction = async () => {
  if (!actionModal.value.open || !actionModal.value.id || isSubmitting.value) return
  isSubmitting.value = true
  try {
    const approvalsStore = useApprovalsStore();
    if (actionModal.value.mode === 'approve') {
      const override = {
        paymentMethodId: actionModal.value.paymentMethodId || undefined,
        paidAmount: actionModal.value.paidAmount ?? undefined
      }
      const res = await approvalsStore.approve(actionModal.value.id, actionModal.value.note, override)
      if (res.status === 'error') { showToast(res.message || 'فشل التنفيذ', 'error'); return }
      const saleId = actionModal.value.id
      const invoiceTotal = actionModal.value.invoiceTotal
      const change = (actionModal.value.paidAmount || 0) - invoiceTotal
      closeAction()
      await fetchPending()
      productStore.invalidateCache()
      printModal.value = { open: true, saleId, invoiceTotal, change: Math.max(0, change) }
    } else {
      const res = await approvalsStore.reject(actionModal.value.id, actionModal.value.note)
      if (res.status === 'error') { showToast(res.message || 'فشل التنفيذ', 'error'); return }
      showToast('تم رفض الفاتورة', 'success')
      closeAction(); await fetchPending();
    }
  } catch (e) { showToast(e?.response?.data?.message || 'فشل التنفيذ', 'error') } 
  finally { isSubmitting.value = false; }
}

const printApprovedSale = async () => {
  printModal.value.open = false
  try {
    const res = await salesStore.fetchSaleDetails(printModal.value.saleId)
    const saleData = res?.data ?? res
    if (saleData) { details.value = saleData; showDetails.value = true; }
  } catch { showToast('فشل تحميل تفاصيل الفاتورة للطباعة', 'error') }
}

const viewDetails = async (id) => {
  isLoadingDetails.value = true
  try {
    const res = await salesStore.fetchSaleDetails(id, { force: true });
    const saleData = res?.data ?? res
    if (saleData) { details.value = saleData; showDetails.value = true; }
    else showToast('لم يتم استرجاع بيانات الفاتورة', 'error')
  } catch { showToast('فشل تحميل التفاصيل', 'error') } 
  finally { isLoadingDetails.value = false; }
}

// isMounting flag: يمنع watch(selectedBranch) من إطلاق fetch أثناء onMounted
let isMounting = true;

onMounted(async () => {
  const hadPriorBranchChoice = localStorage.getItem('selectedBranchId') !== null
                                && localStorage.getItem('selectedBranchId') !== 'all';
  branchStore.loadFromStorage();
  if (!branchStore.branches || branchStore.branches.length === 0) {
    await branchStore.fetchBranches().catch(() => {})
  }

  userChoseBranch.value = hadPriorBranchChoice;

  fetchSettings()
  fetchPending()
  paymentStore.fetchPaymentMethods()
  startPolling()
  document.addEventListener('visibilitychange', handleVisibilityChange)
  isMounting = false;
})
onUnmounted(() => {
  stopPolling()
  clearTimeout(newInvoicesAlertTimer)
  document.removeEventListener('visibilitychange', handleVisibilityChange)
  if (listAbortCtrl) listAbortCtrl.abort()
})
watch(page, () => fetchPending())
watch(limit, () => { page.value = 1; fetchPending() })
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.3s ease; }
.fade-scale-enter-from, .fade-scale-leave-to { opacity: 0; transform: scale(0.8); }
</style>