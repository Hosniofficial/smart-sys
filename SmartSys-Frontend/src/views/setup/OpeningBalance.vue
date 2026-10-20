<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="loading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="ترحيل الرصيد الافتتاحي"
        description="سجّل الكميات والتكاليف الافتتاحية للأصناف، وحدّد أرصدتها في كل فرع عند بدء استخدام النظام."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <button @click="preview" :disabled="loading" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all flex items-center gap-2">
            <i class="fas fa-magnifying-glass text-[10px]"></i> تحقق / Preview
          </button>
          <button @click="commit" :disabled="loading || items.length === 0" class="h-9 px-6 rounded-md bg-emerald-600 text-white text-xs font-bold shadow-lg shadow-emerald-900/20 hover:bg-emerald-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-check-double text-[10px]"></i> ترحيل السجل نهائياً
          </button>
        </template>
      </PageHeader>

      <!-- Strategy & Global Toggles: Professional SaaS Toolbar -->
      <section class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-6">
          <div class="flex flex-wrap items-center gap-8">
            <label class="custom-toggle group">
              <input type="checkbox" v-model="postAccounting" class="sr-only peer" />
              <div class="toggle-indicator peer-checked:bg-blue-600"></div>
              <span class="toggle-label">إنشاء قيد محاسبي تلقائي</span>
            </label>

            <label class="custom-toggle group">
              <input type="checkbox" v-model="setPurchasePriceIfZero" class="sr-only peer" />
              <div class="toggle-indicator peer-checked:bg-indigo-600"></div>
              <span class="toggle-label">تحديث سعر شراء الصنف</span>
            </label>

            <label class="custom-toggle group">
              <input type="checkbox" v-model="purchasesOnly" class="sr-only peer" />
              <div class="toggle-indicator peer-checked:bg-amber-500"></div>
              <span class="toggle-label">إنشاء مشتريات فقط (بدون رصيد)</span>
            </label>
          </div>

          <!-- Quick Actions Bar -->
          <div class="flex items-center gap-1.5 p-1 bg-slate-50 rounded-lg border border-slate-100">
            <button v-for="action in [
              { id: 'add', icon: 'plus', title: 'إضافة صف', click: addRow, color: 'text-blue-600' },
              { id: 'clear', icon: 'trash-can', title: 'مسح الجدول', click: clearRows, color: 'text-rose-500' },
              { id: 'dl', icon: 'download', title: 'تحميل قالب', click: () => downloadTemplate('basic'), color: 'text-slate-500' }
            ]" :key="action.id" @click="action.click" :title="action.title" class="w-8 h-8 rounded-md bg-white border border-slate-200 flex items-center justify-center hover:bg-slate-50 transition-all shadow-sm">
              <i :class="['fas fa-' + action.icon, action.color, 'text-[10px]']"></i>
            </button>
            <label class="w-8 h-8 rounded-md bg-white border border-slate-200 flex items-center justify-center hover:bg-emerald-50 cursor-pointer transition-all shadow-sm" title="استيراد CSV">
              <i class="fas fa-file-csv text-emerald-600 text-[10px]"></i>
              <input type="file" class="hidden" accept=".csv" @change="onCsvSelected" />
            </label>
          </div>
        </div>
      </section>

      <!-- Inventory Summary: High Contrast context -->
      <section class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div class="bg-slate-900 rounded-xl p-6 text-white shadow-xl flex items-center justify-between border border-white/5 relative overflow-hidden">
          <div class="absolute top-0 left-0 w-32 h-32 bg-white/5 rounded-full -translate-x-12 -translate-y-12"></div>
          <div class="flex items-center gap-6 relative z-10">
            <div>
              <p class="text-[9px] font-bold text-white/30 uppercase tracking-[0.2em] mb-1">إجمالي الكميات المسجلة</p>
              <p class="text-2xl font-bold font-mono tracking-tighter leading-none text-blue-400">{{ formatNumber(totalQuantity) }}</p>
            </div>
            <div class="h-10 w-px bg-white/10 mx-2"></div>
            <div>
              <p class="text-[9px] font-bold text-white/30 uppercase tracking-[0.2em] mb-1">إجمالي التكلفة التقديرية</p>
              <p class="text-2xl font-bold font-mono tracking-tighter leading-none text-emerald-400">{{ formatMoney(totalCost) }}</p>
            </div>
          </div>
          <div v-if="lastSaveTime" class="hidden lg:block text-left opacity-30">
            <p class="text-[8px] font-bold uppercase tracking-widest">آخر حفظ تلقائي</p>
            <p class="text-[10px] font-mono">{{ lastSaveTime.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'}) }}</p>
          </div>
        </div>
      </section>

      <!-- Main Entry Grid: Professional Table Style -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
        <div class="overflow-x-auto custom-scroll" style="max-height: 50vh;">
          <table class="w-full text-right border-collapse">
            <thead class="sticky top-0 z-30 bg-slate-50/95 backdrop-blur-sm border-b border-slate-200">
              <tr class="text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                <th class="px-6 py-4">المستودع / الفرع</th>
                <th class="px-4 py-4 w-72">المنتج / الصنف</th>
                <th class="px-4 py-4">الوحدة</th>
                <th class="px-4 py-4 text-center">الكمية</th>
                <th class="px-4 py-4 text-center">التكلفة (الوحدة)</th>
                <th class="px-4 py-4 text-left">الإجمالي</th>
                <th class="px-4 py-4 w-48">ملاحظات</th>
                <th class="px-6 py-4 w-12"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium">
              <tr v-if="items.length === 0">
                <td colspan="8" class="py-20 text-center text-slate-300">
                  <i class="fas fa-boxes-stacked text-3xl mb-4 opacity-20"></i>
                  <p class="text-xs font-bold uppercase tracking-widest">لا توجد صفوف مدخلة حالياً</p>
                </td>
              </tr>
              <tr v-for="(row, idx) in items" :key="idx" class="hover:bg-blue-50/20 group/row transition-all">
                <!-- Branch -->
                <td class="px-6 py-3">
                  <div class="relative dropdown-container">
                    <input type="text" v-model="row.branch_search" @input="filterbranchs(idx)" @focus="showbranchDropdown(idx)"
                           class="h-8 w-44 bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold outline-none focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500" placeholder="بحث المخزن..." />
                    <div v-if="row.showbranchList" class="absolute top-full right-0 mt-1 w-64 bg-white border border-slate-200 rounded-lg shadow-2xl z-50 overflow-hidden py-1">
                      <div v-for="branch in filteredbranchs(idx)" :key="branch.id" @click="selectbranch(idx, branch)" class="px-4 py-2 hover:bg-slate-50 cursor-pointer flex justify-between items-center transition-colors">
                        <span class="text-[11px] font-bold text-slate-700">{{ branch.name }}</span>
                        <span class="text-[9px] font-mono text-slate-400">#{{ branch.id }}</span>
                      </div>
                    </div>
                  </div>
                </td>
                <!-- Product -->
                <td class="px-4 py-3">
                  <div class="relative dropdown-container">
                    <input type="text" v-model="row.product_search" @input="filterProducts(idx)" @focus="showProductDropdown(idx)"
                           class="h-8 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold outline-none focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500" placeholder="اسم المنتج أو الكود..." />
                    <div v-if="row.showProductList" class="absolute top-full right-0 mt-1 w-80 bg-white border border-slate-200 rounded-lg shadow-2xl z-50 overflow-hidden py-1">
                      <div v-for="product in filteredProducts(idx)" :key="product.id" @click="selectProduct(idx, product)" class="px-4 py-2 hover:bg-slate-50 cursor-pointer flex justify-between items-center border-b border-slate-50 last:border-0">
                        <span class="text-[11px] font-bold text-slate-700 truncate max-w-[200px]">{{ product.name }}</span>
                        <span class="text-[9px] font-mono text-slate-400">{{ product.barcode }}</span>
                      </div>
                    </div>
                  </div>
                </td>
                <!-- Unit -->
                <td class="px-4 py-3">
                  <div class="relative dropdown-container">
                    <input type="text" v-model="row.unit_search" @input="filterUnits(idx)" @focus="showUnitDropdown(idx)" :disabled="!row.product_id"
                           class="h-8 w-24 bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold outline-none focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 disabled:opacity-30" placeholder="الوحدة" />
                    <div v-if="row.showUnitList" class="absolute top-full right-0 mt-1 w-32 bg-white border border-slate-200 rounded-lg shadow-2xl z-50 overflow-hidden py-1">
                      <div v-for="unit in filteredUnits(idx)" :key="unit.id" @click="selectUnit(idx, unit)" class="px-4 py-2 hover:bg-slate-50 cursor-pointer text-[11px] font-bold text-slate-700">{{ unit.name }}</div>
                    </div>
                  </div>
                </td>
                <!-- Quantity -->
                <td class="px-4 py-3 text-center">
                  <input type="number" step="0.0001" min="0" v-model.number="row.quantity" @change="autoSave"
                         class="h-8 w-24 bg-white border border-slate-200 rounded-md text-center text-[11px] font-bold font-mono text-blue-600 outline-none" />
                </td>
                <!-- Cost -->
                <td class="px-4 py-3 text-center">
                  <input type="number" step="0.01" min="0" v-model.number="row.cost" @change="autoSave"
                         class="h-8 w-24 bg-white border border-slate-200 rounded-md text-center text-[11px] font-bold font-mono text-emerald-600 outline-none" />
                </td>
                <!-- Subtotal -->
                <td class="px-4 py-3 text-left text-xs font-bold font-mono text-slate-900 tracking-tighter">
                  {{ formatMoney(row.quantity * row.cost) }}
                </td>
                <!-- Notes -->
                <td class="px-4 py-3">
                  <input type="text" v-model="row.notes" @change="autoSave" placeholder="ملاحظة اختيارية..."
                         class="h-8 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold outline-none focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 italic" />
                </td>
                <!-- Remove -->
                <td class="px-6 py-3 text-center">
                  <button @click="removeRow(idx)" class="w-6 h-6 text-slate-300 hover:text-rose-500 transition-colors">
                    <i class="fas fa-times-circle"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Preview Report: Audit Document Look -->
      <transition name="slide-down">
        <section v-if="previewResult" class="bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden animate-fadeIn">
          <div :class="[previewResult.status === 'success' ? 'bg-emerald-50 border-emerald-100' : 'bg-rose-50 border-rose-100']" class="px-8 py-5 border-b flex items-center justify-between">
            <div class="flex items-center gap-4">
               <div :class="[previewResult.status === 'success' ? 'bg-emerald-600' : 'bg-rose-600']" class="w-8 h-8 rounded flex items-center justify-center text-white"><i :class="[previewResult.status === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle']"></i></div>
               <h4 class="text-sm font-bold uppercase tracking-wider" :class="[previewResult.status === 'success' ? 'text-emerald-800' : 'text-rose-800']">تقرير مراجعة البيانات المبدئي</h4>
            </div>
            <button @click="previewResult = null; previewItems = [];" class="text-slate-400 hover:text-slate-900 transition-colors"><i class="fas fa-times text-lg"></i></button>
          </div>

          <div class="p-8 space-y-8">
            <!-- Summary Stats -->
            <div v-if="previewResult.data?.summary" class="grid grid-cols-3 gap-4">
              <div class="p-4 bg-blue-50 border border-blue-100 rounded-lg text-center">
                <p class="text-[9px] font-bold text-blue-600 uppercase mb-1">الأصناف</p>
                <p class="text-2xl font-bold text-blue-900">{{ previewResult.data.summary.total_quantity || 0 }}</p>
              </div>
              <div class="p-4 bg-emerald-50 border border-emerald-100 rounded-lg text-center">
                <p class="text-[9px] font-bold text-emerald-600 uppercase mb-1">التكلفة الإجمالية</p>
                <p class="text-2xl font-bold text-emerald-900 font-mono">{{ formatMoney(previewResult.data.summary.total_cost || 0) }}</p>
              </div>
              <div class="p-4 bg-indigo-50 border border-indigo-100 rounded-lg text-center">
                <p class="text-[9px] font-bold text-indigo-600 uppercase mb-1">عدد الصفوف</p>
                <p class="text-2xl font-bold text-indigo-900">{{ previewItems.length }}</p>
              </div>
            </div>

            <div v-if="previewResult.errors?.length" class="p-4 bg-rose-50 border border-rose-100 rounded-lg">
              <p class="text-[10px] font-bold text-rose-700 uppercase mb-2">الأخطاء المكتشفة:</p>
              <ul class="text-[11px] font-medium text-rose-600 space-y-1"><li v-for="(err, i) in previewResult.errors" :key="i">• {{ err }}</li></ul>
            </div>

            <div v-if="previewWarnings?.length" class="p-4 bg-amber-50 border border-amber-100 rounded-lg">
              <p class="text-[10px] font-bold text-amber-700 uppercase mb-2 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> تنبيهات هامة:</p>
              <ul class="text-[11px] font-medium text-amber-700 space-y-1"><li v-for="(warn, i) in previewWarnings" :key="i">⚠ {{ warn }}</li></ul>
            </div>

            <div v-if="previewItems.length" class="border border-slate-200 rounded-lg overflow-hidden">
               <table class="w-full text-right text-xs">
                 <thead><tr class="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[9px]"><th class="px-4 py-2">المخزن</th><th class="px-4 py-2">المنتج</th><th class="px-4 py-2 text-center">الوحدة</th><th class="px-4 py-2 text-center">الكمية</th><th class="px-4 py-2 text-center">التكلفة</th><th class="px-4 py-2 text-left">الإجمالي</th><th class="px-4 py-2">الملاحظات</th></tr></thead>
                 <tbody class="divide-y divide-slate-100 font-bold">
                   <tr v-for="(r, i) in previewItems" :key="i" class="text-slate-600">
                     <td class="px-4 py-2">{{ r.branch_code || r.branch_id }}</td>
                     <td class="px-4 py-2">{{ r.product_code || r.barcode || r.product_id }}</td>
                     <td class="px-4 py-2 text-center font-mono text-slate-500">{{ r.unit_code || '—' }}</td>
                     <td class="px-4 py-2 text-center font-mono">{{ formatNumber(r.quantity) }}</td>
                     <td class="px-4 py-2 text-center font-mono">{{ formatMoney(r.cost) }}</td>
                     <td class="px-4 py-2 text-left font-mono text-emerald-600">{{ formatMoney(r.subtotal) }}</td>
                     <td class="px-4 py-2 text-slate-500 italic">{{ r.notes || '—' }}</td>
                   </tr>
                 </tbody>
               </table>
            </div>
          </div>
        </section>
      </transition>

      <!-- Import Methods Navigation: Segmented Switch -->
      <section class="bg-white border border-slate-200 rounded-xl p-8 lg:p-10 shadow-sm space-y-8 max-w-5xl mx-auto">
        <div class="text-center space-y-2">
          <h3 class="text-lg font-bold text-slate-900">استيراد البيانات الخارجية</h3>
          <p class="text-xs text-slate-400">اختر الطريقة الأنسب لرفع كميات المخزون الضخمة</p>
        </div>

        <div class="flex items-center justify-center">
          <div class="flex bg-slate-100 p-1 rounded-lg border border-slate-200/50">
            <button v-for="tab in [
                { id: 'paste', name: 'لصق سريع', icon: 'paste' },
                { id: 'file', name: 'رفع ملف Excel', icon: 'file-excel' },
                { id: 'templates', name: 'قوالب النظام', icon: 'table' }
              ]"
              :key="tab.id"
              @click="activeTab = tab.id"
              :class="[activeTab === tab.id ? 'bg-white text-blue-600 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']"
              class="px-8 py-2 rounded-md text-xs font-bold transition-all flex items-center gap-2"
            >
              <i :class="['fas fa-' + tab.icon, 'text-[10px]']"></i>
              {{ tab.name }}
            </button>
          </div>
        </div>

        <!-- Tab: Paste -->
        <div v-if="activeTab === 'paste'" class="space-y-6 animate-fadeIn">
          <!-- Instructions Box -->
          <div class="p-4 bg-blue-50 border border-blue-100 rounded-lg">
            <p class="text-[10px] font-bold text-blue-700 uppercase mb-3 flex items-center gap-2"><i class="fas fa-lightbulb"></i> خطوات الاستخدام:</p>
            <ol class="text-[11px] text-blue-700 space-y-2 ml-4">
              <li><strong>الخطوة 1:</strong> انسخ البيانات من Excel أو أي جدول (Ctrl+C)</li>
              <li><strong>الخطوة 2:</strong> الصق البيانات أدناه (Ctrl+V) بصيغة الفروع والمنتجات والكميات والأسعار</li>
              <li><strong>الخطوة 3:</strong> اضغط "تحويل إلى صفوف الجدول" لإضافة البيانات للجدول</li>
            </ol>
            <div class="mt-4 p-3 bg-white border border-blue-200 rounded text-[10px] font-mono text-slate-600">
              <p class="font-bold mb-1">مثال:</p>
              <code>MAIN,SKU-001,PCS,10,25.50,ملاحظة اختيارية<br/>MAIN,SKU-002,PCS,5,40.00,</code>
            </div>
          </div>

          <textarea v-model="bulkText" @input="validateBulkText" rows="5"
                    class="w-full rounded-xl border border-slate-200 p-6 text-xs font-bold font-mono bg-slate-50 focus:bg-white focus:ring-4 focus:ring-blue-500/5 transition-all outline-none"
                    placeholder="MAIN,SKU-001,PCS,10,25.50,Initial..."></textarea>
          
          <div class="flex justify-between items-center gap-4">
             <div v-if="bulkValidation.message" :class="[bulkValidation.valid ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-100']" class="px-3 py-1.5 rounded-lg border text-[10px] font-bold uppercase">{{ bulkValidation.message }}</div>
             <div class="flex items-center gap-2">
               <button v-if="bulkText.trim()" @click="clearBulk" class="h-10 px-6 bg-slate-200 text-slate-700 rounded-lg text-xs font-bold transition-all hover:bg-slate-300">مسح</button>
               <button @click="convertBulkToRows" :disabled="!bulkValidation.valid || !bulkText.trim()" class="h-10 px-8 bg-slate-900 text-white rounded-lg text-xs font-bold shadow-lg hover:bg-black transition-all disabled:opacity-40">تحويل إلى صفوف الجدول</button>
             </div>
          </div>
        </div>

        <!-- Tab: File Upload -->
        <div v-if="activeTab === 'file'" class="animate-fadeIn space-y-6">
          <label class="flex flex-col items-center justify-center w-full h-48 border-2 border-slate-100 border-dashed rounded-xl cursor-pointer bg-slate-50 hover:bg-white hover:border-blue-300 transition-all duration-500 group relative">
            <div class="flex flex-col items-center justify-center py-6 text-center">
              <div class="w-12 h-12 bg-white rounded flex items-center justify-center text-slate-300 group-hover:text-blue-600 transition-all shadow-sm mb-4"><i class="fas fa-cloud-upload-alt text-lg"></i></div>
              <p class="text-xs font-bold text-slate-700">{{ selectedFile ? selectedFile.name : 'اسحب ملف Excel أو CSV هنا' }}</p>
              <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-widest">يدعم ملفات XLSX و CSV</p>
            </div>
            <input type="file" @change="onFileSelected" accept=".xlsx,.xls,.csv" class="hidden" id="file-input-main" />
          </label>
          
          <div v-if="filePreview" class="space-y-4">
            <div class="bg-slate-900 rounded-xl p-6 text-white flex items-center justify-between shadow-xl">
               <div class="flex items-center gap-4">
                  <div class="w-9 h-9 bg-white/10 rounded flex items-center justify-center text-emerald-400"><i class="fas fa-table-list"></i></div>
                  <div>
                    <p class="text-xs font-bold">{{ filePreview.name }}</p>
                    <p class="text-[9px] opacity-40 uppercase">{{ filePreview.rows }} صف مكتشف • {{ formatFileSize(filePreview.size) }}</p>
                  </div>
               </div>
               <div class="flex items-center gap-2">
                  <button @click="clearFile" class="h-9 px-4 bg-slate-700 text-white rounded-md text-[10px] font-bold uppercase hover:bg-slate-600 transition-all">إلغاء</button>
                  <button @click="importFile" :disabled="loading" class="h-9 px-6 bg-blue-600 text-white rounded-md text-[10px] font-bold uppercase shadow-lg shadow-blue-900/20 transition-all hover:bg-blue-700">استيراد الآن</button>
               </div>
            </div>

            <!-- Sample Preview Table -->
            <div v-if="filePreview.sample?.data?.length" class="border border-slate-200 rounded-lg overflow-hidden bg-white">
              <div class="px-4 py-2 bg-slate-50 border-b border-slate-100">
                <p class="text-[10px] font-bold text-slate-600 uppercase">معاينة من محتوى الملف (أول 5 صفوف)</p>
              </div>
              <div class="overflow-x-auto max-h-64 custom-scroll">
                <table class="w-full text-right text-xs">
                  <thead class="sticky top-0 bg-slate-50 border-b border-slate-100">
                    <tr class="text-slate-600 font-bold uppercase text-[9px]">
                      <th v-for="(h, i) in filePreview.sample.headers" :key="i" class="px-4 py-2 text-left">{{ h }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-50">
                    <tr v-for="(row, ri) in filePreview.sample.data" :key="ri" class="text-slate-600 hover:bg-slate-50">
                      <td v-for="(h, ci) in filePreview.sample.headers" :key="ci" class="px-4 py-2 text-left text-[10px]">
                        {{ row[ci] || '—' }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Tab: Templates -->
        <div v-if="activeTab === 'templates'" class="space-y-6 animate-fadeIn">
          <!-- Tip Box -->
          <div class="p-4 bg-amber-50 border border-amber-100 rounded-lg">
            <p class="text-[10px] font-bold text-amber-700 uppercase mb-2 flex items-center gap-2"><i class="fas fa-lightbulb"></i> نصيحة:</p>
            <p class="text-[11px] text-amber-700">استخدم القالب الأساسي للبدء السريع. استخدم القالب المتقدم إذا كنت تملك معرّفات محددة من النظام. قالب الأمثلة يحتوي على 8 صفوف جاهزة للاختبار.</p>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div v-for="t in [
                { id: 'basic', name: 'القالب الأساسي', icon: 'file-lines', desc: 'استخدم أكواد الفروع والمنتجات والوحدات. الطريقة الأسهل للبدء.' },
                { id: 'advanced', name: 'القالب المتقدم', icon: 'file-shield', desc: 'يتضمن معرّفات (IDs) مباشرة من النظام للفروع والمنتجات والوحدات.' },
                { id: 'samples', name: 'قالب أمثلة', icon: 'file-signature', desc: '8 صفوف جاهزة للاختبار عبر فروع مختلفة. مثالي للتجربة.' }
              ]" :key="t.id" @click="downloadTemplate(t.id)" class="p-6 bg-slate-50 border border-slate-100 rounded-xl text-center hover:bg-white hover:border-blue-300 transition-all group cursor-pointer space-y-3">
              <div class="w-12 h-12 bg-white rounded-lg flex items-center justify-center text-slate-300 group-hover:text-blue-600 shadow-sm mx-auto transition-transform group-hover:scale-110"><i :class="['fas fa-' + t.icon, 'text-xl']"></i></div>
              <div class="space-y-2">
                <p class="text-xs font-bold text-slate-700 uppercase tracking-wide">{{ t.name }}</p>
                <p class="text-[10px] text-slate-600 leading-relaxed">{{ t.desc }}</p>
              </div>
              <button @click.stop="downloadTemplate(t.id)" class="w-full h-8 mt-2 bg-blue-600 text-white rounded-md text-[10px] font-bold uppercase transition-all hover:bg-blue-700">تحميل</button>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useSetupStore } from '@/stores/setup/setupStore'
import { useProductStore } from '@/stores/product/productStore'
import apiClient from '@/config/axios'
import ExcelJS from 'exceljs'
import AlertService from '@/services/AlertService'
import { useBranchStore } from '@/stores/branch/branchStore'
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

// ─── State ───────────────────────────────────────────────────────────────────

// Stores
const productStore = useProductStore();
const branchStore = useBranchStore();
const setupStore = useSetupStore();
const { breadcrumb } = useBreadcrumb();

const items = ref([])
const bulkText = ref('')
const bulkValidation = ref({ valid: false, message: '', validRows: 0 })
const previewResult = ref(null)
const previewItems = ref([])
const previewWarnings = ref([])
const loading = ref(false)
const setPurchasePriceIfZero = ref(true)
const purchasesOnly = ref(false)
const postAccounting = ref(true)

const activeTab = ref('file')
const filePreview = ref(null)
const selectedFile = ref(null)

const branches = computed(() => branchStore.branches)
const products = ref([])
const units = ref([])

// Auto-save
const autoSaveKey = 'opening_balance_draft'
const lastSaveTime = ref(null)

// Change log (kept from original)
const changeLog = ref([])

// ─── Computed ─────────────────────────────────────────────────────────────────

const totalQuantity = computed(() => items.value.reduce((s, r) => s + (Number(r.quantity) || 0), 0))
const totalCost = computed(() => items.value.reduce((s, r) => s + ((Number(r.quantity) || 0) * (Number(r.cost) || 0)), 0))

// ─── Row Factory ──────────────────────────────────────────────────────────────

function makeRow(overrides = {}) {
  return {
    branch_code: '',
    product_code_or_barcode: '',
    unit_code: '',
    quantity: 0,
    cost: 0,
    notes: '',
    branch_search: '',
    product_search: '',
    unit_search: '',
    showbranchList: false,
    showProductList: false,
    showUnitList: false,
    filteredbranchs: [],
    filteredProducts: [],
    filteredUnits: [],
    branch_id: null,
    product_id: null,
    unit_id: null,
    ...overrides
  }
}

// ─── Row Management ───────────────────────────────────────────────────────────

function addRow() {
  items.value.push(makeRow())
  initializeRowFilters(items.value.length - 1)
}

function removeRow(idx) {
  logChange('remove_row', { index: idx })
  items.value.splice(idx, 1)
  autoSave()
}

function clearRows() {
  logChange('clear_all', { previousCount: items.value.length })
  items.value = []
  autoSave()
}

// ─── Dropdown / Filter Logic (STRICTLY PRESERVED from original) ───────────────

function initializeRowFilters(idx) {
  const row = items.value[idx]
  if (!row) return
  row.filteredbranchs = [...branches.value]
  row.filteredProducts = [...products.value]
  row.filteredUnits = [...units.value]
}

function closeAllDropdowns() {
  items.value.forEach(row => {
    row.showbranchList = false
    row.showProductList = false
    row.showUnitList = false
  })
}

function filterbranchs(idx) {
  closeAllDropdowns()
  const row = items.value[idx]
  const q = (row.branch_search || '').toLowerCase()
  row.filteredbranchs = branches.value.filter(w =>
    w.name.toLowerCase().includes(q) || (w.code && w.code.toLowerCase().includes(q))
  )
  row.showbranchList = true
}

function filterProducts(idx) {
  closeAllDropdowns()
  const row = items.value[idx]
  const q = (row.product_search || '').toLowerCase()
  row.filteredProducts = products.value.filter(p =>
    p.name.toLowerCase().includes(q) ||
    (p.code && p.code.toLowerCase().includes(q)) ||
    (p.barcode && p.barcode.includes(q))
  )
  row.showProductList = true
}

function filterUnits(idx) {
  closeAllDropdowns()
  const row = items.value[idx]
  const q = (row.unit_search || '').toLowerCase()
  row.filteredUnits = units.value.filter(u =>
    u.name.toLowerCase().includes(q) || (u.code && u.code.toLowerCase().includes(q))
  )
  row.showUnitList = true
}

function showbranchDropdown(idx) {
  closeAllDropdowns()
  items.value[idx].showbranchList = true
  filterbranchs(idx)
}

function showProductDropdown(idx) {
  closeAllDropdowns()
  items.value[idx].showProductList = true
  filterProducts(idx)
}

function showUnitDropdown(idx) {
  closeAllDropdowns()
  if (items.value[idx].product_id) {
    items.value[idx].showUnitList = true
    filterUnits(idx)
  }
}

function selectbranch(idx, branch) {
  const row = items.value[idx]
  row.branch_code = branch.code || branch.name
  row.branch_id = branch.id
  row.branch_search = branch.name
  row.showbranchList = false
  logChange('select_branch', { index: idx, branch: branch.name })
  autoSave()
}

function selectProduct(idx, product) {
  const row = items.value[idx]
  row.product_code_or_barcode = product.code || product.barcode
  row.product_id = product.id
  row.product_search = product.name
  row.showProductList = false

  // Auto-fill cost if available (preserved from original)
  if (product.purchase_price && !row.cost) {
    row.cost = product.purchase_price
  }

  // Auto-fill default unit (preserved from original)
  if (product.unit_id) {
    const defaultUnit = units.value.find(u => u.id === product.unit_id)
    if (defaultUnit) {
      row.unit_code = defaultUnit.code
      row.unit_id = defaultUnit.id
      row.unit_search = defaultUnit.name
    }
  }

  logChange('select_product', { index: idx, product: product.name })
  autoSave()
}

function selectUnit(idx, unit) {
  const row = items.value[idx]
  row.unit_code = unit.code
  row.unit_id = unit.id
  row.unit_search = unit.name
  row.showUnitList = false
  logChange('select_unit', { index: idx, unit: unit.name })
  autoSave()
}

function filteredbranchs(idx) { return items.value[idx]?.filteredbranchs || [] }
function filteredProducts(idx) { return items.value[idx]?.filteredProducts || [] }
function filteredUnits(idx) { return items.value[idx]?.filteredUnits || [] }

// ─── Auto-save (STRICTLY PRESERVED from original) ─────────────────────────────

function autoSave() {
  const data = {
    items: items.value,
    timestamp: new Date().toISOString(),
    expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString()
  }
  localStorage.setItem(autoSaveKey, JSON.stringify(data))
  lastSaveTime.value = new Date()
}

function loadAutoSave() {
  try {
    const saved = localStorage.getItem(autoSaveKey)
    if (!saved) return false
    const data = JSON.parse(saved)
    if (data.expiresAt && new Date() > new Date(data.expiresAt)) {
      clearAutoSave(); return false
    }
    if (data.items && data.items.length > 0) {
      items.value = data.items
      items.value.forEach((_, idx) => initializeRowFilters(idx))
      lastSaveTime.value = new Date(data.timestamp)
      return true
    }
  } catch (e) {
    console.error('Error loading auto-save:', e)
  }
  return false
}

function clearAutoSave() {
  localStorage.removeItem(autoSaveKey)
  lastSaveTime.value = null
}

// ─── Change Tracking (preserved from original) ────────────────────────────────

function logChange(action, details) {
  changeLog.value.unshift({ action, details, timestamp: new Date().toISOString() })
  if (changeLog.value.length > 50) changeLog.value = changeLog.value.slice(0, 50)
}

// ─── CSV Paste Parser (STRICTLY PRESERVED from original) ──────────────────────

function parseCsvText(text) {
  const lines = text.split(/\r?\n/).filter(l => l.trim() !== '')
  const out = []
  for (const line of lines) {
    const parts = line.split(',').map(s => s.trim())
    if (parts.length < 5) continue
    const [a, b, c, q, cost, ...rest] = parts
    const quantity = Number(q)
    const costNum = Number(cost)
    const aNum = Number(a); const bNum = Number(b); const cNum = Number(c)
    const isIds = !isNaN(aNum) && !isNaN(bNum) && !isNaN(cNum) && a !== '' && b !== '' && c !== ''
    if (isIds) {
      out.push({
        branch_id: aNum || null,
        product_id: bNum || null,
        unit_id: cNum || 1,
        quantity: isNaN(quantity) ? 0 : quantity,
        cost: isNaN(costNum) ? 0 : costNum,
        notes: rest.join(',') || ''
      })
    } else {
      out.push({
        branch_code: a || '',
        product_code_or_barcode: b || '',
        unit_code: c || '',
        quantity: isNaN(quantity) ? 0 : quantity,
        cost: isNaN(costNum) ? 0 : costNum,
        notes: rest.join(',') || ''
      })
    }
  }
  return out
}

// ─── Bulk Paste Validation (STRICTLY PRESERVED from original) ─────────────────

function validateBulkText() {
  const text = bulkText.value.trim()
  if (!text) { bulkValidation.value = { valid: false, message: '', validRows: 0 }; return }

  const lines = text.split('\n').filter(l => l.trim())
  let validRows = 0
  const errors = []

  for (let i = 0; i < lines.length; i++) {
    const parts = lines[i].trim().split(',').map(p => p.trim())
    if (parts.length < 5) { errors.push(`صف ${i + 1}: يجب أن يحتوي على 5-6 حقول`); continue }
    const qty = parseFloat(parts[3])
    const cst = parseFloat(parts[4])
    if (isNaN(qty) || qty < 0) { errors.push(`صف ${i + 1}: يجب أن تكون الكمية رقمًا موجبًا أو صفرًا`); continue }
    if (isNaN(cst) || cst < 0) { errors.push(`صف ${i + 1}: التكلفة يجب أن تكون رقماً موجباً`); continue }
    validRows++
  }

  if (errors.length > 0) {
    bulkValidation.value = {
      valid: false,
      message: `أخطاء في ${errors.length} صفوف: ${errors.slice(0, 3).join(' | ')}${errors.length > 3 ? '...' : ''}`,
      validRows
    }
  } else {
    bulkValidation.value = { valid: true, message: `جميع البيانات صالحة (${validRows} صفوف)`, validRows }
  }
}

// ─── Convert Bulk to Rows (STRICTLY PRESERVED from original) ──────────────────

async function convertBulkToRows() {
  if (!bulkValidation.value.valid) {
    await AlertService.warning('يرجى تصحيح الأخطاء أولاً', 'بيانات غير صالحة')
    return
  }
  const lines = bulkText.value.trim().split('\n').filter(l => l.trim())
  const newItems = lines.map(line => {
    const p = line.split(',').map(s => s.trim())
    return makeRow({
      branch_code: p[0],
      product_code_or_barcode: p[1],
      unit_code: p[2],
      quantity: parseFloat(p[3]) || 0,
      cost: parseFloat(p[4]) || 0,
      notes: p[5] || ''
    })
  })
  items.value = [...items.value, ...newItems]
  newItems.forEach((_, i) => initializeRowFilters(items.value.length - newItems.length + i))
  logChange('bulk_import', { rowsCount: newItems.length })
  autoSave()
  clearBulk()
  await AlertService.success(`تم تحويل ${newItems.length} صفوف بنجاح`, 'نجاح')
}

function clearBulk() {
  bulkText.value = ''
  bulkValidation.value = { valid: false, message: '', validRows: 0 }
}

// ─── CSV Quick Import (STRICTLY PRESERVED from original) ──────────────────────

async function onCsvSelected(e) {
  const file = e.target.files?.[0]
  if (!file) return
  try {
    const text = await file.text()
    const rows = parseCsvText(text)
    if (rows.length === 0) {
      await AlertService.warning('ملف CSV لا يحتوي بيانات صالحة', 'ملف CSV فارغ')
      return
    }
    const newItems = rows.map(r => makeRow(r))
    items.value.push(...newItems)
    newItems.forEach((_, i) => initializeRowFilters(items.value.length - newItems.length + i))
    logChange('csv_import', { rowsCount: newItems.length })
    autoSave()
    await AlertService.success(`تم استيراد ${newItems.length} صفوف من CSV بنجاح`, 'نجاح')
  } catch (err) {
    await AlertService.error('فشل قراءة ملف CSV: ' + err.message, 'خطأ')
  } finally {
    e.target.value = ''
  }
}

// ─── File Selection & Preview (STRICTLY PRESERVED from original) ───────────────

async function onFileSelected(event) {
  const file = event.target.files[0]
  if (!file) return
  selectedFile.value = file

  const ext = '.' + file.name.split('.').pop().toLowerCase()
  const validTypes = ['.xlsx', '.xls', '.csv']
  if (!validTypes.includes(ext)) {
    await AlertService.warning('نوع الملف غير مدعوم. الرجاء اختيار Excel أو CSV', 'خطأ في نوع الملف')
    clearFile(); return
  }

  const reader = new FileReader()
  reader.onload = async (e) => {
    try {
      if (ext === '.csv') {
        const lines = (e.target.result).split('\n').filter(l => l.trim())
        const headers = lines[0].split(',').map(h => h.trim())
        const rows = lines.slice(1, 6).map(line => line.split(',').map(c => c.trim()))
        filePreview.value = {
          name: file.name, size: file.size,
          rows: lines.length - 1, columns: headers.length,
          sample: { headers, data: rows }
        }
      } else {
        const workbook = new ExcelJS.Workbook()
        await workbook.xlsx.load(e.target.result)
        const worksheet = workbook.getWorksheet(1)
        if (!worksheet) {
          await AlertService.warning('الملف لا يحتوي على أوراق عمل', 'خطأ')
          clearFile(); return
        }
        const rows = worksheet.getRows(1, 6)
        if (!rows || rows.length === 0) {
          await AlertService.warning('الملف فارغ', 'ملف فارغ')
          clearFile(); return
        }
        const headers = rows[0].values.slice(1)
        const sampleRows = rows.slice(1).map(r => r.values.slice(1))
        filePreview.value = {
          name: file.name, size: file.size,
          rows: worksheet.rowCount - 1, columns: headers.length,
          sample: { headers, data: sampleRows }
        }
      }
    } catch (err) {
      await AlertService.error('فشل قراءة الملف: ' + err.message, 'خطأ')
      clearFile()
    }
  }
  reader.readAsArrayBuffer(file)
}

// ─── File Import (STRICTLY PRESERVED from original) ───────────────────────────

async function importFile() {
  if (!selectedFile.value || !filePreview.value) return
  loading.value = true
  const reader = new FileReader()
  reader.onload = async (e) => {
    try {
      const ext = '.' + selectedFile.value.name.split('.').pop().toLowerCase()
      let jsonData = []

      if (ext === '.csv') {
        const lines = (new TextDecoder().decode(e.target.result)).split('\n').filter(l => l.trim())
        const headers = lines[0].split(',').map(h => h.trim())
        for (let i = 1; i < lines.length; i++) {
          const values = lines[i].split(',').map(v => v.trim())
          const row = {}
          headers.forEach((h, idx) => { row[h] = values[idx] || '' })
          jsonData.push(row)
        }
      } else {
        const workbook = new ExcelJS.Workbook()
        await workbook.xlsx.load(e.target.result)
        const worksheet = workbook.getWorksheet(1)
        if (!worksheet) { await AlertService.warning('الملف لا يحتوي على أوراق عمل', 'خطأ'); return }
        const headerRow = worksheet.getRow(1)
        const headers = headerRow.values.slice(1)
        for (let i = 2; i <= worksheet.rowCount; i++) {
          const row = worksheet.getRow(i)
          const values = row.values.slice(1)
          const rowData = {}
          headers.forEach((h, idx) => { rowData[h] = values[idx] || '' })
          jsonData.push(rowData)
        }
      }

      // Convert to our format (preserved logic)
      const newItems = jsonData.map(row => {
        const hasCodes = row.branch_code || row.product_code_or_barcode || row.unit_code
        const hasIds = row.branch_id || row.product_id || row.unit_id
        if (hasCodes) {
          return makeRow({
            branch_code: row.branch_code || '',
            product_code_or_barcode: row.product_code_or_barcode || row.barcode || '',
            unit_code: row.unit_code || '',
            quantity: parseFloat(row.quantity) || 0,
            cost: parseFloat(row.cost) || 0,
            notes: row.notes || ''
          })
        } else if (hasIds) {
          return makeRow({
            branch_id: parseInt(row.branch_id) || null,
            product_id: parseInt(row.product_id) || null,
            unit_id: parseInt(row.unit_id) || 1,
            quantity: parseFloat(row.quantity) || 0,
            cost: parseFloat(row.cost) || 0,
            notes: row.notes || ''
          })
        } else {
          const keys = Object.keys(row)
          return makeRow({
            branch_code: row[keys[0]] || '',
            product_code_or_barcode: row[keys[1]] || '',
            unit_code: row[keys[2]] || '',
            quantity: parseFloat(row[keys[3]]) || 0,
            cost: parseFloat(row[keys[4]]) || 0,
            notes: row[keys[5]] || ''
          })
        }
      }).filter(r => r.branch_code || r.branch_id || r.product_code_or_barcode || r.product_id)

      items.value = [...items.value, ...newItems]
      newItems.forEach((_, i) => initializeRowFilters(items.value.length - newItems.length + i))
      logChange('file_import', { rowsCount: newItems.length, fileName: selectedFile.value.name })
      autoSave()
      await AlertService.success(`تم استيراد ${newItems.length} صفوف من ${selectedFile.value.name}`, 'نجاح')
      clearFile()
    } catch (err) {
      await AlertService.error('فشل استيراد الملف: ' + err.message, 'خطأ')
    } finally {
      loading.value = false
    }
  }
  reader.readAsArrayBuffer(selectedFile.value)
}

function clearFile() {
  selectedFile.value = null
  filePreview.value = null
  const fi = document.getElementById('file-input-main')
  if (fi) fi.value = ''
}

// ─── Download Template (STRICTLY PRESERVED from original, all 3 types) ─────────

async function downloadTemplate(type = 'basic') {
  try {
    let templateData = ''
    let filename = ''
    switch (type) {
      case 'basic':
        templateData = 'branch_code,product_code_or_barcode,unit_code,quantity,cost,notes\nMAIN,SKU-001,PCS,10,25.5,initial stock\nMAIN,SKU-002,PCS,5,40.0,new item'
        filename = 'opening_balance_basic_template.csv'; break
      case 'advanced':
        templateData = 'branch_code,product_code_or_barcode,unit_code,quantity,cost,notes,branch_id,product_id,unit_id\nMAIN,SKU-001,PCS,10,25.5,initial stock,1,101,1\nMAIN,SKU-002,PCS,5,40.0,new item,1,102,1'
        filename = 'opening_balance_advanced_template.csv'; break
      case 'samples':
        templateData = `branch_code,product_code_or_barcode,unit_code,quantity,cost,notes
MAIN,SKU-001,PCS,10,25.5,initial stock
MAIN,SKU-002,PCS,5,40.0,new item
MAIN,SKU-003,PCS,20,15.75,bulk purchase
MAIN,SKU-004,PCS,50,8.99,discounted item
MAIN,SKU-005,PCS,100,2.50,clearance stock
SECOND,SKU-006,PCS,15,30.00,branch transfer
SECOND,SKU-007,PCS,8,45.50,premium item
THIRD,SKU-008,PCS,12,22.75,seasonal stock`
        filename = 'opening_balance_samples_template.csv'; break
      default:
        templateData = 'branch_code,product_code_or_barcode,unit_code,quantity,cost,notes\nMAIN,SKU-001,PCS,10,25.5,initial stock'
        filename = 'opening_balance_template.csv'
    }
    const blob = new Blob([templateData], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url; a.download = filename; a.click()
    URL.revokeObjectURL(url)
    await AlertService.success(`تم تحميل ${filename} بنجاح`, 'نجاح')
  } catch (e) {
    await AlertService.error('فشل تحميل القالب: ' + e.message, 'خطأ')
  }
}

// ─── Preview (STRICTLY PRESERVED from original — full payload mapping) ─────────

async function preview() {
  previewResult.value = null; previewItems.value = []; previewWarnings.value = []
  loading.value = true
  try {
    const payload = items.value.map(r => ({
      branch_id: r.branch_id ?? null,
      product_id: r.product_id ?? null,
      unit_id: r.unit_id ?? null,
      branch_code: r.branch_code || undefined,
      product_code: (r.product_code || r.product_code_or_barcode)?.match(/\D/)
        ? (r.product_code || r.product_code_or_barcode) : undefined,
      barcode: (r.barcode || r.product_code_or_barcode)?.match(/^\d+$/)
        ? (r.barcode || r.product_code_or_barcode) : undefined,
      unit_code: r.unit_code || undefined,
      quantity: r.quantity,
      cost: r.cost,
      notes: r.notes || ''
    }))
    const res = await setupStore.preview(payload)
    if (res.status === 'success') {
      previewResult.value = res.data
      previewItems.value = res.data?.items || []
      previewWarnings.value = res.data?.warnings || []
    } else {
      previewResult.value = { status: 'error', message: res.message }
    }
  } catch (e) {
    previewResult.value = e?.response?.data || { status: 'error', message: e.message }
  } finally {
    loading.value = false
  }
}

// ─── Commit (STRICTLY PRESERVED from original — double confirm + full payload) ─

async function commit() {
  // Double confirmation as in original
  const firstConfirm = await AlertService.confirm(
    'هل أنت متأكد من ترحيل الرصيد الافتتاحي؟ هذه العملية لا يمكن التراجع عنها.',
    'تأكيد العملية'
  )
  if (!firstConfirm) return

  // Delay prevents residual click event from first dialog bleeding into the second
  await new Promise(resolve => setTimeout(resolve, 350))

  const secondConfirm = await AlertService.confirm(
    'تأكيد نهائي: هل تريد ترحيل الرصيد الآن؟',
    'تأكيد نهائي'
  )
  if (!secondConfirm) return

  loading.value = true
  try {
    const payload = items.value.map(r => ({
      branch_id: r.branch_id ?? null,
      product_id: r.product_id ?? null,
      unit_id: r.unit_id ?? null,
      branch_code: r.branch_code || undefined,
      product_code: (r.product_code || r.product_code_or_barcode)?.match(/\D/)
        ? (r.product_code || r.product_code_or_barcode) : undefined,
      barcode: (r.barcode || r.product_code_or_barcode)?.match(/^\d+$/)
        ? (r.barcode || r.product_code_or_barcode) : undefined,
      unit_code: r.unit_code || undefined,
      quantity: r.quantity,
      cost: r.cost,
      notes: r.notes,
      setPurchasePrice: setPurchasePriceIfZero.value,
      purchasesOnly: purchasesOnly.value,
      postAccounting: postAccounting.value
    }))
    const res = await setupStore.commit(payload)
    if (res.status === 'success') {
      await AlertService.success(res.message || 'تم الترحيل بنجاح', 'نجاح')
      clearAutoSave()
      previewResult.value = null; previewItems.value = []; previewWarnings.value = []
      logChange('commit', { itemCount: items.value.length })
      
      // ─── Emit event to notify InventoryManagement.vue to refresh ────────────
      // Wait a moment for backend to finish processing
      await new Promise(resolve => setTimeout(resolve, 500))
      
      const event = new CustomEvent('openingBalancePosted', {
        detail: {
          timestamp: new Date().toISOString(),
          itemCount: items.value.length,
          message: 'تم ترحيل الرصيد الافتتاحي بنجاح'
        }
      })
      window.dispatchEvent(event)
      console.log('📡 openingBalancePosted event emitted', { itemCount: items.value.length })
      
      if (typeof productStore !== 'undefined' && productStore.invalidateCache) {
        productStore.invalidateCache()
      }
      
      // Also store in session storage as fallback (in case InventoryManagement isn't open)
      sessionStorage.setItem('lastOpeningBalancePosted', JSON.stringify({
        timestamp: new Date().toISOString(),
        itemCount: items.value.length
      }))
      
      // Clear items after successful commit
      items.value = []
      addRow()
    } else {
      throw new Error(res.message)
    }
  } catch (e) {
    await AlertService.error('فشل الترحيل: ' + (e?.response?.data?.message || e.message), 'خطأ')
  } finally {
    loading.value = false
  }
}

// ─── Click Outside Handler ─────────────────────────────────────────────────────

function handleClickOutside(event) {
  if (!event.target.closest('.dropdown-container')) closeAllDropdowns()
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatNumber(n) {
  return (Number(n) || 0).toLocaleString('en-US', { maximumFractionDigits: 4 })
}
function formatMoney(n) {
  return (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function formatFileSize(bytes) {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

// ─── Lifecycle ────────────────────────────────────────────────────────────────

onMounted(async () => {
  // Initialize stores
  await branchStore.initialize()
  
  try {
    // Load data using stores
    const [productsRes, unitsRes] = await Promise.all([
      productStore.fetchProducts({ force: true }),
      apiClient.get('/units')
    ])
    products.value = productsRes.status === 'success' ? productsRes.data : []
    const rawUnits = unitsRes?.data?.data ?? unitsRes?.data ?? []
    units.value = Array.isArray(rawUnits) ? rawUnits : []
  } catch (e) {
    console.error('Error loading master data', e)
  }

  // Auto-save restore prompt (STRICTLY PRESERVED from original)
  const hasAutoSave = loadAutoSave()
  if (hasAutoSave) {
    const restore = await AlertService.confirm(
      'تم العثور على بيانات محفوظة. هل تريد استعادتها؟',
      'استعادة البيانات المحفوظة'
    )
    if (restore) {
      await AlertService.success('تم استعادة البيانات المحفوظة بنجاح', 'نجاح')
    } else {
      clearAutoSave()
      items.value = []
    }
  }

  if (items.value.length === 0) addRow()
  items.value.forEach((_, idx) => initializeRowFilters(idx))

  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

/* Dashboard Identity Controls */
.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1; }

/* Custom Toggle Switch Style */
.custom-toggle { @apply flex items-center gap-3 cursor-pointer select-none; }
.toggle-indicator { @apply w-4 h-4 rounded border-2 border-slate-200 bg-white transition-all; }
.toggle-label { @apply text-[10px] font-bold text-slate-400 uppercase tracking-wider group-hover:text-slate-900 transition-colors; }

/* Spreadsheet Input Styles */
.grid-input { @apply w-full bg-white border border-slate-200 rounded-md transition-all outline-none focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-10px); }
</style>