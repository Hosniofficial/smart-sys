<template>
  <div class="space-y-8">
    <!-- Step 1: Invoice Search -->
    <div class="relative space-y-2">
      <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">1. البحث عن المستند الأصلي</label>
      <div class="relative group">
        <input
          ref="invoiceSearchInputRef"
          type="text"
          v-model="invoiceNumber"
          @input="debouncedSearchInvoice"
          @focus="showInvoiceDropdown = true"
          @blur="handleInvoiceBlur"
          @keydown.down="selectNextResult"
          @keydown.up="selectPrevResult"
          @keydown.enter.prevent="selectHighlightedResult"
          @keydown.escape="showInvoiceDropdown = false"
          class="h-11 w-full bg-white border border-slate-200 rounded-lg text-sm font-bold focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all"
          style="padding-right: 2rem; padding-left: 2rem;"
          placeholder="ابحث بـ: رقم الفاتورة أو اسم الطرف..."
          autocomplete="off"
        >
        <i class="fas fa-search absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 text-xs pointer-events-none"></i>
        <div v-if="isSearchingInvoice" class="absolute left-4 top-1/2 -translate-y-1/2"><BaseSpinner :size="16" color="#e11d48" /></div>

        <!-- Search Dropdown Teleport Style -->
        <Teleport to="body">
          <transition name="dropdown">
            <div v-if="showInvoiceDropdown && invoiceSearchResults.length && !selectedInvoice" ref="invoiceDropdownRef" class="fixed bg-white border border-slate-200 rounded-lg shadow-2xl overflow-hidden z-[99999]" :style="invoiceDropdownPosition">
              <div
                v-for="(inv, idx) in invoiceSearchResults.slice(0, 20)"
                :key="inv.id"
                @click="selectInvoice(inv)"
                :class="['px-4 py-3 cursor-pointer border-b border-slate-50 flex items-center justify-between group transition-colors', idx === highlightedIdx ? 'bg-rose-50 border-l-4 border-l-rose-500' : 'hover:bg-rose-50/60']"
              >
                <div class="flex-grow min-w-0">
                  <p class="text-xs font-bold text-slate-900">#{{ inv.invoice_number || inv.id }} <span class="text-[10px] font-normal text-slate-500">(ID: {{ inv.id }})</span></p>
                  <p class="text-[10px] text-slate-400 truncate">{{ inv.customer_name || inv.supplier_name }}</p>
                </div>
                <div class="text-left"><p class="text-xs font-bold text-rose-600">{{ formatPrice(inv.total_amount) }}</p></div>
              </div>
			  
              <div v-if="invoiceSearchResults.length > 20" class="px-4 py-2 text-center bg-slate-50 border-t border-slate-100">
                <p class="text-[10px] text-slate-500">و {{ invoiceSearchResults.length - 20 }} نتيجة أخرى (استخدم ↑↓ للتنقل)</p>
              </div>
            </div>
          </transition>
        </Teleport>

        <div v-if="invoiceNumber && !isSearchingInvoice && !invoiceSearchResults.length && !selectedInvoice" class="text-xs text-red-500 mt-2">
          <i class="fas fa-exclamation-circle ml-1"></i>
          لا توجد فواتير مطابقة للبحث
        </div>
      </div>
    </div>

    <div v-if="selectedInvoice" class="space-y-6 animate-fadeIn">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-1">
        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-[0.15em]">2. تحديد الأصناف المرتجعة</label>
        <label class="inline-flex w-fit items-center gap-2 cursor-pointer bg-slate-100 px-3 py-2 rounded-full text-[10px] font-bold text-slate-600 hover:bg-slate-200 transition-colors">
          <input type="checkbox" v-model="isFullReturn" @change="setFullReturn" class="rounded border-slate-300 text-blue-600 focus:ring-0" />
          إرجاع كل الكميات المتاحة
        </label>
      </div>

      <!-- Selected Invoice Card Header -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-6 bg-slate-900 rounded-xl p-4 sm:p-6 text-white shadow-lg overflow-hidden relative">
        <div class="absolute right-0 top-0 w-32 h-32 bg-white/5 rounded-full translate-x-10 -translate-y-10"></div>
        <div class="space-y-3 relative z-10">
          <div class="flex items-center gap-3">
             <span class="text-[10px] font-bold text-white/40 uppercase tracking-widest">مستند المصدر</span>
             <span class="px-2 py-0.5 bg-white/10 rounded text-[9px] font-bold border border-white/10 font-mono">#{{ selectedInvoice.invoice_number || selectedInvoice.id }}</span>
          </div>
          <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-baseline gap-2 sm:gap-4">
            <span class="text-[10px] font-medium text-white/50">تاريخ الإصدار: {{ selectedInvoice.invoice_date || selectedInvoice.sale_date || selectedInvoice.purchase_date }}</span>
            <span class="text-[10px] font-medium text-white/50">الذمة المتبقية: {{ formatPrice(outstanding) }}</span>
          </div>
          <div class="flex flex-wrap gap-x-4 gap-y-2 border-t border-white/10 pt-3 text-[10px] text-white/70">
            <span><i class="fas fa-user ml-1 text-white/40"></i>{{ selectedInvoice.customer_name || 'بدون عميل' }}</span>
            <span v-if="selectedInvoice.branch_name"><i class="fas fa-code-branch ml-1 text-white/40"></i>{{ selectedInvoice.branch_name }}</span>
            <span v-if="selectedInvoice.payment_method_name"><i class="fas fa-credit-card ml-1 text-white/40"></i>الدفع الأصلي: {{ selectedInvoice.payment_method_name }}</span>
          </div>
        </div>
        <div class="flex flex-row sm:flex-col items-center sm:items-end justify-between sm:justify-center gap-3 relative z-10 border-t border-white/10 pt-4 md:border-0 md:pt-0">
           <p class="text-[9px] font-bold text-rose-300 uppercase tracking-widest mb-1">قيمة الكميات المضافة للمرتجع</p>
           <p class="text-2xl sm:text-3xl font-bold font-mono tracking-tighter whitespace-nowrap">{{ formatPrice(totalReturnAmount) }}</p>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-6">
        <div class="p-4 bg-slate-50 rounded-xl border border-slate-100 space-y-2">
          <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">التفصيل المالي للفاتورة الأصلية</p>
          <div class="flex justify-between text-xs font-bold text-slate-500">
            <span>إجمالي الفاتورة</span>
            <span class="font-mono text-slate-900">{{ formatPrice(selectedInvoice.grand_total ?? (Number(selectedInvoice.net_total_amount || selectedInvoice.total_amount || 0) + Number(selectedInvoice.tax_amount || 0))) }}</span>
          </div>
          <div v-if="selectedInvoice.has_discount || Number(selectedInvoice.discount_value) > 0" class="flex justify-between text-xs font-bold text-slate-500">
            <span>الخصم</span>
            <span class="font-mono text-rose-500">- {{ formatPrice(selectedInvoice.discount_value || 0) }}</span>
          </div>
          <div v-if="Number(selectedInvoice.tax_amount) > 0" class="flex justify-between text-xs font-bold text-slate-500">
            <span>الضريبة</span>
            <span class="font-mono text-indigo-600">+ {{ formatPrice(selectedInvoice.tax_amount || 0) }}</span>
          </div>
          <div class="flex justify-between text-xs font-bold text-slate-500">
            <span>صافي المدفوع فعلياً</span>
            <span class="font-mono text-slate-900">{{ formatPrice(selectedInvoice.actual_paid_amount ?? selectedInvoice.paid_amount ?? 0) }}</span>
          </div>
          <div v-if="outstanding > 0" class="flex justify-between text-xs font-bold text-slate-500">
            <span>المتبقي غير المسدد</span>
            <span class="font-mono text-amber-600">{{ formatPrice(outstanding) }}</span>
          </div>
          <div class="flex justify-between text-xs font-bold border-t border-slate-200 pt-2">
            <span class="text-rose-700">قيمة المرتجع الحالية</span>
            <span class="font-mono text-rose-700">{{ formatPrice(totalReturnAmount) }}</span>
          </div>
        </div>
      </div>

      <div
        v-if="isSale && selectedInvoice && (!partyId || customerTotalOutstanding === null || customerTotalOutstanding > 0)"
        class="p-4 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 text-xs flex items-start gap-3"
      >
        <i class="fas fa-exclamation-triangle text-amber-500 mt-0.5"></i>
        <div class="min-w-0 flex-1 space-y-3">
          <div>
            <p class="font-bold">
              {{ refundMode === 'refund_excess'
                ? `سيُصرف ${formatPrice(paidAmount)} للعميل، ويُقاصّ المتبقي مع رصيد حسابه.`
                : refundMode === 'cash' && !partyId
                  ? `سيُرد مبلغ ${formatPrice(paidAmount)} عبر طريقة الدفع المختارة.`
                  : 'سيُضاف المرتجع إلى رصيد العميل دون صرف نقدي.' }}
            </p>
            <p class="mt-1 font-normal leading-5 text-amber-700">
              {{ refundMode === 'refund_excess'
                ? customerTotalOutstanding > 0
                  ? 'ستُقاصّ ديون العميل المستحقة أولًا، ثم يُصرف الفائض عبر طريقة الدفع المختارة.'
                  : 'لا توجد ديون مستحقة على العميل؛ سيُرد له كامل المبلغ عبر طريقة الدفع المختارة.'
                : refundMode === 'cash'
                  ? 'سيُرد مبلغ المرتجع عبر طريقة الدفع المختارة بعد اعتماد مستخدم مخوّل.'
                  : partyId
                    ? 'سيُضاف مبلغ المرتجع كرصيد دائن للعميل، حتى إن لم تكن عليه ديون؛ لن يُصرف نقدًا بهذا الاختيار.'
                    : 'لعدم ارتباط الفاتورة بعميل، يلزم اختيار رد المبلغ عبر طريقة الدفع واعتماده من مستخدم مخوّل.' }}
            </p>
          </div>
          <div v-if="partyId" class="grid grid-cols-1 gap-2 border-t border-amber-200 pt-3 sm:grid-cols-2">
            <div class="flex items-center justify-between gap-3">
              <span class="font-normal text-amber-700">رصيد العميل الحالي</span>
              <span v-if="customerAccountBalance !== null" class="shrink-0 font-mono font-bold">
                {{ formatPrice(Math.abs(customerAccountBalance)) }}
                {{ customerAccountBalance > 0 ? 'مدين' : customerAccountBalance < 0 ? 'دائن' : 'متزن' }}
              </span>
              <span v-else class="shrink-0 text-[10px] font-semibold">
                {{ selectedInvoice.customer_account_balance === undefined ? 'غير متاح' : 'غير محدد' }}
              </span>
            </div>
            <div class="flex items-center justify-between gap-3">
              <span class="font-normal text-amber-700">إجمالي ديون العميل المستحقة</span>
              <span v-if="customerTotalOutstanding !== null" class="shrink-0 font-mono font-bold">{{ formatPrice(customerTotalOutstanding) }}</span>
              <span v-else class="shrink-0 text-[10px] font-semibold">غير متاح</span>
            </div>
            <div class="flex items-center justify-between gap-3">
              <span class="font-normal text-amber-700">المقاصة من الديون</span>
              <span v-if="customerTotalOutstanding !== null" class="shrink-0 font-mono font-bold">{{ formatPrice(customerBalanceOffset) }}</span>
              <span v-else class="shrink-0 text-[10px] font-semibold">غير متاح</span>
            </div>
            <div class="flex items-center justify-between gap-3">
              <span class="font-normal text-amber-700">صافي رصيد العميل بعد المرتجع</span>
              <span v-if="customerAccountBalance !== null" class="shrink-0 font-mono font-bold">
                {{ formatPrice(Math.abs(projectedCustomerBalance)) }}
                {{ projectedCustomerBalance > 0 ? 'مدين للمنشأة' : projectedCustomerBalance < 0 ? 'دائن للعميل' : 'متزن' }}
              </span>
              <span v-else class="shrink-0 text-[10px] font-semibold">غير متاح</span>
            </div>
            <div v-if="canRefundExcess && (customerAccountBalance === null || customerTotalOutstanding === null)" class="text-[10px] font-normal leading-5 text-rose-700 sm:col-span-2">
              لا يمكن إظهار خيار رد الفائض قبل توفر رصيد العميل وإجمالي ديونه المستحقة.
            </div>
          </div>
        </div>
      </div>
      <div v-else-if="!isSale && hasOutstanding" class="p-4 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 text-xs font-bold flex items-start gap-3">
        <i class="fas fa-exclamation-triangle text-amber-500 mt-0.5"></i>
        <span>لا يمكن استلام نقدي لمرتجع على فاتورة مشتريات آجلة أو غير مسددة بالكامل. سيتم خصم قيمة المرتجع من ذمة المورد فقط.</span>
      </div>

      <!-- Items Table: High Density -->
      <div class="hidden md:block border border-slate-200 rounded-xl overflow-hidden shadow-sm">
        <table class="w-full text-right text-xs">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 font-bold text-slate-400 uppercase tracking-tighter">
              <th class="px-4 py-3">الصنف</th>
              <th class="px-4 py-3 text-center">أصلي</th>
              <th class="px-4 py-3 text-center">مرتجع سابقاً</th>
              <th class="px-4 py-3 text-center">المتبقي</th>
              <th class="px-4 py-3 text-center w-24">كمية المرتجع</th>
              <th class="px-4 py-3 text-left">الإجمالي</th>
              <th class="px-4 py-3 text-center w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="item in selectedInvoice.items" :key="item.item_id" class="hover:bg-slate-50 transition-colors">
              <td class="px-4 py-3">
                <p class="font-bold text-slate-800">{{ item.product_name }}</p>
                <p v-if="hasDuplicateProduct(item.product_id)" class="text-[8px] text-amber-500 font-bold uppercase mt-0.5">بند مكرر #{{ item.item_id }}</p>
              </td>
              <td class="px-4 py-3 text-center text-slate-400 font-mono">{{ item[quantityKey] }}</td>
              <td class="px-4 py-3 text-center text-slate-400 font-mono">{{ item.prevReturned ?? 0 }}</td>
              <td class="px-4 py-3 text-center text-slate-900 font-bold font-mono">{{ item.remainingQty ?? item[quantityKey] }}</td>
              <td class="px-4 py-3 text-center">
                <input type="number" v-model.number="item.returnQuantity" :disabled="isFullReturn" min="0" :max="item.remainingQty ?? item[quantityKey]" class="h-7 w-16 bg-white border border-slate-200 rounded text-center text-[11px] font-bold focus:ring-4 focus:ring-blue-500/10 outline-none" @change="() => { const max = item.remainingQty ?? item[quantityKey]; if(item.returnQuantity>max) item.returnQuantity=max; }" />
              </td>
              <td class="px-4 py-3 text-left font-bold text-slate-900 font-mono">{{ formatPrice(item[displayPriceKey] * (item.returnQuantity || 0)) }}</td>
              <td class="px-4 py-3 text-center">
                <button v-if="!isFullReturn" @click="addToReturnList(item)" :aria-label="`إضافة ${item.product_name} إلى المرتجع`" title="إضافة الكمية المحددة إلى المرتجع" class="text-blue-600 hover:text-blue-700 transition-colors"><i class="fas fa-plus-circle text-base"></i></button>
                <span v-else class="text-slate-300">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="space-y-3 md:hidden">
        <article v-for="item in selectedInvoice.items" :key="item.item_id" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-xs font-bold text-slate-800">{{ item.product_name }}</p>
              <p v-if="hasDuplicateProduct(item.product_id)" class="mt-1 text-[9px] font-bold text-amber-600">بند مكرر #{{ item.item_id }}</p>
            </div>
            <span class="shrink-0 rounded-lg bg-rose-50 px-2 py-1 font-mono text-xs font-bold text-rose-700">
              {{ formatPrice(item[displayPriceKey] * (item.returnQuantity || 0)) }}
            </span>
          </div>
          <div class="mt-3 grid grid-cols-3 gap-2 border-y border-slate-100 py-3 text-center">
            <div><p class="text-[9px] text-slate-400">أصلي</p><p class="mt-1 font-mono text-xs text-slate-600">{{ item[quantityKey] }}</p></div>
            <div><p class="text-[9px] text-slate-400">مرتجع سابقاً</p><p class="mt-1 font-mono text-xs text-slate-600">{{ item.prevReturned ?? 0 }}</p></div>
            <div><p class="text-[9px] text-slate-400">المتبقي</p><p class="mt-1 font-mono text-xs font-bold text-slate-900">{{ item.remainingQty ?? item[quantityKey] }}</p></div>
          </div>
          <div class="mt-3 flex items-center justify-between gap-3">
            <label class="text-[10px] font-bold text-slate-500">كمية المرتجع</label>
            <div class="flex items-center gap-3">
              <input type="number" v-model.number="item.returnQuantity" :disabled="isFullReturn" min="0" :max="item.remainingQty ?? item[quantityKey]" class="h-9 w-20 rounded-lg border border-slate-200 bg-white text-center font-mono text-xs font-bold outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/10" @change="() => { const max = item.remainingQty ?? item[quantityKey]; if(item.returnQuantity > max) item.returnQuantity = max; }" />
              <button v-if="!isFullReturn" @click="addToReturnList(item)" :aria-label="`إضافة ${item.product_name} إلى المرتجع`" class="text-blue-600 hover:text-blue-700"><i class="fas fa-plus-circle text-lg"></i></button>
              <span v-else class="w-5 text-center text-slate-300">-</span>
            </div>
          </div>
        </article>
      </div>
      <p v-if="!returnItems.length" class="text-[10px] text-slate-500 px-1">
        أدخل كمية المرتجع للصنف، ثم اضغط علامة الإضافة بجواره لاحتسابه ضمن المرتجع.
      </p>
    </div>

    <!-- Step 3: Refund Config -->
    <div v-if="selectedInvoice" class="grid grid-cols-1 md:grid-cols-2 gap-8 animate-fadeIn">
      <div class="space-y-4">
        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">3. إعدادات التسوية والسبب</label>
        <div class="space-y-4 p-6 bg-slate-50 border border-slate-200 rounded-xl">
          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">طريقة تسوية المرتجع</label>
            <div v-if="isSale" class="grid grid-cols-1 gap-3">
              <button v-if="partyId" type="button" :aria-pressed="refundMode === 'credit_note'" @click="refundMode = 'credit_note'" :class="refundMode === 'credit_note' ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-500/10' : 'border-slate-200 bg-white hover:border-slate-300'" class="rounded-xl border p-4 text-right transition-colors">
                <span class="flex items-center justify-between gap-3">
                  <span class="text-xs font-bold text-slate-800">إضافة المرتجع إلى رصيد العميل</span>
                  <i :class="refundMode === 'credit_note' ? 'fas fa-check-circle text-blue-600' : 'far fa-circle text-slate-300'"></i>
                </span>
                <span class="mt-1 block text-[10px] leading-5 text-slate-500">يُضاف كامل المبلغ إلى حساب العميل دون صرف نقدي.</span>
              </button>
              <button v-if="partyId && canRefundExcess" type="button" :disabled="!canRefundExcessNow" :aria-pressed="refundMode === 'refund_excess'" @click="refundMode = 'refund_excess'" :class="refundMode === 'refund_excess' ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500/10' : 'border-slate-200 bg-white hover:border-slate-300'" class="rounded-xl border p-4 text-right transition-colors disabled:cursor-not-allowed disabled:opacity-50">
                <span class="flex items-center justify-between gap-3">
                  <span class="text-xs font-bold text-slate-800">رد الفائض بعد مقاصة الديون</span>
                  <i :class="refundMode === 'refund_excess' ? 'fas fa-check-circle text-emerald-600' : 'far fa-circle text-slate-300'"></i>
                </span>
                <span class="mt-1 block text-[10px] leading-5 text-slate-500">قيمة المرتجع {{ formatPrice(totalReturnAmount) }}، الديون المستحقة {{ customerTotalOutstanding === null ? 'غير متاحة' : formatPrice(customerTotalOutstanding) }}، وصافي المبلغ القابل للرد {{ customerTotalOutstanding === null ? 'غير متاح' : formatPrice(refundExcessAmount) }}.</span>
              </button>
              <button v-if="!partyId && canRefundUnlinkedCash" type="button" :aria-pressed="refundMode === 'cash'" @click="refundMode = 'cash'" :class="refundMode === 'cash' ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500/10' : 'border-slate-200 bg-white hover:border-slate-300'" class="rounded-xl border p-4 text-right transition-colors">
                <span class="flex items-center justify-between gap-3">
                  <span class="text-xs font-bold text-slate-800">رد قيمة المرتجع عبر طريقة الدفع</span>
                  <i :class="refundMode === 'cash' ? 'fas fa-check-circle text-emerald-600' : 'far fa-circle text-slate-300'"></i>
                </span>
                <span class="mt-1 block text-[10px] leading-5 text-slate-500">سيتم رد {{ formatPrice(totalReturnAmount) }} عبر طريقة الدفع المختارة.</span>
              </button>
              <div v-if="partyId && refundMode === 'refund_excess' && customerTotalOutstanding !== null" class="rounded-lg border border-emerald-100 bg-white p-3 text-[10px]">
                <div class="flex justify-between gap-3 py-1"><span class="text-slate-500">قيمة المرتجع</span><span class="font-mono font-bold text-slate-800">{{ formatPrice(totalReturnAmount) }}</span></div>
                <div class="flex justify-between gap-3 py-1"><span class="text-slate-500">المقاصة من الديون</span><span class="font-mono font-bold text-amber-700">− {{ formatPrice(customerBalanceOffset) }}</span></div>
                <div class="mt-1 flex justify-between gap-3 border-t border-slate-100 pt-2 text-xs"><span class="font-bold text-slate-700">المبلغ القابل للرد</span><span class="font-mono font-black text-emerald-700">{{ formatPrice(refundExcessAmount) }}</span></div>
              </div>
              <p v-if="!partyId && !canRefundUnlinkedCash" class="text-[10px] leading-5 text-slate-500">رد مبلغ فاتورة البيع دون عميل يتطلب أن تكون الفاتورة مسددة، وتوفر طريقة دفع غير آجلة، وصلاحية اعتماد المرتجعات.</p>
              <p v-else-if="partyId && canRefundExcess && !canRefundExcessNow" class="text-[10px] leading-5 text-slate-500">لا يوجد فائض قابل للصرف بعد مقاصة رصيد العميل، أو لا تتوفر طريقة دفع غير آجلة.</p>
              <p v-else-if="partyId && !canRefundExcess" class="text-[10px] leading-5 text-slate-500">رد الفائض النقدي يتطلب صلاحية اعتماد المرتجعات.</p>
            </div>
            <select v-else v-model="refundMode" :disabled="hasOutstanding" class="filter-input-v2 h-10">
              <option value="auto">مقاصة مع حساب المورد ثم استرداد المتبقي</option>
              <option value="cash">استرداد نقدي كامل</option>
              <option value="credit_note">تسوية على حساب المورد</option>
            </select>
            <p v-if="isSale && !partyId && !canRefundUnlinkedCash" class="text-[10px] leading-5 text-slate-500">
              رد مبلغ فاتورة البيع دون عميل يتطلب أن تكون الفاتورة مسددة، وتوفر طريقة دفع غير آجلة، وصلاحية اعتماد المرتجعات.
            </p>
            <p v-else-if="isSale && partyId && canRefundExcess && !canRefundExcessNow" class="text-[10px] leading-5 text-slate-500">
              لا يوجد فائض قابل للصرف بعد مقاصة رصيد العميل، أو لا تتوفر طريقة دفع غير آجلة.
            </p>
            <p v-else-if="isSale && partyId && !canRefundExcess" class="text-[10px] leading-5 text-slate-500">
              رد الفائض النقدي يتطلب صلاحية اعتماد المرتجعات.
            </p>
          </div>
          <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">سبب الإرجاع</label><textarea v-model="returnReason" rows="2" class="filter-input-v2 h-auto py-2" placeholder="اكتب التفاصيل هنا..."></textarea></div>
        </div>
      </div>

      <div class="space-y-4">
        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">4. الدفع النهائي</label>
        <div class="space-y-4 p-6 bg-white border border-slate-200 rounded-xl shadow-inner">
           <div class="grid grid-cols-2 gap-4">
             <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">المبلغ المصروف للعميل</label><input type="number" v-model.number="paidAmount" :disabled="isSale || hasOutstanding" :max="totalReturnAmount" min="0" class="filter-input-v2 h-10 font-mono text-emerald-600 font-black" /></div>
             <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">طريقة الدفع</label><select v-model="paymentMethodId" :disabled="isSale ? !['refund_excess', 'cash'].includes(refundMode) : hasOutstanding" class="filter-input-v2 h-10 font-bold"><option v-for="m in (isSale && ['refund_excess', 'cash'].includes(refundMode) ? refundPaymentMethods : paymentMethods)" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
           </div>
           <LoadingButton v-if="!submitInFooter"
             text="تأكيد وتسجيل المرتجع"
             :isLoading="isSubmittingReturn"
             loadingText="جاري التسجيل..."
             icon="fas fa-check-circle"
             variant="primary"
             loaderType="dots"
             @click="submitReturn"
             :disabled="!returnItems.length"
             class="w-full h-12"
           />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue';
import apiClient from '@/config/axios';
import paymentService from '@/services/payment';
import { useToast } from '@/composables/useToast';
import LoadingButton from '@/components/ui/LoadingButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import { getLocalDateISO } from '@/utils';
import { useReturnStore } from '@/stores/return/returnStore';

const props = defineProps({
  type: { type: String, required: true }, // 'sales' or 'purchases'
  submitInFooter: { type: Boolean, default: false },
  canRefundExcess: { type: Boolean, default: false },
  branchId: { type: [Number, String], default: null },
});

const emit = defineEmits(['returnSuccess']);
const { showToast } = useToast();
const returnStore = useReturnStore();

// حالة
const invoiceNumber = ref('');
const selectedInvoice = ref(null);
const returnItems = ref([]);
const returnReason = ref('');
const isSubmittingReturn = ref(false);
const canSubmitReturn = computed(() =>
  returnItems.value.length > 0
  && !isSubmittingReturn.value
  && (!isSale.value || !!partyId.value || canRefundUnlinkedCash.value)
);
const isFullReturn = ref(false);
const paidAmount = ref(0);
const paymentMethodId = ref(null);
const paymentMethods = ref([]);
const refundMode = ref(props.type === 'sales' ? 'credit_note' : 'auto'); // auto | cash | credit_note | refund_excess
const discountType = ref('fixed'); // أو 'percent'
const discountValue = ref(0);
const partyId = ref(null);
const invoiceId = ref(null);
const invoiceSearchResults = ref([]);
const isSearchingInvoice = ref(false);
const showInvoiceDropdown = ref(false);
const highlightedIdx = ref(-1);
const invoiceSearchInputRef = ref(null);
const invoiceDropdownRef = ref(null);
const dropdownPositionUpdate = ref(0); // Trigger for position recalculation

// Event handler references for scroll/resize
let scrollThrottleTimer = null;
let resizeDebounceTimer = null;
let handleScroll = null;
let handleResize = null;

let debounceTimer = null;
let invoiceSearchAbortController = null;
const invoiceSearchCache = new Map();
const blockedSaleStatuses = new Set([
  'draft',
  'pending',
  'pending_approval',
  'awaiting_approval',
  'rejected',
  'canceled',
  'cancelled'
]);

function canReturnInvoice(invoice) {
  return props.type !== 'sales' || !blockedSaleStatuses.has(String(invoice?.status || '').trim().toLowerCase());
}

// جلب طرق الدفع عند التحميل
onMounted(async () => {
  try {
    const res = await paymentService.getPaymentMethods();
    if (res.data?.status === 'success') {
      paymentMethods.value = res.data.data;
      // اختر أول طريقة دفع بشكل افتراضي إذا متاح
      if (!paymentMethodId.value && paymentMethods.value.length) {
        paymentMethodId.value = paymentMethods.value[0].id;
      }
    }
  } catch (e) {
    paymentMethods.value = [];
  }

  // إضافة مستمعي أحداث التمرير وتغيير الحجم لتحديث موضع القائمة المنسدلة
  handleScroll = () => {
    if (scrollThrottleTimer) return;
    scrollThrottleTimer = setTimeout(() => {
      dropdownPositionUpdate.value++;
      scrollThrottleTimer = null;
    }, 16); // ~60fps
  };

  handleResize = () => {
    clearTimeout(resizeDebounceTimer);
    resizeDebounceTimer = setTimeout(() => {
      dropdownPositionUpdate.value++;
    }, 100);
  };

  window.addEventListener('scroll', handleScroll, true);
  window.addEventListener('resize', handleResize);
});

// تنظيف المستمعين عند فصل المكون
onBeforeUnmount(() => {
  if (handleScroll) window.removeEventListener('scroll', handleScroll, true);
  if (handleResize) window.removeEventListener('resize', handleResize);
  clearTimeout(scrollThrottleTimer);
  clearTimeout(resizeDebounceTimer);
});

// تطبيع النوع ليطابق ما يتوقعه الـ backend
const normalizedType = computed(() => props.type === 'sales' ? 'sale' : (props.type === 'purchases' ? 'purchase' : props.type));
const isSale = computed(() => normalizedType.value === 'sale');
const outstanding = computed(() => {
  if (!selectedInvoice.value) return 0;
  if (selectedInvoice.value.net_outstanding !== undefined && selectedInvoice.value.net_outstanding !== null) {
    return Math.max(0, Number(selectedInvoice.value.net_outstanding) || 0);
  }
  const net = Number(
    selectedInvoice.value.grand_total
    ?? (Number(selectedInvoice.value.net_total_amount || selectedInvoice.value.total_amount || 0)
      + Number(selectedInvoice.value.tax_amount || 0))
  );
  const paid = Number(selectedInvoice.value.paid_amount || 0);
  const credits = Number(selectedInvoice.value.return_credits_applied || 0);
  return Math.max(0, net - paid - credits);
});
const hasOutstanding = computed(() => outstanding.value > 0);
const customerAccountBalance = computed(() => {
  const balance = selectedInvoice.value?.customer_account_balance;
  return balance === undefined || balance === null || !Number.isFinite(Number(balance))
    ? null
    : Number(balance);
});
const customerTotalOutstanding = computed(() => {
  const outstandingAmount = selectedInvoice.value?.customer_total_outstanding;
  return outstandingAmount === undefined || outstandingAmount === null || !Number.isFinite(Number(outstandingAmount))
    ? null
    : Math.max(0, Number(outstandingAmount));
});
const refundPaymentMethods = computed(() =>
  paymentMethods.value.filter(method => String(method.kind || '').toLowerCase() !== 'credit')
);
const canRefundUnlinkedCash = computed(() =>
  props.canRefundExcess
  && !!selectedInvoice.value
  && !partyId.value
  && !hasOutstanding.value
  && Number(selectedInvoice.value.actual_paid_amount ?? selectedInvoice.value.paid_amount ?? 0) > 0
  && refundPaymentMethods.value.length > 0
);
const customerBalanceOffset = computed(() =>
  customerTotalOutstanding.value === null
    ? 0
    : Math.min(totalReturnAmount.value, customerTotalOutstanding.value)
);
const refundExcessAmount = computed(() =>
  customerTotalOutstanding.value === null
    ? 0
    : Math.max(0, totalReturnAmount.value - customerBalanceOffset.value)
);
const projectedCustomerBalance = computed(() =>
  customerAccountBalance.value === null
    ? 0
    : customerAccountBalance.value
      - totalReturnAmount.value
      + (refundMode.value === 'refund_excess' ? paidAmount.value : 0)
);
const canRefundExcessNow = computed(() =>
  props.canRefundExcess
  && !!partyId.value
  && customerAccountBalance.value !== null
  && customerTotalOutstanding.value !== null
  && refundExcessAmount.value > 0
  && refundPaymentMethods.value.length > 0
);

watch(paymentMethodId, (newId) => {
  const m = paymentMethods.value.find(pm => Number(pm.id) === Number(newId));
  if (m?.kind === 'credit') {
    refundMode.value = isSale.value && canRefundUnlinkedCash.value ? 'cash' : 'credit_note';
  }
});

// اجعل paidAmount يتبع إجمالي المرتجع تلقائياً عند تغيير العناصر
watch([returnItems, isFullReturn, refundMode, selectedInvoice], () => {
  // اقتراح مبلغ مدفوع تلقائي حسب سياسة الاسترجاع
  if (!selectedInvoice.value) { paidAmount.value = 0; return; }
  if (isSale.value && refundMode.value === 'refund_excess') {
    paidAmount.value = refundExcessAmount.value;
    return;
  }
  if (isSale.value && refundMode.value === 'cash' && !partyId.value) {
    paidAmount.value = totalReturnAmount.value;
    return;
  }
  // منع أي حركة نقدية إن وُجد متبقي (مبيعات أو مشتريات)
  if (hasOutstanding.value) {
    refundMode.value = 'credit_note';
    paidAmount.value = 0;
    return;
  }
  const total = Number(totalReturnAmount.value || 0);
  const out = Number(outstanding.value || 0);
  if (refundMode.value === 'auto') {
    paidAmount.value = Math.max(0, total - out);
  } else if (refundMode.value === 'cash') {
    paidAmount.value = total;
  } else if (refundMode.value === 'credit_note') {
    paidAmount.value = 0;
  }
});

// مفاتيح حسب النوع
const quantityKey = computed(() => 'quantity');
const priceKey = computed(() => 'return_unit_price');
const displayPriceKey = priceKey;
const placeholderText = computed(() => props.type === 'sales' ? 'أدخل رقم فاتورة المبيعات أو ID...' : 'أدخل رقم فاتورة المشتريات أو ID...');

function formatPrice(amount) {
  if (!amount) return '0';
  return Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
}

// دالة للكشف عن المنتجات المكررة
function hasDuplicateProduct(productId) {
  if (!selectedInvoice.value?.items) return false;
  return selectedInvoice.value.items.filter(
    item => item.product_id === productId
  ).length > 1;
}

const totalReturnNet = computed(() =>
  returnItems.value.reduce((total, item) => total + (item[displayPriceKey.value] * item.returnQuantity), 0)
);
const totalReturnTax = computed(() => {
  if (isSale.value) {
    return totalReturnNet.value * (Number(selectedInvoice.value?.tax_rate || 0) / 100);
  }
  return returnItems.value.reduce((total, item) =>
    total + Number(item.return_tax_unit_amount || 0) * Number(item.returnQuantity || 0), 0);
});
const totalReturnAmount = computed(() => totalReturnNet.value + totalReturnTax.value);

watch(refundMode, (mode) => {
  if (isSale.value && ['refund_excess', 'cash'].includes(mode) && refundPaymentMethods.value.length) {
    const selected = paymentMethods.value.find(method => Number(method.id) === Number(paymentMethodId.value));
    if (selected?.kind === 'credit') {
      paymentMethodId.value = refundPaymentMethods.value[0].id;
    }
  }
});

function debouncedSearchInvoice() {
  clearTimeout(debounceTimer);
  highlightedIdx.value = -1;

  const query = invoiceNumber.value.trim();
  if (!query) {
    invoiceSearchResults.value = [];
    return;
  }

  // تحقق من Cache
  const cacheKey = `${normalizedType.value}:${props.branchId || 'all'}:${query}`;
  if (invoiceSearchCache.has(cacheKey)) {
    invoiceSearchResults.value = invoiceSearchCache.get(cacheKey).filter(canReturnInvoice);
    return;
  }

  const searchBranchId = props.branchId ? String(props.branchId) : null;
  isSearchingInvoice.value = true;
  debounceTimer = setTimeout(async () => {
    try {
      if (invoiceSearchAbortController) invoiceSearchAbortController.abort();
      invoiceSearchAbortController = new AbortController();

      const res = await apiClient.get('/returns/invoices', {
        params: {
          type: normalizedType.value,
          q: query,
          ...(searchBranchId ? { branch_id: searchBranchId } : {})
        },
        signal: invoiceSearchAbortController.signal
      });

      if (searchBranchId !== (props.branchId ? String(props.branchId) : null)) return;
      const data = (res.data?.data || []).filter(canReturnInvoice);
      invoiceSearchResults.value = data;
      invoiceSearchCache.set(cacheKey, data);
    } catch (err) {
      if (err.name !== 'CanceledError') {
        invoiceSearchResults.value = [];
      }
    } finally {
      isSearchingInvoice.value = false;
    }
  }, 400);
}

watch(() => props.branchId, (newBranchId, oldBranchId) => {
  if (String(newBranchId ?? '') === String(oldBranchId ?? '')) return;
  invoiceSearchAbortController?.abort();
  invoiceSearchCache.clear();
  invoiceSearchResults.value = [];
  highlightedIdx.value = -1;

  if (selectedInvoice.value
      && newBranchId
      && String(selectedInvoice.value.branch_id) !== String(newBranchId)) {
    selectedInvoice.value = null;
    returnItems.value = [];
    partyId.value = null;
    invoiceId.value = null;
    invoiceNumber.value = '';
    refundMode.value = isSale.value ? 'credit_note' : 'auto';
  }

  if (invoiceNumber.value.trim()) debouncedSearchInvoice();
});

function handleInvoiceBlur() {
  setTimeout(() => {
    showInvoiceDropdown.value = false;
  }, 200);
}

function selectNextResult() {
  if (invoiceSearchResults.value.length === 0) return;
  highlightedIdx.value = (highlightedIdx.value + 1) % invoiceSearchResults.value.length;
}

function selectPrevResult() {
  if (invoiceSearchResults.value.length === 0) return;
  highlightedIdx.value = highlightedIdx.value <= 0 ? invoiceSearchResults.value.length - 1 : highlightedIdx.value - 1;
}

function selectHighlightedResult() {
  if (highlightedIdx.value >= 0 && highlightedIdx.value < invoiceSearchResults.value.length) {
    selectInvoice(invoiceSearchResults.value[highlightedIdx.value]);
  }
}

const invoiceDropdownPosition = computed(() => {
  // Access dropdownPositionUpdate to create dependency for reactivity
  dropdownPositionUpdate.value;

  if (!invoiceSearchInputRef.value) {
    return { top: '0', left: '0', width: '0' };
  }

  const rect = invoiceSearchInputRef.value.getBoundingClientRect();
  const inputWidth = rect.width;

  return {
    top: `${rect.bottom + 4}px`,
    left: `${rect.left}px`,
    width: `${inputWidth}px`
  };
});

async function selectInvoice(invoice) {
  if (!canReturnInvoice(invoice)) {
    showToast('لا يمكن إنشاء مرتجع لفاتورة مبيعات غير معتمدة أو ملغاة.', 'error');
    return;
  }

  // تعيين طريقة الدفع الافتراضية من الفاتورة أو أول خيار
  if (invoice.payment_method_id) {
    paymentMethodId.value = invoice.payment_method_id;
  } else {
    paymentMethodId.value = paymentMethods.value.length ? paymentMethods.value[0].id : null;
  }
  isSearchingInvoice.value = true;
  try {
    let items = invoice.items;
    let invoiceDetails = {};
    if (!items) {
      if (props.type === 'sales') {
        const itemsRes = await apiClient.get(`/sales/${invoice.id}`);
        invoiceDetails = itemsRes.data?.data || {};
        items = invoiceDetails.items || [];
      } else {
        const itemsRes = await apiClient.get('/returns/invoice-items', { params: { type: 'purchase', invoice_id: invoice.id } });
        invoiceDetails = itemsRes.data?.data || {};
        items = invoiceDetails.items || [];
      }
      if (invoiceDetails.payment_method_id) {
        paymentMethodId.value = invoiceDetails.payment_method_id;
      }
    }
    // استخدام API الجديد الذي يعيد item_id
    let availableItemsMap = {};
    try {
      const sumRes = await apiClient.get('/returns/returned-qty', {
        params: {
          type: normalizedType.value,
          invoice_id: invoice.id
        }
      });
      const rows = sumRes.data?.data || [];

      // Aggregate historical quantities by product and unit, then allocate them
      // across duplicate invoice lines in display order.
      availableItemsMap = rows.reduce((acc, r) => {
        acc[`${r.product_id}:${r.unit_id || 0}`] = {
          product_id: Number(r.product_id),
          unit_id: Number(r.unit_id || 0),
          original_qty: Number(r.original_qty || 0),
          returned_qty: Number(r.returned_qty || 0),
          remaining_qty: Number(r.remaining_qty || 0)
        };
        return acc;
      }, {});
    } catch (e) {
      console.error('Error fetching available quantities:', e);
      availableItemsMap = {};
    }

    const returnedRemainingByGroup = Object.fromEntries(
      Object.entries(availableItemsMap).map(([key, value]) => [key, value.returned_qty])
    );
    const sourceGroupPrices = items.reduce((groups, item) => {
      const key = `${item.product_id}:${item.unit_id || 0}`;
      const quantity = Number(item[quantityKey.value] || 0);
      const netAmount = props.type === 'sales'
        ? Number(item.net_total || 0)
        : Number(item.subtotal || 0) - Number(item.tax_amount || 0);
      groups[key] ||= { quantity: 0, netAmount: 0, taxAmount: 0 };
      groups[key].quantity += quantity;
      groups[key].netAmount += netAmount;
      groups[key].taxAmount += Number(item.tax_amount || 0);
      return groups;
    }, {});
    selectedInvoice.value = {
      ...invoice,
      ...invoiceDetails,
      items: items.map(item => {
        // استخدام item.id (sale_item_id أو purchase_item_id)
        const itemId = item.id;
        const groupKey = `${item.product_id}:${item.unit_id || 0}`;
        const s = availableItemsMap[groupKey] || null;
        const quantity = Number(item[quantityKey.value] || 0);
        const prev = s
          ? Math.min(quantity, Number(returnedRemainingByGroup[groupKey] || 0))
          : 0;
        if (s) returnedRemainingByGroup[groupKey] = Math.max(0, Number(returnedRemainingByGroup[groupKey] || 0) - prev);
        const remaining = Math.max(0, quantity - prev);
        const sourceGroup = sourceGroupPrices[groupKey];

        return {
          ...item,
          item_id: itemId,           // حفظ item_id
          return_unit_price: sourceGroup?.quantity > 0 ? sourceGroup.netAmount / sourceGroup.quantity : 0,
          return_tax_unit_amount: sourceGroup?.quantity > 0 ? sourceGroup.taxAmount / sourceGroup.quantity : 0,
          prevReturned: prev,
          remainingQty: remaining,
          returnQuantity: 0
        };
      })
    };
    returnItems.value = [];
    invoiceNumber.value = invoice.invoice_number;
    invoiceSearchResults.value = [];
    isFullReturn.value = true;
    paidAmount.value = 0;
    refundMode.value = isSale.value ? 'credit_note' : 'auto';
    setFullReturn();
    // تعيين رقم الفاتورة للطباعة
    invoiceId.value = invoice.id;
    // تعيين الطرف (عميل أو مورد) بناءً على نوع الفاتورة
    if (invoice.customer_id) partyId.value = invoice.customer_id;
    else if (invoice.supplier_id) partyId.value = invoice.supplier_id;
    else partyId.value = null;
    if (isSale.value && !partyId.value && canRefundUnlinkedCash.value) {
      refundMode.value = 'cash';
    } else if (
      isSale.value
      && partyId.value
      && canRefundExcessNow.value
      && customerTotalOutstanding.value === 0
    ) {
      refundMode.value = 'refund_excess';
    }
  } catch (error) {
    selectedInvoice.value = null;
    showToast('تعذر جلب تفاصيل الفاتورة', 'error');
  } finally {
    isSearchingInvoice.value = false;
  }
}

function setFullReturn() {
  if (selectedInvoice.value && selectedInvoice.value.items) {
    selectedInvoice.value.items.forEach(item => {
      const maxQty = Number(item.remainingQty ?? item[quantityKey.value] ?? 0);
      item.returnQuantity = isFullReturn.value ? maxQty : 0;
    });
    returnItems.value = isFullReturn.value
      ? selectedInvoice.value.items
          .filter(item => Number(item.returnQuantity) > 0 && !isNaN(Number(item[priceKey.value])) && Number(item[priceKey.value]) > 0)
          .map(item => ({ ...item, [priceKey.value]: Number(item[priceKey.value]) }))
      : [];
    if (isFullReturn.value && returnItems.value.length === 0) {
      showToast('لا توجد كميات متاحة وبسعر صالح للإرجاع في هذه الفاتورة.', 'error');
    }
  }
}

function addToReturnList(item) {
  if (!item.returnQuantity || item.returnQuantity < 1) return showToast('يرجى تحديد كمية صالحة', 'warning');
  const maxQty = Number(item.remainingQty ?? item[quantityKey.value] ?? 0);
  if (item.returnQuantity > maxQty) return showToast(`الكمية المتاحة هي ${maxQty} فقط`, 'warning');
  if (maxQty <= 0) return showToast('لا يوجد رصيد متبقٍ للإرجاع لهذا المنتج', 'warning');

  // استخدام item_id بدلاً من id للبحث
  const existingItem = returnItems.value.find(i => i.item_id === item.item_id);
  if (existingItem) {
    existingItem.returnQuantity = item.returnQuantity;
    existingItem[priceKey.value] = Number(item[priceKey.value]);
  } else {
    returnItems.value.push({
      ...item,
      [priceKey.value]: Number(item[priceKey.value])
    });
  }
  showToast('تمت الإضافة إلى قائمة الإرجاع', 'success');
}

async function submitReturn() {
  if (returnItems.value.length === 0) return showToast('قائمة الإرجاع فارغة!', 'warning');
  if (!returnReason.value.trim()) return showToast('يرجى كتابة سبب الإرجاع', 'warning');

  if (!paymentMethodId.value) return showToast('يرجى اختيار طريقة الدفع', 'warning');
  const selectedPM = paymentMethods.value.find(pm => Number(pm.id) === Number(paymentMethodId.value));
  if (selectedPM?.kind === 'credit' && Number(paidAmount.value) > 0) {
    return showToast('طريقة الدفع الآجلة لا تقبل مبلغاً مسترداً — استخدم credit_note أو اختر طريقة دفع أخرى', 'error');
  }
  if (!invoiceId.value) return showToast('رقم الفاتورة غير محدد', 'warning');

  // تحقق من الحقول داخل كل عنصر
  for (const [index, item] of returnItems.value.entries()) {
    const requiredFields = ['product_id', 'unit_id', 'returnQuantity', priceKey.value];
    for (const field of requiredFields) {
      if (!item[field] && item[field] !== 0) {
        return showToast(`العنصر رقم ${index + 1} ناقص فيه الحقل: ${field}`, 'error');
      }
    }
  }

  isSubmittingReturn.value = true;
  try {
    const user = JSON.parse(localStorage.getItem('user')) || {};
    const today = new Date();
    const formattedDate = getLocalDateISO(today); // YYYY-MM-DD (local)
    const returnData = {
      return_type: normalizedType.value,
      return_date: formattedDate,
      invoice_id: invoiceId.value,
      party_id: partyId.value,
      items: returnItems.value.map(item => ({
        // إضافة sale_item_id أو purchase_item_id
        sale_item_id: normalizedType.value === 'sale' ? item.item_id : undefined,
        purchase_item_id: normalizedType.value === 'purchase' ? item.item_id : undefined,
        product_id: item.product_id,
        unit_id: item.unit_id,
        quantity: item.returnQuantity,
        unit_price: item[priceKey.value],
        subtotal: item[priceKey.value] * item.returnQuantity
      })),
      paid_amount: paidAmount.value,
      payment_method_id: paymentMethodId.value,
      refund_mode: refundMode.value,

      notes: returnReason.value,
      cashier_id: user.id,
      branch_id: selectedInvoice.value?.branch_id ?? user.branch_id
    };
    const res = await returnStore.createReturn(returnData);
    if (res.status !== 'success') throw new Error(res.message || 'فشل تسجيل المرتجع');
    const result = res.data || {};

    // أبلغ الواجهة عن تسجيل مرتجع بنجاح لتحديث درج النقدية (إن كان نقديًا)
    try {
      const detail = {
        returnId: result.return_id || Date.now(),
        return_type: returnData.return_type,
        saleId: invoiceId.value,
        customerId: partyId.value,
        totalRefunded: Number(returnData.paid_amount || 0),
        payment_method_id: Number(returnData.payment_method_id),
        paid_amount: Number(returnData.paid_amount || 0)
      };
      window.dispatchEvent(new CustomEvent('pos:return-recorded', { detail }));
    } catch (_) { /* ignore */ }
    showToast('تم تسجيل المرتجع بنجاح', 'success');

    // إرسال البيانات الحقيقية إلى الـ parent
    emit('returnSuccess', {
      returnId: result.return_id || Date.now(),
      saleId: invoiceId.value,
      customerId: partyId.value,
      totalRefunded: Number(returnData.paid_amount || 0),
      paymentMethodId: paymentMethodId.value
    });
    // إعادة تعيين النموذج
    selectedInvoice.value = null;
    returnItems.value = [];
    returnReason.value = '';
    paidAmount.value = 0;
    paymentMethodId.value = paymentMethods.value[0]?.id || null;
    discountType.value = 'fixed';
    discountValue.value = 0;
    partyId.value = null;
    invoiceId.value = null;
  } catch (error) {
    showToast(error?.response?.data?.message || error?.message || 'حدث خطأ أثناء تسجيل المرتجع', 'error');
  } finally {
    isSubmittingReturn.value = false;
  }
}

defineExpose({ submitReturn, isSubmittingReturn, canSubmitReturn });
</script>

<style scoped>
.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}
/* Transitions */
.dropdown-enter-active { animation: dropdownIn 0.2s ease-out; }
@keyframes dropdownIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
.animate-fadeIn { animation: fadeIn 0.3s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
</style>