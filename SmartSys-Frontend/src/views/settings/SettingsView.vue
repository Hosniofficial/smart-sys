<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Global Loading Overlay: Refined Glassmorphism -->
    <Transition name="fade">
      <div v-if="isLoading" class="fixed inset-0 bg-slate-900/40 backdrop-blur-md flex items-center justify-center z-[110]">
        <div class="bg-white p-8 rounded-xl shadow-2xl flex flex-col items-center border border-slate-200">
          <div class="flex items-center gap-1">
            <div class="w-3 h-3 rounded-full bg-blue-500 animate-pulse"></div>
            <div class="w-3 h-3 rounded-full bg-blue-500 animate-pulse" style="animation-delay: 0.2s"></div>
            <div class="w-3 h-3 rounded-full bg-blue-500 animate-pulse" style="animation-delay: 0.4s"></div>
          </div>
          <p class="text-slate-500 mt-4 font-bold uppercase tracking-widest text-[10px] animate-pulse">مزامنة إعدادات النظام...</p>
        </div>
      </div>
    </Transition>

    <!-- Sticky Floating Save Bar: The "Stripe" Action Anchor -->
    <Transition name="slide-up">
      <div v-if="isDirty" class="fixed bottom-8 inset-x-0 flex justify-center z-[100] px-4">
        <div class="bg-slate-900 border border-white/10 shadow-2xl rounded-full px-6 py-3 flex items-center gap-8 max-w-2xl w-full backdrop-blur-md bg-opacity-95">
          <div class="flex items-center gap-3 border-l border-white/10 pl-6">
            <div class="w-2 h-2 bg-amber-400 rounded-full animate-pulse shadow-[0_0_10px_rgba(251,191,36,0.5)]"></div>
            <span class="text-xs font-bold text-white uppercase tracking-wider">تعديلات غير محفوظة</span>
          </div>
          <div class="flex gap-3 mr-auto">
            <button @click="discardActiveTab" class="px-4 py-1.5 rounded-md text-[10px] font-bold text-slate-400 hover:text-white transition-colors">تجاهل</button>
            <LoadingButton
              text="حفظ الإعدادات"
              :isLoading="isSaving"
              loadingText="جاري الحفظ..."
              icon="fas fa-save"
              variant="primary"
              loaderType="dots"
              @click="saveActiveTab"
              class="px-6 py-1.5 bg-blue-600 text-white rounded-md text-[10px] font-bold shadow-lg shadow-blue-900/20 hover:bg-blue-500"
            />
          </div>
        </div>
      </div>
    </Transition>

    <!-- Success/Error Alerts: Minimalist SaaS Toasts -->
    <div class="fixed top-20 left-6 z-[100] space-y-3 max-w-sm">
      <Transition name="slide-left">
        <div v-if="errorMessage" class="bg-rose-600 text-white px-4 py-3 rounded-lg shadow-2xl flex items-center gap-3 border border-rose-500">
          <i class="fas fa-exclamation-circle text-sm"></i>
          <span class="text-[11px] font-bold flex-1">{{ errorMessage }}</span>
          <button @click="errorMessage = ''" class="opacity-50 hover:opacity-100"><i class="fas fa-times text-xs"></i></button>
        </div>
      </Transition>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="تكوين المنشأة"
        description="التحكم المركزي في هوية النشاط، الضرائب، الصلاحيات وقواعد العمل."
        :branches="[]"
        :selectedBranch="null"
      />

      <!-- Access Denied State -->
      <div v-if="!hasSettingsAccess" class="py-32 text-center animate-fadeIn bg-white border border-slate-200 rounded-xl shadow-sm">
        <div class="w-16 h-16 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-6">
          <i class="fas fa-lock text-2xl"></i>
        </div>
        <h2 class="text-lg font-bold text-slate-900 uppercase tracking-widest">وصول مقيد</h2>
        <p class="text-xs text-slate-400 mt-2 font-medium">تحتاج إلى صلاحيات المدير العام للوصول إلى وحدة الإعدادات.</p>
        <router-link to="/dashboard" class="mt-8 inline-flex px-6 py-2 bg-slate-900 text-white rounded-md text-[10px] font-bold uppercase tracking-widest shadow-sm">العودة للرئيسية</router-link>
      </div>

      <!-- Main Layout: Sidebar + Main Area -->
      <div v-else class="flex flex-col lg:flex-row gap-10 items-start">
        
        <!-- Sidebar Navigation: Linear-style -->
        <nav class="w-full lg:w-72 space-y-6 lg:sticky lg:top-24">
          <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <div class="relative group">
              <input v-model="searchTerm" type="text" class="h-9 w-full bg-slate-50 border border-slate-100 rounded-md pr-9 pl-3 text-[11px] font-bold text-slate-700 focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="البحث في الإعدادات..." />
              <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
            </div>
          </div>

          <div class="bg-white border border-slate-200 rounded-xl overflow-hidden py-2 shadow-sm">
            <button
              v-for="tab in filteredTabs"
              :key="tab.id"
              @click="setActiveTab(tab.id)"
              :class="[activeTab === tab.id ? 'bg-slate-50 text-blue-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900']"
              class="w-full px-5 py-3 flex items-center gap-4 transition-all group relative"
            >
              <div v-if="activeTab === tab.id" class="absolute right-0 top-0 bottom-0 w-1 bg-blue-600"></div>
              <i :class="[tab.icon, activeTab === tab.id ? 'text-blue-600' : 'text-slate-300 group-hover:text-slate-500']" class="text-xs"></i>
              <span class="flex-grow text-right text-[11px] font-bold tracking-tight">{{ tab.label }}</span>
              <!-- Dirty Indicator -->
              <div v-if="isSectionDirty(sectionKeyFromTab(tab.id))" class="w-1.5 h-1.5 bg-amber-400 rounded-full animate-pulse shadow-sm"></div>
            </button>
          </div>
        </nav>

        <!-- Main Content Area -->
        <main class="flex-1 w-full space-y-10">

          <!-- ════════════════════════════════════════════════════════════ -->
          <!-- 1. GENERAL SETTINGS                                         -->
          <!-- ════════════════════════════════════════════════════════════ -->
          <section v-if="activeTab === 'general-settings'" class="animate-fadeIn space-y-8">
            <div class="p-2 border-b border-slate-200">
               <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest">هوية النشاط التجاري</h2>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
              <div class="p-8 grid grid-cols-1 md:grid-cols-12 gap-10">
                
                <!-- Business Info -->
                <div class="md:col-span-7 space-y-6">
                  <h3 class="metadata-label text-blue-600"><i class="fas fa-building ml-2"></i> البيانات الرسمية</h3>
                  <div class="space-y-4">
                    <div class="space-y-1.5">
                      <label class="metadata-label">اسم المنشأة <span class="text-rose-500">*</span></label>
                      <input v-model="general.businessName" type="text" class="filter-input-v2 h-10 font-bold text-sm" :class="{ 'border-rose-400': errors.businessName }" />
                      <p v-if="errors.businessName" class="text-[9px] text-rose-500 font-bold">{{ errors.businessName }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                      <div class="space-y-1.5"><label class="metadata-label">البريد الرسمي</label><input v-model="general.email" type="email" class="filter-input-v2 h-10" /></div>
                      <div class="space-y-1.5"><label class="metadata-label">رقم التواصل</label><input v-model="general.phone" type="tel" class="filter-input-v2 h-10 font-mono" /></div>
                    </div>
                    <div class="space-y-1.5"><label class="metadata-label">العنوان الفعلي</label><input v-model="general.address" type="text" class="filter-input-v2 h-10" /></div>
                  </div>
                </div>

                <!-- Logo Section -->
                <div class="md:col-span-5 flex flex-col items-center justify-center border-r border-slate-100 pr-10">
                   <h3 class="metadata-label self-start mb-6 text-purple-600"><i class="fas fa-image ml-2"></i> الهوية البصرية</h3>
                   <div class="w-40 h-40 rounded-xl bg-slate-50 border border-slate-200 relative overflow-hidden group shadow-inner">
                      <img v-if="logoPreview" :src="logoPreview" class="w-full h-full object-contain p-4" />
                      <div v-else class="flex flex-col items-center justify-center h-full text-slate-300">
                        <i class="fas fa-images text-3xl mb-2"></i>
                        <span class="text-[9px] font-bold uppercase tracking-widest">بدون شعار</span>
                      </div>
                      <div class="absolute inset-0 bg-slate-900/80 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                        <button @click="$refs.logoInput.click()" class="w-8 h-8 rounded-md bg-blue-600 text-white flex items-center justify-center hover:bg-blue-500"><i class="fas fa-pen text-xs"></i></button>
                        <button v-if="logoPreview" @click="removeLogo" class="w-8 h-8 rounded-md bg-rose-600 text-white flex items-center justify-center hover:bg-rose-500"><i class="fas fa-trash text-xs"></i></button>
                      </div>
                   </div>
                   <input type="file" ref="logoInput" @change="handleLogoUpload" class="hidden" accept="image/*" />
                   <p class="text-[9px] text-slate-400 mt-4 text-center leading-relaxed italic">JPG, PNG بحد أقصى 2MB<br>يفضل أبعاد متساوية (Square)</p>
                </div>
              </div>

              <!-- Localization -->
              <div class="p-8 border-t border-slate-100 bg-slate-50/30">
                <h3 class="metadata-label mb-6 text-emerald-600"><i class="fas fa-globe ml-2"></i> الإعدادات الإقليمية</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                  <div class="space-y-1.5"><label class="metadata-label">العملة</label><select v-model="general.currency" class="filter-input-v2 h-10 font-bold"><option v-for="curr in getAvailableCurrencies('ar')" :key="curr.code" :value="curr.code">{{ curr.name }}</option></select></div>
                  <div class="space-y-1.5"><label class="metadata-label">المنطقة الزمنية</label><select v-model="general.timezone" class="filter-input-v2 h-10"><option value="Asia/Riyadh">الرياض (GMT+3)</option><option value="Africa/Cairo">القاهرة (GMT+2)</option></select></div>
                  <div class="space-y-1.5"><label class="metadata-label">تنسيق التاريخ</label><select v-model="general.dateFormat" class="filter-input-v2 h-10 font-mono"><option value="DD/MM/YYYY">DD/MM/YYYY</option><option value="YYYY-MM-DD">YYYY-MM-DD</option></select></div>
                  <div class="space-y-1.5"><label class="metadata-label">توقيت الساعة</label><select v-model="general.timeFormat" class="filter-input-v2 h-10"><option value="12">12 ساعة</option><option value="24">24 ساعة</option></select></div>
                </div>
                <div class="mt-6 rounded-lg border border-slate-200 bg-white p-5">
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <h4 class="text-xs font-bold text-slate-800">أسعار الصرف المرجعية</h4>
                      <p class="mt-1 text-[10px] text-slate-500">مقابل العملة الأساسية {{ exchangeRates.base_currency_code || general.currency }}.</p>
                    </div>
                    <button type="button" @click="refreshCurrencyRates" :disabled="currencyRatesLoading" class="h-8 rounded-md border border-slate-200 px-3 text-[10px] font-bold text-blue-700 hover:bg-blue-50 disabled:opacity-50">
                      <i :class="currencyRatesLoading ? 'fas fa-spinner fa-spin' : 'fas fa-sync-alt'" class="ml-1"></i>
                      تحديث الأسعار
                    </button>
                  </div>
                  <p v-if="currencyRatesError" class="mt-3 text-[10px] font-bold text-rose-600">{{ currencyRatesError }}</p>
                  <div v-else-if="exchangeRates.rates?.length" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    <div v-for="rate in exchangeRates.rates" :key="rate.quote_currency_code" class="rounded-md bg-slate-50 px-3 py-2">
                      <div class="text-[9px] font-bold text-slate-500">{{ rate.quote_currency_code }}</div>
                      <div class="mt-1 text-xs font-bold text-slate-900">{{ Number(rate.rate).toLocaleString('en-US', { maximumFractionDigits: 6 }) }}</div>
                    </div>
                  </div>
                  <p v-else class="mt-3 text-[10px] text-slate-500">لا توجد أسعار محفوظة حالياً؛ اضغط تحديث الأسعار لجلبها.</p>
                  <p v-if="exchangeRates.rates?.length" class="mt-3 text-[9px] text-slate-400">آخر تحديث: {{ exchangeRates.rates[0]?.fetched_at || exchangeRates.rates[0]?.rate_date }}</p>
                </div>
              </div>
            </div>
          </section>

          <!-- ════════════════════════════════════════════════════════════ -->
          <!-- 2. POS SETTINGS                                             -->
          <!-- ════════════════════════════════════════════════════════════ -->
          <section v-if="activeTab === 'pos-settings'" class="animate-fadeIn space-y-8">
            <div class="p-2 border-b border-slate-200">
               <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest">إدارة نقاط البيع والورديات</h2>
            </div>

            <!-- Terminal Redirect: Professional CTA Card -->
            <div class="bg-slate-900 rounded-xl p-8 text-white shadow-xl relative overflow-hidden group">
              <div class="absolute right-0 top-0 w-48 h-48 bg-blue-600/10 rounded-full translate-x-12 -translate-y-12 group-hover:scale-110 transition-transform duration-[2s]"></div>
              <div class="relative z-10 flex flex-col md:flex-row items-center justify-between gap-8">
                <div class="flex items-center gap-6">
                  <div class="w-14 h-14 bg-white/10 rounded-xl flex items-center justify-center text-2xl text-blue-400 shadow-inner"><i class="fas fa-desktop"></i></div>
                  <div>
                    <h3 class="text-lg font-bold leading-none">تعريف الأجهزة (Terminals)</h3>
                    <p class="text-xs text-slate-400 mt-2 font-medium">قم بربط أجهزة الكاشير بالمستودعات وتسميتها للتقارير.</p>
                  </div>
                </div>
                <router-link :to="{ name: 'TerminalsManagement' }" class="h-10 px-8 bg-blue-600 text-white rounded-lg text-[11px] font-bold uppercase tracking-widest hover:bg-blue-500 transition-all shadow-lg shadow-blue-900/40">إدارة الأجهزة</router-link>
              </div>
            </div>

            <!-- Configuration Grid -->
            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm p-8 space-y-10">

              <!-- ═══ Master Switch: تفعيل نظام الجلسات ═══ -->
              <div class="space-y-4">
                <h3 class="metadata-label text-slate-900"><i class="fas fa-power-off ml-2 text-blue-600"></i> تشغيل نظام الجلسات</h3>
                <div class="space-y-3">
                  <label class="setting-toggle-row bg-blue-50 border border-blue-200 px-4 rounded-lg h-14">
                    <div class="flex flex-col">
                      <span class="text-xs font-bold text-slate-800 uppercase tracking-tight">تفعيل نظام الورديات (Cashier Sessions)</span>
                      <span class="text-[9px] text-slate-500 mt-0.5">عند التفعيل، يُلزَم الكاشير بفتح جلسة قبل تسجيل أي مبيعات نقدية</span>
                    </div>
                    <input type="checkbox" v-model="posSession.sessionsEnabled" class="ios-toggle" />
                  </label>
                  <label class="setting-toggle-row bg-amber-50 border border-amber-200 px-4 rounded-lg h-14">
                    <div class="flex flex-col">
                      <span class="text-xs font-bold text-slate-800 uppercase tracking-tight">السماح برصيد نقدي سالب أثناء الجلسة</span>
                      <span class="text-[9px] text-slate-500 mt-0.5">يُطبَّق على حسابات النقد (Cash) فقط — يسمح بالصرف أكثر من الرصيد شرط أن تكون جلسة الكاشير مفتوحة</span>
                    </div>
                    <input type="checkbox" v-model="posSession.allowNegativeIntraday" class="ios-toggle" />
                  </label>
                </div>
              </div>

              <!-- Role Restrictions -->
              <div class="space-y-6">
                <div class="flex items-center justify-between border-b border-slate-50 pb-3">
                  <h3 class="metadata-label text-slate-900"><i class="fas fa-user-lock ml-2 text-rose-500"></i> تقييد صلاحيات البيع</h3>
                  <div class="flex gap-4">
                    <button @click="posSession.enforceForRoles = filteredPosRoleOptions.map(r => Number(r.id))" class="text-[9px] font-bold text-blue-600 uppercase hover:underline">تحديد الكل</button>
                    <button @click="posSession.enforceForRoles = []" class="text-[9px] font-bold text-slate-400 uppercase hover:underline">إلغاء الكل</button>
                  </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <label v-for="role in filteredPosRoleOptions" :key="role.id" class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 border border-slate-100 cursor-pointer hover:bg-white hover:border-blue-500 transition-all has-[:checked]:bg-blue-50 has-[:checked]:border-blue-200">
                    <input type="checkbox" :value="Number(role.id)" v-model="posSession.enforceForRoles" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-0" />
                    <span class="text-[11px] font-bold text-slate-700 truncate">{{ translateRole(role.name) }}</span>
                  </label>
                </div>
              </div>

              <!-- Approval Toggles -->
              <div class="pt-6 border-t border-slate-100 grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="space-y-6">
                  <h3 class="metadata-label text-slate-900"><i class="fas fa-toggle-on ml-2 text-blue-500"></i> معايير الاعتماد</h3>
                  <div class="space-y-3">
                    <label class="setting-toggle-row">
                      <div class="flex flex-col">
                        <span class="text-xs font-bold text-slate-800 uppercase tracking-tight">نظام الموافقة المسبقة</span>
                        <span class="text-[9px] text-slate-400 mt-0.5">يتطلب مراجعة إدارية قبل ترحيل الفواتير</span>
                      </div>
                      <input type="checkbox" v-model="posSession.requireApproval" class="ios-toggle" />
                    </label>
                    <label class="setting-toggle-row">
                      <div class="flex flex-col">
                        <span class="text-xs font-bold text-slate-800 uppercase tracking-tight">تجاوز حد الورديات للمدير</span>
                        <span class="text-[9px] text-slate-400 mt-0.5">السماح للمدراء بفتح أكثر من جلسة يومياً</span>
                      </div>
                      <input type="checkbox" v-model="posSession.allowManagerOverride" class="ios-toggle" />
                    </label>
                  </div>
                </div>

                <div class="space-y-6">
                  <h3 class="metadata-label text-slate-900"><i class="fas fa-history ml-2 text-indigo-500"></i> إدارة الورديات (Shifts)</h3>
                  <div class="grid grid-cols-1 gap-4">
                    <div class="space-y-1.5"><label class="metadata-label">الحد الأقصى للجلسات يومياً</label><select v-model="posSession.mode" class="filter-input-v2 h-10"><option value="">بدون قيود (مفتوح)</option><option value="one_per_day">جلسة واحدة فقط</option><option value="two_per_day">جلستان</option></select></div>
                    <div class="space-y-1.5"><label class="metadata-label">نمط التشغيل التلقائي</label><select v-model="posSession.sessionTypeMode" class="filter-input-v2 h-10"><option value="">يدوي (اختيار الكاشير)</option><option value="daily">يومي كامل</option><option value="morning">صباحي / مسائي (Cut-off)</option></select></div>
                  </div>
                </div>
              </div>

              <!-- ✅ RESTORED: وقت الفصل بين الوردية الصباحية والمسائية -->
              <div v-if="posSession.sessionTypeMode === 'morning'" class="pt-6 border-t border-slate-100">
                <div class="max-w-sm space-y-1.5">
                  <label class="metadata-label">وقت الفصل بين الوردية الصباحية والمسائية</label>
                  <input type="time" v-model="posSession.periodCutoff" class="filter-input-v2 h-10 font-mono font-bold" />
                  <p class="text-[9px] text-slate-400 font-bold italic"><i class="fas fa-clock ml-1"></i> قبل هذا الوقت = صباحية، بعده = مسائية</p>
                </div>
              </div>

              <!-- ✅ RESTORED: تجاوزات الموافقة لكل فرع -->
              <div class="pt-6 border-t border-slate-100 space-y-4">
                <h3 class="metadata-label text-slate-900"><i class="fas fa-code-branch ml-2 text-emerald-500"></i> تجاوزات الموافقة لكل فرع</h3>
                <p class="text-[10px] text-slate-400 font-medium italic leading-relaxed">
                  <i class="fas fa-info-circle ml-1"></i> حدد فروعاً تعمل بشكل مختلف عن الإعداد العام أعلاه. الفروع غير المحددة تتبع الإعداد العام.
                </p>
                <div v-if="branchOptions.length" class="space-y-2">
                  <label v-for="branch in branchOptions" :key="branch.id"
                    class="flex items-center justify-between p-3 rounded-lg bg-slate-50 border border-slate-100">
                    <span class="text-[11px] font-bold text-slate-700"><i class="fas fa-store ml-2 text-slate-400"></i>{{ branch.name }}</span>
                    <select
                      :value="posSession.branchApprovals[branch.id] === true ? 'on' : posSession.branchApprovals[branch.id] === false ? 'off' : ''"
                      @change="e => { const v = e.target.value; if (v === '') { const b = {...posSession.branchApprovals}; delete b[branch.id]; posSession.branchApprovals = b } else { posSession.branchApprovals = {...posSession.branchApprovals, [branch.id]: v === 'on'} } }"
                      class="h-8 bg-white border border-slate-200 rounded-md px-2 text-[10px] font-bold outline-none">
                      <option value="">إعداد عام</option>
                      <option value="on">موافقة مفعّلة</option>
                      <option value="off">موافقة معطّلة</option>
                    </select>
                  </label>
                </div>
                <div v-else class="text-[10px] text-slate-400 bg-slate-50 border border-slate-100 rounded-lg p-3 font-medium">
                  لا توجد فروع معرّفة. أضف فروعاً من إعدادات المخازن.
                </div>
              </div>
            </div>
          </section>

          <!-- ════════════════════════════════════════════════════════════ -->
          <!-- 3. INVOICE & TAX SETTINGS                                   -->
          <!-- ════════════════════════════════════════════════════════════ -->
          <section v-if="activeTab === 'invoice-settings'" class="animate-fadeIn space-y-8">
            <div class="p-2 border-b border-slate-200">
               <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest">تخصيص الفواتير والضرائب</h2>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm p-8">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                <!-- Tax Config -->
                <div class="space-y-8">
                  <h3 class="metadata-label text-rose-600"><i class="fas fa-percent ml-2"></i> تهيئة الضرائب (VAT)</h3>
                  <div class="space-y-4 p-6 bg-slate-50 border border-slate-100 rounded-xl">
                    <div class="space-y-1.5"><label class="metadata-label">رقم السجل الضريبي</label><input v-model="invoice.taxNumber" class="filter-input-v2 h-10 font-mono tracking-widest" /></div>
                    <div class="grid grid-cols-2 gap-4">
                      <div class="space-y-1.5"><label class="metadata-label">المُسمى</label><input v-model="invoice.taxName" class="filter-input-v2 h-10" /></div>
                      <div class="space-y-1.5"><label class="metadata-label">النسبة (%)</label><input v-model.number="invoice.taxRate" type="number" class="filter-input-v2 h-10 font-bold" /></div>
                    </div>
                    <div class="pt-4 flex flex-col gap-3 border-t border-slate-200/50">
                      <label class="setting-toggle-row bg-white rounded-md border border-slate-100 px-3 h-10">
                        <span class="text-[11px] font-bold text-slate-700">تفعيل احتساب الضريبة</span>
                        <input type="checkbox" v-model="invoice.taxEnabled" class="ios-toggle" />
                      </label>
                      <label class="setting-toggle-row bg-white rounded-md border border-slate-100 px-3 h-10">
                        <span class="text-[11px] font-bold text-slate-700">تضمين الضريبة في السعر المعروض</span>
                        <input type="checkbox" v-model="invoice.showTaxInPrice" class="ios-toggle" />
                      </label>
                    </div>
                  </div>
                </div>

                <!-- Sequencing -->
                <div class="space-y-8">
                  <h3 class="metadata-label text-blue-600"><i class="fas fa-hashtag ml-2"></i> أرقام الفواتير والتذييل</h3>
                  <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4">
                      <div class="space-y-1.5"><label class="metadata-label">بادئة الرقم (Prefix)</label><input v-model="invoice.invoicePrefix" class="filter-input-v2 h-10 font-mono" /></div>
                      <div class="space-y-1.5"><label class="metadata-label">الرقم القادم</label><input v-model.number="invoice.nextInvoiceNumber" type="number" class="filter-input-v2 h-10 font-mono font-bold" /></div>
                    </div>
                    <div class="space-y-1.5"><label class="metadata-label">تذييل الفاتورة</label><textarea v-model="invoice.footerText" rows="3" class="filter-input-v2 h-auto py-3 italic" placeholder="شكراً لتعاملكم معنا..."></textarea></div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- ════════════════════════════════════════════════════════════ -->
          <!-- 4. ACCOUNTING SETTINGS                                      -->
          <!-- ════════════════════════════════════════════════════════════ -->
          <section v-if="activeTab === 'accounting-settings'" class="animate-fadeIn space-y-8">
             <div class="p-2 border-b border-slate-200">
               <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest">توجيه الحسابات المحاسبية</h2>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm p-8 space-y-10">
              <!-- WAC Banner -->
              <div class="flex items-start gap-5 p-6 bg-slate-900 rounded-xl text-white shadow-xl">
                 <div class="w-12 h-12 bg-white/10 rounded-lg flex items-center justify-center text-blue-400 shrink-0"><i class="fas fa-chart-line text-lg"></i></div>
                 <div class="space-y-1">
                   <h4 class="text-sm font-bold uppercase tracking-widest text-blue-400">سياسة تقييم المخزون: WAC</h4>
                   <p class="text-[11px] text-slate-400 font-medium leading-relaxed">يستخدم النظام حالياً سياسة **متوسط التكلفة المرجّح (WAC)** لتقييم تكلفة البضاعة المباعة وقيمة المخزون تلقائياً وفقاً للقيود المسجلة.</p>
                 </div>
              </div>

              <!-- ✅ RESTORED: تنبيه الحسابات الأساسية غير المكتملة -->
              <div v-if="accountingMissingKeys.length" class="rounded-xl bg-amber-50 border border-amber-200 p-6 text-amber-900">
                <div class="font-bold text-xs uppercase tracking-widest mb-3 flex items-center gap-2">
                  <i class="fas fa-exclamation-triangle text-amber-500"></i>
                  إعدادات الحسابات الأساسية غير مكتملة
                </div>
                <ul class="list-disc pr-5 text-[11px] font-bold space-y-1 text-amber-800">
                  <li v-for="key in accountingMissingKeys" :key="key">لم يتم ضبط: {{ accountingKeyLabels[key] }}</li>
                </ul>
                <p class="text-[10px] text-amber-700 mt-3 font-bold italic">
                  قم بتعيين هذه الحسابات ثم اضغط حفظ لضمان إنشاء القيود المحاسبية بشكل صحيح.
                </p>
              </div>

              <!-- Integration Mapping -->
              <div class="space-y-6">
                <h3 class="metadata-label text-slate-900"><i class="fas fa-map-marked-alt ml-2 text-indigo-500"></i> ربط حسابات دفتر الأستاذ (GL Mapping)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                  <div class="space-y-1.5">
                    <label class="metadata-label">حساب تكلفة البضاعة (COGS)</label>
                    <select v-model="accounting.cogsAccountId" class="filter-input-v2 h-10 font-bold">
                      <option :value="null">-- اختر حساب --</option>
                      <optgroup v-if="accounts.tenant.length" label="حسابات المنشأة">
                        <option v-for="acc in accounts.tenant" :key="'cogs-t-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                      <optgroup v-if="accounts.global.length" label="حسابات عامة (افتراضية)">
                        <option v-for="acc in accounts.global" :key="'cogs-g-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                    </select>
                    <p class="text-[9px] font-bold text-slate-400 italic">يستخدم عند بيع المنتجات</p>
                  </div>
                  <div class="space-y-1.5">
                    <label class="metadata-label">حساب المخزون (Inventory Asset)</label>
                    <select v-model="accounting.inventoryAccountId" class="filter-input-v2 h-10 font-bold">
                      <option :value="null">-- اختر حساب --</option>
                      <optgroup v-if="accounts.tenant.length" label="حسابات المنشأة">
                        <option v-for="acc in accounts.tenant" :key="'inv-t-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                      <optgroup v-if="accounts.global.length" label="حسابات عامة (افتراضية)">
                        <option v-for="acc in accounts.global" :key="'inv-g-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                    </select>
                    <p class="text-[9px] font-bold text-slate-400 italic">يستخدم لتسجيل قيمة المخزون</p>
                  </div>
                  <div class="space-y-1.5 md:col-span-2">
                    <label class="metadata-label">حساب تسويات المخزون (Adjustments)</label>
                    <select v-model="accounting.inventoryAdjustmentAccountId" class="filter-input-v2 h-10 font-bold">
                      <option :value="null">-- اختر حساب --</option>
                      <optgroup v-if="accounts.tenant.length" label="حسابات المنشأة">
                        <option v-for="acc in accounts.tenant" :key="'adj-t-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                      <optgroup v-if="accounts.global.length" label="حسابات عامة (افتراضية)">
                        <option v-for="acc in accounts.global" :key="'adj-g-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                    </select>
                    <p class="text-[9px] font-bold text-slate-400 italic">يستخدم عند تسوية فروق المخزون</p>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- ════════════════════════════════════════════════════════════ -->
          <!-- 5. PRINTING                                                 -->
          <!-- ════════════════════════════════════════════════════════════ -->
          <section v-if="activeTab === 'printers-settings'" class="animate-fadeIn space-y-8">
            <div class="p-2 border-b border-slate-200">
               <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest">إعدادات الطباعة والقوالب</h2>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-10">
              <!-- Design Config -->
              <div class="xl:col-span-7 space-y-8">
                <div class="bg-white border border-slate-200 rounded-xl p-8 space-y-8 shadow-sm">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-1.5"><label class="metadata-label">آلية الطباعة</label><select v-model="printMode" class="filter-input-v2 h-10 font-bold"><option value="browser">متصفح (نافذة النظام)</option><option value="qztray" :disabled="isProductionBuild">طباعة مباشرة (QZ Tray — تطوير فقط)</option></select></div>
                    <div class="space-y-1.5"><label class="metadata-label">قالب الفاتورة</label><select v-model="printTemplate" class="filter-input-v2 h-10 font-bold"><option value="thermal-compact">حراري مختصر (80mm)</option><option value="a4-professional">A4 احترافي</option></select></div>
                  </div>
                  <p v-if="isProductionBuild" class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[10px] font-bold">
                    الطباعة المباشرة عبر QZ Tray متوقفة في الإنتاج حتى استكمال تكامل شهادة موثوقة وموقّع خادمي. استخدم طباعة المتصفح حاليًا.
                  </p>

                  <!-- اختيار طابعة QZ Tray في بيئة التطوير -->
                  <div v-if="printMode === 'qztray'" class="pt-4 border-t border-slate-50 space-y-3">
                    <div class="flex items-center justify-between">
                      <label class="metadata-label">اختر الطابعة</label>
                      <button type="button" @click="loadQzPrinters" :disabled="qzPrintersLoading" class="text-[10px] font-bold text-blue-600 hover:text-blue-800 flex items-center gap-1 disabled:opacity-50">
                        <i :class="qzPrintersLoading ? 'fas fa-spinner fa-spin' : 'fas fa-sync'"></i>
                        {{ qzPrintersLoading ? 'جاري البحث...' : 'تحديث الطابعات' }}
                      </button>
                    </div>
                    <select v-model="qzPrinterName" class="filter-input-v2 h-10 font-bold" :disabled="qzPrintersLoading">
                      <option value="">— طابعة النظام الافتراضية —</option>
                      <option v-for="p in qzPrinters" :key="p" :value="p">{{ p }}</option>
                    </select>
                    <p v-if="qzConnectError" class="text-[9px] text-rose-500 font-bold"><i class="fas fa-exclamation-circle ml-1"></i>{{ qzConnectError }}</p>
                    <div class="p-3 rounded-lg bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-bold">
                      <i class="fas fa-info-circle ml-1"></i>
                      يتطلب تشغيل تطبيق <strong>QZ Tray</strong> على الجهاز. <a href="https://qz.io" target="_blank" class="underline">تحميل QZ Tray</a>
                    </div>
                  </div>

                  <!-- ✅ RESTORED: إظهار الترويسة/التذييل -->
                  <div class="pt-4 border-t border-slate-50 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="setting-toggle-row bg-slate-50 rounded-lg border border-slate-100 px-3 h-10">
                      <span class="text-[11px] font-bold text-slate-700">إظهار ترويسة المتجر</span>
                      <input type="checkbox" v-model="printers.printHeader" class="ios-toggle" />
                    </label>
                    <label class="setting-toggle-row bg-slate-50 rounded-lg border border-slate-100 px-3 h-10">
                      <span class="text-[11px] font-bold text-slate-700">إظهار تذييل الفاتورة</span>
                      <input type="checkbox" v-model="printers.printFooter" class="ios-toggle" />
                    </label>
                  </div>

                  <div class="space-y-4 pt-4 border-t border-slate-50">
                    <div class="space-y-1.5"><label class="metadata-label">نص ترحيبي</label><input v-model="printTexts.headerText" class="filter-input-v2 h-9 text-xs" /></div>
                    <div class="space-y-1.5"><label class="metadata-label">الشروط والأحكام</label><textarea v-model="printTexts.termsText" rows="3" class="filter-input-v2 h-auto py-3 text-xs"></textarea></div>
                  </div>
                </div>
              </div>

              <!-- Live Preview: The Professional Terminal Style -->
              <div class="xl:col-span-5">
                <div class="bg-slate-900 rounded-xl p-8 shadow-2xl relative overflow-hidden border border-white/5">
                   <div class="flex items-center justify-between mb-6">
                      <span class="text-[9px] font-bold text-white/30 uppercase tracking-[0.3em]">معاينة حية للمخرجات</span>
                      <div class="flex gap-1.5 p-1 bg-white/5 rounded-lg border border-white/5">
                        <button v-for="pt in ['sale','return','purchase']" :key="pt" @click="previewType = pt" :class="[previewType === pt ? 'bg-white text-slate-900 shadow-sm' : 'text-white/40 hover:bg-white/5']" class="px-3 py-1 rounded-md text-[9px] font-bold uppercase transition-all">{{ pt }}</button>
                      </div>
                   </div>
                   <div class="bg-white rounded-lg overflow-hidden shadow-inner border border-black/20" style="height: 480px">
                     <iframe :srcdoc="previewHtml" sandbox="allow-same-origin" class="w-full h-full border-none pointer-events-none"></iframe>
                   </div>
                   <button @click="testPrint" class="w-full mt-6 h-10 rounded-lg bg-white/10 text-white text-[10px] font-bold uppercase tracking-widest hover:bg-white/20 transition-all border border-white/10">طباعة تجريبية</button>
                </div>
              </div>
            </div>
          </section>

        </main>
      </div>
    </div>

  </div>
</template>


<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useRoleStore } from '@/stores/role/roleStore';
import { useAccountStore } from '@/stores/account/accountStore';
import { useSettingsStore } from '@/stores/settings/settingsStore'
import { getAvailablePrinters, getQzPrinterName, setQzPrinterName, printDocument } from '@/utils';
import { getBuilderByTemplate, buildReturnHtml, buildPurchaseHtml } from '@/utils/printTemplates';
import { clearPrintConfigCache } from '@/utils/printTemplates/printConfig';
import { getImageUrl } from '@/utils';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency'
import { useToast } from '@/composables/useToast'
import { useDirtyTracking } from '@/composables/useSettings'
import { getAvailableCurrencies, getCurrencySymbol, SUPPORTED_CURRENCIES } from '@/config/currencies'
import LoadingButton from '@/components/ui/LoadingButton.vue'
import AlertService from '@/services/AlertService'
import { storeToRefs } from 'pinia'
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';
import { isSettingEnabled } from '@/utils';
import { currencyService } from '@/services/currencies';

// ─── Core Setup ───────────────────────────────────────────────────────────────
const route = useRoute();
const { breadcrumb } = useBreadcrumb();
const router = useRouter();
const { showToast } = useToast()
const authStore = useAuthStore();
const branchStore = useBranchStore();
const roleStore = useRoleStore();
const accountStore = useAccountStore();
const settingsStore = useSettingsStore();
const isLoading = ref(false)
const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const logoPreview = ref(null)
const logoFile = ref(null)
const activeTab = ref(route.query.tab === 'users-settings' ? 'general-settings' : route.query.tab || 'general-settings')
const errors = ref({})
const searchTerm = ref('')
const exchangeRates = ref({ base_currency_code: '', supported_currencies: [], rates: [] })
const currencyRatesLoading = ref(false)
const currencyRatesError = ref('')

// ─── Tabs ─────────────────────────────────────────────────────────────────────
const tabs = [
  { id: 'general-settings',  label: 'الإعدادات العامة',     icon: 'fas fa-cog' },
  { id: 'invoice-settings',  label: 'الفواتير والضرائب',     icon: 'fas fa-file-invoice' },
  { id: 'pos-settings',      label: 'نقطة البيع (POS)',      icon: 'fas fa-cash-register' },
  { id: 'accounting-settings', label: 'الحسابات المحاسبية', icon: 'fas fa-balance-scale' },
  { id: 'printers-settings', label: 'إعدادات الطابعات',      icon: 'fas fa-print' },
]

const filteredTabs = computed(() => {
  const q = (searchTerm.value || '').trim()
  if (!q) return tabs
  return tabs.filter(t => t.label.includes(q) || t.id.includes(q))
})

// ─── Print Mode ───────────────────────────────────────────────────────────────
const isProductionBuild = import.meta.env.PROD
const printMode       = ref('browser')
const qzPrinterName   = ref('')
const qzPrinters      = ref([])
const qzPrintersLoading = ref(false)
const qzConnectError  = ref('')

const loadQzPrinters = async () => {
  qzPrintersLoading.value = true
  qzConnectError.value = ''
  try {
    qzPrinters.value = await getAvailablePrinters()
    if (!qzPrinters.value.length) qzConnectError.value = 'لم يتم العثور على طابعات — تأكد من تشغيل QZ Tray'
  } catch {
    qzConnectError.value = 'فشل الاتصال بـ QZ Tray — تأكد من تثبيته وتشغيله'
  } finally {
    qzPrintersLoading.value = false
  }
}

// ─── Settings State ───────────────────────────────────────────────────────────
const general = ref({
  businessName: '', email: '', phone: '', address: '',
  currency: 'EGP', timezone: 'Asia/Riyadh',
  dateFormat: 'DD/MM/YYYY', timeFormat: '12', logo: null,
})

const invoice = ref({
  taxNumber: '', taxRate: 15, taxName: 'ضريبة القيمة المضافة',
  taxEnabled: true, footerText: 'شكراً لتعاملكم معنا',
  invoicePrefix: 'INV-', nextInvoiceNumber: 1001, showTaxInPrice: true,
})

const posSession = ref({
  sessionsEnabled: false,
  enforceForRoles: [],
  mode: '',
  requireApproval: false,
  sessionTypeMode: '',
  periodCutoff: '15:00',
  allowManagerOverride: false,
  branchApprovals: {},
  allowNegativeIntraday: false,
})

const printers = ref({
  printHeader: true, printFooter: true,
})

const printTemplate = ref(localStorage.getItem('pos_print_template') || 'thermal-compact')
const previewType   = ref('sale')

const printTexts = ref({
  headerText: localStorage.getItem('pos_print_header_text') || '',
  footerText: localStorage.getItem('pos_print_footer_text') || 'شكراً لتعاملكم معنا',
  termsText:  localStorage.getItem('pos_print_terms_text')  || '',
})

// ─── Accounting ───────────────────────────────────────────────────────────────
const accounting = ref({
  cogsAccountId: null,
  inventoryAccountId: null,
  inventoryAdjustmentAccountId: null,
})

const { accounts } = storeToRefs(accountStore);

// Reactive role options from store
const roleOptions = computed(() => roleStore.roles);

// Computed: missing required accounting keys
const accountingMissingKeys = computed(() => {
  const missing = []
  if (!accounting.value.cogsAccountId)                 missing.push('cogs_account_id')
  if (!accounting.value.inventoryAccountId)             missing.push('inventory_account_id')
  if (!accounting.value.inventoryAdjustmentAccountId)       missing.push('inventory_adjustment_account_id')
  return missing
})

const accountingKeyLabels = {
  cogs_account_id: 'حساب تكلفة البضاعة المباعة (COGS)',
  inventory_account_id: 'حساب المخزون',
  inventory_adjustment_account_id: 'حساب تسويات/فروق المخزون',
}

const rolesLoading = ref(false);
const branchOptions = computed(() => branchStore.branches.map(w => ({ id: w.id, name: w.name })))
const branchesLoading = ref(false)

// ─── Dirty Tracking ───────────────────────────────────────────────────────────
const { isDirty, isSectionDirty, setSnapshotsFromCurrent, resetSectionToSnapshot } = useDirtyTracking({
  general, invoice, pos: posSession, printers, accounting,
})

// ─── Role Filters ─────────────────────────────────────────────────────────────
const excludedPosRoleNames = ['super_admin', 'مدير النظام']

const filteredPosRoleOptions = computed(() => {
  try {
    return (roleOptions.value || []).filter(r => {
      const n = String(r.name || '').trim().toLowerCase()
      return !excludedPosRoleNames.some(x => n === String(x).toLowerCase())
    })
  } catch (_) { return roleOptions.value || [] }
})

// Guard: strip excluded roles from posSession.enforceForRoles if they somehow slip in
watch(() => posSession.value.enforceForRoles, (vals) => {
  try {
    const excludedIds = new Set(
      (roleOptions.value || [])
        .filter(r => excludedPosRoleNames.map(x => x.toLowerCase()).includes(String(r.name || '').trim().toLowerCase()))
        .map(r => Number(r.id))
    )
    const cleaned = (vals || []).filter(v => !excludedIds.has(Number(v)))
    if (cleaned.length !== (vals || []).length) posSession.value.enforceForRoles = cleaned
  } catch (_) { /* ignore */ }
}, { deep: true })

// ─── Role Translations ────────────────────────────────────────────────────────
const roleTranslations = {
  super_admin: 'مدير النظام', admin: 'مدير', manager: 'مدير الفرع',
  user: 'مستخدم', cashier: 'كاشير', finance_officer: 'مسؤول مالي',
  inventory_clerk: 'مسؤول المخزون', accountant: 'محاسب',
  inventory: 'مخزون', client: 'عميل',
}
const translateRole = (role) => {
  if (!role) return role
  return roleTranslations[String(role).toLowerCase().trim()] || role
}

// ─── Tab Navigation ───────────────────────────────────────────────────────────
function sectionKeyFromTab(tabId) {
  return {
    'general-settings':    'general',
    'invoice-settings':    'invoice',
    'pos-settings':        'pos',
    'printers-settings':   'printers',
    'accounting-settings': 'accounting',
  }[tabId] || null
}

function setActiveTab(tabId) {
  const currentKey = sectionKeyFromTab(activeTab.value)
  if (currentKey && isSectionDirty(currentKey)) {
    const ok = AlertService.confirm(
      'هناك تغييرات غير محفوظة في هذا القسم. هل تريد المتابعة بدون حفظ؟',
      'تغييرات غير محفوظة'
    )
    if (!ok) return
  }
  activeTab.value = tabId
}

// Sync URL query param with activeTab
watch(() => route.query.tab, (newTab) => {
  if (newTab === 'users-settings') {
    router.replace({ name: 'UserManagement' })
    return
  }
  if (newTab && newTab !== activeTab.value) activeTab.value = String(newTab)
})

watch(activeTab, (tab) => {
  if (tab === 'pos-settings') {
    if (!roleOptions.value.length) fetchRoleOptions();
    if (!branchOptions.value.length) fetchBranchOptions();
  }
});

// ─── Save Bar Handlers ────────────────────────────────────────────────────────
async function saveActiveTab() {
  const key = sectionKeyFromTab(activeTab.value)
  if (!key) { showToast('لا يوجد ما يتم حفظه في هذا التبويب', 'info'); return }
  if (!isSectionDirty(key)) { showToast('لا توجد تغييرات لحفظها', 'info'); return }

  if (key === 'general')          await saveGeneralSettings()
  else if (key === 'invoice')     await saveInvoiceSettings()
  else if (key === 'pos')         await savePosSettings()
  else if (key === 'printers')    await savePrinterSettings()
  else if (key === 'accounting')  await saveAccountingSettings()
}

function discardActiveTab() {
  const key = sectionKeyFromTab(activeTab.value)
  if (!key || !isSectionDirty(key)) return
  const ok = AlertService.confirm(
    'سيتم تجاهل التغييرات غير المحفوظة في هذا القسم. هل أنت متأكد؟',
    'تجاهل التغييرات'
  )
  if (!ok) return
  resetSectionToSnapshot(key)
}

// ─── Toast Helpers ────────────────────────────────────────────────────────────
const showError = (message) => {
  errorMessage.value = message
  showToast(message, 'error', 5000)
  setTimeout(() => { errorMessage.value = '' }, 5000)
}

const showSuccess = (message) => {
  showToast(message, 'success', 3000)
}

// ─── Logo Handling ────────────────────────────────────────────────────────────
const handleLogoUpload = (event) => {
  const file = event.target.files[0]
  if (!file) return

  const validTypes = ['image/jpeg', 'image/png', 'image/gif']
  if (!validTypes.includes(file.type)) {
    showError('نوع الملف غير مدعوم. يرجى اختيار صورة بصيغة JPG أو PNG أو GIF')
    return
  }
  if (file.size > 2 * 1024 * 1024) {
    showError('حجم الملف كبير جداً. الحد الأقصى المسموح به هو 2 ميجابايت')
    return
  }
  logoFile.value = file
  logoPreview.value = URL.createObjectURL(file)
}

const removeLogo = () => {
  logoFile.value = null
  logoPreview.value = null
  general.value.logo = null
  const fileInput = document.querySelector('input[type="file"]')
  if (fileInput) fileInput.value = ''
  // Clear from localStorage immediately
  try {
    localStorage.removeItem('pos_company_logo')
  } catch (e) { /* ignore */ }
}

// ─── Reset Form ───────────────────────────────────────────────────────────────
const resetForm = () => {
  general.value = {
    businessName: general.value.businessName,
    email: '', phone: '', address: '',
    currency: 'EGP', timezone: 'Asia/Riyadh',
    dateFormat: 'DD/MM/YYYY', timeFormat: '12', logo: null,
  }
  logoFile.value = null
  logoPreview.value = null
  errors.value = {}
}

// ─── Computed: Access Control ─────────────────────────────────────────────────
const hasSettingsAccess = computed(() => {
  if (!authStore.user) return false

  // Explicit permission check (most reliable)
  if (typeof authStore.hasPermission === 'function' &&
      (authStore.hasPermission('settings.view') || authStore.hasPermission('settings.manage'))) {
    return true
  }

  // Store-level admin flag
  if (typeof authStore.isAdmin !== 'undefined' && authStore.isAdmin) return true

  // Role-based fallback
  const userData = authStore.user.user || authStore.user
  const role = (userData?.role || '').toLowerCase()

  return authStore.isSuperAdmin ||
    (Number(userData?.tenant_id) > 0 &&
      (role === 'admin' || role === 'manager'))
})

// ─── Data Fetching: Roles / Branches ────────────────────────────────────────
async function fetchRoleOptions() {
  if (!hasSettingsAccess.value) return
  try {
    rolesLoading.value = true
    await roleStore.fetchRoles();
    // Role options are now cached in roleStore
  } catch (err) {
    console.error('Error fetching roles:', err)
    showError(err.response?.data?.message || 'فشل تحميل الأدوار.')
  } finally {
    rolesLoading.value = false
  }
}

async function fetchBranchOptions() {
  if (!hasSettingsAccess.value) return
  try {
    branchesLoading.value = true
    await branchStore.fetchBranches();
  } catch (err) {
    console.error('Error fetching branches:', err)
    showError(err.response?.data?.message || 'فشل تحميل الفروع.')
  } finally {
    branchesLoading.value = false
  }
}

// ─── Settings Fetch ───────────────────────────────────────────────────────────
async function fetchSettings() {
  if (!hasSettingsAccess.value) return
  isLoading.value = true
  errorMessage.value = ''
  try {
    const response = await settingsStore.fetchSettings();
    if (response.status === 'success' && response.data && response.data.settings) {
      const s = response.data;

      general.value = {
        businessName: s['company.name'] || s['company_name'] || '',
        email:        s['company.email'] || '',
        phone:        s['company.phone'] || '',
        address:      s['company.address'] || '',
        currency:     s['company.currency'] || 'EGP',
        timezone:     s['app.timezone'] || 'Asia/Riyadh',
        dateFormat:   s['app.date_format'] || 'DD/MM/YYYY',
        timeFormat:   s['app.time_format'] || '12',
        logo:         s['company.logo'] || null,
      }
      logoPreview.value = getImageUrl(general.value.logo)

      invoice.value = {
        taxNumber:         s['tax.tax_number'] || '',
        taxRate:           parseFloat(s['tax.tax_rate']) || 15,
        taxName:           s['tax.tax_name'] || 'ضريبة القيمة المضافة',
        taxEnabled:        s['tax.tax_enabled'] === '1',
        footerText:        s['invoice.footer_text'] || 'شكراً لتعاملكم معنا',
        invoicePrefix:     s['invoice.prefix'] || 'INV-',
        nextInvoiceNumber: Number(s['invoice.next_number']) || 1001,
        showTaxInPrice:    s['invoice.show_tax_in_price'] !== '0',
      }

      printMode.value    = isProductionBuild ? 'browser' : (s['print.mode'] || 'browser')
      qzPrinterName.value = s['print.qztray_printer'] || getQzPrinterName()
      printers.value = {
        printHeader: s['printer.print_header'] !== '0',
        printFooter: s['printer.print_footer'] !== '0',
      }

      // POS sessions enforcement — handles JSON array or comma-separated string
      ;(function parseEnforceRoles() {
        const raw = s['pos.sessions.enforce_for_roles']
        let parsed = []
        if (Array.isArray(raw)) {
          parsed = raw
        } else if (typeof raw === 'string') {
          const trimmed = raw.trim()
          try {
            if (trimmed.startsWith('[')) parsed = JSON.parse(trimmed)
            else if (trimmed.length) parsed = trimmed.split(',').map(x => Number(x.trim())).filter(n => !isNaN(n))
          } catch (_) { /* ignore */ }
        }
        posSession.value.enforceForRoles = parsed.map(n => Number(n)).filter(n => !isNaN(n))
      })()

      posSession.value.sessionsEnabled      = isSettingEnabled(s['pos.sessions.enabled'])
      posSession.value.mode                 = (s['pos.sessions.mode'] || '').toString()
      posSession.value.requireApproval      = s['pos.require_approval'] === '1'
      posSession.value.sessionTypeMode      = (s['pos.sessions.session_type_mode'] || '').toString()
      posSession.value.periodCutoff         = (s['pos.sessions.period_cutoff'] || '15:00').toString()
      posSession.value.allowManagerOverride = s['pos.sessions.allow_manager_override'] === '1'
      posSession.value.allowNegativeIntraday = s['pos.cash_session.allow_negative_intraday'] === '1'
      // Per-branch approval overrides: stored as JSON object {branch_id: bool}
      try {
        const raw = s['pos.branch_approvals']
        posSession.value.branchApprovals = raw ? JSON.parse(raw) : {}
      } catch (_) { posSession.value.branchApprovals = {} }

      accounting.value = {
        cogsAccountId:                   s['cogs_account_id'] || null,
        inventoryAccountId:              s['inventory_account_id'] || null,
        inventoryAdjustmentAccountId:    s['inventory_adjustment_account_id'] || null,
      }

      printTexts.value = {
        headerText: s['print.header_text'] || printTexts.value.headerText,
        footerText: s['print.footer_text'] || printTexts.value.footerText,
        termsText:  s['print.terms_text']  || printTexts.value.termsText,
      }

      // Sync key values to localStorage for POS print headers
      try {
        if (general.value.businessName) localStorage.setItem('pos_company_name', general.value.businessName)
        if (general.value.phone)        localStorage.setItem('pos_company_phone', general.value.phone)
        if (invoice.value.taxNumber)    localStorage.setItem('pos_company_tax_number', invoice.value.taxNumber)
        if (general.value.logo) {
          localStorage.setItem('pos_company_logo', getImageUrl(general.value.logo))
        }
      } catch (e) { /* ignore quota errors */ }

      setSnapshotsFromCurrent()
    }
  } catch (error) {
    console.error('Error fetching settings:', error)
    showError(error.response?.data?.message || 'فشل تحميل الإعدادات. يرجى المحاولة مرة أخرى.')
  } finally {
    isLoading.value = false
  }
  await fetchCurrencyRates()
}

async function fetchCurrencyRates(forceRefresh = false) {
  currencyRatesLoading.value = true
  currencyRatesError.value = ''
  try {
    if (forceRefresh) {
      await currencyService.refreshRates()
    }
    const response = await currencyService.getRates()
    if (response.status !== 'success' || !response.data) {
      throw new Error(response.message || 'لم يتم إرجاع أسعار الصرف')
    }
    exchangeRates.value = response.data
  } catch (error) {
    currencyRatesError.value = error.response?.data?.message || error.message || 'تعذر تحميل أسعار الصرف'
  } finally {
    currencyRatesLoading.value = false
  }
}

async function refreshCurrencyRates() {
  await fetchCurrencyRates(true)
}

// ─── Save Methods ─────────────────────────────────────────────────────────────
async function saveGeneralSettings() {
  errors.value = {}
  if (!general.value.businessName?.trim()) {
    errors.value.businessName = 'يرجى إدخال اسم النشاط التجاري'
    return
  }
  isSaving.value = true
  try {
    const formData = new FormData()
    const settings = {
      'company.name':            general.value.businessName,
      'company.email':           general.value.email,
      'company.phone':           general.value.phone,
      'company.address':         general.value.address,
      'company.currency':        general.value.currency,
      'company.currency_code':   general.value.currency,
      'company.currency_symbol': SUPPORTED_CURRENCIES[general.value.currency]?.symbol || '€',
      'app.timezone':            general.value.timezone,
      'app.date_format':         general.value.dateFormat,
      'app.time_format':         general.value.timeFormat,
    }
    if (logoFile.value) {
      formData.append('logo', logoFile.value)
    } else if (general.value.logo === null) {
      settings['company.logo'] = ''
    }
    formData.append('settings', JSON.stringify(settings))

    const response = await settingsStore.uploadLogo(formData)
    if (response.status === 'success') {
      showSuccess('تم حفظ الإعدادات بنجاح')
      try {
        localStorage.setItem('pos_company_name',    general.value.businessName || '')
        localStorage.setItem('pos_company_phone',   general.value.phone || '')
        localStorage.setItem('pos_company_email',   general.value.email || '')
        localStorage.setItem('pos_company_address', general.value.address || '')
      } catch (e) { /* ignore */ }
      const { refreshSettings } = useCompanyCurrency()
      await refreshSettings()
      await settingsStore.fetchSettings({ force: true })
      await fetchCurrencyRates()
      // Update logoPreview immediately with the new logo from store
      const newLogo = settingsStore.settings?.['company.logo']
      if (newLogo) {
        general.value.logo = newLogo
        logoPreview.value = getImageUrl(newLogo)
      }
      setSnapshotsFromCurrent()
    } else {
      showError(response?.message || 'حدث خطأ أثناء حفظ الإعدادات.')
    }
  } catch (error) {
    console.error('Error saving general settings:', error)
    showError(error.response?.data?.message || 'حدث خطأ أثناء حفظ الإعدادات.')
  } finally {
    isSaving.value = false
  }
}

async function saveInvoiceSettings() {
  isSaving.value = true
  try {
    const settings = {
      // Write tax settings under tax.* namespace (source of truth, matches Setup wizard & settingsStore.tax)
      'tax.tax_number':              invoice.value.taxNumber,
      'tax.tax_rate':                String(invoice.value.taxRate),
      'tax.tax_name':                invoice.value.taxName,
      'tax.tax_enabled':             invoice.value.taxEnabled ? '1' : '0',
      // Keep company.* copies in sync so any legacy code reading that namespace still works
      'company.tax_number':          invoice.value.taxNumber,
      'company.tax_rate':            String(invoice.value.taxRate),
      'company.tax_name':            invoice.value.taxName,
      'company.tax_enabled':         invoice.value.taxEnabled ? '1' : '0',
      // Invoice layout settings
      'invoice.footer_text':         invoice.value.footerText,
      'invoice.prefix':              invoice.value.invoicePrefix,
      'invoice.next_number':         String(invoice.value.nextInvoiceNumber),
      'invoice.show_tax_in_price':   invoice.value.showTaxInPrice ? '1' : '0',
    }
    const response = await settingsStore.updateSettings(settings)
    if (response.status === 'success') {
      showSuccess('تم حفظ إعدادات الفواتير بنجاح')
      const { refreshSettings } = useCompanyCurrency()
      await refreshSettings()
      await fetchSettings()
      setSnapshotsFromCurrent()
    } else {
  showError(response?.message || 'حدث خطأ أثناء حفظ إعدادات الفواتير.')
   }
  } catch (error) {
    console.error('Error saving invoice settings:', error)
    showError(error.response?.data?.message || 'حدث خطأ أثناء حفظ إعدادات الفواتير.')
  } finally {
    isSaving.value = false
  }
}

async function savePosSettings() {
  isSaving.value = true
  try {
    const enforce = (posSession.value.enforceForRoles || [])
      .filter(n => !isNaN(Number(n))).map(n => Number(n))

    const settings = {
      'pos.sessions.enabled':                 posSession.value.sessionsEnabled ? '1' : '0',
      'pos.sessions.enforce_for_roles':       JSON.stringify(enforce),
      'pos.sessions.mode':                    posSession.value.mode || '',
      'pos.require_approval':                 posSession.value.requireApproval ? '1' : '0',
      'pos.sessions.session_type_mode':       posSession.value.sessionTypeMode || '',
      'pos.sessions.period_cutoff':           posSession.value.periodCutoff || '15:00',
      'pos.sessions.allow_manager_override':  posSession.value.allowManagerOverride ? '1' : '0',
      'pos.branch_approvals':                 JSON.stringify(posSession.value.branchApprovals || {}),
      'pos.cash_session.allow_negative_intraday': posSession.value.allowNegativeIntraday ? '1' : '0',
    }
    const response = await settingsStore.updateSettings(settings)
    if (response.status === 'success') {
      showSuccess('تم حفظ إعدادات نقطة البيع بنجاح')
      const { refreshSettings } = useCompanyCurrency()
      await refreshSettings()
      await fetchSettings()
      setSnapshotsFromCurrent()
    } else {
  showError(response?.message || 'حدث خطأ أثناء حفظ إعدادات POS.')
   }
  } catch (error) {
    console.error('Error saving POS settings:', error)
    showError(error.response?.data?.message || 'حدث خطأ أثناء حفظ إعدادات POS.')
  } finally {
    isSaving.value = false
  }
}

async function savePrinterSettings() {
  isSaving.value = true
  try {
    const settings = {
      'print.mode':              isProductionBuild ? 'browser' : printMode.value,
      'print.qztray_printer':   qzPrinterName.value,
      'printer.print_header':   printers.value.printHeader ? '1' : '0',
      'printer.print_footer': printers.value.printFooter ? '1' : '0',
      'print.header_text':    printTexts.value.headerText,
      'print.footer_text':    printTexts.value.footerText,
      'print.terms_text':     printTexts.value.termsText,
    }
    const response = await settingsStore.updateSettings(settings)
    if (response.status === 'success') {
      localStorage.setItem('print.mode',                 isProductionBuild ? 'browser' : printMode.value)
      setQzPrinterName(qzPrinterName.value)
      localStorage.setItem('pos_print_template',         printTemplate.value)
      localStorage.setItem('pos_print_header_enabled',   printers.value.printHeader ? '1' : '0')
      localStorage.setItem('pos_print_footer_enabled',   printers.value.printFooter ? '1' : '0')
      localStorage.setItem('pos_print_header_text',      printTexts.value.headerText || '')
      localStorage.setItem('pos_print_footer_text',      printTexts.value.footerText || '')
      localStorage.setItem('pos_print_terms_text',       printTexts.value.termsText  || '')
      showSuccess('تم حفظ إعدادات الطابعات بنجاح')
      const { refreshSettings } = useCompanyCurrency()
      await refreshSettings()
      await fetchSettings()
      setSnapshotsFromCurrent()
    } else {
  showError(response?.message || 'حدث خطأ أثناء حفظ إعدادات الطابعة.')
  }
  } catch (error) {
    console.error('Error saving printer settings:', error)
    showError(error.response?.data?.message || 'حدث خطأ أثناء حفظ إعدادات الطابعات.')
  } finally {
    isSaving.value = false
  }
}

async function saveAccountingSettings() {
  isSaving.value = true
  try {
    const settings = {
      cogs_account_id:                    accounting.value.cogsAccountId,
      inventory_account_id:               accounting.value.inventoryAccountId,
      inventory_adjustment_account_id:    accounting.value.inventoryAdjustmentAccountId,
    }
    const response = await settingsStore.updateSettings(settings)
    if (response.status === 'success') {
      showSuccess('تم حفظ إعدادات الحسابات المحاسبية بنجاح')
      const { refreshSettings } = useCompanyCurrency()
      await refreshSettings()
      await fetchSettings()
      setSnapshotsFromCurrent()
    }
  } catch (error) {
    console.error('Error saving accounting settings:', error)
    showError(error.response?.data?.message || 'حدث خطأ أثناء حفظ إعدادات الحسابات المحاسبية.')
  } finally {
    isSaving.value = false
  }
}

// ─── Accounting Accounts Loader ───────────────────────────────────────────────
async function loadAccountingAccounts() {
  try {
    await accountStore.fetchGroupedAccounts();
    // Accounting accounts are now cached in accountStore
  } catch (error) {
    console.error('Error loading accounting accounts:', error)
    showError('فشل تحميل الحسابات المحاسبية')
  }
}

// ─── Temporarily sync form values → localStorage → call fn → restore ─────────
function withFormLS(fn) {
  const KEYS = [
    'pos_print_header_enabled', 'pos_print_footer_enabled',
    'pos_print_header_text',    'pos_print_footer_text',
    'pos_print_terms_text',
    'pos_company_name',         'pos_company_phone',
    'pos_company_tax_number',   'pos_company_logo',
    'pos_company_address',
  ]
  const prev = Object.fromEntries(KEYS.map(k => [k, localStorage.getItem(k)]))
  const logo = getImageUrl(general.value.logo || prev['pos_company_logo']) || ''
  localStorage.setItem('pos_print_header_enabled', printers.value.printHeader ? '1' : '0')
  localStorage.setItem('pos_print_footer_enabled', printers.value.printFooter ? '1' : '0')
  localStorage.setItem('pos_print_header_text',    printTexts.value.headerText || '')
  localStorage.setItem('pos_print_footer_text',    printTexts.value.footerText || '')
  localStorage.setItem('pos_print_terms_text',     printTexts.value.termsText  || '')
  localStorage.setItem('pos_company_name',         general.value.businessName  || prev['pos_company_name'] || 'اسم المتجر')
  localStorage.setItem('pos_company_phone',        general.value.phone         || prev['pos_company_phone'] || '')
  localStorage.setItem('pos_company_tax_number',   invoice.value.taxNumber     || prev['pos_company_tax_number'] || '')
  localStorage.setItem('pos_company_address',      general.value.address       || prev['pos_company_address'] || '')
  localStorage.setItem('pos_company_logo',         logo)
  clearPrintConfigCache()
  const result = fn()
  KEYS.forEach(k => { if (prev[k] === null) localStorage.removeItem(k); else localStorage.setItem(k, prev[k]) })
  clearPrintConfigCache()
  return result
}

// ─── Print Test ───────────────────────────────────────────────────────────────
async function testPrint() {
  const taxR = Number(invoice.value.taxRate || 15)
  const sub  = 50
  const tax  = +(sub * taxR / 100).toFixed(2)
  const html = withFormLS(() => {
    const sampleSale = {
      invoice_number: `${invoice.value.invoicePrefix || 'INV-'}DEMO`,
      sale_date:      new Date().toISOString(),
      customer_name:  'عميل تجريبي',
      payment_method: 'نقداً',
      discount_type:  'fixed',
      discount_value: 5,
      tax_rate:       taxR,
      tax_amount:     tax,
      total_amount:   sub,
      net_total_amount: +(sub - 5 + tax).toFixed(2),
      paid_amount:    +(sub - 5 + tax).toFixed(2),
      change_amount:  0,
      items: [
        { product_name: 'منتج تجريبي أ', quantity: 1, sale_price: 25, net_price: 25, net_total: 25 },
        { product_name: 'منتج تجريبي ب', quantity: 2, sale_price: 12.5, net_price: 12.5, net_total: 25 },
      ],
    }
    return getBuilderByTemplate(printTemplate.value || 'thermal-compact')(sampleSale)
  })
  await printDocument(html)
}

// ─── Live Print Preview ───────────────────────────────────────────────────────
const previewHtml = computed(() => {
  const type = previewType.value
  const taxR = Number(invoice.value.taxRate || 15)
  const sub  = 50
  const tax  = +(sub * taxR / 100).toFixed(2)

  return withFormLS(() => {
    let html
    if (type === 'return') {
      html = buildReturnHtml({
        return_number:  'RET-001',
        return_date:    '2025-08-20T15:30:00',
        customer_name:  'أحمد محمد',
        return_reason:  'منتج تالف',
        grand_total:    80,
        total_amount:   80,
        items: [
          { product_name: 'منتج مرتجع أ', quantity: 1, unit_price: 50, net_total: 50 },
          { product_name: 'منتج مرتجع ب', quantity: 2, unit_price: 15, net_total: 30 },
        ],
      })
    } else if (type === 'purchase') {
      html = buildPurchaseHtml({
        invoice_number: 'PUR-001',
        invoice_date:   '2025-08-20T15:30:00',
        supplier_name:  'المورد الرئيسي',
        status:         'partial',
        total_amount:   350,
        paid_amount:    200,
        items: [
          { product_name: 'مادة خام أ', quantity: 10, price: 20, unit_price: 20 },
          { product_name: 'مادة خام ب', quantity: 5,  price: 30, unit_price: 30 },
        ],
      })
    } else {
      html = getBuilderByTemplate(printTemplate.value || 'thermal-compact')({
        invoice_number:    `${invoice.value.invoicePrefix || 'INV-'}12345`,
        sale_date:         '2025-08-20T15:30:00',
        customer_name:     'أحمد محمد',
        payment_method:    'نقداً',
        discount_type:     'fixed',
        discount_value:    5,
        tax_rate:          taxR,
        tax_amount:        tax,
        total_amount:      sub,
        net_total_amount:  +(sub - 5 + tax).toFixed(2),
        paid_amount:       +(sub - 5 + tax).toFixed(2),
        change_amount:     0,
        items: [
          { product_name: 'منتج أ', quantity: 1, sale_price: 25,   net_price: 25,   net_total: 25 },
          { product_name: 'منتج ب', quantity: 2, sale_price: 12.5, net_price: 12.5, net_total: 25 },
        ],
      })
    }
    return html.replace(/<script[\s\S]*?<\/script>/gi, '')
  })
})

// ─── Lifecycle ────────────────────────────────────────────────────────────────
onBeforeRouteLeave((to, from, next) => {
  if (isDirty.value) {
    const ok = confirm('لديك تغييرات غير محفوظة، هل تريد المغادرة؟');
    if (!ok) return next(false);
  }
  next();
});

onMounted(async () => {
  if (route.query.tab === 'users-settings') {
    await router.replace({ name: 'UserManagement' })
    return
  }
  if (hasSettingsAccess.value) {
    await Promise.all([fetchSettings(), loadAccountingAccounts(), fetchRoleOptions(), fetchBranchOptions()])
  }
});
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.filter-input-v3 {
  @apply w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1; }

.setting-toggle-row { @apply flex items-center justify-between cursor-pointer transition-all; }

/* Custom iOS-style toggle */
.ios-toggle {
  @apply relative w-9 h-5 bg-slate-200 rounded-full appearance-none cursor-pointer transition-colors duration-300;
  @apply checked:bg-blue-600;
}
.ios-toggle::before {
  content: "";
  @apply absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform duration-300 shadow-sm;
}
.ios-toggle:checked::before {
  @apply translate-x-4;
}
[dir="rtl"] .ios-toggle:checked::before {
  @apply -translate-x-4;
}

/* ✅ RESTORED: pagination buttons used by the Users pagination footer */
.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-up-enter-active, .slide-up-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(20px); opacity: 0; }
</style>
