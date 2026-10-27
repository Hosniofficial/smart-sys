<template>
  <div class="space-y-2">
    <label :for="id" class="block px-1 text-xs font-bold text-slate-600">
      {{ label }}
      <span v-if="required" class="text-rose-500" aria-hidden="true">*</span>
    </label>

    <div class="group relative">
      <input
        :id="id"
        :value="modelValue"
        :type="type"
        :dir="dir"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :required="required"
        :disabled="disabled"
        :autofocus="autofocus"
        :aria-describedby="hint ? `${id}-hint` : undefined"
        class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm font-medium text-slate-800 outline-none transition placeholder:text-slate-300 hover:border-slate-300 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-70"
        :class="[
          icon ? 'pr-11' : '',
          $slots.suffix ? 'pl-12' : '',
          dir === 'ltr' ? 'text-left' : 'text-right',
          inputClass,
        ]"
        @input="$emit('update:modelValue', $event.target.value)"
      />

      <i
        v-if="icon"
        :class="icon"
        class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-300 transition-colors group-focus-within:text-blue-500"
        aria-hidden="true"
      ></i>

      <div v-if="$slots.suffix" class="absolute left-3 top-1/2 -translate-y-1/2">
        <slot name="suffix"></slot>
      </div>
    </div>

    <p v-if="hint" :id="`${id}-hint`" class="px-1 text-xs leading-5 text-slate-400">
      {{ hint }}
    </p>
  </div>
</template>

<script setup>
defineProps({
  id: { type: String, required: true },
  label: { type: String, required: true },
  modelValue: { type: [String, Number], default: '' },
  type: { type: String, default: 'text' },
  icon: { type: String, default: '' },
  dir: { type: String, default: 'rtl' },
  placeholder: { type: String, default: '' },
  autocomplete: { type: String, default: undefined },
  inputmode: { type: String, default: undefined },
  inputClass: { type: String, default: '' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  autofocus: { type: Boolean, default: false },
})

defineEmits(['update:modelValue'])
</script>
