<template>
  <main
    class="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-50 px-4 py-8 text-slate-900 antialiased sm:px-6 lg:px-8"
    dir="rtl"
    :aria-busy="loading"
  >
    <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
      <div class="absolute -right-40 -top-40 h-96 w-96 rounded-full bg-blue-100/60 blur-3xl"></div>
      <div class="absolute -bottom-48 -left-40 h-[30rem] w-[30rem] rounded-full bg-indigo-100/50 blur-3xl"></div>
    </div>

    <PageLoadingBar :active="loading" />

    <section
      class="relative w-full overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xl shadow-slate-900/5"
      :class="wide ? 'max-w-5xl lg:flex lg:min-h-[50rem] lg:flex-row' : 'max-w-md'"
    >
      <div class="absolute inset-x-0 top-0 z-20 h-1 bg-gradient-to-l from-blue-600 via-indigo-500 to-blue-400"></div>

      <div class="relative z-10 w-full p-6 sm:p-8 lg:p-10" :class="wide ? 'lg:w-[30rem] lg:flex-none' : ''">
        <header class="mb-8" :class="centered ? 'text-center' : ''">
          <router-link
            to="/"
            class="mb-6 inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-slate-100 bg-white p-1.5 shadow-sm"
            :class="centered ? 'mx-auto' : ''"
            aria-label="SmartSys - الصفحة الرئيسية"
          >
            <img src="/favicons/logo-smartsys.png" alt="SmartSys" class="h-11 w-11 object-contain" />
          </router-link>

          <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ title }}</h1>
          <p v-if="description" class="mt-2 text-sm font-medium leading-7 text-slate-500">
            {{ description }}
          </p>
        </header>

        <slot></slot>

        <footer v-if="$slots.footer" class="mt-7 border-t border-slate-100 pt-6">
          <slot name="footer"></slot>
        </footer>
      </div>

      <aside
        v-if="wide"
        class="relative hidden min-h-full flex-1 flex-col justify-start overflow-hidden bg-gradient-to-br from-blue-50 via-white to-slate-100 p-10 lg:flex"
      >
        <img
          src="/favicons/logo-smartsys.png"
          alt=""
          aria-hidden="true"
          class="pointer-events-none absolute -left-72 -top-12 w-[30rem] rotate-12 opacity-[0.12]"
        />

        <div class="relative z-10 mt-6 rounded-2xl border border-white/90 bg-white/80 p-8 shadow-sm backdrop-blur">
          <img src="/favicons/banner-smartsys.png" alt="SmartSys" class="mb-8 h-auto w-52" />
          <p class="mb-3 text-xs font-bold tracking-wide text-blue-600">منصة واحدة لإدارة أعمالك</p>
          <h2 class="text-3xl font-bold leading-tight text-slate-900">
            {{ asideTitle }}<br />
            <span class="text-blue-600">{{ asideHighlight }}</span>
          </h2>
          <p class="mt-4 max-w-sm text-sm font-medium leading-7 text-slate-600">
            {{ asideDescription }}
          </p>

          <div class="mt-7 flex flex-wrap gap-x-5 gap-y-3 border-t border-slate-100 pt-5 text-xs font-bold text-slate-500">
            <span class="inline-flex items-center gap-2"><i class="fas fa-receipt text-blue-500"></i>المبيعات</span>
            <span class="inline-flex items-center gap-2"><i class="fas fa-boxes-stacked text-blue-500"></i>المخزون</span>
            <span class="inline-flex items-center gap-2"><i class="fas fa-calculator text-blue-500"></i>الحسابات</span>
          </div>
        </div>
      </aside>
    </section>
  </main>
</template>

<script setup>
import PageLoadingBar from '@/components/ui/PageLoadingBar.vue'

defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  wide: { type: Boolean, default: false },
  centered: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  asideTitle: { type: String, default: 'إدارة أعمالك' },
  asideHighlight: { type: String, default: 'من مكان واحد' },
  asideDescription: {
    type: String,
    default: 'تابع المبيعات، راقب المخزون، وأدر حساباتك اليومية بكل سهولة.',
  },
})
</script>
