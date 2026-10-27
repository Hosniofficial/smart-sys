<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar: High-precision indicator -->


    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: { label: 'التقارير', path: '/reports' }, current: { label: 'التقارير المالية والمحاسبية', path: '/reports/accounting' } }"
        title="التقارير المالية والمحاسبية"
        description="موازين المراجعة والقوائم الختامية • IAS/IFRS Compliant"
        :branches="branches"
        :selectedBranch="selectedBranch === 'all' ? null : selectedBranch"
        :hasExplicitSelection="true"
        @branch-changed="selectedBranch = $event === null ? 'all' : $event"
      >
        <template #controls>
          <LoadingButton
            text="تحديث التقارير"
            loadingText="جاري التحديث..."
            icon="fas fa-sync-alt"
            :isLoading="loadingAll"
            variant="secondary"
            class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs shadow-sm hover:bg-slate-50"
            @click="loadAll"
          />
        </template>
      </PageHeader>

      <main class="space-y-8">

      <!-- Analytical Filters Utility Bar -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-6 items-end">
          
          <div class="lg:col-span-2 space-y-1.5 group">
            <label class="metadata-label">من تاريخ</label>
            <div class="relative">
              <input ref="startRef" type="date" v-model="filters.start" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
              <i @click="startRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
            </div>
          </div>

          <div class="lg:col-span-2 space-y-1.5 group">
            <label class="metadata-label">إلى تاريخ</label>
            <div class="relative">
              <input ref="endRef" type="date" v-model="filters.end" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
              <i @click="endRef?.showPicker?.()" class="fas fa-calendar-check absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
            </div>
          </div>

          <div class="lg:col-span-2 space-y-1.5 group">
            <label class="metadata-label">الميزانية كما في</label>
            <div class="relative">
              <input ref="asOfRef" type="date" v-model="filters.asOf" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
              <i @click="asOfRef?.showPicker?.()" class="fas fa-history absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
            </div>
          </div>

          <div class="lg:col-span-4 space-y-1.5">
            <label class="metadata-label">حساب الأستاذ المساعد</label>
            <select v-model.number="filters.ledgerAccountId" class="filter-input-v2 appearance-none font-bold">
              <option value="">-- عرض كافة الحسابات --</option>
              <optgroup label="حسابات المنشأة" v-if="tenantAccounts.length">
                <option v-for="acc in tenantAccounts" :key="'lg-tenant-'+acc.id" :value="acc.id">{{ accOptionLabel(acc) }}</option>
              </optgroup>
              <optgroup label="الحسابات الافتراضية" v-if="globalAccounts.length">
                <option v-for="acc in globalAccounts" :key="'lg-global-'+acc.id" :value="acc.id">{{ accOptionLabel(acc) }}</option>
              </optgroup>
            </select>
          </div>

          <div class="lg:col-span-2">
             <LoadingButton
               text="تطبيق الفلاتر"
               loadingText="جاري التحديث..."
               :isLoading="loadingAll"
               variant="secondary"
               class="h-9 w-full rounded-md text-[11px] uppercase tracking-widest shadow-sm"
               @click="loadAll"
             />
          </div>
        </div>
      </section>

      <!-- Primary Reports Row -->
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Trial Balance: High-Density Audit Grid -->
        <section class="lg:col-span-7 bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm flex flex-col">
          <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
             <h2 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
               <i class="fas fa-list-check text-blue-500"></i>
               ميزان المراجعة بالمجاميع
             </h2>
             <span class="text-[9px] font-bold text-slate-400 bg-white border border-slate-200 px-2 py-0.5 rounded uppercase tracking-tighter">Trial Balance</span>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-white border-b border-slate-100 text-slate-400 font-bold uppercase text-[9px] tracking-widest">
                  <th class="px-6 py-4">كود الحساب</th>
                  <th class="px-4 py-4">اسم الحساب</th>
                  <th class="px-4 py-4">النوع</th>
                  <th class="px-4 py-4 text-center">مدين (+)</th>
                  <th class="px-6 py-4 text-center">دائن (-)</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium text-xs">
                <tr v-for="row in trialBalance" :key="row.account_id" class="hover:bg-slate-50 transition-colors group">
                  <td class="px-6 py-3 font-mono font-bold text-blue-600">{{ row.code }}</td>
                  <td class="px-4 py-3 text-slate-700 font-bold">{{ row.name }}</td>
                  <td class="px-4 py-3 text-slate-400 text-[10px]">{{ row.type || '—' }}</td>
                  <td class="px-4 py-3 text-center font-mono font-bold text-rose-600">{{ fmt(row.total_debit) }}</td>
                  <td class="px-6 py-3 text-center font-mono font-bold text-emerald-600">{{ fmt(row.total_credit) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Sidebar Summary Area -->
        <aside class="lg:col-span-5 space-y-8">
          <!-- Income Statement: High-Contrast SaaS Accent -->
          <div class="bg-slate-900 rounded-xl p-8 text-white shadow-2xl relative overflow-hidden group border border-white/5">
            <div class="absolute top-0 left-0 w-32 h-32 bg-blue-600/10 rounded-full -translate-x-12 -translate-y-12"></div>
            
            <h2 class="text-[10px] font-bold text-blue-400 uppercase tracking-[0.3em] mb-10 flex items-center gap-2 relative z-10">
               <i class="fas fa-chart-line text-xs"></i> ملخص قائمة الدخل
            </h2>
            
            <div class="space-y-6 relative z-10">
              <div class="flex justify-between items-center">
                <span class="text-[11px] font-medium text-white/50 uppercase tracking-widest">إجمالي الإيرادات</span>
                <span class="text-xl font-bold font-mono text-emerald-400">+{{ fmt(incomeStatement.revenues) }}</span>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-[11px] font-medium text-white/50 uppercase tracking-widest">إجمالي التكاليف (COGS)</span>
                <span class="text-xl font-bold font-mono text-rose-400">-{{ fmt(incomeStatement.expenses) }}</span>
              </div>
              <div class="pt-6 border-t border-white/10 flex justify-between items-center">
                <div class="flex flex-col">
                  <span class="text-xs font-bold uppercase tracking-widest text-blue-400">صافي الأرباح</span>
                  <span class="text-[8px] font-bold text-white/20 uppercase tracking-tighter mt-1 leading-none">Net Comprehensive Income</span>
                </div>
                <span class="text-3xl font-bold font-mono tracking-tighter" :class="incomeStatement.net_income >= 0 ? 'text-emerald-500' : 'text-rose-500'">{{ fmt(incomeStatement.net_income) }}</span>
              </div>
            </div>
          </div>

          <!-- Branch Audit Coverage -->
          <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-8 px-1">
              <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">سلامة ربط الفروع</h3>
              <LoadingButton
                text=""
                loadingText=""
                :showTextDuringLoad="false"
                icon="fas fa-rotate"
                :isLoading="loadingCoverage"
                variant="secondary"
                class="w-8 h-8 rounded-lg bg-slate-50 p-0 text-slate-400 shadow-inner hover:text-blue-600"
                aria-label="تحديث سلامة ربط الفروع"
                @click="loadCoverage"
              />
            </div>

            <div v-if="coverage" class="space-y-6">
              <div class="grid grid-cols-2 gap-4">
                 <div class="p-4 bg-slate-50 border border-slate-100 rounded-lg text-center">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">المواقع</p>
                    <p class="text-xl font-bold text-slate-900">{{ coverage.total_branches }}</p>
                 </div>
                 <div :class="[coverage.missing_count > 0 ? 'bg-rose-50 border-rose-100 text-rose-600' : 'bg-emerald-50 border-emerald-100 text-emerald-600']" class="p-4 border rounded-lg text-center">
                    <p class="text-[9px] font-bold uppercase tracking-widest mb-1">غير مرتبطة</p>
                    <p class="text-xl font-bold font-mono">{{ coverage.missing_count }}</p>
                 </div>
              </div>

              <div v-if="(coverage.missing_branches || []).length" class="border border-slate-100 rounded-lg overflow-hidden">
                <table class="w-full text-right text-[10px]">
                   <tbody class="divide-y divide-slate-50">
                     <tr v-for="w in coverage.missing_branches" :key="w.id" class="hover:bg-rose-50 transition-colors">
                       <td class="px-4 py-2.5 font-bold text-slate-700">{{ w.name }}</td>
                       <td class="px-4 py-2.5 text-left"><span class="text-rose-500 font-bold uppercase tracking-tighter">Unlinked</span></td>
                     </tr>
                   </tbody>
                </table>
              </div>
              <div v-else class="p-4 rounded-lg bg-emerald-50 border border-emerald-100 flex items-center gap-3">
                 <i class="fas fa-check-double text-emerald-500 text-xs"></i>
                 <p class="text-[10px] font-bold text-emerald-800 uppercase tracking-tight">كافة الفروع مرتبطة ومؤمنة محاسبياً.</p>
              </div>
            </div>
          </div>
        </aside>
      </div>

      <!-- Balance Sheet: Pro 3-Column Financial Layout -->
      <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
        <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
          <span class="w-1.5 h-6 bg-indigo-600 rounded-full"></span>
          <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest">الميزانية العمومية (Statement of Financial Position)</h2>
        </div>
        
        <div class="p-8 grid grid-cols-1 md:grid-cols-3 gap-12 divide-x divide-slate-100 divide-x-reverse">
          <!-- Column Template -->
          <div v-for="category in [
            { id: 'asset', label: 'الأصول (Assets)', icon: 'fa-vault', color: 'text-blue-600', border: 'border-blue-100' },
            { id: 'liability', label: 'الخصوم (Liabilities)', icon: 'fa-hand-holding-dollar', color: 'text-rose-600', border: 'border-rose-100' },
            { id: 'equity', label: 'حقوق الملكية (Equity)', icon: 'fa-scale-balanced', color: 'text-emerald-600', border: 'border-emerald-100' }
          ]" :key="category.id" class="space-y-6">
            <h3 :class="[category.color, category.border]" class="text-[10px] font-bold uppercase tracking-[0.3em] border-b-2 pb-3">
              <i :class="['fas', category.icon, 'ml-2']"></i> {{ category.label }}
            </h3>
            <ul class="space-y-4">
              <li v-for="item in balanceSheet[category.id]" :key="item.id" class="flex justify-between items-baseline group">
                <span class="text-xs font-bold text-slate-500 group-hover:text-slate-900 transition-colors uppercase tracking-tight">{{ item.code }} — {{ item.name }}</span>
                <span class="font-bold font-mono text-xs tracking-tighter text-slate-900">{{ fmt(item.balance) }}</span>
              </li>
              <li v-if="!balanceSheet[category.id].length" class="text-[10px] text-slate-300 italic uppercase">لا توجد بيانات مسجلة</li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Advanced Compliance Reports -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        
        <!-- NRV Report: Warning Style -->
        <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
          <div class="px-6 py-4 bg-amber-50/50 border-b border-amber-100 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <i class="fas fa-triangle-exclamation text-amber-500 text-xs"></i>
              <h2 class="text-[11px] font-bold text-slate-800 uppercase tracking-widest">تحليل صافي القيمة البيعية (NRV)</h2>
            </div>
            <div class="flex items-center gap-2">
              <LoadingButton
                text=""
                loadingText=""
                :showTextDuringLoad="false"
                icon="fas fa-rotate"
                :isLoading="loadingNrv"
                variant="secondary"
                class="w-7 h-7 rounded border border-amber-200 bg-white p-0 text-amber-500 hover:bg-amber-500 hover:text-white"
                aria-label="تحديث تحليل صافي القيمة البيعية"
                @click="loadNrv"
              />
              <button v-if="nrvData?.count > 0" @click="postWriteDown" class="h-7 px-3 bg-rose-600 text-white text-[9px] font-bold uppercase tracking-widest rounded shadow-sm hover:bg-rose-700">تسجيل قيد تخفيض</button>
            </div>
          </div>
          <div class="p-6">
            <div v-if="!nrvData" class="py-12 text-center text-[10px] text-slate-400 font-bold uppercase tracking-widest">بانتظار تحديث التقرير</div>
            <div v-else-if="nrvData.count === 0" class="py-12 text-center text-emerald-600 flex flex-col items-center gap-2"><i class="fas fa-check-circle text-2xl"></i><p class="text-[10px] font-bold uppercase tracking-widest">كافة المخزون مطابق لمعايير IAS 2</p></div>
            <div v-else class="space-y-4">
               <div class="flex justify-between items-center p-3 bg-amber-50 border border-amber-100 rounded-lg">
                 <span class="text-[10px] font-bold text-amber-800 uppercase">إجمالي مخصص التخفيض المطلوب:</span>
                 <span class="text-sm font-bold font-mono text-rose-600">{{ fmt(nrvData.total_impairment) }}</span>
               </div>
               <!-- writeDownResult -->
               <div v-if="writeDownResult" :class="writeDownResult.status === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700'" class="p-3 rounded-lg border text-[10px] font-bold flex items-center justify-between">
                 <span><i :class="writeDownResult.status === 'success' ? 'fas fa-check-circle' : 'fas fa-times-circle'" class="ml-2"></i>{{ writeDownResult.status === 'success' ? 'تم تسجيل قيد التخفيض بنجاح' : (writeDownResult.message || 'فشل تسجيل القيد') }}</span>
                 <span v-if="writeDownResult.status === 'success' && writeDownResult.data?.total_amount" class="font-mono">{{ fmt(writeDownResult.data.total_amount) }}</span>
               </div>
               <div class="overflow-x-auto max-h-48 custom-scroll">
                  <table class="w-full text-right text-[10px] font-medium border-collapse">
                    <thead class="bg-slate-50 sticky top-0"><tr class="text-slate-400 font-bold uppercase border-b border-slate-100"><th class="py-2 px-3">المنتج</th><th class="py-2 px-3">الفرع</th><th class="py-2 px-3 text-center">الكمية</th><th class="py-2 px-3 text-center">متوسط التكلفة</th><th class="py-2 px-3 text-center">سعر البيع</th><th class="py-2 px-3 text-center">فرق الوحدة</th><th class="py-2 px-3 text-left">إجمالي الفرق</th></tr></thead>
                    <tbody class="divide-y divide-slate-50">
                      <tr v-for="item in nrvData.items" :key="`${item.product_id}-${item.branch_id}`" class="hover:bg-slate-50">
                        <td class="py-2 px-3 font-bold text-slate-700">{{ item.product_name }}</td>
                        <td class="py-2 px-3 text-slate-500">{{ item.branch_name || '—' }}</td>
                        <td class="py-2 px-3 text-center font-mono">{{ item.qty_on_hand }}</td>
                        <td class="py-2 px-3 text-center font-mono text-slate-600">{{ fmt(item.avg_cost ?? item.unit_cost) }}</td>
                        <td class="py-2 px-3 text-center font-mono text-slate-600">{{ fmt(item.nrv ?? item.sale_price) }}</td>
                        <td class="py-2 px-3 text-center font-mono" :class="(item.unit_diff ?? item.margin_per_unit ?? ((item.nrv ?? item.sale_price) - (item.avg_cost ?? item.unit_cost))) < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ fmt(item.unit_diff ?? item.margin_per_unit ?? ((item.nrv ?? item.sale_price) - (item.avg_cost ?? item.unit_cost))) }}</td>
                        <td class="py-2 px-3 text-left font-bold font-mono text-rose-600">{{ fmt(item.impairment_amount) }}</td>
                      </tr>
                    </tbody>
                  </table>
               </div>
            </div>
          </div>
        </section>

        <!-- AR Aging: Risk Indicator Style -->
        <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
          <div class="px-6 py-4 bg-rose-50/50 border-b border-rose-100 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <i class="fas fa-hourglass-half text-rose-500 text-xs"></i>
              <h2 class="text-[11px] font-bold text-slate-800 uppercase tracking-widest">تحليل أعمار الذمم (AR Aging)</h2>
            </div>
            <div class="flex items-center gap-2">
              <input type="date" v-model="arAgingAsOf" class="h-7 border border-rose-200 rounded px-2 text-[10px] font-mono outline-none focus:border-rose-400 bg-white" />
              <LoadingButton
                text=""
                loadingText=""
                :showTextDuringLoad="false"
                icon="fas fa-rotate"
                :isLoading="loadingArAging"
                variant="secondary"
                class="w-7 h-7 rounded border border-rose-200 bg-white p-0 text-rose-500 hover:bg-rose-500 hover:text-white"
                aria-label="تحديث تحليل أعمار الديون"
                @click="loadArAging"
              />
            </div>
          </div>
          <div class="p-6 space-y-6">
            <div v-if="!arAgingData" class="py-12 text-center text-[10px] text-slate-400 font-bold uppercase tracking-widest">تحديث البيانات للعرض</div>
            <template v-else>
               <div class="grid grid-cols-2 gap-4">
                  <div class="p-4 bg-slate-900 rounded-lg text-white">
                    <p class="text-[8px] font-bold uppercase text-blue-400 tracking-widest mb-1">إجمالي الذمم</p>
                    <p class="text-lg font-bold font-mono tracking-tighter">{{ fmt(arAgingData.total_outstanding) }}</p>
                  </div>
                  <div class="p-4 bg-rose-50 border border-rose-100 rounded-lg">
                    <p class="text-[8px] font-bold uppercase text-rose-400 tracking-widest mb-1">مخصص ECL (IFRS 9)</p>
                    <p class="text-lg font-bold font-mono tracking-tighter text-rose-600">{{ fmt(arAgingData.total_provision) }}</p>
                  </div>
               </div>

               <!-- Buckets: progress bars + ECL rate + detail table -->
               <div class="space-y-6">
                 <div v-for="(bucket, k) in arAgingData.buckets" :key="k" class="space-y-2">
                   <div class="flex justify-between items-end">
                     <span class="text-[9px] font-bold text-slate-500 uppercase">{{ bucket.label }}</span>
                     <div class="flex items-center gap-3">
                       <span v-if="bucket.rate != null" class="text-[9px] font-bold text-slate-400">معدل ECL: {{ (bucket.rate * 100).toFixed(0) }}%</span>
                       <span class="text-[10px] font-bold font-mono text-slate-900">{{ fmt(bucket.total_outstanding) }}</span>
                     </div>
                   </div>
                   <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                     <div class="h-full bg-rose-500 transition-all duration-1000" :style="{ width: Math.min(100, (bucket.total_outstanding / arAgingData.total_outstanding) * 100) + '%' }"></div>
                   </div>
                   <!-- Invoices detail table per bucket -->
                   <div v-if="bucket.invoices && bucket.invoices.length > 0" class="overflow-x-auto mt-2">
                     <table class="w-full text-right text-[9px] font-medium border-collapse">
                       <thead><tr class="text-slate-400 font-bold uppercase border-b border-slate-100 bg-slate-50"><th class="px-3 py-1.5">رقم الفاتورة</th><th class="px-3 py-1.5">العميل</th><th class="px-3 py-1.5">التاريخ</th><th class="px-3 py-1.5 text-center">أيام التقادم</th><th class="px-3 py-1.5 text-center">الإجمالي</th><th class="px-3 py-1.5 text-center">المدفوع</th><th class="px-3 py-1.5 text-center">المتبقي</th><th class="px-3 py-1.5 text-center">المخصص</th></tr></thead>
                       <tbody class="divide-y divide-slate-50">
                         <tr v-for="inv in bucket.invoices" :key="inv.id ?? inv.sale_id" class="hover:bg-rose-50/30">
                           <td class="px-3 py-1.5 font-mono font-bold text-blue-600">#{{ inv.id ?? inv.sale_id }}</td>
                           <td class="px-3 py-1.5 text-slate-700">{{ inv.customer_name }}</td>
                           <td class="px-3 py-1.5 font-mono text-slate-400">{{ inv.invoice_date ?? inv.sale_date }}</td>
                           <td class="px-3 py-1.5 text-center font-mono font-bold text-rose-600">{{ inv.days_overdue ?? inv.days_outstanding }}</td>
                           <td class="px-3 py-1.5 text-center font-mono">{{ fmt(inv.total ?? inv.net_total) }}</td>
                           <td class="px-3 py-1.5 text-center font-mono text-emerald-600">{{ fmt(inv.paid) }}</td>
                           <td class="px-3 py-1.5 text-center font-mono font-bold">{{ fmt(inv.outstanding) }}</td>
                           <td class="px-3 py-1.5 text-center font-mono text-rose-600">{{ fmt(inv.provision ?? inv.ecl_provision) }}</td>
                         </tr>
                       </tbody>
                     </table>
                   </div>
                 </div>
               </div>

               <!-- postProvision button + result -->
               <div class="pt-4 border-t border-slate-100 space-y-3">
                 <button v-if="arAgingData.total_provision > 0" @click="postProvision" :disabled="postingProvision" class="h-8 px-4 bg-rose-600 text-white text-[9px] font-bold uppercase tracking-widest rounded shadow-sm hover:bg-rose-700 disabled:opacity-50 flex items-center gap-2">
                   <i class="fas fa-book text-[9px]"></i>
                   {{ postingProvision ? 'جارٍ التسجيل...' : 'تسجيل قيد المخصص (IFRS 9)' }}
                 </button>
                 <div v-if="provisionResult" :class="provisionResult.status === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700'" class="p-3 rounded-lg border text-[10px] font-bold flex items-center justify-between">
                   <span><i :class="provisionResult.status === 'success' ? 'fas fa-check-circle' : 'fas fa-times-circle'" class="ml-2"></i>{{ provisionResult.status === 'success' ? 'تم تسجيل قيد المخصص بنجاح' : (provisionResult.message || 'فشل تسجيل القيد') }}</span>
                   <span v-if="provisionResult.status === 'success' && provisionResult.data?.entry_id" class="font-mono text-[9px]">قيد #{{ provisionResult.data.entry_id }}</span>
                 </div>
               </div>
            </template>
          </div>
        </section>
      </div>

      <!-- Cash Flow Statement: IAS 7 -->
      <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
          <h2 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
            <i class="fas fa-water text-blue-500"></i>
            قائمة التدفقات النقدية (IAS 7)
          </h2>
          <div class="flex items-center gap-2">
            <LoadingButton
              text=""
              loadingText=""
              :showTextDuringLoad="false"
              icon="fas fa-rotate"
              :isLoading="loadingCashFlow"
              variant="secondary"
              class="w-8 h-8 rounded-lg bg-slate-50 p-0 text-slate-400 hover:text-blue-600"
              aria-label="تحديث قائمة التدفقات النقدية"
              @click="loadCashFlow"
            />
            <span class="text-[9px] font-bold text-slate-400 bg-white border border-slate-200 px-2 py-0.5 rounded uppercase tracking-tighter">Cash Flow</span>
          </div>
        </div>
        <div class="p-6">
          <div v-if="loadingCashFlow" class="py-12 text-center">
            <div class="flex items-center justify-center gap-1">
              <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
              <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
              <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
            </div>
          </div>
          <div v-else-if="!cashFlowData" class="py-12 text-center text-[10px] text-slate-400 font-bold uppercase tracking-widest">بانتظار تحديث التقرير</div>
          <div v-else class="space-y-8">
            <div v-for="(section, type) in cashFlowData.sections" :key="type" class="space-y-3">
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <h3 class="text-[10px] font-bold text-slate-700 uppercase tracking-widest">{{ section.label }}</h3>
                <span class="text-sm font-bold font-mono" :class="section.total >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ fmt(section.total) }}</span>
              </div>
              <table class="w-full text-right text-[10px] font-medium border-collapse">
                <tbody class="divide-y divide-slate-50">
                  <tr v-for="line in (section.items || section.lines || [])" :key="`${line.reference_type}-${line.account_code}-${line.label}`" class="hover:bg-slate-50">
                    <td class="py-1.5 px-3 text-slate-600">{{ line.label }}</td>
                    <td class="py-1.5 px-3 text-left font-mono font-bold" :class="(line.net ?? line.amount) >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ fmt(line.net ?? line.amount) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="flex justify-between items-center p-4 bg-slate-900 rounded-xl text-white">
              <span class="text-[10px] font-bold uppercase tracking-widest text-blue-400">صافي التغير في النقدية</span>
              <span class="text-xl font-bold font-mono tracking-tighter" :class="cashFlowData.net_change >= 0 ? 'text-emerald-400' : 'text-rose-400'">{{ fmt(cashFlowData.net_change) }}</span>
            </div>
          </div>
        </div>
      </section>

      <!-- General Ledger Detail: Full Audit Log (Bottom Fixed Context) -->
      <section v-if="filters.ledgerAccountId" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm animate-fadeIn">
        <div class="px-8 py-5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
           <h2 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
             <i class="fas fa-file-invoice text-indigo-500"></i> تفاصيل دفتر الأستاذ للحساب
           </h2>
           <div class="bg-slate-900 px-4 py-1.5 rounded text-white flex items-center gap-4">
              <span class="text-[8px] font-bold uppercase tracking-[0.2em] text-blue-400">الرصيد الافتتاحي</span>
              <span class="text-sm font-bold font-mono tracking-tighter">{{ fmt(ledger.opening_balance) }}</span>
           </div>
        </div>
        <div class="overflow-x-auto max-h-[500px] custom-scroll">
          <table class="w-full text-right border-collapse text-[11px] font-medium">
            <thead class="sticky top-0 z-10 bg-slate-50 border-b border-slate-200">
              <tr class="text-slate-400 font-bold uppercase tracking-widest">
                <th class="px-8 py-4">تاريخ القيد</th>
                <th class="px-4 py-4">البيان المحاسبي</th>
                <th class="px-4 py-4 text-center">مدين (+)</th>
                <th class="px-8 py-4 text-center">دائن (-)</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="(line, idx) in ledger.lines" :key="idx" class="hover:bg-blue-50/10 transition-colors">
                <td class="px-8 py-3 text-slate-400 font-mono">{{ line.entry_date }}</td>
                <td class="px-4 py-3 font-bold text-slate-800">{{ line.description }}</td>
                <td class="px-4 py-3 text-center font-mono font-bold text-rose-600">{{ line.debit_amount > 0 ? fmt(line.debit_amount) : '—' }}</td>
                <td class="px-8 py-3 text-center font-mono font-bold text-emerald-600">{{ line.credit_amount > 0 ? fmt(line.credit_amount) : '—' }}</td>
              </tr>
              <tr v-if="!ledger.lines.length"><td colspan="4" class="py-12 text-center text-slate-300 uppercase font-bold tracking-widest italic">لا توجد حركات مسجلة للفترة</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      </main>
    </div>
  </div>
</template>

<script setup>
// [SCRIPT SECTION REMAINS 100% IDENTICAL AS PER THE CRITICAL BUSINESS LOGIC RULE]
import { ref, computed, onMounted, watch } from 'vue'
import { useReportsStore } from '@/stores/reports/reportsStore'
import { useAccountStore } from '@/stores/account/accountStore'
import { useBranchStore } from '@/stores/branch/branchStore';

import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import AlertService from '@/services/AlertService';

const { breadcrumb } = useBreadcrumb();
const branchStore = useBranchStore();
const branches = computed(() => branchStore.branches);
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId || 'all',
  set: (val) => branchStore.setSelectedBranch(val === 'all' ? null : val)
});
onMounted(() => {
  branchStore.fetchBranches().catch(() => {});
});
const reportsStore = useReportsStore();
const accountStore = useAccountStore();

const filters = ref({ start: '', end: '', asOf: '', ledgerAccountId: '' })
const startRef = ref(null); const endRef = ref(null); const asOfRef = ref(null);
const trialBalance = ref([]); const ledger = ref({ opening_balance: 0, lines: [] });
const incomeStatement = ref({ revenues: 0, expenses: 0, net_income: 0 });
const balanceSheet = ref({ asset: [], liability: [], equity: [] });
const coverage = ref(null);
const loadingCoverage = ref(false);
const loadingAll = ref(false);
const nrvData = ref(null); const loadingNrv = ref(false); const postingWriteDown = ref(false); const writeDownResult = ref(null);
const cashFlowData = ref(null); const loadingCashFlow = ref(false);
const arAgingData = ref(null); const loadingArAging = ref(false);
const arAgingAsOf = ref(new Date().toISOString().split('T')[0]);
const provisionResult = ref(null); const postingProvision = ref(false);
const tenantAccounts = ref([]); const globalAccounts = ref([]);

const accOptionLabel = (acc) => acc?.code ? `${acc.code} — ${acc.name}` : acc?.name || ''

function fmt(n) {
  if (n === undefined || n === null) return '—'
  const num = Number(n)
  return isNaN(num) ? '—' : num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function loadAll() {
  loadingAll.value = true
  const branchId = selectedBranch.value === 'all' ? null : selectedBranch.value
  try {
    const [tb, is, bs] = await Promise.allSettled([
      reportsStore.fetchTrialBalance(filters.value.start, filters.value.end, branchId),
      reportsStore.fetchIncomeStatement(filters.value.start, filters.value.end, branchId),
      reportsStore.fetchBalanceSheet(filters.value.asOf, branchId)
    ])

    trialBalance.value = tb.status === 'fulfilled' && tb.value.status === 'success'
      ? (tb.value.data?.items || []) : []

    incomeStatement.value = is.status === 'fulfilled' && is.value.status === 'success'
      ? (is.value.data || { revenues: 0, expenses: 0, net_income: 0 })
      : { revenues: 0, expenses: 0, net_income: 0 }

    balanceSheet.value = bs.status === 'fulfilled' && bs.value.status === 'success'
      ? (bs.value.data || { asset: [], liability: [], equity: [] })
      : { asset: [], liability: [], equity: [] }

    if (filters.value.ledgerAccountId) {
      const lgResult = await reportsStore.fetchLedger(filters.value.ledgerAccountId, filters.value.start, filters.value.end, branchId)
      ledger.value = lgResult.status === 'success' ? (lgResult.data || { opening_balance: 0, lines: [] }) : { opening_balance: 0, lines: [] }
    } else {
      ledger.value = { opening_balance: 0, lines: [] }
    }
  } catch (e) {
    console.error('Error loading accounting reports:', e);
  } finally {
    loadingAll.value = false
  }
}

async function loadAccounts() {
  try {
    const result = await accountStore.fetchAccounts()
    const payload = result.status === 'success' ? result.data : {}
    tenantAccounts.value = Array.isArray(payload) ? payload : (payload.tenant_accounts || [])
    globalAccounts.value = Array.isArray(payload) ? [] : (payload.global_accounts || [])
  } catch (_) { tenantAccounts.value = []; globalAccounts.value = []; }
}

async function loadCoverage() {
  loadingCoverage.value = true
  try {
    const result = await reportsStore.fetchBranchAccountCoverage(selectedBranch.value === 'all' ? null : selectedBranch.value)
    coverage.value = result.status === 'success' ? result.data : null
  } catch (e) { coverage.value = null; console.error(e); }
  finally { loadingCoverage.value = false }
}

async function loadArAging() {
  loadingArAging.value = true
  provisionResult.value = null
  try {
    const result = await reportsStore.fetchArAging(arAgingAsOf.value || null, selectedBranch.value === 'all' ? null : selectedBranch.value)
    arAgingData.value = result.status === 'success' ? result.data : null
  } catch (e) { arAgingData.value = null; console.error(e); }
  finally { loadingArAging.value = false }
}

async function postProvision() {
  if (!arAgingData.value?.total_provision) return
  const confirmed = await AlertService.confirm(
    `سيتم تسجيل قيد مخصص الديون المشكوك فيها بمبلغ ${fmt(arAgingData.value.total_provision)} وفق IFRS 9. هل أنت متأكد؟`,
    'تأكيد قيد المخصص',
    { confirmText: 'تسجيل القيد', cancelText: 'إلغاء' }
  )
  if (!confirmed) return
  postingProvision.value = true
  provisionResult.value = null
  try {
    provisionResult.value = await reportsStore.postBadDebtProvision(
      arAgingData.value.total_provision,
      arAgingAsOf.value || null
    )
  } catch (e) { console.error(e); }
  finally { postingProvision.value = false }
}

async function loadCashFlow() {
  loadingCashFlow.value = true
  try {
    const result = await reportsStore.fetchCashFlow(filters.value.start, filters.value.end, selectedBranch.value === 'all' ? null : selectedBranch.value)
    cashFlowData.value = result.status === 'success' ? result.data : null
  } catch (e) { cashFlowData.value = null; console.error(e); }
  finally { loadingCashFlow.value = false }
}

async function loadNrv() {
  loadingNrv.value = true
  try {
    const result = await reportsStore.fetchNrvReport(selectedBranch.value === 'all' ? null : selectedBranch.value)
    nrvData.value = result.status === 'success' ? result.data : null
  } catch (e) { nrvData.value = null; console.error(e); }
  finally { loadingNrv.value = false }
}

async function postWriteDown() {
  const confirmed = await AlertService.confirm(
    'سيتم تسجيل قيود تخفيض قيمة المخزون وفق IAS 2. هل أنت متأكد؟',
    'تأكيد قيود تخفيض المخزون',
    { confirmText: 'تسجيل القيود', cancelText: 'إلغاء' }
  )
  if (!confirmed) return
  postingWriteDown.value = true
  writeDownResult.value = null
  try {
    const result = await reportsStore.postNrvWriteDown(selectedBranch.value === 'all' ? null : selectedBranch.value)
    writeDownResult.value = result
    if (result.status === 'success') {
      await loadNrv()
    }
  } catch (e) { console.error(e); }
  finally { postingWriteDown.value = false }
}

onMounted(async () => {
  await Promise.all([
    loadAccounts(),
    loadAll(),
    loadCoverage(),
    loadCashFlow(),
    loadNrv(),
    loadArAging()
  ])
})

watch(selectedBranch, () => {
  loadAll()
  loadCoverage()
  loadCashFlow()
  loadNrv()
  loadArAging()
})
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply focus:ring-4 focus:ring-blue-500/5;
}

.metadata-label { @apply mb-1; }

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>