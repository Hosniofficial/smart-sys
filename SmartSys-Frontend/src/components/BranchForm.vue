<template>
  <form @submit.prevent="handleSubmit" class="space-y-6" dir="rtl">
      
      <!-- Row 1: Branch Name -->
      <div class="space-y-1.5 group">
        <label for="branch-name" class="metadata-label">اسم الفرع<span class="text-rose-500">*</span></label>
        <div class="relative">
          <input 
            type="text" 
            id="branch-name" 
            v-model="form.name" 
            class="form-input-v3 font-bold" 
            style="padding-right: 2rem;"
            :class="{'border-rose-400 focus:ring-rose-500/10': serverErrors.name}"
            placeholder="مثال: الفرع الرئيسي" 
            required
          >
          <i class="fas fa-store absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-600 transition-colors text-[10px]"></i>
        </div>
        <p v-if="serverErrors.name" class="text-[10px] text-rose-500 font-bold px-1">{{ serverErrors.name[0] }}</p>
      </div>

      <!-- Row 2: Location -->
      <div class="space-y-1.5 group">
        <label for="branch-location" class="metadata-label">الموقع الجغرافي / العنوان</label>
        <div class="relative">
          <input 
            type="text" 
            id="branch-location" 
            v-model="form.location" 
            class="form-input-v3"
            style="padding-right: 2rem;"
            placeholder="المدينة، الحي، رقم المبنى..."
          >
          <i class="fas fa-map-marker-alt absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-600 transition-colors text-[10px]"></i>
        </div>
        <p v-if="serverErrors.location" class="text-[10px] text-rose-500 font-bold px-1">{{ serverErrors.location[0] }}</p>
      </div>

      <!-- Row 3: Contacts (Two Columns) -->
      <div class="grid grid-cols-2 gap-4">
        <div class="space-y-1.5 group">
          <label for="branch-phone" class="metadata-label">هاتف التواصل</label>
          <div class="relative">
            <input
              type="tel"
              id="branch-phone"
              v-model="form.phone"
              class="form-input-v3 font-mono tracking-tighter"
              style="padding-right: 2rem;"
              placeholder="01xxxxxxxx"
            >
            <i class="fas fa-phone absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-600 transition-colors text-[10px]"></i>
          </div>
        </div>

        <div class="space-y-1.5 group">
          <label for="branch-email" class="metadata-label">البريد الإلكتروني</label>
          <div class="relative">
            <input
              type="email"
              id="branch-email"
              v-model="form.email"
              class="form-input-v3"
              style="padding-right: 2rem;"
              placeholder="example@company.com"
            >
            <i class="fas fa-envelope absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-600 transition-colors text-[10px]"></i>
          </div>
        </div>
      </div>

      <!-- Operational Status: Card Style Toggle -->
      <div class="pt-2">
        <label class="flex items-center justify-between p-4 rounded-xl bg-slate-50 border border-slate-200 cursor-pointer hover:bg-white hover:border-blue-200 transition-all group">
          <div class="space-y-1">
            <p class="text-xs font-bold text-slate-900 uppercase tracking-tight">حالة التشغيل الحالية</p>
            <p class="text-[10px] text-slate-400 font-medium">تفعيل أو إيقاف استقبال العمليات لهذا الفرع</p>
          </div>
          <div
            @click="form.is_active = !form.is_active"
            :class="form.is_active ? 'bg-blue-600' : 'bg-slate-200'"
            class="w-10 h-5 rounded-full cursor-pointer transition-colors duration-200 relative overflow-hidden"
          >
            <span
              :class="form.is_active ? 'left-0.5' : 'right-0.5'"
              class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-all duration-200"
            ></span>
          </div>
        </label>
      </div>

      <!-- Footer: Minimal Actions -->
      <div class="flex justify-end gap-3 pt-6 border-t border-slate-100">
        <button type="button" @click="closeModal" class="px-5 h-10 text-[11px] font-bold text-slate-500 hover:text-slate-900 transition-colors">
          إلغاء
        </button>
        <LoadingButton
          :text="isEditing ? 'تحديث البيانات' : 'اعتماد الفرع'"
          :isLoading="isLoading"
          :loadingText="isEditing ? 'جاري التحديث...' : 'جاري الاعتماد...'"
          type="submit"
          variant="primary"
          loaderType="dots"
          class="px-8 h-10"
        />
      </div>
    </form>
</template>

<script setup>
// [المنطق البرمجي لم يتغير بتاتاً - تم الحفاظ عليه بنسبة 100%]
import { ref, watch, computed } from 'vue';
import { useToast } from '@/composables/useToast';
import { useBranchStore } from '@/stores/branch/branchStore';
import LoadingButton from '@/components/ui/LoadingButton.vue'

const props = defineProps({
  branch: { type: Object, default: null }
});

const emit = defineEmits(['close', 'cancel', 'success']);
const { showToast } = useToast();
const branchStore = useBranchStore();

const form = ref({
  id: null,
  name: '',
  location: '',
  phone: '',
  email: '',
  is_active: true
});
const isLoading = ref(false);
const serverErrors = ref({});

watch(() => props.branch, (newVal) => {
  if (newVal) {
    const activeStatus = newVal.is_active ?? newVal.active;
    form.value = {
      ...newVal,
      is_active: activeStatus === true || activeStatus === 1 || activeStatus === '1'
    };
  } else {
    form.value = { id: null, name: '', location: '', phone: '', email: '', is_active: true };
  }
  serverErrors.value = {};
}, { immediate: true });

const isEditing = computed(() => !!form.value.id);

const handleSubmit = async () => {
  isLoading.value = true;
  serverErrors.value = {};
  try {
    const payload = { ...form.value, is_active: form.value.is_active ? 1 : 0 };
    let result;
    if (isEditing.value) result = await branchStore.updateBranch(form.value.id, payload);
    else result = await branchStore.createBranch(payload);

    if (result.status === 'success') {
      showToast(`تم ${isEditing.value ? 'تحديث' : 'إنشاء'} الفرع بنجاح!`, 'success');
      emit('success');
    } else {
      throw new Error(result.message || 'An unknown error occurred.');
    }
  } catch (error) {
    const errorMessage = error.response?.data?.message || error.message || `فشل في العملية.`;
    showToast(errorMessage, 'error');
    if (error.response?.data?.errors) serverErrors.value = error.response.data.errors;
  } finally {
    isLoading.value = false;
  }
};

const closeModal = () => emit('cancel');
</script>

<style scoped>
/* High-Density SaaS UI Tokens */
.metadata-label {
  @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 px-1;
}

.form-input-v3 {
  @apply w-full h-10 bg-white border border-slate-200 rounded-md px-3 outline-none transition-all duration-300 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 text-xs font-medium text-slate-800 placeholder-slate-300;
}

/* Modal Animations */
@keyframes modalIn {
  from { opacity: 0; transform: scale(0.98) translateY(10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}
.animate-modalIn {
  animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
</style>