<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">

    <PageLoadingBar :active="statementLoading" />

    <main class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      <PageHeader
        :breadcrumb="breadcrumb"
        :title="title"
        :description="isCustomer
          ? 'الفواتير والتحصيلات والحركات المالية ورصيد العميل خلال الفترة المحددة.'
          : 'الفواتير والمدفوعات والحركات المالية ورصيد المورد خلال الفترة المحددة.'"
        :branches="[]"
      >
        <template #controls>
          <button
            @click="showFilters = !showFilters"
            :class="[showFilters ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 border-slate-200']"
            class="h-9 px-4 rounded-md border text-xs font-bold transition-all flex items-center gap-2 shadow-sm"
          >
            <i class="fas fa-filter text-[10px]"></i>
            {{ showFilters ? 'إخفاء أدوات التصفية' : 'تصفية النتائج' }}
          </button>
          <RouterLink :to="`/contacts/${type}/${id}/details`"
                      class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all flex items-center gap-2">
            <i class="fas fa-user text-[10px]"></i> تفاصيل {{ isCustomer ? 'العميل' : 'المورد' }}
          </RouterLink>
          <button v-if="isCustomer && invoicesTotals.due > 0" @click="openPaymentModal" class="h-9 px-4 rounded-md bg-emerald-600 text-white text-xs font-bold shadow-lg shadow-emerald-900/20 hover:bg-emerald-700 transition-all flex items-center gap-2">
            <i class="fas fa-hand-holding-usd text-[10px]"></i> تحصيل الآن
          </button>
          <button v-if="canExport" @click="exportPdf" :disabled="!data" class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-lg shadow-blue-900/20 hover:bg-blue-700 transition-all flex items-center gap-2 disabled:opacity-50">
            <i class="fas fa-print text-[10px]"></i> طباعة / PDF
          </button>
        </template>
      </PageHeader>

      <transition name="slide-down">
        <div v-if="showFilters" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden animate-fadeIn">
          <div class="p-6 space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">من تاريخ</label>
              <div class="relative">
                <input ref="startDateRef" type="date" v-model="startDate" @change="applyFilter" class="filter-input" style="padding-left: 2rem;" />
                <i @click="startDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer text-slate-300 hover:text-slate-500 transition-colors text-[10px]"></i>
              </div>
            </div>
            <div class="space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">إلى تاريخ</label>
              <div class="relative">
                <input ref="endDateRef" type="date" v-model="endDate" @change="applyFilter" class="filter-input" style="padding-left: 2rem;" />
                <i @click="endDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer text-slate-300 hover:text-slate-500 transition-colors text-[10px]"></i>
              </div>
            </div>
            <div class="md:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">النطاقات الزمنية</label>
              <div class="flex flex-wrap gap-2">
                <button v-for="range in [
                  { id: 'last7', label: 'آخر 7 أيام' },
                  { id: 'thisMonth', label: 'هذا الشهر' },
                  { id: 'prevMonth', label: 'الشهر السابق' }
                ]" :key="range.id" @click="setQuickRange(range.id)" class="filter-input w-auto px-3 cursor-pointer">{{ range.label }}</button>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end pt-4 border-t border-slate-50">
            <div class="md:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">بحث نصي سريع</label>
              <div class="relative">
                <input v-model="searchText" type="text" placeholder="رقم فاتورة، مبلغ، وصف..." class="filter-input" style="padding-right: 2rem;" />
                <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
              </div>
            </div>
            <div class="space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">نوع العملية</label>
              <select v-model="typeFilter" @change="applyFilter" class="filter-input appearance-none">
                <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </div>
            <div class="space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">طريقة الدفع</label>
              <select v-model="paymentMethodId" @change="applyFilter" class="filter-input appearance-none">
                <option value="">كل الطرق</option>
                <option v-for="m in paymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end pt-4 border-t border-slate-50">
            <label class="flex items-center gap-2 cursor-pointer select-none text-[11px] font-bold text-slate-600">
                <input type="checkbox" v-model="fillGaps" @change="applyFilter" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-0">
                إظهار الأيام الخالية
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none text-[11px] font-bold text-slate-600">
                <input type="checkbox" v-model="onlyNonZero" @change="applyFilter" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-0">
                إخفاء الأرصدة الصفرية
              </label>
            <button @click="exportCsv" class="h-9 w-full rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all">
              <i class="fas fa-file-excel"></i> تصدير سجل CSV
            </button>
          </div>
          </div>
        </div>
      </transition>

      <div v-if="statementLoading" class="py-32 text-center bg-white border border-slate-200 rounded-xl shadow-sm">
        <BaseSpinner :size="40" color="#2563eb" />
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] mt-4 animate-pulse">جاري جلب وتحليل الحركات المالية...</p>
      </div>

      <div v-else-if="!data" class="py-32 text-center bg-white border border-slate-200 rounded-xl shadow-sm">
        <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center mx-auto mb-4">
          <i class="fas fa-search-dollar text-2xl"></i>
        </div>
        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-widest">لا توجد حركات</h3>
        <p class="text-xs text-slate-400 mt-1">يرجى تعديل الفلاتر أو تحديد نطاق زمني مختلف للبحث.</p>
      </div>

      <div v-else class="space-y-8 animate-fadeIn">

        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
          <div class="flex flex-col md:flex-row">
            <div class="p-8 flex-grow space-y-6">
              <div class="flex items-center gap-4">
                <div v-if="companyInfo.logo" class="w-14 h-14 rounded-lg border border-slate-100 p-2 flex items-center justify-center bg-white shadow-inner">
                  <img :src="companyInfo.logo" class="max-h-full object-contain" />
                </div>
                <div>
                  <h2 class="text-lg font-bold text-slate-900 leading-none">{{ companyInfo.name || 'المؤسسة' }}</h2>
                  <p class="text-[9px] text-slate-400 mt-1 uppercase tracking-[0.2em] font-bold">كشف حساب مالي رسمي</p>
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-[10px] font-bold text-slate-500">
                <p v-if="companyInfo.address" class="flex items-center gap-2"><i class="fas fa-map-marker-alt text-blue-500 w-3"></i> {{ companyInfo.address }}</p>
                <p v-if="companyInfo.phone" class="flex items-center gap-2"><i class="fas fa-phone-alt text-blue-500 w-3"></i> {{ companyInfo.phone }}</p>
              </div>
            </div>

            <div class="p-8 bg-slate-900 text-white min-w-[340px] relative">
              <div class="absolute top-0 left-0 w-32 h-32 bg-white/5 rounded-full -translate-x-12 -translate-y-12"></div>
              <div class="relative z-10 space-y-5">
                <div>
                  <p class="text-[9px] font-bold text-blue-400 uppercase tracking-[0.2em] mb-1">الجهة المعنية</p>
                  <h3 class="text-xl font-bold">{{ partyInfo.name || data.account?.name }}</h3>
                </div>
                <div class="grid grid-cols-2 gap-6 pt-4 border-t border-white/5">
                   <div><p class="text-[8px] text-white/30 uppercase mb-1">رقم الحساب</p><p class="text-xs font-mono font-bold">{{ data.account?.code }}</p></div>
                   <div><p class="text-[8px] text-white/30 uppercase mb-1">حالة الحساب</p><p class="text-[10px] font-bold text-emerald-400">نشط</p></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <section class="grid grid-cols-2 lg:grid-cols-4 gap-6">
          <div v-for="stat in [
            { l: 'الرصيد الافتتاحي', v: data.openingBalance, c: 'text-slate-600', bg: 'bg-white' },
            { l: 'إجمالي مدين (+)', v: data.totalDebit, c: 'text-rose-600', bg: 'bg-white' },
            { l: 'إجمالي دائن (-)', v: data.totalCredit, c: 'text-emerald-600', bg: 'bg-white' },
            { l: 'الرصيد الختامي', v: data.closingBalance, c: 'text-blue-700', bg: 'bg-blue-50/50 border-blue-100' }
          ]" :key="stat.l" :class="[stat.bg]" class="border border-slate-200 p-5 rounded-xl shadow-sm space-y-1 group hover:border-slate-300 transition-all">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ stat.l }}</p>
            <p :class="[stat.c, 'text-xl font-bold font-mono tracking-tighter']">{{ formatPriceEn(stat.v) }} <span class="text-[10px] opacity-40 font-sans mr-1">{{ currencySymbol }}</span></p>
          </div>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <button v-for="tab in [
            { id: 'accounting', label: 'تفاصيل الحركات', sub: 'سجل القيود اليومية والأرصدة المتراكمة', icon: 'fa-list-ul', active: 'border-blue-500 bg-white ring-4 ring-blue-500/5' },
            { id: 'daily', label: 'الملخص اليومي', sub: 'تجميع الأرصدة الختامية لكل يوم عمل', icon: 'fa-calendar-alt', active: 'border-emerald-500 bg-white ring-4 ring-emerald-500/5' },
            { id: 'references', label: 'سجل المراجع', sub: 'الفواتير، السندات المرجعية والمدفوعات', icon: 'fa-project-diagram', active: 'border-purple-500 bg-white ring-4 ring-purple-500/5' }
          ]" :key="tab.id" @click="activeTab = tab.id" 
             :class="[activeTab === tab.id ? tab.active : 'bg-white border-slate-200 hover:border-slate-300 hover:bg-slate-50/30']"
             class="p-5 border-2 rounded-xl text-right transition-all group relative overflow-hidden">
            <div class="flex items-center gap-4 relative z-10">
              <div :class="[activeTab === tab.id ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-400']" class="w-10 h-10 rounded-lg flex items-center justify-center transition-colors">
                <i :class="['fas', tab.icon]"></i>
              </div>
              <div>
                <p class="text-sm font-bold text-slate-900">{{ tab.label }}</p>
                <p class="text-[10px] text-slate-400 font-medium mt-0.5">{{ tab.sub }}</p>
              </div>
            </div>
          </button>
        </section>

        <transition name="fade">
          <div v-if="hasOverdueAlert" class="bg-rose-50 border border-rose-200 rounded-xl p-5 flex items-center justify-between shadow-sm">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 bg-white rounded-lg flex items-center justify-center text-rose-600 shadow-sm border border-rose-100 shrink-0">
                <i class="fas fa-exclamation-triangle text-lg"></i>
              </div>
              <div>
                <p class="text-xs font-bold text-rose-900 uppercase tracking-wide">تنبيه: مديونية متأخرة</p>
                <p class="text-sm font-medium text-rose-700 mt-1">يوجد <strong>{{ overdueInvoices.length }}</strong> فاتورة متجاوزة لفترة الائتمان (30+ يوم) بإجمالي <strong>{{ formatPriceEn(overdueInvoices.reduce((s, it) => s + Number(it.outstanding || it.netTotalAmount - (it.paidAmount || 0)), 0)) }}</strong></p>
              </div>
            </div>
            <button @click="activeTab = 'references'; referencesSubTab = 'invoices'" class="h-9 px-5 bg-rose-600 text-white rounded-md text-[10px] font-bold uppercase tracking-widest hover:bg-rose-700 transition-all">فحص الفواتير</button>
          </div>
        </transition>

        <div class="space-y-8">
          
          <div v-if="activeTab === 'accounting'" class="space-y-6 animate-fadeIn">
            <div class="flex bg-slate-100 p-1 rounded-lg border border-slate-200/50 w-fit">
               <button v-for="mode in [
                 {id: 'detailed', label: 'تفصيلي', icon: 'fa-list-ul'},
                 {id: 'daily', label: 'يومي مجمع', icon: 'fa-calendar-day'},
                 {id: 'by_type', label: 'حسب النوع', icon: 'fa-layer-group'}
               ]" :key="mode.id" @click="viewMode = mode.id; applyFilter();"
               :class="[viewMode === mode.id ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700']"
               class="px-5 py-1.5 rounded-md text-[10px] font-bold transition-all flex items-center gap-2">
                 <i :class="['fas', mode.icon, 'text-[9px]']"></i> {{ mode.label }}
               </button>
            </div>

            <div v-if="viewMode === 'detailed'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
              <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                  <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                      <th class="px-4 py-3">التاريخ</th>
                      <th class="px-4 py-3">المستند / الحركة</th>
                      <th class="px-4 py-3">البيان</th>
                      <th class="px-4 py-3">مدين (+)</th>
                      <th class="px-4 py-3">دائن (-)</th>
                      <th class="px-4 py-3 text-left">الرصيد</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 font-medium text-xs">
                    <tr v-if="!data.transactions?.length">
                      <td colspan="6" class="py-20 text-center text-slate-300 font-bold uppercase tracking-widest italic">لا توجد حركات مسجلة للفترة المحددة</td>
                    </tr>
                    <tr v-for="(t, idx) in pagedTransactions" :key="idx" :class="[rowClass(t), 'hover:bg-blue-50/20 transition-all group']">
                      <td class="px-4 py-3 text-[10px] font-mono font-bold text-slate-400 group-hover:text-slate-900 whitespace-nowrap">{{ formatDateEn(t.date) }}</td>
                      <td class="px-4 py-3 min-w-[190px]">
                        <div class="flex flex-wrap items-center gap-2">
                          <RouterLink v-if="invoiceLink(t)" :to="invoiceLink(t)" class="text-blue-600 hover:underline font-bold">
                            {{ transactionDocumentLabel(t) }}
                          </RouterLink>
                          <span v-else class="text-slate-900 font-bold">{{ transactionDocumentLabel(t) }}</span>
                          <span :class="['px-2 py-0.5 rounded text-[9px] font-bold border whitespace-nowrap', badgeClass(t.type || t.transactionType || t.referenceType || (t.reference || '').split('#')[0])]">
                            {{ getTransactionTypeLabel(t.type || t.transactionType || t.referenceType || (t.reference || '').split('#')[0]) || 'حركة' }}
                          </span>
                        </div>
                        <div v-if="transactionRelatedReference(t) !== '—'" class="text-[9px] text-slate-400 mt-1 truncate max-w-[220px]" :title="transactionRelatedReference(t)">
                          {{ transactionRelatedReference(t) }}
                        </div>
                      </td>
                      <td class="px-4 py-3">
                        <p class="text-slate-500 font-medium max-w-[320px] whitespace-normal break-words" :title="t.description">{{ t.description || '—' }}</p>
                        <span v-if="getAllocatedInvoice(t)" class="text-[9px] text-indigo-700 mt-1 inline-block">تسوية: {{ getAllocatedInvoice(t) }}</span>
                      </td>
                      <td class="px-4 py-3 text-rose-600 font-mono tracking-tighter whitespace-nowrap">{{ t.debit > 0 ? formatPriceEn(t.debit) : '—' }}</td>
                      <td class="px-4 py-3 text-emerald-600 font-mono tracking-tighter whitespace-nowrap">{{ t.credit > 0 ? formatPriceEn(t.credit) : '—' }}</td>
                      <td class="px-4 py-3 font-bold font-mono tracking-tighter text-left whitespace-nowrap" :class="getBalanceColorClass(t)">
                        <!-- ✅ RESTORED: أيقونات اتجاه الرصيد -->
                        <span class="inline-flex items-center gap-1">
                          <i v-if="String(t.balanceNature || 'zero').toLowerCase() === 'debit'" class="fas fa-arrow-up text-[9px]"></i>
                          <i v-else-if="String(t.balanceNature || 'zero').toLowerCase() === 'credit'" class="fas fa-arrow-down text-[9px]"></i>
                          {{ getBalanceWithNature(t) }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot class="bg-slate-50/50 border-t border-slate-200">
                    <tr class="font-bold text-xs text-slate-900">
                      <td colspan="3" class="px-4 py-3 text-[10px] text-slate-400 uppercase tracking-widest">إجمالي الحركات المعروضة</td>
                      <td class="px-4 py-3 text-rose-600 font-mono">{{ formatPriceEn(pageTotals.debit) }}</td>
                      <td class="px-4 py-3 text-emerald-600 font-mono">{{ formatPriceEn(pageTotals.credit) }}</td>
                      <td class="px-4 py-3 font-mono text-left">{{ formatPriceEn(pageTotals.closing || 0) }}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <div class="px-6 py-4 bg-slate-50/30 border-t border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-4">
                  <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">الصفحة: <span class="text-slate-900 font-mono">{{ txPage }} / {{ txPages }}</span></span>
                  <select v-model.number="txPerPage" class="h-7 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none bg-white" @change="txPage = 1; applyFilter()">
                    <option :value="10">10 حركات</option><option :value="20">20 حركة</option><option :value="50">50 حركة</option>
                  </select>
                </div>
                <PaginationControls
                  :page="txPage"
                  :total-pages="txPages"
                  @previous="txPage = Math.max(1, txPage - 1); applyFilter()"
                  @next="txPage = Math.min(txPages, txPage + 1); applyFilter()"
                />
              </div>
            </div>

            <div v-else-if="viewMode === 'daily'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
              <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                  <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                      <th class="px-8 py-4">تاريخ الحركة</th>
                      <th class="px-4 py-4">رصيد الافتتاح</th>
                      <th class="px-4 py-4 text-rose-500">إجمالي مدين (+)</th>
                      <th class="px-4 py-4 text-emerald-500">إجمالي دائن (-)</th>
                      <th class="px-4 py-4">رصيد الإغلاق</th>
                      <th class="px-8 py-4 text-center">عدد العمليات</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 font-medium text-xs">
                    <tr v-if="!dailyBalances.length">
                      <td colspan="6" class="text-center py-16 text-slate-300 font-bold uppercase tracking-widest italic">لا توجد حركات يومية ضمن الفترة المحددة.</td>
                    </tr>
                    <tr v-for="d in dailyBalances" :key="d.date" class="hover:bg-slate-50 transition-all font-bold">
                      <td class="px-8 py-4 text-slate-900 font-mono tracking-tighter">{{ formatDateEn(d.date) }}</td>
                      <td class="px-4 py-4 text-slate-400 font-mono">{{ formatPriceEn(d.openingBalance) }}</td>
                      <td class="px-4 py-4 text-rose-600 font-mono tracking-tighter">{{ formatPriceEn(d.dayDebit) }}</td>
                      <td class="px-4 py-4 text-emerald-600 font-mono tracking-tighter">{{ formatPriceEn(d.dayCredit) }}</td>
                      <td class="px-4 py-4 font-mono text-sm" :class="d.closingBalance === 0 ? 'text-slate-500' : (d.closingBalance < 0 ? 'text-emerald-700' : 'text-rose-700')">{{ formatPriceEn(d.closingBalance) }}</td>
                      <td class="px-8 py-4 text-center">
                        <span class="bg-slate-100 text-slate-500 px-3 py-1 rounded text-[10px] font-bold">{{ new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(d.transactionCount || 0)) }}</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- ✅ RESTORED: وضع العرض "حسب النوع" (كان مفقوداً بالكامل، والـ groupedByType بقت dead code) -->
            <div v-else-if="viewMode === 'by_type'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
              <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                  <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                      <th class="px-8 py-4">نوع المستند</th>
                      <th class="px-8 py-4 text-center">عدد السجلات</th>
                      <th class="px-4 py-4">إجمالي مدين (+)</th>
                      <th class="px-4 py-4">إجمالي دائن (-)</th>
                      <th class="px-4 py-4">صافي الحركة</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 font-medium text-xs">
                    <tr v-if="!groupedByType.length">
                      <td colspan="5" class="text-center py-16 text-slate-300 font-bold uppercase tracking-widest italic">لا توجد حركات ضمن الفترة المحددة.</td>
                    </tr>
                    <tr v-for="g in groupedByType" :key="g.type" class="hover:bg-slate-50 transition-all">
                      <td class="px-8 py-4 font-bold text-slate-800">{{ g.label }}</td>
                      <td class="px-8 py-4 text-center">
                        <span class="bg-blue-50 text-blue-600 px-3 py-1 rounded text-[10px] font-bold">{{ new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(g.count || 0)) }}</span>
                      </td>
                      <td class="px-4 py-4 text-rose-600 font-bold font-mono">{{ formatPriceEn(g.debit) }}</td>
                      <td class="px-4 py-4 text-emerald-600 font-bold font-mono">{{ formatPriceEn(g.credit) }}</td>
                      <td class="px-4 py-4 font-bold font-mono text-sm">{{ formatPriceEn((g.debit || 0) - (g.credit || 0)) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div v-if="canViewAgingAnalysis && isCustomer && data.agingSummary" class="bg-white border border-slate-200 rounded-xl p-8 shadow-sm space-y-6">
              <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest px-1">تحليل أعمار المديونية (Aging Report)</h3>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
                <div v-for="age in [
                  { l: 'حالي (غير مستحق)', v: data.agingSummary.current, s: agingCounts.current, c: 'text-emerald-600', bg: 'bg-emerald-500' },
                  { l: '1 - 30 يوم', v: data.agingSummary['1_30_days'], s: agingCounts['1_30_days'], c: 'text-amber-500', bg: 'bg-amber-400' },
                  { l: '31 - 60 يوم', v: data.agingSummary['31_60_days'], s: agingCounts['31_60_days'], c: 'text-orange-500', bg: 'bg-orange-500' },
                  { l: '+60 يوم', v: data.agingSummary['60_plus_days'], s: agingCounts['60_plus_days'], c: 'text-rose-600', bg: 'bg-rose-600' }
                ]" :key="age.l" class="space-y-3">
                  <div class="flex justify-between items-end">
                    <p class="text-[10px] font-bold text-slate-400 uppercase leading-none">{{ age.l }}</p>
                    <p class="text-lg font-bold font-mono tracking-tighter" :class="age.c">{{ formatPrice(age.v) }}</p>
                  </div>
                  <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-1000" :class="age.bg" :style="{ width: Math.max((age.v / Math.max(agingBreakdown.current + agingBreakdown._1to30 + agingBreakdown._31to60 + agingBreakdown._60plus, 1)) * 100, 2) + '%' }"></div>
                  </div>
                  <p class="text-[9px] font-bold text-slate-400 opacity-60">عدد الفواتير: {{ age.s || 0 }}</p>
                </div>
              </div>
            </div>
          </div>

          <div v-else-if="activeTab === 'daily'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm animate-fadeIn">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
              <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest">تجميع الأرصدة اليومية</h3>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-right border-collapse">
                <thead>
                  <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                    <th class="px-8 py-4">التاريخ</th>
                    <th class="px-4 py-4 text-slate-300">الرصيد السابق</th>
                    <th class="px-4 py-4 text-rose-500">مدين (+)</th>
                    <th class="px-4 py-4 text-emerald-500">دائن (-)</th>
                    <th class="px-4 py-4 text-slate-600">صافي اليوم</th>
                    <th class="px-8 py-4 text-left">الرصيد الختامي</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 font-medium text-xs">
                  <tr v-for="d in dailyBalances" :key="d.date" class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-8 py-4 font-mono font-bold">{{ formatDateEn(d.date) }}</td>
                    <td class="px-4 py-4 font-mono text-slate-300">{{ formatPriceEn(d.openingBalance) }}</td>
                    <td class="px-4 py-4 font-mono text-rose-600">{{ formatPriceEn(d.dayDebit) }}</td>
                    <td class="px-4 py-4 font-mono text-emerald-600">{{ formatPriceEn(d.dayCredit) }}</td>
                    <td class="px-4 py-4 font-mono text-slate-600">{{ formatPriceEn(d.dayDebit - d.dayCredit) }}</td>
                    <td class="px-8 py-4 font-bold font-mono text-left text-sm" :class="d.closingBalance === 0 ? 'text-slate-400' : (d.closingBalance < 0 ? 'text-emerald-700' : 'text-rose-700')">
                      {{ formatPriceEn(d.closingBalance) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div v-else-if="activeTab === 'references'" class="space-y-6 animate-fadeIn">
            <div class="flex bg-slate-100 p-1 rounded-lg border border-slate-200/50 w-fit">
               <button v-for="sub in [
                 {id: 'invoices', label: isCustomer ? 'فواتير المبيعات' : 'فواتير الشراء', icon: 'fa-file-invoice-dollar'},
                 {id: 'vouchers', label: 'سندات القبض/الصرف', icon: 'fa-list-check'},
                 {id: 'payments', label: 'سجل المدفوعات', icon: 'fa-hand-holding-usd'}
               ]" :key="sub.id" @click="referencesSubTab = sub.id"
               :class="[referencesSubTab === sub.id ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700']"
               class="px-5 py-1.5 rounded-md text-[10px] font-bold transition-all flex items-center gap-2">
                 <i :class="['fas', sub.icon, 'text-[9px]']"></i> {{ sub.label }}
               </button>
            </div>

            <div v-if="referencesSubTab === 'invoices'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
              <!-- ✅ RESTORED: عداد الفواتير المتأخرة -->
              <div class="px-6 py-4 bg-slate-50/30 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 flex-wrap">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ salesOnlySorted.length }} فاتورة في السجل</span>
                <div class="flex gap-4 text-[10px] font-bold flex-wrap">
                   <span class="text-slate-400 uppercase">إجمالي: <span class="text-slate-900 font-mono">{{ formatPriceEn(invoicesTotals.total) }}</span></span>
                   <span class="text-slate-400 uppercase">المتبقي: <span class="text-rose-600 font-mono">{{ formatPriceEn(invoicesTotals.due) }}</span></span>
                   <span v-if="salesOnlySorted.length" class="text-slate-400 uppercase">متأخرة: <span class="text-rose-500 font-mono">{{ salesOnlySorted.filter(s => getItemOutstanding(s) > 0 && daysBetween(s.date) > 0).length }}</span></span>
                </div>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse text-xs">
                  <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                      <th class="px-6 py-4">التاريخ</th>
                      <th class="px-4 py-4">رقم الفاتورة</th>
                      <!-- ✅ RESTORED: الأعمدة الخمسة التالية -->
                      <th class="px-4 py-4">المرجع</th>
                      <th class="px-4 py-4 text-center">الحالة</th>
                      <th class="px-4 py-4 text-right">الأصناف</th>
                      <th class="px-4 py-4 text-right">الصافي</th>
                      <th class="px-4 py-4 text-right">الضريبة</th>
                      <th class="px-4 py-4 text-right">الخصم</th>
                      <th class="px-4 py-4 text-right">الإجمالي</th>
                      <th class="px-4 py-4 text-right">المدفوع</th>
                      <th class="px-6 py-4 text-left">المتبقي</th>
                      <th class="px-4 py-4 text-center">أيام التأخير</th>
                      <th class="px-4 py-4">قيد محاسبي؟</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-50 font-medium">
                    <tr v-for="s in salesOnlySorted" :key="s.id" class="hover:bg-slate-50 transition-all">
                      <td class="px-6 py-4 text-slate-400 font-mono">{{ formatDateEn(s.date) }}</td>
                      <td class="px-4 py-4">
                        <RouterLink v-if="invoiceLink(s)" :to="invoiceLink(s)" class="text-blue-600 hover:underline font-bold">
                          {{ s.invoiceNumber || ('#' + s.id) }}
                        </RouterLink>
                        <span v-else class="font-bold text-slate-900">{{ s.invoiceNumber || ('#' + s.id) }}</span>
                      </td>
                      <td class="px-4 py-4">
                        <div class="font-semibold text-slate-700">{{ s.reference || '-' }}</div>
                        <div class="text-[9px] text-slate-400 mt-0.5">{{ s.referenceLabel || refTypeLabel(s.reference) }}</div>
                      </td>
                      <td class="px-4 py-4 text-center">
                        <span :class="['px-2 py-0.5 rounded text-[9px] font-bold border', badgeClass(displayStatusCode(s, s.status))]">
                          {{ displayStatusLabel(s, s.status, s.statusLabel) }}
                        </span>
                      </td>
                      <td class="px-4 py-4 text-right font-mono text-slate-400">{{ new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(s.itemsCount ?? 0)) }}</td>
                      <td class="px-4 py-4 text-right font-mono text-slate-500">{{ formatPriceEn(s.netTotalAmount) }}</td>
                      <td class="px-4 py-4 text-right font-mono text-slate-500">{{ formatPriceEn(s.taxAmount) }}</td>
                      <td class="px-4 py-4 text-right">
                        <span v-if="(s.discountValue || 0) > 0">
                          <template v-if="s.discountType === 'percentage'">{{ Number(s.discountValue).toFixed(2) }}%</template>
                          <template v-else>{{ formatPriceEn(s.discountValue) }}</template>
                        </span>
                        <span v-else class="text-slate-300">-</span>
                      </td>
                      <td class="px-4 py-4 text-right font-mono text-slate-900 font-bold">{{ formatPriceEn(s.totalAmount) }}</td>
                      <td class="px-4 py-4 text-right font-mono text-emerald-600">{{ formatPriceEn(s.paidAmount) }}</td>
                      <td class="px-6 py-4 text-left font-mono font-bold" :class="getItemOutstanding(s) > 0 ? 'text-rose-600' : 'text-slate-200'">{{ formatPriceEn(getItemOutstanding(s)) }}</td>
                      <td class="px-4 py-4 text-center">
                        <span v-if="getItemOutstanding(s) > 0 && daysBetween(s.date) > 0" class="text-rose-500 font-black">
                          {{ new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(daysBetween(s.date)) }} ي
                        </span>
                        <span v-else class="text-slate-200">—</span>
                      </td>
                      <td class="px-4 py-4">
                        <span v-if="s.hasJournal" class="text-emerald-600 font-semibold text-[10px] flex items-center gap-1">
                          <i class="fas fa-check-circle"></i> قيد #{{ s.journalEntryId || '-' }}
                        </span>
                        <span v-else class="text-slate-300 text-[10px]">لا</span>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot class="bg-slate-50/80 font-bold border-t-2 border-slate-100">
                    <tr>
                      <td class="px-6 py-4 text-[10px] text-slate-400 uppercase tracking-widest" colspan="8">
                        <i class="fas fa-chart-bar"></i> ملخص الفواتير
                      </td>
                      <td colspan="5" class="px-6 py-4">
                        <div class="grid grid-cols-3 gap-4 text-center">
                          <div class="p-3 bg-slate-100/50 rounded-lg">
                            <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-1">إجمالي الفواتير</div>
                            <div class="text-base font-bold text-slate-900 font-mono">{{ formatPriceEn(invoicesTotals.total) }}</div>
                          </div>
                          <div class="p-3 bg-emerald-50 rounded-lg border border-emerald-100">
                            <div class="text-[10px] text-emerald-600 font-bold uppercase tracking-wider mb-1">المدفوع</div>
                            <div class="text-base font-bold text-emerald-700 font-mono">{{ formatPriceEn(invoicesTotals.paid) }}</div>
                          </div>
                          <div class="p-3 bg-rose-50 rounded-lg border border-rose-100">
                            <div class="text-[10px] text-rose-600 font-bold uppercase tracking-wider mb-1">المتبقي</div>
                            <div class="text-base font-bold text-rose-700 font-mono">{{ formatPriceEn(invoicesTotals.due) }}</div>
                          </div>
                        </div>
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>

            <!-- ✅ RESTORED: تبويب "السندات المرجعية" — كان بديل بجملة نصية عامة بدون بيانات -->
            <div v-else-if="referencesSubTab === 'vouchers'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
              <div class="px-6 py-4 bg-slate-50/30 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                  <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">السندات والمراجع ({{ referencesItems.length }} مستند)</span>
                  <p class="mt-1 text-[10px] text-slate-500">عمليات القبض والصرف تظهر أيضًا في تبويب سجل المدفوعات.</p>
                </div>
                <div class="flex items-center gap-3">
                  <button v-if="referencesItems.length" @click="exportReferencesCsv" class="px-4 py-1.5 rounded-md bg-emerald-50 text-emerald-600 text-[10px] font-bold uppercase tracking-widest hover:bg-emerald-600 hover:text-white transition-all">تصدير CSV</button>
                  <PaginationControls
                    v-if="referencesItems.length"
                    :page="refsPage"
                    :total-pages="referencesPages"
                    show-page-indicator
                    @previous="refsPage = Math.max(1, refsPage - 1)"
                    @next="refsPage = Math.min(referencesPages, refsPage + 1)"
                  />
                </div>
              </div>

              <div v-if="pagedReferences.length" class="overflow-x-auto">
                <table class="w-full text-right border-collapse text-xs">
                  <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                      <th class="px-6 py-4">التاريخ</th>
                      <th class="px-4 py-4">النوع</th>
                      <th class="px-4 py-4">رقم العملية</th>
                      <th class="px-4 py-4">المرجع</th>
                      <th class="px-4 py-4 text-right">المبلغ</th>
                      <th class="px-6 py-4">قيد محاسبي؟</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-50 font-medium">
                    <tr v-for="(r, idx) in pagedReferences" :key="idx" class="hover:bg-slate-50 transition-all font-bold">
                      <td class="px-6 py-4 text-slate-400 font-mono">{{ formatDateEn(r.date) }}</td>
                      <td class="px-4 py-4">
                        <div class="flex flex-col gap-1">
                          <span class="px-2 py-1 rounded bg-slate-100 text-slate-600 text-[9px] font-bold uppercase tracking-widest w-fit">{{ resolvedRefType(r) }}</span>
                          <span v-if="r.sourceLabel" class="text-[9px] text-slate-500 px-1">{{ r.sourceLabel }}</span>
                          <span v-if="getTransactionSubtypeLabel(r)" class="text-[8px] text-slate-400 font-semibold px-1">{{ getTransactionSubtypeLabel(r) }}</span>
                        </div>
                      </td>
                      <td class="px-4 py-4 font-bold text-slate-900">#{{ r.id }}</td>
                      <td class="px-4 py-4">
                        <div class="text-[9px]" :class="isReturnLinkedReceipt(r) ? 'text-rose-400 font-bold' : 'text-slate-400'">{{ resolvedRefLabel(r) }}</div>
                        <div v-if="allocatedInvoiceLabel(r)" class="text-[9px] mt-1.5 px-2 py-0.5 bg-cyan-50 text-cyan-700 rounded font-bold tracking-tight w-fit">{{ allocatedInvoiceLabel(r) }}</div>
                      </td>
                      <td class="px-4 py-4 text-right font-mono text-slate-900 font-bold">{{ formatPriceEn(r.totalAmount || r.paidAmount || 0) }}</td>
                      <td class="px-6 py-4">
                        <span v-if="r.hasJournal" class="text-emerald-600 flex items-center gap-1.5 text-[10px] font-bold">
                          <i class="fas fa-check-circle"></i> مرحل - قيد #{{ r.journalEntryId || '-' }}
                        </span>
                        <span v-else class="text-slate-300 text-[10px]">غير مرحل</span>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot class="bg-slate-50/80 font-bold border-t-2 border-slate-100">
                    <tr>
                      <td class="px-6 py-4 text-[10px] text-slate-400 uppercase tracking-widest" colspan="4"><i class="fas fa-calculator"></i> إجمالي الصفحة</td>
                      <td colspan="2" class="px-6 py-4">
                        <div class="flex items-center justify-end gap-6 text-sm font-mono">
                          <div class="text-center">
                            <span class="text-[10px] text-slate-400 font-bold block mb-0.5">الصافي</span>
                            <span class="text-base font-bold text-slate-700">{{ formatPriceEn(referenceItemsTotals.net) }}</span>
                          </div>
                          <div class="text-center">
                            <span class="text-[10px] text-slate-400 font-bold block mb-0.5">الإجمالي</span>
                            <span class="text-base font-bold text-slate-900">{{ formatPriceEn(referenceItemsTotals.total) }}</span>
                          </div>
                          <div class="text-center">
                            <span class="text-[10px] text-emerald-600 font-bold block mb-0.5">المدفوع</span>
                            <span class="text-base font-bold text-emerald-700">{{ formatPriceEn(referenceItemsTotals.paid) }}</span>
                          </div>
                        </div>
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <div v-else class="text-center text-slate-400 p-12 font-bold text-xs uppercase tracking-widest">لا توجد بيانات مرجعية للعرض.</div>
            </div>

            <!-- ✅ RESTORED: تبويب "سجل المدفوعات" — نفس السبب -->
            <div v-else-if="referencesSubTab === 'payments'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
              <div class="px-6 py-4 bg-slate-50/30 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                  <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">سجل التحصيلات والمدفوعات ({{ referencesItems.length }} عملية)</span>
                  <p class="mt-1 text-[10px] text-slate-500">السندات المعروضة هنا قد تظهر أيضًا ضمن تبويب السندات والمراجع.</p>
                </div>
                <div class="flex flex-wrap items-center gap-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                  <span v-if="referencesItems.length">إجمالي السجلات: <span class="text-slate-900 font-mono">{{ formatPriceEn(referencesItems.reduce((s, p) => s + Number(p.totalAmount || p.paidAmount || 0), 0)) }}</span></span>
                  <PaginationControls
                    v-if="referencesItems.length"
                    :page="refsPage"
                    :total-pages="referencesPages"
                    show-page-indicator
                    @previous="refsPage = Math.max(1, refsPage - 1)"
                    @next="refsPage = Math.min(referencesPages, refsPage + 1)"
                  />
                </div>
              </div>

              <div v-if="referencesItems.length" class="overflow-x-auto">
                <table class="w-full text-right border-collapse text-xs">
                  <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                      <th class="px-6 py-4">التاريخ</th>
                      <th class="px-4 py-4">رقم العملية</th>
                      <th class="px-4 py-4">نوع العملية</th>
                      <th class="px-4 py-4">الفاتورة / التوزيع</th>
                      <th class="px-4 py-4 text-right">المبلغ</th>
                      <th class="px-4 py-4">قيد محاسبي</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-50 font-medium">
                    <tr v-for="p in pagedReferences" :key="p.id" class="hover:bg-slate-50 transition-all font-bold">
                      <td class="px-6 py-4 text-slate-400 font-mono">{{ formatDateEn(p.date) }}</td>
                      <td class="px-4 py-4 font-bold text-slate-900">#{{ p.id }}</td>
                      <td class="px-4 py-4">
                        <span :class="['px-2 py-1 rounded text-[9px] font-bold', p.type === 'receipt' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700']">{{ p.sourceLabel || p.typeLabel || (p.type === 'receipt' ? 'قبض' : 'صرف') }}</span>
                      </td>
                      <td class="px-4 py-4">
                        <span v-if="paymentInvoiceLabel(p)" class="text-blue-600">{{ paymentInvoiceLabel(p) }}</span>
                        <span v-else class="text-slate-300">—</span>
                      </td>
                      <td class="px-4 py-4 text-right font-mono font-bold text-purple-600">{{ formatPriceEn(p.totalAmount || p.paidAmount) }}</td>
                      <td class="px-4 py-4">
                        <span v-if="p.hasJournal" class="text-emerald-600 flex items-center gap-1.5 text-[10px] font-bold"><i class="fas fa-check-circle"></i> قيد #{{ p.journalEntryId || '-' }}</span>
                        <span v-else class="text-slate-300 text-[10px]">غير مرحل</span>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot class="bg-slate-50/80 font-bold border-t-2 border-slate-100">
                    <tr>
                      <td class="px-6 py-4 text-[10px] text-slate-400 uppercase tracking-widest" colspan="4"><i class="fas fa-receipt"></i> إجمالي الصفحة</td>
                      <td colspan="2" class="px-6 py-4">
                        <div class="flex items-center justify-end gap-6 text-sm font-mono">
                          <div class="text-center">
                            <span class="text-[10px] text-purple-600 font-bold block mb-0.5">المجموع</span>
                            <span class="text-base font-bold text-purple-700">{{ formatPriceEn(pagedReferences.reduce((s, p) => s + Number(p.totalAmount || p.paidAmount || 0), 0)) }}</span>
                          </div>
                        </div>
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <div v-else class="text-center text-slate-400 p-12 font-bold text-xs uppercase tracking-widest">لا توجد عمليات دفع وتحويلات للعرض في هذه الفترة.</div>
            </div>

          </div>
        </div>

      </div>
    </main>

    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="showPaymentModal"
          class="fixed inset-0 z-[300] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md"
          @keydown.esc="showPaymentModal = false"
          @click.self="showPaymentModal = false"
        >
          <div class="bg-white w-full max-w-md rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn" dir="rtl">
            <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
              <div>
                <h3 class="text-sm font-bold text-slate-900">تحصيل دفعة من</h3>
                <p class="text-xs text-slate-400 mt-0.5 font-medium">{{ partyInfo.name }}</p>
              </div>
              <button @click="showPaymentModal = false" class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-900 hover:bg-slate-100 transition-all flex items-center justify-center">
                <i class="fas fa-times text-xs"></i>
              </button>
            </div>
            <form @submit.prevent="submitPayment" class="px-8 py-6 space-y-4">
              <p v-if="hasPendingCustomerPayment" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[10px] font-semibold text-amber-800">
                توجد دفعة لم تُحسم نتيجتها. أعد المحاولة بالبيانات نفسها قبل بدء دفعة أخرى.
              </p>
              <div class="space-y-1.5">
                <label class="metadata-label">المبلغ <span class="text-rose-500">*</span></label>
                <input v-model.number="paymentData.amount" :disabled="hasPendingCustomerPayment" type="number" step="0.01" min="0.01" class="filter-input-v3" placeholder="0.00" required />
              </div>
              <div class="space-y-1.5">
                <label class="metadata-label">تاريخ الدفع <span class="text-rose-500">*</span></label>
                <input v-model="paymentData.paymentDate" :disabled="hasPendingCustomerPayment" type="date" class="filter-input-v3 font-mono" required />
              </div>
              <div class="space-y-1.5">
                <label class="metadata-label">طريقة الدفع <span class="text-rose-500">*</span></label>
                <select v-model="paymentData.paymentMethodId" :disabled="hasPendingCustomerPayment" class="filter-input-v3 appearance-none" required>
                  <option value="">اختر طريقة الدفع</option>
                  <option v-for="m in collectiblePaymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
              </div>
              <div class="space-y-1.5">
                <label class="metadata-label">الفرع <span class="text-rose-500">*</span></label>
                <select v-model="paymentData.branchId" :disabled="hasPendingCustomerPayment" class="filter-input-v3 appearance-none" required>
                  <option value="">اختر الفرع</option>
                  <option v-for="w in branches" :key="w.id" :value="w.id">{{ w.name || w.branchName }}</option>
                </select>
              </div>
              <div class="space-y-1.5">
                <label class="metadata-label">ملاحظات</label>
                <textarea v-model="paymentData.notes" :disabled="hasPendingCustomerPayment" rows="2" class="filter-input-v3 h-auto py-2 italic resize-none" placeholder="مرجع التحويل، شيك..."></textarea>
              </div>
              <div class="pt-4 border-t border-slate-100 flex gap-3">
                <button type="button" @click="showPaymentModal = false"
                        class="flex-1 h-10 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors rounded-md hover:bg-slate-50">
                  إلغاء
                </button>
                <LoadingButton
                  type="submit"
                  :text="hasPendingCustomerPayment ? 'إعادة المحاولة بنفس البيانات' : 'تأكيد التحصيل'"
                  loadingText="جارٍ التسجيل..."
                  variant="success"
                  :isLoading="loading"
                  class="flex-[2] h-10 rounded-md text-xs shadow-lg shadow-emerald-900/20"
                />
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
import { useRoute, useRouter } from 'vue-router';
import { useStatementData } from '@/composables/useStatementData';
import { useStatementRBAC } from '@/composables/useStatementRBAC';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useStatementStore } from '@/stores/statement/statementStore';
import { useSettingsStore } from '@/stores/settings/settingsStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { useToast } from '@/composables/useToast';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useDateValidation } from '@/composables/useDateValidation';
import { usePendingIdempotentRequest } from '@/composables/usePendingIdempotentRequest';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import { getLocalDateISO } from '@/utils';
import { getImageUrl } from '@/utils';
import { formatDateForLocale } from '@/utils/formatters/date';

// Core Composables
const route = useRoute();
const router = useRouter();
const { showToast } = useToast();
const { currencySymbol, formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { validateDateRange } = useDateValidation();

// Unified Statement Data Composable
const {
  fetchStatementData,
  loading: statementLoading,
  error: statementError,
  data: statementData,
  invoices,
  payments,
  transactions,
  returns,
  totals,
  agingAnalysis,
  availableCredit,
  alertStatus,
  page,
  perPage,
  totalRecords,
  totalPages
} = useStatementData();

// Stores
const authStore = useAuthStore();
const branchStore = useBranchStore();
const paymentStore = usePaymentStore();
const statementStore = useStatementStore();
const settingsStore = useSettingsStore();
const customerStore = useCustomerStore();
const supplierStore = useSupplierStore();
const showFilters = ref(false);

// Component State
const loading = ref(false);
const activeTab = ref('accounting');
const viewMode = ref(route.query.view || 'detailed');
const companyInfo = ref({ name: '', address: '', phone: '', email: '', logo: '' });
const partyInfo = ref({ name: '', address: '', phone: '', email: '' });
const showPaymentModal = ref(false);
const paymentData = ref({ amount: null, paymentDate: null, paymentMethodId: null, branchId: null, notes: '' });
const pendingCustomerPayment = usePendingIdempotentRequest('customer-payment');
const hasPendingCustomerPayment = computed(() => !!pendingCustomerPayment.pending.value);

// Route Params & Defaults
const type = computed(() => route.params.type);
const id = computed(() => Number(route.params.id));

// RBAC Composable
const {
  canViewStatement,
  canExport,
  canViewSensitiveData,
  canViewAgingAnalysis,
  logAccess,
  logDenial
} = useStatementRBAC(type.value);

const data = computed(() => statementData.value);

const today = new Date();
const yyyy = today.getFullYear();
const mm = String(today.getMonth() + 1).padStart(2, '0');
const dd = String(today.getDate()).padStart(2, '0');
const startOfMonth = `${yyyy}-${mm}-01`;
const startOfYear = `${yyyy}-01-01`;
const todayString = `${yyyy}-${mm}-${dd}`;

const startDate = ref(route.query.startDate || startOfYear);
const endDate = ref(route.query.endDate || todayString);
const searchText = ref('');

const startDateRef = ref(null);
const endDateRef = ref(null);
const typeFilter = ref(route.query.type || '');
const fillGaps = ref(typeof route.query.fillGaps !== 'undefined' ? route.query.fillGaps !== '0' : false);
const onlyNonZero = ref(route.query.onlyNonzero === '1');
const statusFilter = ref(route.query.status || 'any');
const paymentMethodId = ref(route.query.paymentMethodId || '');
const typeOptions = [
    { value: '', label: 'كل العمليات' },
    { value: 'sale', label: 'فواتير المبيعات' },
    { value: 'receipt', label: 'مدفوعات/تحصيل' },
    { value: 'sales_return', label: 'مرتجعات مبيعات' },
    { value: 'purchase', label: 'فواتير المشتريات' },
    { value: 'payment', label: 'مدفوعات للمورد' },
    { value: 'purchase_return', label: 'مرتجعات مشتريات' },
    { value: 'journal', label: 'قيود يومية' }
];
const statusOptions = [
    { value: 'any', label: 'كل الحالات' },
    { value: 'paid', label: 'مدفوع' },
    { value: 'partial', label: 'مدفوع جزئياً' },
    { value: 'unpaid', label: 'غير مدفوع' }
];

const paymentMethods = computed(() => paymentStore.paymentMethods);
const collectiblePaymentMethods = computed(() =>
    paymentMethods.value.filter(method => String(method.kind || '').toLowerCase() !== 'credit')
);
const branches = computed(() => branchStore.branches);

const txPerPage = ref(Number(route.query.txPerPage) || 20);
const txPage = ref(Number(route.query.txPage || 1));
const refsPerPage = ref(20);
const refsPage = ref(1);

const showReferencesLoading = ref(false);
const referencesSubTab = ref('invoices');

const visibleTransactions = computed(() => {
    const all = transactions.value || [];
    const sf = (statusFilter?.value || '').toString();
    const filtered = sf && sf !== 'any' ? all : all.filter(t => {
        const HIDE = new Set(['rejected', 'canceled', 'cancelled']);
        return !HIDE.has(String(t.status || t.invoiceStatus || '').toLowerCase());
    });
    if (!searchText.value) return filtered;
    const search = String(searchText.value || '').toLowerCase();
    return filtered.filter(t => {
        const refNo = String(t.reference || t.invoiceNumber || '').toLowerCase();
        const desc = String(t.description || '').toLowerCase();
        const debit = String(t.debit || '').toLowerCase();
        const credit = String(t.credit || '').toLowerCase();
        return refNo.includes(search) || desc.includes(search) || debit.includes(search) || credit.includes(search);
    });
});

const txPages = computed(() => Math.max(1, Math.ceil(visibleTransactions.value.length / txPerPage.value)));

const pagedTransactions = computed(() => {
    const arr = visibleTransactions.value;
    const start = (txPage.value - 1) * txPerPage.value;
    return arr.slice(start, start + txPerPage.value);
});

const pageTotals = computed(() => {
    const arr = pagedTransactions.value || [];
    let debit = 0, credit = 0, closing = null;
    for (const t of arr) {
        debit += Number(t.debit || 0);
        credit += Number(t.credit || 0);
        closing = t.balance;
    }
    return { debit, credit, closing };
});

const dailyBalances = computed(() => {
    const backend = data.value?.dailyBalances;
    if (Array.isArray(backend) && backend.length) return backend;
    const txs = (transactions.value || []).slice().sort((a, b) => new Date(a.date) - new Date(b.date));
    if (!txs.length) return [];
    const byDate = {};
    for (const t of txs) {
        const key = getLocalDateISO(new Date(t.date));
        if (!byDate[key]) byDate[key] = { date: key, openingBalance: 0, dayDebit: 0, dayCredit: 0, closingBalance: 0, transactionCount: 0 };
        byDate[key].dayDebit += Number(t.debit || 0);
        byDate[key].dayCredit += Number(t.credit || 0);
        byDate[key].transactionCount += 1;
    }
    const keys = Object.keys(byDate).sort();
    let running = Number(data.value?.openingBalance || 0);
    for (const k of keys) {
        const rec = byDate[k];
        rec.openingBalance = running;
        rec.closingBalance = running + rec.dayDebit - rec.dayCredit;
        running = rec.closingBalance;
    }
    return keys.map(k => byDate[k]);
});

const referencesData = computed(() => data.value?.references || null);
const salesOnly = computed(() => ({
    items: invoices.value || []
}));

const salesOnlySorted = computed(() => {
    const arr = salesOnly.value?.items || [];
    return [...arr].sort((a, b) => new Date(a?.date || 0).getTime() - new Date(b?.date || 0).getTime());
});

const invoicesTotals = computed(() => {
    const arr = salesOnlySorted.value || [];
    let net = 0, tax = 0, disc = 0, total = 0, paid = 0, due = 0;
    for (const s of arr) {
        net += Number(s.netTotalAmount || 0);
        tax += Number(s.taxAmount || 0);
        const dAmt = (s.discountAmount != null)
            ? Number(s.discountAmount)
            : (String(s.discountType || '').toLowerCase() === 'percentage'
                ? (Number(s.discountValue || 0) / 100) * Number(s.netTotalAmount || 0)
                : Number(s.discountValue || 0));
        disc += Number(dAmt || 0);
        total += Number(s.totalAmount || 0);
        paid += Number(s.paidAmount || 0);
        const status = String(s.status || s.statusCode || '').toLowerCase();
        if (status === 'closed_by_return') {
            continue;
        }
        const rawDue = (s.outstanding != null) ? Number(s.outstanding) : (s.dueAmount != null ? Number(s.dueAmount) : (Number(s.netTotalAmount || 0) - Number(s.paidAmount || 0)));
        due += Math.max(0, Number(rawDue || 0));
    }
    return { net, tax, disc, total, paid, due };
});

const referenceItemsTotals = computed(() => {
    const arr = pagedReferences.value || [];
    let total = 0, paid = 0, net = 0;
    for (const r of arr) {
        net += Number(r.netTotalAmount || 0);
        total += Number(r.totalAmount || grossTotal(r) || 0);
        paid += Number(r.paidAmount || r.amount || 0);
    }
    return { total, paid, net };
});

const isVoucherItem = (it) => {
    const prefix = String(it?.reference || '').split('#')[0];
    const t = String(it?.type || '').toLowerCase();
    const voucherTypes = ['receipt', 'payment', 'cash_voucher', 'refund', 'sales_return', 'purchase_return'];
    return voucherTypes.includes(prefix) || voucherTypes.some(vt => t.includes(vt));
};

const returnJournalIds = computed(() => {
    const items = referencesData.value?.items || [];
    return new Set(
        items
            .filter(r => r.type === 'sales_return' || r.type === 'purchase_return')
            .map(r => r.journalEntryId)
            .filter(Boolean)
    );
});

const isReturnLinkedReceipt = (r) =>
    (r.type === 'receipt' || r.type === 'payment') &&
    r.journalEntryId &&
    returnJournalIds.value.has(r.journalEntryId);

const isPaymentItem = (it) => {
    const t = String(it?.type || '').toLowerCase();
    const prefix = String(it?.reference || '').split('#')[0];
    const paymentTypes = ['receipt', 'refund'];
    return paymentTypes.includes(prefix.toLowerCase()) || paymentTypes.some(pt => t.includes(pt));
};

const referencesItems = computed(() => {
    const arr = referencesData.value?.items || [];
    let filtered = arr;
    if (referencesSubTab.value === 'vouchers') {
        filtered = arr.filter(isVoucherItem);
    } else if (referencesSubTab.value === 'payments') {
        filtered = arr.filter(isPaymentItem);
    } else {
        filtered = arr.filter(it => !isVoucherItem(it) && !isPaymentItem(it));
    }
    return [...filtered].sort((a, b) => new Date(a?.date || 0).getTime() - new Date(b?.date || 0).getTime());
});

const referencesPages = computed(() => Math.max(1, Math.ceil(referencesItems.value.length / refsPerPage.value)));

const pagedReferences = computed(() => {
    const start = (refsPage.value - 1) * refsPerPage.value;
    return referencesItems.value.slice(start, start + refsPerPage.value);
});

const title = computed(() => type.value === 'customers' ? 'كشف حساب العميل' : 'كشف حساب المورد');
const isCustomer = computed(() => type.value === 'customers');
const breadcrumb = computed(() => ({
  parent: {
    label: isCustomer.value ? 'العملاء' : 'الموردون',
    path: isCustomer.value ? '/customers' : '/suppliers'
  },
  current: { label: title.value }
}));
const referencesTabLabel = computed(() => isCustomer.value ? 'مرجع الفواتير والسندات للعميل' : 'مرجع فواتير ومدفوعات المورد');

const groupedByType = computed(() => {
    if (!transactions.value || !Array.isArray(transactions.value)) return [];
    const map = {};
    for (const t of transactions.value) {
        const key = String(t.transactionType || t.reference || '').split('#')[0] || 'other';
        const label = getTransactionTypeLabel(t.transactionType || t.referenceType || (t.reference || '').split('#')[0]) || 'حركة';
        if (!map[key]) map[key] = { type: key, label: label, debit: 0, credit: 0, count: 0 };
        map[key].debit += Number(t.debit || 0);
        map[key].credit += Number(t.credit || 0);
        map[key].count += 1;
    }
    return Object.values(map);
});

const agingCounts = computed(() => {
    const counts = data.value?.agingCounts || null;
    if (counts) return counts;
    const c = { current: 0, '1_30_days': 0, '31_60_days': 0, '60_plus_days': 0 };
    const items = referencesData.value?.items || salesOnly.value?.items || [];
    const now = new Date();
    for (const it of items) {
        const itType = it.type || String(it.reference || '').split('#')[0];
        const outstanding = Number((it.outstanding ?? (it.netTotalAmount - (it.paidAmount || 0))) || 0);
        if (itType !== 'sale' || !(outstanding > 0)) continue;
        const diff = Math.floor((now - new Date(it.date)) / (1000 * 60 * 60 * 24));
        if (diff <= 0) c.current++;
        else if (diff <= 30) c['1_30_days']++;
        else if (diff <= 60) c['31_60_days']++;
        else c['60_plus_days']++;
    }
    return c;
});

const overdueInvoices = computed(() => {
    const items = referencesData.value?.items || salesOnly.value?.items || [];
    const now = new Date();
    return items.filter(it => {
        const itType = it.type || String(it.reference || '').split('#')[0];
        const outstanding = Number((it.outstanding ?? (it.netTotalAmount - (it.paidAmount || 0))) || 0);
        if (itType !== 'sale' || !(outstanding > 0)) return false;
        const diff = Math.floor((now - new Date(it.date)) / (1000 * 60 * 60 * 24));
        return diff > 30;
    });
});

const hasOverdueAlert = computed(() => isCustomer.value && overdueInvoices.value.length > 0);

const invoiceStatusMap = computed(() => {
    const map = {};
    try {
        const items = referencesData.value?.items || salesOnly.value?.items || [];
        for (const it of items) {
            const itType = it.type || String(it.reference || '').split('#')[0];
            if (itType !== 'sale' && itType !== 'purchase') continue;
            const itId = it.id || it.saleId || it.purchaseId || Number(String(it.reference || '').split('#')[1] || 0) || null;
            const refNo = String(it.reference || '').includes('#') ? String(it.reference).split('#')[1] : '';
            const code = paymentStatusFromAmounts(it);
            if (!code) continue;
            const label = code === 'paid' ? 'مدفوعة' : code === 'settled' ? 'مسددة' : code === 'partial' ? 'مدفوعة جزئياً' : 'غير مدفوعة';
            if (itId) map[`${itType}:${itId}`] = { code, label };
            if (refNo) map[`${itType}#${refNo}`] = { code, label };
        }
    } catch { /* ignore */ }
    return map;
});

const taxAmount = (r) => {
    try {
        const direct = Number(
            r.taxAmount ?? r.vatAmount ?? r.totalTax ?? r.tax ?? r.vat ??
            r.taxValue ?? r.vatValue ?? r.totalVat ?? r.totalTaxAmount ??
            r.taxAmount ?? r.vatAmount ?? 0
        );
        if (!Number.isNaN(direct) && direct > 0) return direct;
        if (Array.isArray(r.taxes) && r.taxes.length) {
            const sum = r.taxes.reduce((s, t) => s + Number(t.amount || t.value || 0), 0);
            if (sum > 0) return sum;
        }
        const net = Number(r.netTotalAmount || 0);
        const rate = Number(r.taxRate || r.vatRate || 0);
        if (!Number.isNaN(rate) && rate > 0) {
            const calc = +(net * rate / 100).toFixed(2);
            if (calc > 0) return calc;
        }
        const total = Number(r.totalAmount || 0);
        let diff = total - net;
        if (diff <= 0) {
            const paid = Number(r.paidAmount || 0);
            const status = String(r.status || r.statusCode || '').toLowerCase();
            if (paid > net || ['paid', 'completed', 'posted'].includes(status)) {
                const paidDiff = paid - net;
                if (paidDiff > 0 && paidDiff < net * 2) return paidDiff;
            }
        }
        return diff > 0 ? diff : 0;
    } catch (_) { return 0; }
};

const grossTotal = (r) => {
    const net = Number(r.netTotalAmount || r.net || 0);
    const tax = taxAmount(r);
    const total = Number(r.totalAmount || r.total || 0);
    return Math.max(net + tax, total);
};

const statusTextLocal = (s) => {
    const v = String(s || '').toLowerCase();
    if (v === 'paid') return 'مدفوعة';
    if (v === 'settled') return 'مسددة';
    if (v === 'partial' || v === 'partially_paid') return 'مدفوعة جزئياً';
    if (v === 'unpaid' || v === 'pending' || v === 'pending_payment') return 'آجل';
    if (v === 'closed_by_return') return 'مرتجعة';
    if (v === 'settled_by_return') return 'مسواة بمرتجع';
    if (v === 'settled_by_credit') return 'مسوّاة بمرتجع';
    if (v === 'posted') return 'مرحلة';
    if (v === 'approved') return 'معتمد';
    if (v === 'draft') return 'مسودة';
    if (v === 'rejected') return 'مرفوضة';
    if (v === 'canceled' || v === 'cancelled') return 'ملغاة';
    if (v === 'completed') return 'مكتملة';
    return '';
};

const paymentStatusFromAmounts = (item) => {
    try {
        const total = Number(item?.netTotalAmount ?? item?.totalAmount ?? item?.grandTotal ?? 0);
        const paid = Number(item?.paidAmount ?? 0);
        const outstanding = item?.outstanding != null ? Number(item.outstanding) : (total - paid);
        if (total > 0) {
            if (outstanding <= 0) return 'paid';
            if (paid > 0 && outstanding > 0) return 'partial';
            return 'unpaid';
        }
        return null;
    } catch { return null; }
};

const getItemOutstanding = (item) => {
    try {
        if (item?.outstanding != null && String(item.outstanding).trim() !== '') {
            return Number(item.outstanding) || 0;
        }
        const total = Number(item?.netTotalAmount ?? item?.totalAmount ?? item?.grandTotal ?? 0);
        const paid = Number(item?.paidAmount ?? 0);
        return Math.max(0, total - paid);
    } catch {
        return 0;
    }
};

const displayStatusCode = (item, fallback) => {
    const apiStatus = String(item?.status || item?.statusCode || '').toLowerCase();
    if (apiStatus === 'closed_by_return')  return 'closed_by_return';
    if (apiStatus === 'settled_by_return') return 'settled_by_return';
    if (apiStatus === 'settled_by_credit') return 'settled_by_credit';
    if (apiStatus === 'settled_mixed')     return 'settled_mixed';
    if (apiStatus === 'returned')          return 'returned';
    if (apiStatus === 'paid')              return 'paid';
    if (apiStatus === 'partial' || apiStatus === 'partially_paid') return 'partial';
    if (apiStatus === 'pending_payment' || apiStatus === 'unpaid') {
      const calc = paymentStatusFromAmounts(item);
      if (calc === 'partial') return 'partial';
      return 'unpaid';
    }
    if (apiStatus === 'approved')          return 'approved';
    if (apiStatus === 'draft')             return 'draft';
    return paymentStatusFromAmounts(item) || String(fallback || '').toLowerCase();
};

const displayStatusLabel = (item, fallbackCode, fallbackLabel) => {
    const apiStatus = String(item?.status || item?.statusCode || '').toLowerCase();
    if (apiStatus === 'closed_by_return')  return 'مرتجعة';
    if (apiStatus === 'settled_by_return') return 'مسواة بمرتجع';
    if (apiStatus === 'settled_by_credit') return 'مسوّاة بمرتجع';
    if (apiStatus === 'settled_mixed')     return 'مسوّاة بمزيج نقدي/إشعار دائن';
    if (apiStatus === 'returned')          return 'مرتجعة';
    if (apiStatus === 'paid')              return 'مدفوعة';
    if (apiStatus === 'partial' || apiStatus === 'partially_paid') return 'مدفوعة جزئياً';
    if (apiStatus === 'pending_payment' || apiStatus === 'unpaid') {
      const calc = paymentStatusFromAmounts(item);
      if (calc === 'partial') return 'مدفوعة جزئياً';
      return 'آجل';
    }
    if (apiStatus === 'approved')          return 'معتمدة';
    if (apiStatus === 'draft')             return 'مسودة';
    const code = paymentStatusFromAmounts(item);
    if (code === 'paid')    return 'مدفوعة';
    if (code === 'partial') return 'مدفوعة جزئياً';
    if (code === 'unpaid')  return 'غير مدفوعة';
    return fallbackLabel || statusTextLocal(fallbackCode) || '-';
};

const formatDate = (date) => date ? formatDateForLocale(date, 'en-GB') : new Date(date).toLocaleDateString('en-GB');
const formatDateEn = (date) => date ? formatDateForLocale(date, 'en-GB') : '';
const formatPrice = (amount) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount || 0));
const formatPriceEn = (amount) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount || 0));
const referenceParts = (transaction) => {
    const [type = '', id = ''] = String(transaction?.reference || '').split('#');
    return { type: type.toLowerCase(), id: transaction?.referenceId ?? id };
};

const relatedInvoiceNumber = (transaction, type, id) => {
    if (transaction?.invoiceNumber) return transaction.invoiceNumber;
    if (!['sale', 'purchase'].includes(type) || !id) return null;
    const items = referencesData.value?.items || salesOnly.value?.items || [];
    const invoice = items.find(item => {
        const invoiceType = item.type || String(item.reference || '').split('#')[0];
        return invoiceType === type && String(item.id || item.saleId || item.purchaseId) === String(id);
    });
    return invoice?.invoiceNumber || null;
};

const transactionDocumentLabel = (transaction) => {
    const { type, id } = referenceParts(transaction);
    const invoiceNumber = relatedInvoiceNumber(transaction, type, id);
    const transactionType = String(transaction?.transactionType || transaction?.type || '').toLowerCase();
    if (invoiceNumber && ['receipt', 'payment', 'refund'].includes(transactionType)) {
        return `${type === 'purchase' ? 'دفعة على فاتورة شراء' : 'تحصيل على فاتورة بيع'} ${invoiceNumber}`;
    }
    if (invoiceNumber) {
        const invoiceType = type.includes('purchase') ? 'فاتورة شراء' : 'فاتورة بيع';
        return `${invoiceType} ${invoiceNumber}`;
    }
    const labels = {
        cashVoucher: 'سند قبض',
        customerPayment: 'تحصيل من العميل',
        customerPaymentReclassification: 'تسوية تحصيل',
        supplierPayment: 'دفعة للمورد',
        receipt: 'سند قبض',
        payment: 'سند صرف',
        refund: 'استرداد',
        salesReturn: 'مرتجع بيع',
        purchaseReturn: 'مرتجع شراء',
        journal: 'قيد يومية'
    };
    const label = labels[type] || getTransactionTypeLabel(transaction?.transactionType || type);
    return id ? `${label} #${id}` : label;
};

const documentNumber = (transaction) => {
    if (transaction?.invoiceNumber) return transaction.invoiceNumber;
    if (transaction?.paymentReferenceNumber) return transaction.paymentReferenceNumber;
    if (transaction?.referenceNumber) return transaction.referenceNumber;

    const { id } = referenceParts(transaction);
    if (id) return `#${id}`;
    return transaction?.id ? `#${transaction.id}` : transactionDocumentLabel(transaction);
};

const transactionRelatedReference = (transaction) => {
    const { type, id } = referenceParts(transaction);
    const invoiceNumber = relatedInvoiceNumber(transaction, type, id);
    const transactionType = String(transaction?.transactionType || transaction?.type || '').toLowerCase();
    if (invoiceNumber && transactionType === type) return '—';
    if (invoiceNumber) return `الفاتورة المرتبطة: ${invoiceNumber}`;
    if (transaction?.paymentReferenceNumber) return `مرجع التحصيل: ${transaction.paymentReferenceNumber}`;
    return '—';
};

const refTypeLabel = (reference) => {
    const t = String(reference || '').includes('#') ? String(reference).split('#')[0] : '';
    switch (t) {
        case 'sale': return 'فاتورة بيع';
        case 'purchase': return 'فاتورة شراء';
        case 'receipt': return 'قبض';
        case 'customer_payment_reclassification': return 'تسوية تحصيل';
        case 'payment': return 'صرف';
        case 'sales_return': return 'مرتجع بيع';
        case 'purchase_return': return 'مرتجع شراء';
        case 'return': return 'مرتجع';
        case 'cash_voucher': return 'سند قبض';
        case 'journal': return 'قيد يومية';
        default: return t || '-';
    }
};

const getTransactionTypeLabel = (type) => {
    if (!type) return 'حركة';
    const t = String(type || '').toLowerCase();
    switch (t) {
        case 'sale': return 'فاتورة بيع';
        case 'sales': return 'فاتورة بيع';
        case 'purchase': return 'فاتورة شراء';
        case 'purchases': return 'فاتورة شراء';
        case 'receipt': return 'قبض';
        case 'customer_payment_reclassification': return 'تسوية قيد التحصيل';
        case 'payment': return 'صرف';
        case 'refund': return 'صرف';
        case 'sales_return': return 'مرتجع بيع';
        case 'sale_return': return 'مرتجع بيع';
        case 'return_sale': return 'مرتجع بيع';
        case 'purchase_return': return 'مرتجع شراء';
        case 'return_purchase': return 'مرتجع شراء';
        case 'return': return 'مرتجع';
        case 'cash_voucher': return isCustomer.value ? 'سند قبض' : 'سند صرف';
        case 'journal': return 'قيد يومية';
        case 'return_payment': return 'سداد مرتجع';
        case 'sales_return_refund': return 'استرداد عميل';
        case 'purchase_return_refund': return 'استرجاع مورد';
        default: return t || 'حركة';
    }
};

const resolvedRefType = (r) => {
    if (isReturnLinkedReceipt(r)) return 'صرف مرتجع';
    if (r.transactionSubtype) {
        const subtypeDisplayMap = {
            'salesReturnOnly':          'مرتجع بيع (بدون صرف)',
            'salesReturnRefund':        'مرتجع بيع + صرف',
            'salesReturnBankRefund':   'مرتجع بيع + تحويل',
            'purchaseReturnOnly':       'مرتجع شراء (بدون صرف)',
            'purchaseReturnRefund':     'مرتجع شراء + صرف',
            'purchaseReturnBankRefund':'مرتجع شراء + تحويل',
        };
        if (subtypeDisplayMap[r.transactionSubtype]) {
            return subtypeDisplayMap[r.transactionSubtype];
        }
    }
    const type = String(r.type || '').toLowerCase();
    if (type) {
        return getTransactionTypeLabel(type);
    }
    const ref = String(r.reference || '').split('#')[0];
    return getTransactionTypeLabel(ref) || 'مستند';
};

const resolvedRefLabel = (r) => {
    if (isReturnLinkedReceipt(r)) return 'سند صرف مرتجع';
    if (r.sourceLabel) {
        if (r.invoiceNumber) return `فاتورة ${r.invoiceNumber}`;
        if (r.paymentReferenceNumber) return `مرجع التحصيل: ${r.paymentReferenceNumber}`;
        return r.sourceLabel;
    }
    if (r.referenceLabel) {
        return r.referenceLabel;
    }
    const ref = String(r.reference || '').split('#')[0];
    return refTypeLabel(ref) || '-';
};

const paymentInvoiceLabel = (payment) => {
    if (payment?.invoiceNumber) return payment.invoiceNumber;
    const allocatedInvoices = String(payment?.allocatedInvoiceNumbers || '').trim();
    return allocatedInvoices ? `موزعة على: ${allocatedInvoices}` : null;
};

const allocatedInvoiceLabel = (payment) => {
    const invoiceLabel = paymentInvoiceLabel(payment);
    if (!invoiceLabel) return '';
    return payment?.invoiceNumber
        ? `مرتبطة بالفاتورة: ${invoiceLabel}`
        : `توزيع التحصيل: ${invoiceLabel}`;
};

const getTransactionSubtypeLabel = (transaction) => {
    if (!transaction) return '';
    const groupId = transaction.returnGroupId;
    if (!groupId) return '';
    return `ربط #${groupId}`;
};

const badgeClass = (type) => {
    const t = String(type || '').toLowerCase();
    if (t === 'closed_by_return')  return 'bg-indigo-50 text-indigo-700 border border-indigo-200';
    if (t === 'settled_by_return') return 'bg-teal-50 text-teal-700 border border-teal-200';
    if (t === 'settled_by_credit') return 'bg-cyan-50 text-cyan-700 border border-cyan-200';
    if (t === 'settled_mixed')     return 'bg-purple-50 text-purple-700 border border-purple-200';
    if (t === 'returned')          return 'bg-indigo-50 text-indigo-700 border border-indigo-200';
    if (t === 'paid')              return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    if (t === 'partial')           return 'bg-amber-50 text-amber-700 border border-amber-200';
    if (t === 'unpaid')            return 'bg-rose-50 text-rose-700 border border-rose-200';
    if (['sale', 'sales', 'purchase', 'purchases'].includes(t)) return 'bg-rose-50 text-rose-700 border border-rose-200';
    if (['receipt', 'refund', 'payment'].includes(t)) return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    if (['sales_return', 'return_sale', 'purchase_return', 'return_purchase', 'return'].includes(t)) return 'bg-blue-50 text-blue-700 border border-blue-200';
    if (['journal', 'cash_voucher'].includes(t)) return 'bg-amber-50 text-amber-700 border border-amber-200';
    return 'bg-slate-50 text-slate-700 border border-slate-200';
};

const getBalanceWithNature = (transaction) => {
    if (!transaction) return '-';
    const balance = Number(transaction.balance || 0);
    const nature = String(transaction.balanceNature || 'zero').toLowerCase();
    if (nature === 'debit') {
        return `${formatPriceEn(balance)} مدين`;
    } else if (nature === 'credit') {
        return `${formatPriceEn(Math.abs(balance))} دائن`;
    }
    return formatPriceEn(balance);
};

const getBalanceColorClass = (transaction) => {
    if (!transaction) return 'text-slate-600';
    const nature = String(transaction.balanceNature || 'zero').toLowerCase();
    if (nature === 'debit') return 'text-blue-700 font-bold';
    if (nature === 'credit') return 'text-amber-700 font-bold';
    return 'text-slate-600';
};

const invoiceLink = (item) => {
    try {
        const ref = String(item.reference || item.type || '');
        if (!ref.includes('#')) return null;
        const [rawPrefix, rawId] = ref.split('#');
        const prefix = String(rawPrefix || '').toLowerCase();
        const linkId = Number(item.referenceId || item.saleId || item.purchaseId || rawId);
        if (!linkId || Number.isNaN(linkId)) return null;
        if (prefix === 'sale' || prefix === 'sales') return { name: 'SalesHistory', query: { id: linkId } };
        if (prefix === 'purchase' || prefix === 'purchases') return { name: 'PurchaseHistory', query: { id: linkId } };
        if (['return', 'sales_return', 'sale_return', 'return_sale', 'purchase_return', 'return_purchase'].includes(prefix)) {
            return { name: 'ReturnsHistory', query: { id: linkId } };
        }
        if (['receipt', 'payment', 'cash_voucher'].includes(prefix)) return { name: 'CashVouchers', query: { id: linkId } };
        return null;
    } catch { return null; }
};

const getAllocatedInvoice = (returnItem) => {
    if (!returnItem || !['sales_return', 'return', 'purchase_return'].includes(String(returnItem.type || '').toLowerCase())) {
        return null;
    }
    const groupId = returnItem.returnGroupId;
    if (!groupId) return null;
    if (salesOnly.value?.items) {
        const settled = salesOnly.value.items.find(s => 
            String(s.status || '').toLowerCase() === 'settled_by_credit' && 
            (s.returnGroupId === groupId || s.id === returnItem.saleId)
        );
        return settled?.invoiceNumber || null;
    }
    return null;
};

const daysBetween = (d) => {
    if (!d) return 0;
    const start = new Date(typeof d === 'string' || typeof d === 'number' ? d : String(d));
    return Math.max(0, Math.floor((new Date() - start) / (1000 * 60 * 60 * 24)));
};

const rowClass = (t) => {
    const txType = String(t.reference || '').split('#')[0];
    if (txType.includes('sale') || txType.includes('purchase')) return 'border-r-4 border-r-rose-200';
    if (txType.includes('receipt') || txType.includes('payment')) return 'border-r-4 border-r-emerald-200';
    if (txType.includes('return')) return 'border-r-4 border-r-blue-200';
    return '';
};

const openPaymentModal = () => {
    try {
        const pending = pendingCustomerPayment.restore(id.value);
        paymentData.value = pending?.payload
            ? { ...pending.payload }
            : {
                amount: null,
                paymentDate: getLocalDateISO(new Date()),
                paymentMethodId: null,
                branchId: null,
                notes: ''
            };
        showPaymentModal.value = true;
    } catch (error) {
        showToast(error.message || 'تعذر استعادة عملية التحصيل السابقة.', 'error');
    }
};

const submitPayment = async () => {
    if (!paymentData.value.amount || paymentData.value.amount <= 0) {
        showToast('ادخل مبلغ صحيح', 'error');
        return;
    }
    if (!paymentData.value.paymentMethodId) {
        showToast('اختر الطريقة', 'error');
        return;
    }
    if (!paymentData.value.paymentDate) {
        showToast('الرجاء تحديد تاريخ الدفع', 'error');
        return;
    }

    loading.value = true;
    try {
        const payload = {
            amount: Number(paymentData.value.amount),
            paymentDate: paymentData.value.paymentDate,
            paymentMethodId: Number(paymentData.value.paymentMethodId),
            branchId: paymentData.value.branchId ? Number(paymentData.value.branchId) : undefined,
            notes: paymentData.value.notes || '',
        };
        const pending = pendingCustomerPayment.prepare(id.value, payload);
        const res = await customerStore.recordCustomerPayment(id.value, pending.payload, pending.key);
        if (res.status === 'success') {
            pendingCustomerPayment.clear(id.value);
            showToast('تم التحصيل بنجاح', 'success');
            showPaymentModal.value = false;
            await fetchStatementData(type.value, id.value, {
                startDate: startDate.value,
                endDate: endDate.value,
                status: statusFilter.value || 'any',
                fillGaps: fillGaps.value ? 1 : 0,
                includeReferences: 1,
            }, { force: true });
        } else {
            if (res.outcomeUnknown) {
                showToast('تعذر تأكيد نتيجة التحصيل. أعد المحاولة بالبيانات نفسها.', 'error');
            } else {
                pendingCustomerPayment.clear(id.value);
                showToast(res.message || 'فشل التسجيل', 'error');
            }
        }
    } catch (e) {
        showToast(e.response?.data?.message || e.message || 'فشل التسجيل', 'error');
    } finally {
        loading.value = false;
    }
};

const fetchStatement = async () => {
    if (!id.value || !['customers', 'suppliers'].includes(type.value)) {
        showToast('مسار غير صحيح', 'error');
        return;
    }
    const result = await fetchStatementData(type.value, id.value, {
        startDate: startDate.value,
        endDate: endDate.value,
        status: statusFilter.value || 'any',
        fillGaps: fillGaps.value ? 1 : 0,
        includeReferences: 1,
        includeTypes: typeFilter.value || undefined,
        paymentMethodId: paymentMethodId.value || undefined,
        onlyNonzero: onlyNonZero.value ? 1 : 0,
    });
    if (result.status !== 'success') {
        showToast(result.error?.message || 'فشل في تحميل كشف الحساب', 'error');
    }
};

let _isApplyingFilter = false;

const applyFilter = () => {
    if (!validateDateRange(startDate.value, endDate.value)) {
        return;
    }
    _isApplyingFilter = true;
    router.replace({
        query: {
            startDate: startDate.value,
            endDate: endDate.value,
            fillGaps: fillGaps.value ? '1' : '0',
            onlyNonzero: onlyNonZero.value ? '1' : '0',
            type: typeFilter.value || undefined,
            status: statusFilter.value || undefined,
            paymentMethodId: paymentMethodId.value || undefined,
            view: viewMode.value || undefined,
            txPerPage: String(txPerPage.value),
            txPage: String(txPage.value),
        }
    });
    fetchStatement();
    setTimeout(() => { _isApplyingFilter = false; }, 100);
};

const setQuickRange = (kind) => {
    const d = new Date();
    if (kind === 'last7') {
        const s = new Date(d);
        s.setDate(d.getDate() - 6);
        startDate.value = getLocalDateISO(s);
        endDate.value = getLocalDateISO(d);
    } else if (kind === 'thisMonth') {
        startDate.value = getLocalDateISO(new Date(d.getFullYear(), d.getMonth(), 1));
        endDate.value = getLocalDateISO(d);
    } else if (kind === 'prevMonth') {
        startDate.value = getLocalDateISO(new Date(d.getFullYear(), d.getMonth() - 1, 1));
        endDate.value = getLocalDateISO(new Date(d.getFullYear(), d.getMonth(), 0));
    }
    applyFilter();
};

const exportPdf = async () => {
    if (!data.value) {
        showToast('لا توجد بيانات متاحة للطباعة.', 'error');
        return;
    }

    const win = window.open('', '_blank');
    if (!win) {
        showToast('تعذر فتح نافذة الطباعة. اسمح بالنوافذ المنبثقة ثم أعد المحاولة.', 'error');
        return;
    }

    try {
        const requestParams = {
            startDate: startDate.value,
            endDate: endDate.value,
            status: statusFilter.value || 'any',
            fillGaps: fillGaps.value ? 1 : 0,
            includeReferences: 1,
            includeTypes: typeFilter.value || undefined,
            paymentMethodId: paymentMethodId.value || undefined,
            onlyNonzero: onlyNonZero.value ? 1 : 0,
        };
        const statementResult = type.value === 'customers'
            ? await statementStore.fetchCustomerStatement(id.value, requestParams, { force: true })
            : await statementStore.fetchSupplierStatement(id.value, requestParams, { force: true });
        const printData = statementResult?.status === 'success' ? statementResult.data : null;
        if (!printData || !Array.isArray(printData.transactions)) {
            win.close();
            showToast(statementResult?.message || 'تعذر جلب بيانات كشف الحساب للطباعة.', 'error');
            return;
        }

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
        const formatAmount = (value) => Number(value || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        const reportTitle = `${title.value} (${startDate.value} - ${endDate.value})`;
        const rows = printData.transactions.filter((transaction) => {
            if (statusFilter.value === 'any' && ['rejected', 'canceled', 'cancelled'].includes(
                String(transaction.status || transaction.invoiceStatus || '').toLowerCase()
            )) {
                return false;
            }
            if (!searchText.value) return true;
            const search = String(searchText.value).toLowerCase();
            return [
                transaction.reference || transaction.invoiceNumber,
                transaction.description,
                transaction.debit,
                transaction.credit,
            ].some((value) => String(value || '').toLowerCase().includes(search));
        });
        const rowsHtml = rows.length
            ? rows.map((transaction) => `
                <tr>
                    <td>${escapeHtml(formatDate(transaction.date))}</td>
                    <td>${escapeHtml(documentNumber(transaction))}</td>
                    <td>${escapeHtml(transaction.referenceLabel || refTypeLabel(transaction.reference))}</td>
                    <td class="description">${escapeHtml(transaction.description || '-')}</td>
                    <td class="debit">${Number(transaction.debit) > 0 ? formatAmount(transaction.debit) : '-'}</td>
                    <td class="credit">${Number(transaction.credit) > 0 ? formatAmount(transaction.credit) : '-'}</td>
                    <td>${formatAmount(transaction.balance)}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="7" class="empty">لا توجد حركات خلال الفترة المحددة.</td></tr>';
        const logoHtml = companyInfo.value.logo
            ? `<img class="logo" src="${escapeHtml(companyInfo.value.logo)}" alt="">`
            : '';

        const html = `<!DOCTYPE html>
            <html dir="rtl" lang="ar">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>${escapeHtml(reportTitle)}</title>
                <style>
                    *{box-sizing:border-box}
                    body{font-family:Tajawal,Arial,Segoe UI,sans-serif;color:#111827;margin:0;padding:24px;font-size:12px}
                    header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;border-bottom:2px solid #1e3a8a;padding-bottom:16px}
                    .company{display:flex;align-items:center;gap:12px}
                    .logo{max-width:64px;max-height:64px;object-fit:contain}
                    h1{font-size:20px;margin:0 0 6px}
                    h2{font-size:15px;margin:20px 0 6px}
                    p{margin:4px 0;color:#4b5563}
                    .period{font-weight:700;color:#1d4ed8}
                    .summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:16px 0}
                    .summary div{border:1px solid #dbe2ea;border-radius:6px;padding:10px;background:#f8fafc}
                    .summary span{display:block;color:#64748b;font-size:10px;margin-bottom:5px}
                    .summary strong{font-size:13px}
                    table{width:100%;border-collapse:collapse;font-size:10px}
                    thead{display:table-header-group;background:#eaf0f8}
                    tr{break-inside:avoid}
                    th,td{border:1px solid #d1d5db;padding:7px;text-align:center;vertical-align:top}
                    td.description{text-align:right;white-space:pre-wrap;overflow-wrap:anywhere}
                    .debit{color:#b91c1c;font-weight:700}
                    .credit{color:#047857;font-weight:700}
                    .empty{text-align:center;padding:24px;color:#64748b}
                    footer{margin-top:12px;padding-top:8px;border-top:1px solid #d1d5db;display:flex;justify-content:space-between;color:#64748b;font-size:9px}
                    @page{size:A4 landscape;margin:12mm}
                    @media print{body{padding:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
                    @media screen and (max-width:700px){body{padding:12px}.summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
                </style>
            </head>
            <body>
                <header>
                    <div>
                        <div class="company">${logoHtml}<div><h1>${escapeHtml(companyInfo.value.name || 'المؤسسة')}</h1><p>كشف حساب مالي رسمي</p></div></div>
                        <p>${escapeHtml(companyInfo.value.address || '')}${companyInfo.value.phone ? ` • ${escapeHtml(companyInfo.value.phone)}` : ''}</p>
                    </div>
                    <div>
                        <h2>${escapeHtml(isCustomer.value ? 'العميل' : 'المورد')}: ${escapeHtml(partyInfo.value.name || printData.account?.name || '')}</h2>
                        <p>رقم الحساب: <strong>${escapeHtml(printData.account?.code || '')}</strong></p>
                        <p class="period">الفترة: ${escapeHtml(startDate.value)} - ${escapeHtml(endDate.value)}</p>
                    </div>
                </header>
                <section class="summary">
                    <div><span>الرصيد الافتتاحي</span><strong>${formatAmount(printData.openingBalance)} ${escapeHtml(currencySymbol.value)}</strong></div>
                    <div><span>إجمالي مدين</span><strong>${formatAmount(printData.totalDebit)} ${escapeHtml(currencySymbol.value)}</strong></div>
                    <div><span>إجمالي دائن</span><strong>${formatAmount(printData.totalCredit)} ${escapeHtml(currencySymbol.value)}</strong></div>
                    <div><span>الرصيد الختامي</span><strong>${formatAmount(printData.closingBalance)} ${escapeHtml(currencySymbol.value)}</strong></div>
                </section>
                <table>
                    <thead><tr>
                        <th>التاريخ</th><th>رقم العملية</th><th>نوع العملية</th>
                        <th>البيان</th><th>مدين</th><th>دائن</th><th>الرصيد</th>
                    </tr></thead>
                    <tbody>${rowsHtml}</tbody>
                </table>
                <footer><span>عدد الحركات: ${rows.length}</span><span>تاريخ الطباعة: ${escapeHtml(new Date().toLocaleString('en-US'))}</span></footer>
            </body>
            </html>`;

        win.addEventListener('afterprint', () => win.close(), { once: true });
        win.document.open();
        win.document.write(html);
        win.document.close();
        win.setTimeout(async () => {
            try {
                await win.document.fonts?.ready;
                if (win.closed) return;
                win.focus();
                win.print();
            } catch (error) {
                if (!win.closed) win.close();
                console.error('[AccountStatement] Browser print failed:', error);
                showToast(error?.message || 'تعذر بدء طباعة كشف الحساب.', 'error');
            }
        }, 100);
    } catch (error) {
        if (!win.closed) win.close();
        console.error('[AccountStatement] PDF print failed:', error);
        showToast(error?.message || 'تعذر تجهيز كشف الحساب للطباعة.', 'error');
    }
};

const exportCsv = () => {
    if (!data.value || !Array.isArray(data.value.transactions)) return;
    const rows = [['التاريخ', 'رقم العملية', 'نوع العملية', 'الوصف', 'مدين', 'دائن', 'الرصيد']];
    data.value.transactions.forEach(t => {
        rows.push([
            formatDate(t.date),
            documentNumber(t),
            (t.referenceLabel || refTypeLabel(t.reference)),
            t.description || '-',
            t.debit > 0 ? Number(t.debit).toFixed(2) : '0.00',
            t.credit > 0 ? Number(t.credit).toFixed(2) : '0.00',
            Number(t.balance || 0).toFixed(2)
        ]);
    });
    const csv = rows.map(r => r.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `account_statement_${startDate.value}_${endDate.value}.csv`;
    a.click();
    URL.revokeObjectURL(url);
};

const exportReferencesCsv = () => {
    const items = referencesItems.value || [];
    if (!items.length) return;
    const rows = [['التاريخ', 'النوع', 'المرجع', 'رقم الفاتورة', 'الحالة', 'الصافي', 'الضريبة', 'الإجمالي', 'المدفوع', 'المتبقي', 'قيد محاسبي']];
    items.forEach(r => {
        rows.push([
            r.date || '',
            r.reference ? refTypeLabel(r.reference) : (r.type || ''),
            r.reference || '',
            r.invoiceNumber || '',
            r.statusLabel || r.status || '',
            Number(r.netTotalAmount || 0).toFixed(2),
            Number(r.taxAmount || 0).toFixed(2),
            Number(r.totalAmount || grossTotal(r) || 0).toFixed(2),
            Number(r.paidAmount || 0).toFixed(2),
            Number(r.outstanding || 0).toFixed(2),
            r.hasJournal ? '1' : '0',
        ]);
    });
    const escapeCsv = (v) => {
        if (v === null || v === undefined) return '';
        const s = String(v);
        return /[\n\r",]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const csv = rows.map(row => row.map(escapeCsv).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const party = String(partyInfo.value?.name || '').trim() || 'party';
    a.href = url;
    a.download = `references_${party}_${referencesSubTab.value}_${startDate.value}_${endDate.value}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
};

onMounted(async () => {
    if (!canViewStatement.value) {
        showToast('ليس لديك صلاحية لعرض كشوفات الحسابات', 'error');
        logDenial('view_statement', type.value, id.value, 'insufficient_permission');
        router.back();
        return;
    }
    logAccess('view_statement', type.value, id.value);
    fetchSettings();
    try {
        const sTab = localStorage.getItem('references_sub_tab');
        if (sTab && (sTab === 'invoices' || sTab === 'vouchers' || sTab === 'payments')) referencesSubTab.value = sTab;
    } catch (_) {}
    try {
        companyInfo.value.name = localStorage.getItem('pos_company_name') || localStorage.getItem('company_name') || '';
        companyInfo.value.phone = localStorage.getItem('pos_company_phone') || localStorage.getItem('company_phone') || '';
        companyInfo.value.logo = getImageUrl(localStorage.getItem('pos_company_logo') || localStorage.getItem('company_logo')) || '';
        companyInfo.value.email = localStorage.getItem('pos_company_email') || localStorage.getItem('company_email') || '';
        const addrPartsLS = [
            localStorage.getItem('pos_company_address') || localStorage.getItem('company_address') || '',
            localStorage.getItem('pos_company_city') || localStorage.getItem('company_city') || '',
            localStorage.getItem('pos_company_state') || localStorage.getItem('company_state') || '',
            localStorage.getItem('pos_company_country') || localStorage.getItem('company_country') || ''
        ].filter(Boolean);
        companyInfo.value.address = addrPartsLS.join(', ');
        if (!companyInfo.value.name || !companyInfo.value.address || !companyInfo.value.email || !companyInfo.value.phone) {
            try {
                const s2 = await settingsStore.fetchSettings();
                const raw = s2?.data || s2?.settings || s2 || {};
                const get = (k) => raw[k] ?? raw[k.replace(/\./g, '_')] ?? '';
                const addrParts2 = [get('company.address'), get('company.city'), get('company.state'), get('company.country')].filter(Boolean);
                companyInfo.value.name = get('company.name') || companyInfo.value.name;
                companyInfo.value.address = addrParts2.join(', ') || companyInfo.value.address;
                companyInfo.value.phone = get('company.phone') || companyInfo.value.phone;
                companyInfo.value.email = get('company.email') || companyInfo.value.email;
                if (!companyInfo.value.logo) companyInfo.value.logo = get('company.logo') || get('company.logo_url') || '';
            } catch (_) { /* ignore */ }
        }
    } catch (_) { /* ignore */ }

    try {
        if (type.value === 'customers') {
            let c = null;
            try {
                const r1 = await customerStore.fetchCustomerById(id.value);
                c = r1;
            } catch (_) {}
            if (!c) {
                try {
                    const r2 = await customerStore.fetchCustomers();
                    const arr2 = r2.data || [];
                    if (Array.isArray(arr2)) c = arr2.find(x => Number(x.id) === Number(id.value)) || null;
                } catch (_) {}
            }
            if (c) {
                partyInfo.value = {
                    name: c.name || c.companyName || c.contactName || '',
                    address: c.address || c.billingAddress || '',
                    phone: c.phone || c.mobile || '',
                    email: c.email || ''
                };
            }
        } else if (type.value === 'suppliers') {
            let s = null;
            try {
                const r1 = await supplierStore.fetchSupplierById(id.value);
                s = r1;
            } catch (_) {}
            if (!s) {
                try {
                    const r2 = await supplierStore.fetchSuppliers();
                    const arr2 = r2.data || [];
                    if (Array.isArray(arr2)) s = arr2.find(x => Number(x.id) === Number(id.value)) || null;
                } catch (_) {}
            }
            if (s) {
                partyInfo.value = {
                    name: s.name || s.companyName || s.contactName || '',
                    address: s.address || s.billingAddress || '',
                    phone: s.phone || s.mobile || '',
                    email: s.email || ''
                };
            }
        }
    } catch (_) { /* ignore */ }

    try {
        await paymentStore.fetchPaymentMethods();
    } catch (_) { /* paymentMethods computed from paymentStore */ }
    try {
        await branchStore.fetchBranches();
    } catch (_) { /* branches computed from branchStore */ }

    fetchStatement();
});

const previousFilter = ref({ 
  startDate: startDate.value, 
  endDate: endDate.value 
});

watch([page, perPage, startDate, endDate], async () => {
    const filterChanged = 
        previousFilter.value.startDate !== startDate.value || 
        previousFilter.value.endDate !== endDate.value;
    if (filterChanged) {
        page.value = 1;
        previousFilter.value = { startDate: startDate.value, endDate: endDate.value };
    }
    const result = await fetchStatementData(type.value, id.value, {
        startDate: startDate.value,
        endDate: endDate.value,
        page: page.value,
        perPage: perPage.value,
    });
    if (result.status !== 'success') {
        handleStatementError(result);
    }
}, { deep: true });

watch(referencesSubTab, (v) => {
    try { localStorage.setItem('references_sub_tab', v); } catch (_) {}
    refsPage.value = 1;
});

const handleStatementError = (result) => {
    if (!result.error) return;
    const { type, message, retryable, showRetry } = result.error;
    if (type === 'session_expired') {
        showToast('انتهت جلستك. يرجى تسجيل الدخول من جديد.', 'error');
        setTimeout(() => router.push('/login'), 1500);
        return;
    }
    if (type === 'permission_denied') {
        showToast('ليس لديك صلاحية لعرض هذه البيانات.', 'error');
        return;
    }
    if (type === 'not_found') {
        showToast('البيانات المطلوبة غير موجودة.', 'error');
        return;
    }
    if (type === 'server_error') {
        showToast('حدث خطأ في السيرفر. يرجى المحاولة لاحقاً.', 'error');
        return;
    }
    showToast(message || 'فشل تحميل البيانات', 'error');
};

watch(() => route.query, (q) => {
    if (_isApplyingFilter) return;
    if (q.startDate) startDate.value = q.startDate;
    if (q.endDate) endDate.value = q.endDate;
    if (typeof q.fillGaps !== 'undefined') fillGaps.value = q.fillGaps !== '0';
    if (typeof q.onlyNonzero !== 'undefined') onlyNonZero.value = q.onlyNonzero === '1';
    if (typeof q.type !== 'undefined') typeFilter.value = q.type;
    if (typeof q.status !== 'undefined') statusFilter.value = q.status;
    if (typeof q.paymentMethodId !== 'undefined') paymentMethodId.value = q.paymentMethodId;
    if (typeof q.view !== 'undefined') viewMode.value = q.view;
    if (typeof q.txPerPage !== 'undefined') txPerPage.value = Number(q.txPerPage) || 20;
    if (typeof q.txPage !== 'undefined') txPage.value = Number(q.txPage) || 1;
    fetchStatement();
}, { deep: true });
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v3 {
  @apply h-11 w-full bg-white border border-slate-200 rounded-xl px-3 text-xs font-semibold text-slate-700 hover:border-slate-300 focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.metadata-label { @apply text-slate-500 tracking-wide mb-1; }

.quick-range-pill {
  @apply px-3.5 py-2 rounded-lg bg-white border border-slate-200 text-[10px] font-bold text-slate-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-10px); }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.border-r-rose-200   { border-right: 4px solid #fecaca; }
.border-r-emerald-200 { border-right: 4px solid #a7f3d0; }
.border-r-blue-200   { border-right: 4px solid #bfdbfe; }

.status-badge { @apply inline-flex items-center px-2 py-0.5 rounded-md font-bold; }
</style>
