<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">

    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoading || isGLLoading || isTransfersLoading || isStockTransfersLoading" />

    <div class="max-w-[1800px] mx-auto p-4 lg:p-8 space-y-8 animate-fadeIn">

      <!-- Loading Skeleton State -->
      <div v-if="isLoading" class="space-y-6">
        <div class="bg-white border border-slate-200 rounded-xl p-8 h-28 animate-pulse"></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          <div v-for="i in 4" :key="i" class="h-24 bg-white border border-slate-200 rounded-xl animate-pulse"></div>
        </div>
        <div class="h-96 bg-white border border-slate-200 rounded-xl animate-pulse"></div>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="py-24 text-center px-6 bg-white border border-slate-200 rounded-xl shadow-sm max-w-md mx-auto animate-fadeIn">
        <div class="w-12 h-12 bg-rose-50 border border-rose-100 text-rose-600 rounded-xl flex items-center justify-center mx-auto mb-4 shadow-sm">
          <i class="fas fa-triangle-exclamation text-base"></i>
        </div>
        <h3 class="text-sm font-bold text-slate-900">حدث خطأ أثناء تحميل بيانات الفرع</h3>
        <p class="text-xs text-slate-500 mt-1 font-medium leading-relaxed">{{ error }}</p>
        <button
          @click="fetchBranch"
          class="mt-5 h-9 px-6 bg-slate-900 text-white rounded-md text-xs font-bold shadow-sm hover:bg-black active:scale-95 transition-all"
        >
          إعادة المحاولة
        </button>
      </div>

      <!-- Main Branch View -->
      <div v-else-if="branch" class="space-y-8">

        <!-- Page Header -->
        <PageHeader
          :breadcrumb="breadcrumb"
          :title="branch.name"
          description="تفاصيل التشغيل، إدارة المخزون، والقيود المحاسبية الخاصة بالفرع."
          :branches="[]"
          :selected-branch="branchId"
        >
          <template #controls>
            <div class="flex items-center gap-2">
              <router-link
                :to="{ path: '/inventory', query: { branch_id: branchId } }"
                class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2"
              >
                <i class="fas fa-boxes-stacked text-[10px]"></i>
                <span>الرقابة المخزنية</span>
              </router-link>
              <router-link
                :to="{ path: '/branches/bulk-distribution', query: { branch_id: branchId } }"
                class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-700 text-xs font-bold transition-all flex items-center gap-2 shadow-sm hover:bg-slate-50 active:scale-95"
              >
                <i class="fas fa-share-nodes text-blue-600 text-xs"></i>
                <span>التوزيع الجماعي</span>
              </router-link>
              <button
                type="button"
                @click="openBranchEditModal"
                class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-700 text-xs font-bold transition-all flex items-center gap-2 shadow-sm hover:bg-slate-50 active:scale-95"
              >
                <i class="fas fa-edit text-slate-500 text-[10px]"></i>
                <span>تعديل بيانات الفرع</span>
              </button>
            </div>
          </template>
        </PageHeader>

        <!-- KPIs Overview: Stripe-inspired Data Blocks (Unified with Inventory Page) -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          <div v-for="kpi in [
            { label: 'إجمالي منتجات الفرع', val: glProducts.length, icon: 'fa-boxes', color: 'text-blue-600', bg: 'bg-blue-50' },
            { label: 'منتجات مرصودة ومفعلة', val: glProducts.filter(p => p.activation_status === 'RECONCILED' || p.activation_status === 'ACTIVE_IN_BRANCH').length, icon: 'fa-check-double', color: 'text-emerald-600', bg: 'bg-emerald-50' },
            { label: 'عمليات النقل المنفذة', val: transfers.length, icon: 'fa-exchange-alt', color: 'text-amber-600', bg: 'bg-amber-50' },
            { label: 'سجلات النقل المرجعية', val: stockTransfers.length, icon: 'fa-history', color: 'text-indigo-600', bg: 'bg-indigo-50' }
          ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
            <div>
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
              <p class="text-xl font-bold text-slate-900 font-mono tracking-tighter">{{ kpi.val }}</p>
            </div>
            <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
              <i :class="['fas', kpi.icon]"></i>
            </div>
          </div>
        </section>

        <!-- Navigation Tabs Bar Toolbar -->
        <div class="bg-white border border-slate-200 rounded-xl p-2.5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div class="flex items-center gap-1.5 overflow-x-auto custom-scroll pb-1 sm:pb-0">
            <button
              v-for="tab in tabs"
              :key="tab.id"
              @click="activeTab = tab.id"
              :class="[
                activeTab === tab.id
                  ? 'bg-slate-900 text-white shadow-sm'
                  : 'bg-transparent text-slate-600 hover:bg-slate-50 hover:text-slate-900'
              ]"
              class="h-9 px-4 rounded-lg text-xs font-bold transition-all flex items-center gap-2 active:scale-95 whitespace-nowrap"
            >
              <i :class="`fas fa-${tab.icon} text-[11px]`"></i>
              <span>{{ tab.name }}</span>
            </button>
          </div>

          <!-- Branch Quick Metadata Indicator -->
          <div class="flex items-center gap-3 px-2 text-xs">
            <div class="flex items-center gap-1.5 text-slate-500 font-medium">
              <i class="fas fa-map-marker-alt text-slate-400 text-xs"></i>
              <span>{{ branch.location || 'موقع غير محدد' }}</span>
            </div>
            <span class="text-slate-200">|</span>
            <span
              :class="[isBranchActive ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200']"
              class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[9px] font-bold border"
            >
              <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
              {{ isBranchActive ? 'نشط' : 'غير نشط' }}
            </span>
            <span class="font-mono text-slate-400 text-[10px] font-bold">#{{ branch.id }}</span>
          </div>
        </div>

        <!-- Main Content Area -->
        <div class="space-y-6 min-h-[500px]">

          <!-- TAB 1: INVENTORY & GL STATUS -->
          <transition name="fade">
            <div v-if="activeTab === 'inventory'" class="space-y-8 animate-fadeIn">

              <!-- BranchInventory Component -->
              <BranchInventory
                :branch-id="branchId"
                @inventory-updated="handleInventoryUpdated"
              />

              <!-- GL Products Section: High-Density Table Layout -->
              <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">

                <!-- Table Subheader & Filter Tabs -->
                <div class="p-5 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-slate-50/50">
                  <div class="flex items-center gap-3">
                    <div class="w-2.5 h-2.5 rounded-full bg-emerald-500"></div>
                    <div>
                      <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">حالات المنتجات بالفرع (GL Status Matrix)</h3>
                      <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">متابعة التفعيل وشجرة الحسابات المحاسبية</p>
                    </div>
                  </div>

                  <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1 bg-white border border-slate-200 p-1 rounded-lg shadow-sm">
                      <button
                        v-for="status in ['DRAFT', 'ACTIVE_IN_BRANCH', 'RECONCILED']"
                        :key="status"
                        @click="glStatusFilter = status"
                        :class="[
                          glStatusFilter === status
                            ? 'bg-slate-900 text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50'
                        ]"
                        class="px-3 py-1 rounded-md text-[10px] font-bold uppercase transition-all"
                      >
                        <span v-if="status === 'DRAFT'">مسودة</span>
                        <span v-else-if="status === 'ACTIVE_IN_BRANCH'">مفعّل</span>
                        <span v-else>مرصود ✓</span>
                      </button>
                    </div>

                    <LoadingButton
                      text=""
                      loadingText=""
                      :showTextDuringLoad="false"
                      icon="fas fa-sync-alt"
                      :isLoading="isGLLoading"
                      variant="secondary"
                      class="w-8 h-8 rounded-lg border border-slate-200 bg-white p-0 text-slate-400 shadow-sm hover:text-emerald-600 hover:border-emerald-200"
                      title="تحديث حالات GL"
                      aria-label="تحديث حالات GL"
                      @click="loadBranchProductGLStatuses"
                    />
                  </div>
                </div>

                <!-- GL High-Density Grid -->
                <div class="overflow-x-auto">
                  <table class="w-full text-right border-collapse">
                    <thead>
                      <tr class="bg-slate-50/50 border-b border-slate-200">
                        <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الصنف والباركود</th>
                        <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الكمية المسجلة</th>
                        <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">متوسط التكلفة</th>
                        <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة المحاسبية</th>
                        <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراء</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">

                      <!-- Skeletons -->
                      <template v-if="isGLLoading">
                        <tr v-for="i in 4" :key="i" class="animate-pulse">
                          <td class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-48"></div></td>
                          <td class="px-4 py-4 text-center"><div class="h-4 bg-slate-100 rounded w-12 mx-auto"></div></td>
                          <td class="px-4 py-4 text-center"><div class="h-4 bg-slate-100 rounded w-16 mx-auto"></div></td>
                          <td class="px-4 py-4 text-center"><div class="h-4 bg-slate-100 rounded w-20 mx-auto"></div></td>
                          <td class="px-6 py-4 text-center"><div class="h-7 bg-slate-100 rounded w-20 mx-auto"></div></td>
                        </tr>
                      </template>

                      <!-- Error State -->
                      <tr v-else-if="glError">
                        <td colspan="5" class="py-16 text-center text-rose-600 font-bold text-xs">
                          <i class="fas fa-circle-exclamation mb-2 block text-xl"></i>
                          {{ glError }}
                        </td>
                      </tr>

                      <!-- Empty State -->
                      <tr v-else-if="!filteredGLProducts.length" class="text-center">
                        <td colspan="5" class="py-24 text-slate-300">
                          <i class="fas fa-box-open text-3xl mb-4 opacity-20 block"></i>
                          <p class="text-xs font-bold uppercase tracking-widest">لا توجد منتجات مسجلة في هذه الحالة</p>
                        </td>
                      </tr>

                      <!-- Rows -->
                      <tr v-for="product in filteredGLProducts" :key="product.product_id" class="hover:bg-blue-50/10 transition-all group">
                        <td class="px-6 py-4">
                          <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 border border-slate-100 group-hover:text-blue-600 transition-colors">
                              <i class="fas fa-box text-xs"></i>
                            </div>
                            <div class="flex flex-col">
                              <span class="text-xs font-bold text-slate-900 truncate max-w-[240px]">{{ product.product_name }}</span>
                              <span class="text-[9px] font-bold text-slate-400 font-mono mt-1 uppercase tracking-widest">{{ product.barcode || product.product_code || '—' }}</span>
                            </div>
                          </div>
                        </td>

                        <td class="px-4 py-4 text-center font-mono font-bold text-xs text-slate-800">
                          <span class="px-2 py-0.5 rounded text-[10px] bg-slate-50 border border-slate-200">
                            {{ product.quantity || 0 }}
                          </span>
                        </td>

                        <td class="px-4 py-4 text-center font-mono font-bold text-xs text-slate-700">
                          {{ product.average_cost == null ? '—' : Number(product.average_cost).toFixed(2) }}
                        </td>

                        <td class="px-4 py-4 text-center">
                          <span
                            :class="[
                              product.activation_status === 'DRAFT' ? 'bg-amber-50 text-amber-600 border-amber-100' :
                              product.activation_status === 'ACTIVE_IN_BRANCH' ? 'bg-blue-50 text-blue-600 border-blue-100' :
                              'bg-emerald-50 text-emerald-600 border-emerald-100'
                            ]"
                            class="px-2 py-0.5 rounded text-[9px] font-bold border font-mono"
                          >
                            {{
                              product.activation_status === 'DRAFT' ? 'DRAFT' :
                              product.activation_status === 'ACTIVE_IN_BRANCH' ? 'ACTIVE' :
                              'RECONCILED'
                            }}
                          </span>
                        </td>

                        <td class="px-6 py-4 text-center">
                          <div class="flex items-center justify-center gap-1.5">
                            <button
                              v-if="product.activation_status === 'DRAFT'"
                              @click="openBranchActivateModal(product)"
                              class="h-7 px-3 text-[10px] font-bold bg-blue-600 text-white rounded hover:bg-blue-700 active:scale-95 transition-all shadow-sm flex items-center gap-1"
                            >
                              <i class="fas fa-check text-[9px]"></i> تفعيل
                            </button>

                            <button
                              v-else-if="product.activation_status === 'ACTIVE_IN_BRANCH'"
                              @click="openBranchOpeningBalanceModal(product)"
                              class="h-7 px-3 text-[10px] font-bold bg-amber-500 text-white rounded hover:bg-amber-600 active:scale-95 transition-all shadow-sm flex items-center gap-1"
                            >
                              <i class="fas fa-balance-scale text-[9px]"></i> ترحيل الرصيد الافتتاحي
                            </button>

                            <span v-else class="text-xs font-bold text-emerald-600 flex items-center gap-1">
                              <i class="fas fa-check-double text-xs"></i> مرصود
                            </span>
                          </div>
                        </td>
                      </tr>

                    </tbody>
                  </table>
                </div>

              </div>

            </div>
          </transition>

          <!-- TAB 2: TRANSFERS LOG -->
          <transition name="fade">
            <div v-if="activeTab === 'transfers'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative animate-fadeIn">

              <!-- Subheader -->
              <div class="p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50/50">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center text-sm shadow-inner">
                    <i class="fas fa-exchange-alt"></i>
                  </div>
                  <div>
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">سجل عمليات نقل المنتجات</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">حركات الإرسال والاستلام بين المستودعات</p>
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <router-link
                    :to="{ path: '/inventory', query: { branch_id: branchId, action: 'transfer' } }"
                    class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2"
                  >
                    <i class="fas fa-plus text-[10px]"></i>
                    <span>نقل جديد</span>
                  </router-link>
                  <LoadingButton
                    text=""
                    loadingText=""
                    :showTextDuringLoad="false"
                    icon="fas fa-sync-alt"
                    :isLoading="isTransfersLoading"
                    variant="secondary"
                    class="w-9 h-9 rounded-md border border-slate-200 bg-white p-0 text-slate-400 shadow-sm hover:text-blue-600 hover:border-blue-200"
                    title="تحديث السجل"
                    aria-label="تحديث سجل التحويلات"
                    @click="fetchTransfers"
                  />
                </div>
              </div>

              <!-- High-Density Table -->
              <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                  <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-200">
                      <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التاريخ والوقت</th>
                      <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الصنف والباركود</th>
                      <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المسار (من ← إلى)</th>
                      <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الكمية</th>
                      <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المسؤول</th>
                      <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">ملاحظات</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 font-medium">

                    <template v-if="isTransfersLoading">
                      <tr v-for="i in 4" :key="i" class="animate-pulse">
                        <td v-for="m in 6" :key="m" class="px-4 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                      </tr>
                    </template>

                    <tr v-else-if="transfersError">
                      <td colspan="6" class="py-16 text-center text-rose-600 font-bold text-xs">
                        <i class="fas fa-triangle-exclamation mb-1 block text-lg"></i>
                        {{ transfersError }}
                      </td>
                    </tr>

                    <tr v-else-if="!transfers.length" class="text-center">
                      <td colspan="6" class="py-24 text-slate-300">
                        <i class="fas fa-box-open text-3xl mb-4 opacity-20 block"></i>
                        <p class="text-xs font-bold uppercase tracking-widest">لا توجد عمليات نقل مسجلة</p>
                      </td>
                    </tr>

                    <tr v-for="t in transfers" :key="t.id" class="hover:bg-blue-50/10 transition-all group">
                      <td class="px-6 py-4 text-xs font-mono text-slate-400">{{ new Date(t.created_at).toLocaleString('ar-EG') }}</td>
                      <td class="px-4 py-4">
                        <div class="font-bold text-slate-900 text-xs">{{ t.product_name }}</div>
                        <span class="text-[9px] text-slate-400 font-mono block mt-0.5" v-if="t.barcode">{{ t.barcode }}</span>
                      </td>
                      <td class="px-4 py-4">
                        <div class="flex items-center gap-2 text-xs font-bold">
                          <span class="text-slate-600 truncate max-w-[120px]">{{ t.from_branch_name }}</span>
                          <i class="fas fa-arrow-left text-slate-300 text-[10px]"></i>
                          <span class="text-blue-600 truncate max-w-[120px]">{{ t.to_branch_name }}</span>
                        </div>
                      </td>
                      <td class="px-4 py-4 text-center font-mono font-bold text-xs">
                        <span class="px-2 py-0.5 bg-slate-100 border border-slate-200 rounded text-[10px] text-slate-900">
                          {{ t.quantity }}
                        </span>
                      </td>
                      <td class="px-4 py-4 text-xs text-slate-600">{{ t.created_by_name || '—' }}</td>
                      <td class="px-6 py-4 text-xs text-slate-400 italic max-w-xs truncate" :title="t.notes">{{ t.notes || '—' }}</td>
                    </tr>

                  </tbody>
                </table>
              </div>

            </div>
          </transition>

          <!-- TAB 3: STOCK TRANSFERS REF TABLE -->
          <transition name="fade">
            <div v-if="activeTab === 'stock_transfers'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative animate-fadeIn">

              <!-- Subheader -->
              <div class="p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50/50">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 bg-indigo-50 text-indigo-600 rounded-lg flex items-center justify-center text-sm shadow-inner">
                    <i class="fas fa-history"></i>
                  </div>
                  <div>
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">السجل المرجعي للنقل (Stock Transfers Audit)</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">التتبع المحاسبي الكامل لقيود التحويل</p>
                  </div>
                </div>

                <LoadingButton
                  text=""
                  loadingText=""
                  :showTextDuringLoad="false"
                  icon="fas fa-sync-alt"
                  :isLoading="isStockTransfersLoading"
                  variant="secondary"
                  class="w-9 h-9 rounded-md border border-slate-200 bg-white p-0 text-slate-400 shadow-sm hover:text-indigo-600 hover:border-indigo-200"
                  title="تحديث السجلات"
                  aria-label="تحديث سجلات النقل"
                  @click="fetchStockTransfers"
                />
              </div>

              <!-- High-Density Table -->
              <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                  <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-200">
                      <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التاريخ</th>
                      <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الصنف</th>
                      <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المسار</th>
                      <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الكمية</th>
                      <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المسؤول</th>
                      <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراء</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 font-medium">

                    <template v-if="isStockTransfersLoading">
                      <tr v-for="i in 4" :key="i" class="animate-pulse">
                        <td v-for="m in 6" :key="m" class="px-4 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                      </tr>
                    </template>

                    <tr v-else-if="stockTransfersError">
                      <td colspan="6" class="py-16 text-center text-rose-600 font-bold text-xs">{{ stockTransfersError }}</td>
                    </tr>

                    <tr v-else-if="!stockTransfers.length" class="text-center">
                      <td colspan="6" class="py-24 text-slate-300">
                        <i class="fas fa-box-open text-3xl mb-4 opacity-20 block"></i>
                        <p class="text-xs font-bold uppercase tracking-widest">لا توجد سجلات مرجعية</p>
                      </td>
                    </tr>

                    <tr v-for="t in stockTransfers" :key="t.id" class="hover:bg-blue-50/10 transition-all group">
                      <td class="px-6 py-4 text-xs font-mono text-slate-400">{{ new Date(t.created_at).toLocaleString('ar-EG') }}</td>
                      <td class="px-4 py-4 text-xs font-bold text-slate-900">{{ t.product_name }}</td>
                      <td class="px-4 py-4">
                        <div class="flex items-center gap-2 text-xs font-bold">
                          <span class="text-slate-600">{{ t.from_branch_name }}</span>
                          <i class="fas fa-arrow-left text-slate-300 text-[10px]"></i>
                          <span class="text-indigo-600">{{ t.to_branch_name }}</span>
                        </div>
                      </td>
                      <td class="px-4 py-4 text-center font-mono font-bold text-xs text-indigo-600">{{ t.quantity }}</td>
                      <td class="px-4 py-4 text-xs text-slate-600">{{ t.created_by_name || '—' }}</td>
                      <td class="px-6 py-4 text-center">
                        <button
                          @click="openStockTransferDetails(t.id)"
                          class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-indigo-600 hover:border-indigo-200 transition-all flex items-center justify-center shadow-sm mx-auto"
                          title="عرض التفاصيل"
                        >
                          <i class="fas fa-eye text-[10px]"></i>
                        </button>
                      </td>
                    </tr>

                  </tbody>
                </table>
              </div>

            </div>
          </transition>

        </div>

      </div>

    </div>

    <!-- ===== SYSTEM MODALS ===== -->

    <BaseModal :show="showBranchEditModal" @close="showBranchEditModal = false" maxWidth="lg">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
            <i class="fas fa-edit text-xs"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900">تعديل بيانات الفرع</h3>
            <p class="text-[10px] text-slate-500 mt-0.5">{{ branch?.name }}</p>
          </div>
        </div>
      </template>
      <BranchForm
        v-if="branch"
        :branch="branch"
        @success="handleBranchEditSuccess"
        @cancel="showBranchEditModal = false"
      />
    </BaseModal>

    <!-- Stock Transfer Details Modal: Unified with Details Modal in Inventory Page -->
    <BaseModal :show="showStockTransferDetails" @close="showStockTransferDetails = false" maxWidth="4xl">
      <template #header>
        <h3 class="text-sm font-bold text-slate-900 uppercase">تفاصيل عملية النقل المحاسبية</h3>
      </template>

      <div v-if="isStockTransferDetailsLoading" class="py-12 flex flex-col items-center justify-center gap-4 text-slate-400">
        <BaseSpinner size="32" color="#3b82f6" />
        <p class="text-xs font-bold uppercase tracking-widest">جاري تحميل التفاصيل...</p>
      </div>

      <div v-else-if="stockTransferDetails" class="space-y-6">
        <!-- Dark Primary Info Card -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
          <div class="bg-slate-900 p-8 rounded-2xl text-white shadow-xl relative overflow-hidden">
            <div class="absolute top-0 left-0 w-32 h-32 bg-white/5 rounded-full -translate-x-12 -translate-y-12"></div>
            <p class="text-[9px] font-bold text-blue-400 uppercase tracking-widest mb-3">بيانات الصنف المنقول</p>
            <h4 class="text-2xl font-bold mb-1">{{ stockTransferDetails.product_name }}</h4>
            <p class="text-xs font-bold text-slate-500 font-mono mb-6">{{ stockTransferDetails.barcode || '—' }}</p>

            <div class="grid grid-cols-2 gap-4 border-t border-white/10 pt-6">
              <div>
                <p class="text-[9px] font-bold text-slate-400 uppercase">الكمية المنقولة</p>
                <p class="text-lg font-bold font-mono text-emerald-400">{{ stockTransferDetails.quantity }}</p>
              </div>
              <div>
                <p class="text-[9px] font-bold text-slate-400 uppercase">رقم المرجع</p>
                <p class="text-lg font-bold font-mono text-blue-400">#{{ stockTransferDetails.id }}</p>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4 font-bold text-xs">
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 flex flex-col justify-center">
              <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">من مستودع</p>
              <p class="text-slate-800">{{ stockTransferDetails.from_branch_name }}</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 flex flex-col justify-center">
              <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">إلى مستودع</p>
              <p class="text-blue-600">{{ stockTransferDetails.to_branch_name }}</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 flex flex-col justify-center">
              <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">تاريخ النقل</p>
              <p class="text-slate-700 font-mono">{{ new Date(stockTransferDetails.created_at).toLocaleDateString('ar-EG') }}</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 flex flex-col justify-center">
              <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">المسؤول</p>
              <p class="text-slate-700 truncate">{{ stockTransferDetails.created_by_name || '—' }}</p>
            </div>
          </div>
        </div>

        <!-- Audit Traceability Table -->
        <div class="space-y-3 mt-6">
          <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">سجل الحركات المخزنية المرتبطة (Traceability)</h4>
          <div class="border border-slate-100 rounded-xl overflow-hidden shadow-sm">
            <table class="w-full text-right text-xs">
              <thead>
                <tr class="bg-slate-50 border-b border-slate-100 text-slate-400 font-bold uppercase">
                  <th class="px-4 py-3">الوقت</th>
                  <th class="px-4 py-3">النوع التقني</th>
                  <th class="px-4 py-3">المسار</th>
                  <th class="px-4 py-3 text-center">الكمية</th>
                  <th class="px-4 py-3">الملاحظات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-50">
                <tr v-for="it in stockTransferDetails.inventory_transactions" :key="it.id" class="hover:bg-slate-50 transition-colors font-medium">
                  <td class="px-4 py-3 text-slate-400 font-mono">{{ new Date(it.created_at || it.movement_date).toLocaleTimeString('ar-EG') }}</td>
                  <td class="px-4 py-3"><span class="px-2 py-0.5 rounded bg-slate-100 text-[10px] font-bold text-slate-600 font-mono">{{ it.movement_type }}</span></td>
                  <td class="px-4 py-3 text-slate-600">{{ it.branch_from || '-' }} ← {{ it.branch_to || '-' }}</td>
                  <td class="px-4 py-3 text-center font-bold font-mono text-indigo-600">{{ it.quantity }}</td>
                  <td class="px-4 py-3 text-slate-400 italic">{{ it.notes || '—' }}</td>
                </tr>
                <tr v-if="!(stockTransferDetails.inventory_transactions || []).length">
                  <td colspan="5" class="py-10 text-center text-slate-300 uppercase font-bold text-[10px]">لا توجد حركات تقنية مسجلة</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <template #footer>
        <button @click="showStockTransferDetails = false" class="px-6 h-10 text-xs font-bold text-slate-500">إغلاق</button>
      </template>
    </BaseModal>

    <!-- GL Activate Product Modal: Unified with GL Activate Modal in Inventory Page -->
    <BaseModal :show="showBranchActivateModal" @close="showBranchActivateModal = false" maxWidth="md" align="center">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 bg-blue-600 rounded-lg flex items-center justify-center text-white"><i class="fas fa-check-circle text-xs"></i></div>
          <h3 class="text-sm font-bold text-slate-900 uppercase">تفعيل المنتج في الفرع</h3>
        </div>
      </template>

      <div class="space-y-4">
        <div class="p-4 bg-blue-50 rounded-xl border border-blue-100 text-blue-700">
          <p class="text-xs font-bold flex items-start gap-2">
            <i class="fas fa-info-circle mt-0.5 shrink-0"></i>
            <span>سيتم إنشاء حساب محاسبي وتفعيل إمكانية بيع المنتج <strong>{{ selectedBranchProduct?.product_name }}</strong> داخل الفرع <strong>{{ branch.name }}</strong></span>
          </p>
        </div>
        <div class="p-4 bg-slate-50 rounded-xl border border-slate-100 space-y-2">
          <div class="flex justify-between items-center"><span class="text-[9px] font-bold text-slate-400 uppercase">المنتج</span><span class="text-sm font-bold text-slate-800">{{ selectedBranchProduct?.product_name }}</span></div>
          <div class="border-t border-slate-100 pt-2 flex justify-between items-center"><span class="text-[9px] font-bold text-slate-400 uppercase">الباركود</span><span class="text-xs font-mono font-bold text-slate-600">{{ selectedBranchProduct?.barcode || '—' }}</span></div>
          <div class="border-t border-slate-100 pt-2 flex justify-between items-center"><span class="text-[9px] font-bold text-slate-400 uppercase">الفرع</span><span class="text-sm font-bold text-slate-800">{{ branch.name }}</span></div>
        </div>
      </div>

      <template #footer>
        <button @click="showBranchActivateModal = false" class="flex-1 h-10 text-xs font-bold text-slate-500">إلغاء</button>
        <LoadingButton
          @click="activateBranchProduct"
          text="تفعيل الآن"
          :isLoading="isActivatingBranch"
          loadingText="جارٍ التفعيل..."
          variant="primary"
          class="flex-2 h-10 rounded-lg px-8"
        />
      </template>
    </BaseModal>

    <!-- GL Opening Balance Modal: Unified with Opening Balance Modal in Inventory Page -->
    <BaseModal :show="showBranchOpeningBalanceModal" @close="showBranchOpeningBalanceModal = false" maxWidth="md" align="center">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 bg-emerald-600 rounded-lg flex items-center justify-center text-white"><i class="fas fa-balance-scale text-xs"></i></div>
          <h3 class="text-sm font-bold text-slate-900 uppercase">الرصيد الافتتاحي للفرع</h3>
        </div>
      </template>

      <form @submit.prevent="handleBranchOpeningBalanceSubmit" class="space-y-6">
        <div class="p-4 bg-slate-900 rounded-xl text-white flex justify-between items-center shadow-inner">
          <div>
            <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">الصنف</p>
            <p class="text-sm font-bold">{{ selectedBranchProduct?.product_name }}</p>
            <span class="text-[10px] font-mono text-slate-400">{{ selectedBranchProduct?.barcode }}</span>
          </div>
          <div class="text-left">
            <p class="text-[9px] font-bold text-slate-400 uppercase mb-1">الفرع</p>
            <p class="text-xs font-bold text-emerald-400">{{ branch.name }}</p>
          </div>
        </div>

        <div class="space-y-1.5">
          <label class="metadata-label">الكمية الافتتاحية</label>
          <input
            v-model.number="branchObQuantity"
            type="number"
            min="0"
            step="0.01"
            class="h-14 w-full bg-white border-2 border-slate-100 rounded-xl px-4 text-2xl font-bold text-center text-emerald-600 outline-none focus:border-emerald-500 transition-all"
            placeholder="0"
            required
          />
        </div>

        <div class="space-y-1.5">
          <label class="metadata-label">سعر التكلفة (الوحدة)</label>
          <input
            v-model.number="branchObUnitCost"
            type="number"
            min="0"
            step="0.01"
            class="h-14 w-full bg-white border-2 border-slate-100 rounded-xl px-4 text-2xl font-bold text-center text-emerald-600 outline-none focus:border-emerald-500 transition-all"
            placeholder="0.00"
            required
          />
        </div>

        <div class="p-4 bg-emerald-50 rounded-xl border border-emerald-100 flex justify-between items-center">
          <span class="text-[9px] font-bold text-emerald-600 uppercase">الإجمالي المالي:</span>
          <span class="text-xl font-bold text-emerald-700 font-mono">{{ (branchObQuantity * branchObUnitCost).toFixed(2) }}</span>
        </div>

        <div class="p-4 bg-blue-50 rounded-xl border border-blue-100 text-blue-700">
          <p class="text-xs font-bold flex items-start gap-2">
            <i class="fas fa-info-circle mt-0.5 shrink-0"></i>
            <span>سيتم إنشاء قيد محاسبي افتتاحي وإثبات الرصيد في الدفاتر تلقائيًا.</span>
          </p>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="button" @click="showBranchOpeningBalanceModal = false" class="flex-1 h-10 text-xs font-bold text-slate-500">إلغاء</button>
          <LoadingButton
            type="submit"
            text="ترحيل الرصيد الافتتاحي"
            loadingText="جارٍ الترحيل..."
            variant="success"
            icon="fas fa-check"
            :isLoading="isPostingBranch"
            :disabled="!branchObQuantity || !branchObUnitCost"
            class="flex-2 h-10 rounded-lg px-8 text-xs shadow-lg shadow-emerald-900/20"
          />
        </div>
      </form>
    </BaseModal>

  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import BaseModal from '@/components/BaseModal.vue';
import BranchForm from '@/components/BranchForm.vue';
import { useRoute } from 'vue-router';
import { getLocalDateISO } from '@/utils';
import { useToast } from '@/composables/useToast';
import BranchInventory from '@/components/branch/BranchInventory.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useProductStore } from '@/stores/product/productStore';

// --- Services & Router ---
const route = useRoute();
const { showToast } = useToast();
const branchStore = useBranchStore();
const productStore = useProductStore();

// --- State (ALL ORIGINAL REFS PRESERVED) ---
const branch = ref(null);
const isLoading = ref(true);
const error = ref(null);
const activeTab = ref('inventory');
const branchId = parseInt(route.params.id);
const showBranchEditModal = ref(false);
const breadcrumb = computed(() => ({
  parent: { label: 'الفروع', path: '/branches' },
  current: { label: branch.value?.name || 'تفاصيل الفرع' }
}));
const isBranchActive = computed(() =>
  branch.value?.active === 1
  || branch.value?.active === true
  || branch.value?.is_active === 1
  || branch.value?.is_active === true
);
const tabs = [
  { id: 'inventory', name: 'المخزون والربط المحاسبي', icon: 'boxes' },
  { id: 'transfers', name: 'عمليات النقل', icon: 'exchange-alt' },
  { id: 'stock_transfers', name: 'السجل المرجعي', icon: 'history' }
];

// GL Products Data & Modals (محفوظ كاملاً)
const glProducts = ref([]);
const isGLLoading = ref(false);
const glError = ref(null);
const glStatusFilter = ref('ACTIVE_IN_BRANCH');
const showBranchActivateModal = ref(false);
const showBranchOpeningBalanceModal = ref(false);
const selectedBranchProduct = ref(null);
const branchObQuantity = ref(0);
const branchObUnitCost = ref(0);
const isActivatingBranch = ref(false);
const isPostingBranch = ref(false);

// Transfers Data
const transfers = ref([]);
const isTransfersLoading = ref(false);
const transfersError = ref(null);

// Stock Transfers Reference
const stockTransfers = ref([]);
const isStockTransfersLoading = ref(false);
const stockTransfersError = ref(null);

// Transfer Details Modal
const showStockTransferDetails = ref(false);
const isStockTransferDetailsLoading = ref(false);
const stockTransferDetails = ref(null);

// --- Computed Properties ---
const filteredGLProducts = computed(() => {
  return glProducts.value.filter(p => p.activation_status === glStatusFilter.value);
});

// --- GL Integration Methods (محفوظة كاملاً) ---
const loadBranchProductGLStatuses = async () => {
  if (!branchId) return;
  isGLLoading.value = true;
  glError.value = null;
  try {
    const prods = await productStore.fetchGLStatus({ branchId });
    glProducts.value = prods || [];
  } catch (err) {
    glError.value = 'فشل تحميل حالات المنتجات';
    console.warn('GL status load failed:', err);
  } finally {
    isGLLoading.value = false;
  }
};

const openBranchActivateModal = (product) => {
  selectedBranchProduct.value = product;
  showBranchActivateModal.value = true;
};

const openBranchOpeningBalanceModal = (product) => {
  selectedBranchProduct.value = product;
  branchObQuantity.value = 0;
  branchObUnitCost.value = 0;
  showBranchOpeningBalanceModal.value = true;
};

const activateBranchProduct = async () => {
  if (!selectedBranchProduct.value) return;
  isActivatingBranch.value = true;
  try {
    const res = await productStore.activateProductInBranch(selectedBranchProduct.value.product_id, branchId);
    if (res.status === 'success') {
      showToast('تم التفعيل بنجاح', 'success');
      showBranchActivateModal.value = false;
      await loadBranchProductGLStatuses();
    } else {
      showToast(res.message || 'فشل التفعيل', 'error');
    }
  } catch (err) {
    showToast(err.response?.data?.message || 'فشل التفعيل', 'error');
  } finally {
    isActivatingBranch.value = false;
  }
};

const handleBranchOpeningBalanceSubmit = async () => {
  if (!selectedBranchProduct.value || !branchObQuantity.value || !branchObUnitCost.value) {
    showToast('يرجى ملء جميع الحقول', 'error');
    return;
  }
  isPostingBranch.value = true;
  try {
    const res = await productStore.postOpeningBalance({
      mapping_id: selectedBranchProduct.value.mapping_id || selectedBranchProduct.value.product_id,
      branch_id: Number(branchId),
      quantity: branchObQuantity.value,
      unit_cost: branchObUnitCost.value,
      entry_date: getLocalDateISO()
    });
    if (res.status === 'success') {
      showToast('تم ترحيل الرصيد بنجاح', 'success');
      showBranchOpeningBalanceModal.value = false;
      await loadBranchProductGLStatuses();
    } else {
      showToast(res.message || 'فشل ترحيل الرصيد', 'error');
    }
  } catch (err) {
    showToast(err.response?.data?.message || 'فشل ترحيل الرصيد', 'error');
  } finally {
    isPostingBranch.value = false;
  }
};

// --- API Methods (STRICTLY PRESERVED) ---
const fetchBranch = async (branchesData) => {
  isLoading.value = true;
  error.value = null;
  try {
    const list = branchesData ?? await branchStore.fetchBranches();
    const found = list?.find(b => b.id === branchId);
    if (found) {
      branch.value = found;
    } else {
      throw new Error('فشل في تحميل تفاصيل الفرع');
    }
  } catch (err) {
    error.value = 'حدث خطأ أثناء جلب بيانات الفرع';
    showToast(error.value, 'error');
  } finally {
    isLoading.value = false;
  }
};

const fetchTransfers = async () => {
  if (!branchId) return;
  isTransfersLoading.value = true;
  transfersError.value = null;
  try {
    transfers.value = await branchStore.fetchBranchTransfers(branchId);
  } catch (err) {
    transfersError.value = err.response?.data?.message || err.message || 'حدث خطأ أثناء جلب سجل عمليات النقل';
  } finally {
    isTransfersLoading.value = false;
  }
};

const fetchStockTransfers = async () => {
  if (!branchId) return;
  isStockTransfersLoading.value = true;
  stockTransfersError.value = null;
  try {
    stockTransfers.value = await branchStore.fetchStockTransfers(branchId);
  } catch (err) {
    stockTransfersError.value = err.response?.data?.message || err.message || 'حدث خطأ أثناء جلب عمليات النقل المرجعية';
  } finally {
    isStockTransfersLoading.value = false;
  }
};

const openStockTransferDetails = async (id) => {
  if (!id) return;
  isStockTransferDetailsLoading.value = true;
  stockTransferDetails.value = null;
  showStockTransferDetails.value = true;
  try {
    stockTransferDetails.value = await branchStore.fetchStockTransfer(id);
  } catch (err) {
    showToast(err.response?.data?.message || err.message || 'حدث خطأ أثناء جلب التفاصيل', 'error');
  } finally {
    isStockTransferDetailsLoading.value = false;
  }
};

// --- Handlers (PRESERVED) ---
const handleInventoryUpdated = () => {
  showToast('تم تحديث المخزون بنجاح', 'success');
};

const openBranchEditModal = () => {
  showBranchEditModal.value = true;
};

const handleBranchEditSuccess = () => {
  showBranchEditModal.value = false;
  const updatedBranch = branchStore.branches.find(item => String(item.id) === String(branchId));
  if (updatedBranch) branch.value = updatedBranch;
};

// --- Watchers & Lifecycle ---
onMounted(async () => {
  await branchStore.initialize();

  const branchIdFromUrl = parseInt(branchId);
  if (!branchStore.hasAccessToBranch(branchIdFromUrl)) {
    error.value = 'ليس لديك صلاحية للوصول إلى هذا الفرع';
    isLoading.value = false;
    return;
  }

  branchStore.setSelectedBranch(branchIdFromUrl);

  const branchesData = await branchStore.fetchBranches();
  await Promise.all([
    fetchBranch(branchesData),
    fetchTransfers(),
    fetchStockTransfers(),
    loadBranchProductGLStatuses(),
  ]);
});

watch(activeTab, (tab) => {
  if (tab === 'transfers') fetchTransfers();
  if (tab === 'stock_transfers') fetchStockTransfers();
});
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>