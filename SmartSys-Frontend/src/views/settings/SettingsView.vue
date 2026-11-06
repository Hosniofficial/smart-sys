<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
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

    <Transition name="slide-up">
      <div v-if="isDirty" class="fixed bottom-8 inset-x-0 flex justify-center z-[100] px-4">
        <div class="bg-slate-900 border border-white/10 shadow-2xl rounded-2xl sm:rounded-full px-4 sm:px-6 py-3 flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-8 max-w-2xl w-full backdrop-blur-md bg-opacity-95">
          <div class="flex items-center gap-3 border-b sm:border-b-0 sm:border-l border-white/10 pb-2 sm:pb-0 sm:pl-6">
            <div class="w-2 h-2 bg-amber-400 rounded-full animate-pulse shadow-[0_0_10px_rgba(251,191,36,0.5)]"></div>
            <div class="text-right">
              <span class="block text-xs font-bold text-white">تعديلات غير محفوظة</span>
              <span class="block mt-1 text-[10px] text-slate-400">{{ dirtySectionSummary }}</span>
            </div>
          </div>
          <div class="flex justify-between sm:justify-normal gap-2 sm:gap-3 sm:mr-auto">
            <button
              @click="discardActiveTab"
              :disabled="!activeSectionDirty"
              class="px-4 py-1.5 rounded-md text-[10px] font-bold text-slate-400 hover:text-white transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            >تجاهل {{ activeSectionDirty ? activeSectionLabel : 'القسم الحالي' }}</button>
            <LoadingButton
              :text="activeSectionDirty ? `حفظ ${activeSectionLabel}` : 'اذهب إلى القسم المعدّل'"
              :isLoading="isSaving"
              loadingText="جاري الحفظ..."
              icon="fas fa-save"
              variant="primary"
              loaderType="dots"
              @click="activeSectionDirty ? saveActiveTab() : goToFirstDirtySection()"
              class="px-6 py-1.5 bg-blue-600 text-white rounded-md text-[10px] font-bold shadow-lg shadow-blue-900/20 hover:bg-blue-500"
            />
          </div>
        </div>
      </div>
    </Transition>

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
      
      <PageHeader
        :breadcrumb="breadcrumb"
        title="تكوين المنشأة"
        description="التحكم المركزي في هوية النشاط، الضرائب، الصلاحيات وقواعد العمل."
        :branches="[]"
        :selectedBranch="null"
      />

      <div v-if="!hasSettingsAccess" class="py-32 text-center animate-fadeIn bg-white border border-slate-200 rounded-xl shadow-sm">
        <div class="w-16 h-16 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-6">
          <i class="fas fa-lock text-2xl"></i>
        </div>
        <h2 class="text-lg font-bold text-slate-900 uppercase tracking-widest">وصول مقيد</h2>
        <p class="text-xs text-slate-400 mt-2 font-medium">تحتاج إلى صلاحيات المدير العام للوصول إلى وحدة الإعدادات.</p>
        <router-link to="/dashboard" class="mt-8 inline-flex px-6 py-2 bg-slate-900 text-white rounded-md text-[10px] font-bold uppercase tracking-widest shadow-sm">العودة للرئيسية</router-link>
      </div>

      <div v-else class="flex flex-col lg:flex-row gap-10 items-start">
        
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
              <div v-if="isSectionDirty(sectionKeyFromTab(tab.id))" class="w-1.5 h-1.5 bg-amber-400 rounded-full animate-pulse shadow-sm"></div>
            </button>
          </div>
        </nav>

        <main class="flex-1 w-full space-y-10">

          <section v-if="activeTab === 'general-settings'" class="animate-fadeIn space-y-8">
            <div class="rounded-xl border border-emerald-100 bg-gradient-to-l from-emerald-50 via-white to-white px-6 py-5 shadow-sm">
              <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                  <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white shadow-lg shadow-emerald-600/20"><i class="fas fa-building text-lg"></i></div>
                  <div>
                    <div class="mb-1 flex items-center gap-2"><h2 class="text-base font-bold text-slate-900">هوية النشاط التجاري</h2><span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-bold text-emerald-700">GENERAL</span></div>
                    <p class="text-[11px] leading-5 text-slate-500">إدارة البيانات الرسمية والهوية البصرية ومعلومات التواصل.</p>
                  </div>
                </div>
                <div class="flex items-center gap-2 self-start rounded-lg border border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-500 sm:self-auto"><i class="fas fa-id-card text-emerald-500"></i>بيانات المنشأة</div>
              </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
              <div class="p-8 grid grid-cols-1 md:grid-cols-12 gap-10">
                
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
                      <p class="mt-1 text-[10px] text-slate-500">مقابل العملة الأساسية {{ exchangeRates.baseCurrencyCode || general.currency }}.</p>
                    </div>
                    <LoadingButton
                      text="تحديث الأسعار"
                      loadingText="جاري التحديث..."
                      icon="fas fa-sync-alt"
                      :isLoading="currencyRatesLoading"
                      variant="secondary"
                      class="h-8 rounded-md border border-slate-200 px-3 text-[10px] text-blue-700 hover:bg-blue-50"
                      @click="refreshCurrencyRates"
                    />
                  </div>
                  <p v-if="currencyRatesError" class="mt-3 text-[10px] font-bold text-rose-600">{{ currencyRatesError }}</p>
                  <div v-else-if="exchangeRates.rates?.length" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    <div v-for="rate in exchangeRates.rates" :key="rate.quoteCurrencyCode" class="rounded-md bg-slate-50 px-3 py-2">
                      <div class="text-[9px] font-bold text-slate-500">{{ rate.quoteCurrencyCode }}</div>
                      <div class="mt-1 text-xs font-bold text-slate-900">{{ Number(rate.rate).toLocaleString('en-US', { maximumFractionDigits: 6 }) }}</div>
                    </div>
                  </div>
                  <p v-else class="mt-3 text-[10px] text-slate-500">لا توجد أسعار محفوظة حالياً؛ اضغط تحديث الأسعار لجلبها.</p>
                  <p v-if="exchangeRates.rates?.length" class="mt-3 text-[9px] text-slate-400">آخر تحديث: {{ exchangeRates.rates[0]?.fetchedAt || exchangeRates.rates[0]?.rateDate }}</p>
                </div>
              </div>
            </div>
          </section>

          <section v-if="activeTab === 'pos-settings'" class="animate-fadeIn space-y-8">
            <div class="rounded-xl border border-blue-100 bg-gradient-to-l from-blue-50 via-white to-white px-6 py-5 shadow-sm">
              <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                  <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-600/20">
                    <i class="fas fa-cash-register text-lg"></i>
                  </div>
                  <div>
                    <div class="mb-1 flex items-center gap-2">
                      <h2 class="text-base font-bold text-slate-900">إدارة نقاط البيع والورديات</h2>
                      <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[9px] font-bold text-blue-700">POS</span>
                    </div>
                    <p class="text-[11px] leading-5 text-slate-500">تحكم في الجلسات، الورديات، صلاحيات البيع، وقواعد اعتماد الفواتير.</p>
                  </div>
                </div>
                <div class="flex items-center gap-2 self-start rounded-lg border border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-500 sm:self-auto">
                  <i class="fas fa-sliders text-blue-500"></i>
                  إعدادات التشغيل
                </div>
              </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm p-8 space-y-10">

              <div class="space-y-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                  <div>
                    <h3 class="metadata-label text-slate-900"><i class="fas fa-power-off ml-2 text-blue-600"></i> تشغيل الجلسات والورديات</h3>
                    <p class="mt-1 text-[10px] leading-5 text-slate-400">حدد كيف يبدأ الكاشير ورديته وكيف تتم مطابقة النقدية في نهاية العمل.</p>
                  </div>
                  <span
                    class="inline-flex w-fit items-center gap-1.5 rounded-full px-3 py-1 text-[10px] font-bold"
                    :class="posSession.sessionsEnabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                  >
                    <span class="h-1.5 w-1.5 rounded-full" :class="posSession.sessionsEnabled ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                    {{ posSession.sessionsEnabled ? 'النظام مفعّل' : 'النظام غير مفعّل' }}
                  </span>
                </div>

                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                  <label class="setting-toggle-row min-h-[76px] rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
                    <div class="flex items-start gap-3">
                      <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-blue-600 shadow-sm"><i class="fas fa-cash-register text-xs"></i></span>
                      <span class="flex flex-col">
                        <span class="text-xs font-bold text-slate-800">إلزام الجلسة النقدية</span>
                        <span class="mt-1 text-[10px] leading-4 text-slate-500">يطلب من الكاشير فتح جلسة قبل العمليات النقدية، ويربط الحركة بالوردية الحالية.</span>
                      </span>
                    </div>
                    <input type="checkbox" v-model="posSession.sessionsEnabled" class="ios-toggle" />
                  </label>
                  <label class="setting-toggle-row min-h-[76px] rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                    <div class="flex items-start gap-3">
                      <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-amber-600 shadow-sm"><i class="fas fa-wallet text-xs"></i></span>
                      <span class="flex flex-col">
                        <span class="text-xs font-bold text-slate-800">السماح بالسالب داخل الجلسة</span>
                        <span class="mt-1 text-[10px] leading-4 text-slate-500">يسمح بخروج نقدية أكبر من الرصيد، ولا يلغي ضرورة وجود جلسة مفتوحة.</span>
                      </span>
                    </div>
                    <input type="checkbox" v-model="posSession.allowNegativeIntraday" class="ios-toggle" />
                  </label>
                </div>
              </div>

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
                    <span class="text-[11px] font-bold text-slate-700 truncate">{{ role.displayName || role.name || role.roleCode }}</span>
                  </label>
                </div>
              </div>

              <div class="grid grid-cols-1 gap-8 border-t border-slate-100 pt-6 lg:grid-cols-2">
                <div class="space-y-4">
                  <div>
                    <h3 class="metadata-label text-slate-900"><i class="fas fa-history ml-2 text-indigo-500"></i> سياسة الوردية</h3>
                    <p class="mt-1 text-[10px] leading-5 text-slate-400">حدد عدد مرات فتح الجلسة وتصنيفها خلال اليوم.</p>
                  </div>
                  <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                      <label class="text-[10px] font-bold text-slate-500">عدد الجلسات المسموح يومياً</label>
                      <select v-model="posSession.mode" class="filter-input-v2 h-10">
                        <option value="">بدون حد</option>
                        <option value="one_per_day">جلسة واحدة</option>
                        <option value="two_per_day">جلستان</option>
                      </select>
                      <p class="text-[9px] leading-4 text-slate-400">يمكن للمدير تجاوز الحد إذا فُعّل الخيار المقابل.</p>
                    </div>
                    <div class="space-y-1.5">
                      <label class="text-[10px] font-bold text-slate-500">تصنيف الجلسة تلقائياً</label>
                      <select v-model="posSession.sessionTypeMode" class="filter-input-v2 h-10">
                        <option value="">يدوي</option>
                        <option value="daily">يومية</option>
                        <option value="morning">صباحية / مسائية</option>
                      </select>
                      <p class="text-[9px] leading-4 text-slate-400">في الوضع اليدوي يختار المستخدم النوع، وفي غيره يحدده النظام.</p>
                    </div>
                  </div>
                </div>

                <div class="space-y-4">
                  <div>
                    <h3 class="metadata-label text-slate-900"><i class="fas fa-toggle-on ml-2 text-blue-500"></i> الاعتماد والاستثناءات</h3>
                    <p class="mt-1 text-[10px] leading-5 text-slate-400">تحكم في اعتماد الفواتير ومن يستطيع تجاوز سياسة الجلسات.</p>
                  </div>
                  <div class="space-y-3">
                    <label class="setting-toggle-row rounded-lg border border-slate-100 bg-slate-50 px-4 py-3">
                      <div class="flex flex-col">
                        <span class="text-xs font-bold text-slate-800">الموافقة المسبقة على الفواتير</span>
                        <span class="mt-1 text-[10px] text-slate-400">تتطلب مراجعة إدارية قبل ترحيل الفاتورة.</span>
                      </div>
                      <input type="checkbox" v-model="posSession.requireApproval" class="ios-toggle" />
                    </label>
                    <label class="setting-toggle-row rounded-lg border border-indigo-100 bg-indigo-50/50 px-4 py-3">
                      <div class="flex flex-col">
                        <span class="text-xs font-bold text-slate-800">تجاوز حد الجلسات للمدير</span>
                        <span class="mt-1 text-[10px] text-slate-400">يسمح للمدير بفتح جلسة إضافية عند الحاجة.</span>
                      </div>
                      <input type="checkbox" v-model="posSession.allowManagerOverride" class="ios-toggle" />
                    </label>
                  </div>
                </div>
              </div>

              <div v-if="posSession.sessionTypeMode === 'morning'" class="rounded-xl border border-violet-100 bg-violet-50/60 p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <h3 class="text-xs font-bold text-violet-900"><i class="fas fa-clock ml-2"></i> وقت التحويل بين الفترتين</h3>
                    <p class="mt-1 text-[10px] leading-5 text-violet-700/70">قبل هذا الوقت تُصنف الجلسة صباحية، وبعده تُصنف مسائية.</p>
                  </div>
                  <input type="time" v-model="posSession.periodCutoff" class="filter-input-v2 h-10 max-w-[150px] bg-white font-mono font-bold" />
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

          <section v-if="activeTab === 'invoice-settings'" class="animate-fadeIn space-y-8">
            <div class="rounded-xl border border-rose-100 bg-gradient-to-l from-rose-50 via-white to-white px-6 py-5 shadow-sm">
              <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                  <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-rose-600 text-white shadow-lg shadow-rose-600/20"><i class="fas fa-file-invoice-dollar text-lg"></i></div>
                  <div>
                    <div class="mb-1 flex items-center gap-2"><h2 class="text-base font-bold text-slate-900">تخصيص الفواتير والضرائب</h2><span class="rounded-full bg-rose-100 px-2 py-0.5 text-[9px] font-bold text-rose-700">INVOICES</span></div>
                    <p class="text-[11px] leading-5 text-slate-500">ضبط الضريبة، ترقيم الفواتير، ومحتوى التذييل المطبوع.</p>
                  </div>
                </div>
                <div class="flex items-center gap-2 self-start rounded-lg border border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-500 sm:self-auto"><i class="fas fa-percent text-rose-500"></i>ضريبة وفوترة</div>
              </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm p-8">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                <div class="space-y-8">
                  <h3 class="metadata-label text-rose-600"><i class="fas fa-percent ml-2"></i> تهيئة الضرائب (VAT)</h3>
                  <div class="space-y-4 p-6 bg-slate-50 border border-slate-100 rounded-xl">
                    <div class="space-y-1.5"><label class="metadata-label">رقم السجل الضريبي</label><input v-model="invoice.taxNumber" class="filter-input-v2 h-10 font-mono tracking-widest" /></div>
                    <div class="grid grid-cols-2 gap-4">
                      <div class="space-y-1.5"><label class="metadata-label">المُسمى</label><input v-model="invoice.taxName" class="filter-input-v2 h-10" /></div>
                      <div class="space-y-1.5"><label class="metadata-label">النسبة (%)</label><input v-model.number="invoice.taxRate" type="number" min="0" max="100" step="0.01" class="filter-input-v2 h-10 font-bold" /></div>
                    </div>
                    <div class="pt-4 flex flex-col gap-3 border-t border-slate-200/50">
                      <label class="setting-toggle-row bg-white rounded-md border border-slate-100 px-3 h-10">
                        <span class="text-[11px] font-bold text-slate-700">تفعيل احتساب الضريبة</span>
                        <input type="checkbox" v-model="invoice.taxEnabled" class="ios-toggle" />
                      </label>
                      <div class="space-y-1">
                        <label class="setting-toggle-row bg-white rounded-md border border-slate-100 px-3 h-10">
                          <span class="text-[11px] font-bold text-slate-700">أسعار البيع تشمل الضريبة</span>
                          <input type="checkbox" v-model="invoice.showTaxInPrice" class="ios-toggle" />
                        </label>
                        <p class="px-1 text-[10px] leading-relaxed text-slate-500">
                          عند التفعيل تُستخرج الضريبة من السعر ولا تُضاف فوقه. أوقفه إذا كانت أسعارك قبل الضريبة.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

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

          <section v-if="activeTab === 'accounting-settings'" class="animate-fadeIn space-y-8">
            <div class="rounded-xl border border-violet-100 bg-gradient-to-l from-violet-50 via-white to-white px-6 py-5 shadow-sm">
             <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
               <div class="flex items-center gap-4">
                 <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-violet-600 text-white shadow-lg shadow-violet-600/20"><i class="fas fa-book-open text-lg"></i></div>
                 <div>
                   <div class="mb-1 flex items-center gap-2"><h2 class="text-base font-bold text-slate-900">توجيه الحسابات المحاسبية</h2><span class="rounded-full bg-violet-100 px-2 py-0.5 text-[9px] font-bold text-violet-700">ACCOUNTING</span></div>
                   <p class="text-[11px] leading-5 text-slate-500">ربط الحسابات الافتراضية بحركات المبيعات والمشتريات والمخزون.</p>
                 </div>
               </div>
               <div class="flex items-center gap-2 self-start rounded-lg border border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-500 sm:self-auto"><i class="fas fa-chart-line text-violet-500"></i>دليل الحسابات</div>
             </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm p-8 space-y-10">
              <!-- WAC Banner -->
              <div class="flex items-start gap-5 p-6 bg-slate-900 rounded-xl text-white shadow-xl">
                 <div class="w-12 h-12 bg-white/10 rounded-lg flex items-center justify-center text-blue-400 shrink-0"><i class="fas fa-chart-line text-lg"></i></div>
                 <div class="space-y-1">
                   <h4 class="text-sm font-bold uppercase tracking-widest text-blue-400">سياسة تقييم المخزون: متوسط التكلفة المرجّح</h4>
                   <p class="text-[11px] text-slate-400 font-medium leading-relaxed">يحسب النظام تكلفة المخزون والبضاعة المباعة بمتوسط تكلفة المشتريات، ثم يستخدم الحسابات المحددة أدناه في قيود دفتر الأستاذ.</p>
                 </div>
              </div>

              <!-- ✅ RESTORED: تنبيه الحسابات الأساسية غير المكتملة -->
              <div v-if="accountingMissingKeys.length" class="rounded-xl bg-amber-50 border border-amber-200 p-6 text-amber-900">
                <div class="font-bold text-xs uppercase tracking-widest mb-3 flex items-center gap-2">
                  <i class="fas fa-exclamation-triangle text-amber-500"></i>
                  لم تُحدَّد بعض الحسابات يدويًا
                </div>
                <ul class="list-disc pr-5 text-[11px] font-bold space-y-1 text-amber-800">
                  <li v-for="key in accountingMissingKeys" :key="key">لم يتم ضبط: {{ accountingKeyLabels[key] }}</li>
                </ul>
                <p class="text-[10px] text-amber-700 mt-3 font-bold italic">
                  سيستخدم النظام الحساب الافتراضي المقابل إن كان متاحًا؛ وإلا فقد يتعذر ترحيل قيد البيع أو تسوية المخزون. راجع هذه الحسابات قبل اعتماد العمليات.
                </p>
              </div>

              <div class="space-y-6">
                <h3 class="metadata-label text-slate-900"><i class="fas fa-map-marked-alt ml-2 text-indigo-500"></i> ربط حسابات دفتر الأستاذ (GL Mapping)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                  <div class="space-y-1.5">
                    <label class="metadata-label">حساب تكلفة البضاعة (COGS)</label>
                    <select v-model="accounting.cogsAccountId" class="filter-input-v2 h-10 font-bold">
                      <option value="">-- اختر حساب --</option>
                      <optgroup v-if="accounts.tenant.length" label="حسابات المنشأة">
                        <option v-for="acc in accounts.tenant" :key="'cogs-t-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                      <optgroup v-if="accounts.global.length" label="حسابات عامة (افتراضية)">
                        <option v-for="acc in accounts.global" :key="'cogs-g-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                    </select>
                    <p class="text-[9px] font-bold text-slate-400 italic">مدين تكلفة البضاعة المباعة عند البيع؛ ويستخدم الافتراضي 5103 إن لم تحدد حسابًا.</p>
                  </div>
                  <div class="space-y-1.5">
                    <label class="metadata-label">حساب المخزون (Inventory Asset)</label>
                    <select v-model="accounting.inventoryAccountId" class="filter-input-v2 h-10 font-bold">
                      <option value="">-- اختر حساب --</option>
                      <optgroup v-if="accounts.tenant.length" label="حسابات المنشأة">
                        <option v-for="acc in accounts.tenant" :key="'inv-t-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                      <optgroup v-if="accounts.global.length" label="حسابات عامة (افتراضية)">
                        <option v-for="acc in accounts.global" :key="'inv-g-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                    </select>
                    <p class="text-[9px] font-bold text-slate-400 italic">دائن المخزون عند البيع ومدينه عند الشراء؛ يُراعى حساب الفرع إن كان معيّنًا.</p>
                  </div>
                  <div class="space-y-1.5 md:col-span-2">
                    <label class="metadata-label">حساب تسويات المخزون (Adjustments)</label>
                    <select v-model="accounting.inventoryAdjustmentAccountId" class="filter-input-v2 h-10 font-bold">
                      <option value="">-- اختر حساب --</option>
                      <optgroup v-if="accounts.tenant.length" label="حسابات المنشأة">
                        <option v-for="acc in accounts.tenant" :key="'adj-t-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                      <optgroup v-if="accounts.global.length" label="حسابات عامة (افتراضية)">
                        <option v-for="acc in accounts.global" :key="'adj-g-'+acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                      </optgroup>
                    </select>
                    <p class="text-[9px] font-bold text-slate-400 italic">الحساب المقابل لزيادة أو نقص الجرد؛ يستخدم الافتراضي 5104 عند توفره.</p>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section v-if="activeTab === 'printers-settings'" class="animate-fadeIn space-y-8">
            <div class="rounded-xl border border-amber-100 bg-gradient-to-l from-amber-50 via-white to-white px-6 py-5 shadow-sm">
              <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                  <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white shadow-lg shadow-amber-500/20"><i class="fas fa-print text-lg"></i></div>
                  <div>
                    <div class="mb-1 flex items-center gap-2"><h2 class="text-base font-bold text-slate-900">إعدادات الطباعة والقوالب</h2><span class="rounded-full bg-amber-100 px-2 py-0.5 text-[9px] font-bold text-amber-700">PRINTING</span></div>
                    <p class="text-[11px] leading-5 text-slate-500">تخصيص القوالب، نطاق الطباعة، وإعدادات إخراج المستندات.</p>
                  </div>
                </div>
                <div class="flex items-center gap-2 self-start rounded-lg border border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-500 sm:self-auto"><i class="fas fa-file-lines text-amber-500"></i>قوالب المستندات</div>
              </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-10">
              <div class="xl:col-span-7 space-y-8">
                <div class="bg-white border border-slate-200 rounded-xl p-8 space-y-8 shadow-sm">
                  <div class="space-y-2">
                    <label class="metadata-label">نطاق إعدادات الطباعة</label>
                    <select
                      :value="printSettingsBranchId"
                      class="filter-input-v2 h-10 w-full font-bold"
                      :disabled="branchesLoading || isLoading"
                      @change="handlePrintBranchChange($event.target.value)"
                    >
                      <option value="">الإعدادات العامة لجميع الفروع</option>
                      <option v-for="branch in branchOptions" :key="branch.id" :value="String(branch.id)">
                        {{ branch.name }}
                      </option>
                    </select>
                    <p class="text-[10px] text-slate-400">
                      {{ printSettingsBranchId ? 'تخصيصات الطباعة هنا تطبق على هذا الفرع فقط، ويمكنك استعادة الإعدادات العامة في أي وقت.' : 'تُطبَّق هذه الإعدادات على جميع الفروع افتراضيًا. ويمكنك اختيار فرع وحفظ إعدادات طباعة خاصة به.' }}
                    </p>
                    <button
                      v-if="printSettingsBranchId && branchPrintOverrides.length"
                      type="button"
                      class="text-[10px] font-bold text-rose-600 hover:text-rose-700"
                      :disabled="isSaving"
                      @click="resetBranchPrinterSettings"
                    >
                      استعادة إعدادات المنشأة لهذا الفرع
                    </button>
                  </div>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-1.5"><label class="metadata-label">قالب الفاتورة</label><select v-model="printTemplate" class="filter-input-v2 h-10 font-bold"><option value="thermal-compact">حراري مختصر (80mm)</option><option value="thermal-detailed">حراري تفصيلي (80mm)</option><option value="a4-simple">A4 بسيط</option><option value="a4-professional">A4 احترافي</option></select></div>
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

              <div class="xl:col-span-5">
                <div class="bg-slate-900 rounded-xl p-8 shadow-2xl relative overflow-hidden border border-white/5">
                   <div class="flex items-center justify-between mb-6">
                      <span class="text-[9px] font-bold text-white/30 uppercase tracking-[0.3em]">معاينة حية للمخرجات</span>
                      <div class="flex gap-1.5 p-1 bg-white/5 rounded-lg border border-white/5">
                        <button v-for="pt in previewTypes" :key="pt.id" @click="previewType = pt.id" :class="[previewType === pt.id ? 'bg-white text-slate-900 shadow-sm' : 'text-white/40 hover:bg-white/5']" class="px-3 py-1 rounded-md text-[9px] font-bold transition-all">{{ pt.label }}</button>
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
import { settingsService } from '@/services/settings'
import { printDocument } from '@/utils';
import { getBuilderByTemplate, buildReturnHtml, buildPurchaseHtml } from '@/utils/printTemplates';
import { clearPrintConfigCache } from '@/utils/printTemplates/printConfig';
import { clearBranchPrintSettingsCache } from '@/utils/printTemplates/branchPrintSettings';
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

// Core Setup
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
const exchangeRates = ref({ baseCurrencyCode: '', supportedCurrencies: [], rates: [] })
const currencyRatesLoading = ref(false)
const currencyRatesError = ref('')

// Tabs
const tabs = [
  { id: 'general-settings',  label: 'الإعدادات العامة',     icon: 'fas fa-cog' },
  { id: 'invoice-settings',  label: 'الفواتير والضرائب',     icon: 'fas fa-file-invoice' },
  { id: 'pos-settings',      label: 'نقطة البيع (POS)',      icon: 'fas fa-cash-register' },
  { id: 'accounting-settings', label: 'الحسابات المحاسبية', icon: 'fas fa-balance-scale' },
  { id: 'printers-settings', label: 'إعدادات الطابعات',      icon: 'fas fa-print' },
]
const sectionLabels = {
  general: 'الإعدادات العامة',
  invoice: 'الفواتير والضرائب',
  pos: 'نقطة البيع',
  printers: 'إعدادات الطباعة',
  accounting: 'الحسابات المحاسبية',
}
const previewTypes = [
  { id: 'sale', label: 'بيع' },
  { id: 'return', label: 'مرتجع' },
  { id: 'purchase', label: 'شراء' },
]

const filteredTabs = computed(() => {
  const q = (searchTerm.value || '').trim()
  if (!q) return tabs
  return tabs.filter(t => t.label.includes(q) || t.id.includes(q))
})

// Settings State
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
  printHeader: true, printFooter: true, template: localStorage.getItem('pos_print_template') || 'thermal-compact',
})

const printTemplate = computed({
  get: () => printers.value.template,
  set: (value) => { printers.value.template = value },
})
const printSettingsBranchId = ref('')
const loadedPrintSettingsBranchId = ref('')
const branchPrintOverrides = ref([])
const tenantPrinterSettings = ref(null)
const previewType   = ref('sale')
const selectedPrintBranchName = computed(() => {
  const selected = branchOptions.value.find(branch => String(branch.id) === String(printSettingsBranchId.value))
  return selected?.name || ''
})

const printTexts = ref({
  headerText: localStorage.getItem('pos_print_header_text') || '',
  footerText: localStorage.getItem('pos_print_footer_text') || 'شكراً لتعاملكم معنا',
  termsText:  localStorage.getItem('pos_print_terms_text')  || '',
})

// Accounting
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
  cogsAccountId: 'حساب تكلفة البضاعة المباعة (COGS)',
  inventoryAccountId: 'حساب المخزون',
  inventoryAdjustmentAccountId: 'حساب تسويات/فروق المخزون',
}

const rolesLoading = ref(false);
const branchOptions = computed(() => {
  const user = authStore.user?.user || authStore.user || {}
  const role = String(user.role || '').toLowerCase()
  const canManageAllBranches = authStore.isSuperAdmin
    || role === 'super_admin'
    || role === 'admin'
    || Number(user.isOwner) === 1
  const branchId = Number(user.branchId || 0)
  return branchStore.branches
    .filter(branch => canManageAllBranches || (branchId > 0 && Number(branch.id) === branchId))
    .map(branch => ({ id: branch.id, name: branch.name }))
})
const branchesLoading = ref(false)

// Dirty Tracking
const printerSettingsForTracking = computed({
  get: () => ({
    ...printers.value,
    texts: { ...printTexts.value },
  }),
  set: (settings) => {
    const { texts, ...printerOptions } = settings
    printers.value = printerOptions
    printTexts.value = texts
  },
})
const { isDirty, isSectionDirty, setSnapshotsFromCurrent, setSectionSnapshot, resetSectionToSnapshot } = useDirtyTracking({
  general, invoice, pos: posSession, printers: printerSettingsForTracking, accounting,
})
const dirtySections = computed(() => Object.keys(sectionLabels).filter(isSectionDirty))
const activeSectionKey = computed(() => sectionKeyFromTab(activeTab.value))
const activeSectionDirty = computed(() => activeSectionKey.value
  ? isSectionDirty(activeSectionKey.value)
  : false)
const activeSectionLabel = computed(() => sectionLabels[activeSectionKey.value] || 'الإعدادات')
const dirtySectionSummary = computed(() => dirtySections.value
  .map(key => sectionLabels[key])
  .join('، '))

// Role Filters
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
        .filter(r => excludedPosRoleNames.map(x => x.toLowerCase()).includes(String(r.roleCode || r.name || '').trim().toLowerCase()))
        .map(r => Number(r.id))
    )
    const cleaned = (vals || []).filter(v => !excludedIds.has(Number(v)))
    if (cleaned.length !== (vals || []).length) posSession.value.enforceForRoles = cleaned
  } catch (_) { /* ignore */ }
}, { deep: true })

// Tab Navigation
function sectionKeyFromTab(tabId) {
  return {
    'general-settings':    'general',
    'invoice-settings':    'invoice',
    'pos-settings':        'pos',
    'printers-settings':   'printers',
    'accounting-settings': 'accounting',
  }[tabId] || null
}

async function setActiveTab(tabId) {
  const currentKey = sectionKeyFromTab(activeTab.value)
  if (currentKey && isSectionDirty(currentKey)) {
    const ok = await AlertService.confirm(
      'هناك تغييرات غير محفوظة في هذا القسم. هل تريد المتابعة بدون حفظ؟',
      'تغييرات غير محفوظة',
      { confirmText: 'المتابعة دون حفظ', cancelText: 'البقاء في القسم' }
    )
    if (!ok) return
    resetSectionToSnapshot(currentKey)
  }
  activeTab.value = tabId
}

// Sync URL query param with activeTab
watch(() => route.query.tab, (newTab) => {
  if (newTab === 'users-settings') {
    router.replace({ name: 'UserManagement' })
    return
  }
  if (newTab && newTab !== activeTab.value) void setActiveTab(String(newTab))
})

watch(activeTab, (tab) => {
  if (tab === 'pos-settings') {
    if (!roleOptions.value.length) fetchRoleOptions();
    if (!branchOptions.value.length) fetchBranchOptions();
  }
});

// Save Bar Handlers
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

async function discardActiveTab() {
  const key = sectionKeyFromTab(activeTab.value)
  if (!key || !isSectionDirty(key)) return
  const ok = await AlertService.confirm(
    'سيتم تجاهل التغييرات غير المحفوظة في هذا القسم. هل أنت متأكد؟',
    'تجاهل التغييرات',
    { confirmText: 'تجاهل التغييرات', cancelText: 'إلغاء' }
  )
  if (!ok) return
  resetSectionToSnapshot(key)
}

function goToFirstDirtySection() {
  const firstDirtyKey = dirtySections.value[0]
  if (!firstDirtyKey) return
  const tab = Object.entries({
    'general-settings': 'general',
    'invoice-settings': 'invoice',
    'pos-settings': 'pos',
    'printers-settings': 'printers',
    'accounting-settings': 'accounting',
  }).find(([, sectionKey]) => sectionKey === firstDirtyKey)?.[0]
  if (tab) void setActiveTab(tab)
}

// Toast Helpers
const showError = (message) => {
  errorMessage.value = message
  showToast(message, 'error', 5000)
  setTimeout(() => { errorMessage.value = '' }, 5000)
}

const showSuccess = (message) => {
  showToast(message, 'success', 3000)
}

// Logo Handling
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

// Reset Form
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

// Computed: Access Control
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
    (Number(userData?.tenantId) > 0 &&
      (role === 'admin' || role === 'manager'))
})

// Data Fetching: Roles / Branches
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

function applyBranchPrintPayload(payload) {
  const settings = payload?.settings || {}
  const hasBranchTemplate = Array.isArray(payload?.overrides)
    && payload.overrides.includes('print.template')
  const template = hasBranchTemplate || payload?.tenantTemplateConfigured
    ? settings['print.template']
    : tenantPrinterSettings.value?.printers?.template
  printers.value = {
    printHeader: String(settings['printer.print_header'] ?? '1') !== '0',
    printFooter: String(settings['printer.print_footer'] ?? '1') !== '0',
    template: template || 'thermal-compact',
  }
  printTexts.value = {
    headerText: settings['print.header_text'] ?? tenantPrinterSettings.value?.texts?.headerText ?? '',
    footerText: settings['print.footer_text'] ?? tenantPrinterSettings.value?.texts?.footerText ?? 'شكراً لتعاملكم معنا',
    termsText: settings['print.terms_text'] ?? tenantPrinterSettings.value?.texts?.termsText ?? '',
  }
  branchPrintOverrides.value = Array.isArray(payload?.overrides) ? payload.overrides : []
}

async function handlePrintBranchChange(nextBranchId) {
  const previousBranchId = loadedPrintSettingsBranchId.value
  if (isSectionDirty('printers')) {
    const confirmed = await AlertService.confirm(
      'هناك تغييرات غير محفوظة في إعدادات الطباعة. هل تريد المتابعة دون حفظ؟',
      'تغييرات غير محفوظة',
      { confirmText: 'المتابعة دون حفظ', cancelText: 'البقاء في الإعدادات الحالية' }
    )
    if (!confirmed) {
      printSettingsBranchId.value = previousBranchId
      return
    }
  }

  isLoading.value = true
  try {
    if (!nextBranchId) {
      printers.value = { ...tenantPrinterSettings.value.printers }
      printTexts.value = { ...tenantPrinterSettings.value.texts }
      branchPrintOverrides.value = []
    } else {
      const response = await settingsService.getBranchPrintSettings(Number(nextBranchId))
      const payload = response?.data?.data
      if (response?.data?.status !== 'success' || !payload?.settings) {
        throw new Error(response?.data?.message || 'تعذر تحميل إعدادات الطباعة للفرع.')
      }
      applyBranchPrintPayload(payload)
    }
    printSettingsBranchId.value = nextBranchId
    loadedPrintSettingsBranchId.value = nextBranchId
    setSectionSnapshot('printers')
  } catch (error) {
    printSettingsBranchId.value = previousBranchId
    showError(error.response?.data?.message || error.message || 'تعذر تحميل إعدادات الطباعة للفرع.')
  } finally {
    isLoading.value = false
  }
}

async function resetBranchPrinterSettings() {
  if (!printSettingsBranchId.value) return
  const confirmed = await AlertService.confirm(
    'سيتم حذف تخصيصات الطباعة لهذا الفرع واستخدام إعدادات المنشأة الافتراضية. هل تريد المتابعة؟',
    'استعادة الإعدادات الافتراضية',
    { confirmText: 'استعادة الافتراضي', cancelText: 'إلغاء' }
  )
  if (!confirmed) return

  isSaving.value = true
  try {
    const branchId = Number(printSettingsBranchId.value)
    const response = await settingsService.resetBranchPrintSettings(branchId)
    if (response?.data?.status !== 'success') {
      throw new Error(response?.data?.message || 'تعذر استعادة إعدادات الطباعة.')
    }
    clearBranchPrintSettingsCache(branchId)
    if (!tenantPrinterSettings.value) {
      throw new Error('تعذر تحميل إعدادات الطباعة الافتراضية للمنشأة.')
    }
    printers.value = { ...tenantPrinterSettings.value.printers }
    printTexts.value = { ...tenantPrinterSettings.value.texts }
    branchPrintOverrides.value = []
    setSectionSnapshot('printers')
    showSuccess('تمت استعادة إعدادات المنشأة لهذا الفرع')
  } catch (error) {
    showError(error.response?.data?.message || error.message || 'تعذر استعادة إعدادات الطباعة.')
  } finally {
    isSaving.value = false
  }
}

// Settings Fetch
async function fetchSettings() {
  if (!hasSettingsAccess.value) return
  isLoading.value = true
  errorMessage.value = ''
  try {
    const response = await settingsStore.fetchSettings();
    if (response.status === 'success' && response.data?.settings) {
      const s = response.data;

      general.value = {
        businessName: s['company.name'] || s['companyName'] || '',
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
        taxRate:           s['tax.tax_rate'] == null || s['tax.tax_rate'] === ''
          ? 15
          : parseFloat(s['tax.tax_rate']),
        taxName:           s['tax.tax_name'] || 'ضريبة القيمة المضافة',
        taxEnabled:        isSettingEnabled(s['tax.tax_enabled'] ?? s['company.tax_enabled']),
        footerText:        s['invoice.footer_text'] || 'شكراً لتعاملكم معنا',
        invoicePrefix:     s['invoice.prefix'] || 'INV-',
        nextInvoiceNumber: Number(s['invoice.next_number']) || 1001,
        showTaxInPrice:    s['invoice.show_tax_in_price'] == null
          ? true
          : isSettingEnabled(s['invoice.show_tax_in_price']),
      }

      printers.value = {
        printHeader: s['printer.print_header'] !== '0',
        printFooter: s['printer.print_footer'] !== '0',
        template: s['print.template'] || localStorage.getItem('pos_print_template') || 'thermal-compact',
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
        cogsAccountId:                   s['cogsAccountId'] || null,
        inventoryAccountId:              s['inventoryAccountId'] || null,
        inventoryAdjustmentAccountId:    s['inventoryAdjustmentAccountId'] || null,
      }

      printTexts.value = {
        headerText: s['print.header_text'] ?? printTexts.value.headerText,
        footerText: s['print.footer_text'] ?? printTexts.value.footerText,
        termsText:  s['print.terms_text']  ?? printTexts.value.termsText,
      }
      tenantPrinterSettings.value = {
        printers: { ...printers.value },
        texts: { ...printTexts.value },
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
    } else {
      showError(response?.message || 'فشل تحميل الإعدادات. يرجى المحاولة مرة أخرى.')
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

// Save Methods
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
      'print.template':        printTemplate.value,
      'printer.print_header':   printers.value.printHeader ? '1' : '0',
      'printer.print_footer': printers.value.printFooter ? '1' : '0',
      'print.header_text':    printTexts.value.headerText,
      'print.footer_text':    printTexts.value.footerText,
      'print.terms_text':     printTexts.value.termsText,
    }
    const response = printSettingsBranchId.value
      ? await settingsService.updateBranchPrintSettings(Number(printSettingsBranchId.value), settings)
      : await settingsStore.updateSettings(settings)
    if (response?.status === 'success' || response?.data?.status === 'success') {
      clearPrintConfigCache()
      if (printSettingsBranchId.value) {
        const branchId = Number(printSettingsBranchId.value)
        clearBranchPrintSettingsCache(branchId)
        const payload = response?.data?.data
        if (!payload?.settings) {
          throw new Error(response?.data?.message || 'تم الحفظ لكن تعذر تحديث إعدادات الفرع.')
        }
        applyBranchPrintPayload(payload)
        setSectionSnapshot('printers')
        showSuccess('تم حفظ إعدادات الطباعة للفرع بنجاح')
      } else {
        localStorage.setItem('pos_print_template',         printTemplate.value)
        localStorage.setItem('pos_print_header_enabled',   printers.value.printHeader ? '1' : '0')
        localStorage.setItem('pos_print_footer_enabled',   printers.value.printFooter ? '1' : '0')
        localStorage.setItem('pos_print_header_text',      printTexts.value.headerText || '')
        localStorage.setItem('pos_print_footer_text',      printTexts.value.footerText || '')
        localStorage.setItem('pos_print_terms_text',       printTexts.value.termsText  || '')
        clearBranchPrintSettingsCache()
        showSuccess('تم حفظ إعدادات الطباعة الافتراضية للمنشأة')
        const { refreshSettings } = useCompanyCurrency()
        await refreshSettings()
        await fetchSettings()
        setSnapshotsFromCurrent()
      }
    } else {
      showError(response?.message || response?.data?.message || 'حدث خطأ أثناء حفظ إعدادات الطابعة.')
  }
  } catch (error) {
    console.error('Error saving printer settings:', error)
    showError(error.response?.data?.message || 'حدث خطأ أثناء حفظ إعدادات الطابعات.')
  } finally {
    isSaving.value = false
  }
}

async function saveAccountingSettings() {
  if (accountingMissingKeys.value.length) {
    const confirmed = await AlertService.confirm(
      'لم تُحدَّد بعض الحسابات يدويًا. قد يستخدم النظام الحسابات الافتراضية إن كانت متاحة، وإلا فقد يتعذر ترحيل بعض القيود. هل تريد حفظ الإعدادات الحالية؟',
      'حسابات دفتر الأستاذ غير مكتملة',
      { confirmText: 'حفظ على أي حال', cancelText: 'مراجعة الحسابات' }
    )
    if (!confirmed) return
  }

  isSaving.value = true
  try {
    const settings = {
      cogsAccountId:                    accounting.value.cogsAccountId,
      inventoryAccountId:               accounting.value.inventoryAccountId,
      inventoryAdjustmentAccountId:    accounting.value.inventoryAdjustmentAccountId,
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

// Accounting Accounts Loader
async function loadAccountingAccounts() {
  try {
    await accountStore.fetchGroupedAccounts();
    // Accounting accounts are now cached in accountStore
  } catch (error) {
    console.error('Error loading accounting accounts:', error)
    showError('فشل تحميل الحسابات المحاسبية')
  }
}

// Temporarily sync form values → localStorage → call fn → restore
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
  const logo = getImageUrl(general.value.logo || prev['posCompanyLogo']) || ''
  localStorage.setItem('pos_print_header_enabled', printers.value.printHeader ? '1' : '0')
  localStorage.setItem('pos_print_footer_enabled', printers.value.printFooter ? '1' : '0')
  localStorage.setItem('pos_print_header_text',    printTexts.value.headerText || '')
  localStorage.setItem('pos_print_footer_text',    printTexts.value.footerText || '')
  localStorage.setItem('pos_print_terms_text',     printTexts.value.termsText  || '')
  localStorage.setItem('pos_company_name',         general.value.businessName  || prev['posCompanyName'] || 'اسم المتجر')
  localStorage.setItem('pos_company_phone',        general.value.phone         || prev['posCompanyPhone'] || '')
  localStorage.setItem('pos_company_tax_number',   invoice.value.taxNumber     || prev['posCompanyTaxNumber'] || '')
  localStorage.setItem('pos_company_address',      general.value.address       || prev['posCompanyAddress'] || '')
  localStorage.setItem('pos_company_logo',         logo)
  clearPrintConfigCache()
  const result = fn()
  KEYS.forEach(k => { if (prev[k] === null) localStorage.removeItem(k); else localStorage.setItem(k, prev[k]) })
  clearPrintConfigCache()
  return result
}

// Print Test
function getPreviewPrintOptions() {
  return {
    template: printTemplate.value,
    config: {
      headerEnabled: printers.value.printHeader,
      footerEnabled: printers.value.printFooter,
      headerText: printTexts.value.headerText,
      footerText: printTexts.value.footerText,
      termsText: printTexts.value.termsText,
    },
  }
}

async function testPrint() {
  await printDocument(buildPreviewHtml())
}

// Live Print Preview
function buildPreviewHtml(type = previewType.value) {
  const taxR = Number(invoice.value.taxRate || 15)
  const sub  = 50
  const tax  = +(sub * taxR / 100).toFixed(2)

  return withFormLS(() => {
    let html
    if (type === 'return') {
      html = buildReturnHtml({
        returnNumber:  'RET-001',
        returnDate:    '2025-08-20T15:30:00',
        ...(selectedPrintBranchName.value
          ? { branchId: printSettingsBranchId.value, branchName: selectedPrintBranchName.value }
          : {}),
        customerName:  'أحمد محمد',
        returnReason:  'منتج تالف',
        grandTotal:    80,
        totalAmount:   80,
        items: [
          { productName: 'منتج مرتجع أ', quantity: 1, unitPrice: 50, netTotal: 50 },
          { productName: 'منتج مرتجع ب', quantity: 2, unitPrice: 15, netTotal: 30 },
        ],
      }, getPreviewPrintOptions())
    } else if (type === 'purchase') {
      html = buildPurchaseHtml({
        invoiceNumber: 'PUR-001',
        invoiceDate:   '2025-08-20T15:30:00',
        branchId:      printSettingsBranchId.value || null,
        branchName:    selectedPrintBranchName.value || '-',
        supplierName:  'المورد الرئيسي',
        status:         'partial',
        totalAmount:   350,
        paidAmount:    200,
        items: [
          { productName: 'مادة خام أ', quantity: 10, price: 20, unitPrice: 20 },
          { productName: 'مادة خام ب', quantity: 5,  price: 30, unitPrice: 30 },
        ],
      }, getPreviewPrintOptions())
    } else {
      html = getBuilderByTemplate(printTemplate.value || 'thermal-compact')({
        invoiceNumber:    `${invoice.value.invoicePrefix || 'INV-'}12345`,
        ...(selectedPrintBranchName.value
          ? { branchId: printSettingsBranchId.value, branchName: selectedPrintBranchName.value }
          : {}),
        saleDate:         '2025-08-20T15:30:00',
        customerName:     'أحمد محمد',
        paymentMethod:    'نقداً',
        discountType:     'fixed',
        discountValue:    5,
        taxRate:          taxR,
        taxAmount:        tax,
        totalAmount:      sub,
        netTotalAmount:  +(sub - 5 + tax).toFixed(2),
        paidAmount:       +(sub - 5 + tax).toFixed(2),
        changeAmount:     0,
        items: [
          { productName: 'منتج أ', quantity: 1, salePrice: 25,   netPrice: 25,   netTotal: 25 },
          { productName: 'منتج ب', quantity: 2, salePrice: 12.5, netPrice: 12.5, netTotal: 25 },
        ],
      }, getPreviewPrintOptions())
    }
    return html
  })
}

const previewHtml = computed(() => buildPreviewHtml().replace(/<script[\s\S]*?<\/script>/gi, ''))

// Lifecycle
onBeforeRouteLeave(async () => {
  if (isDirty.value) {
    return await AlertService.confirm(
      'لديك تغييرات غير محفوظة، هل تريد المغادرة؟',
      'تغييرات غير محفوظة',
      { confirmText: 'المغادرة دون حفظ', cancelText: 'البقاء في الصفحة' }
    )
  }
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
  @apply focus:ring-4 focus:ring-blue-500/5;
}

.filter-input-v3 {
  @apply w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.setting-toggle-row { @apply flex items-center justify-between cursor-pointer transition-all; }

/* Custom iOS-style toggle */
.ios-toggle {
  @apply relative w-10 h-6 shrink-0 bg-slate-200 rounded-full appearance-none cursor-pointer transition-colors duration-300;
  @apply checked:bg-blue-600;
}
.ios-toggle::before {
  content: "";
  @apply absolute top-1 left-1 w-4 h-4 bg-white rounded-full transition-transform duration-300 shadow-sm;
}
.ios-toggle:checked::before {
  @apply translate-x-4;
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
