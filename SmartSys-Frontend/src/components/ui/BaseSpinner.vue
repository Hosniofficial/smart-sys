<template>
  <div 
    class="base-spinner-container"
    :class="{
      'base-spinner-container--overlay': overlay,
      'base-spinner-container--inline': inline
    }"
 
    role="status" 
    aria-live="polite" 
    aria-busy="true"
  >
    <svg 
      viewBox="0 0 50 50" 
      class="base-spinner-svg"
      :style="spinnerStyle"
    >
      <circle 
        class="base-spinner-track" 
        cx="25" 
        cy="25" 
        r="20" 
        fill="none" 
        stroke-width="5"
      ></circle>
      <circle 
        class="base-spinner-path" 
        cx="25" 
        cy="25" 
        r="20" 
        fill="none" 
        stroke-width="5"
      ></circle>
    </svg>
    <span v-if="text" class="base-spinner-text">{{ text }}</span>
  </div>
</template>

<script setup>
import { computed } from 'vue'

// Props (SAP Fiori inspired)
const props = defineProps({
  size:   { type: [Number, String], default: 40 },
  color:  { type: String, default: '#2563eb' },
  margin: { type: [Number, String], default: 0 },
  // SAP Fiori style props
  overlay: { type: Boolean, default: false }, // Cover parent with backdrop
  inline:  { type: Boolean, default: false }, // Inline with content
  text:    { type: String, default: '' } // Optional text
})

const normalized = (v) => typeof v === 'number' ? `${v}px` : v

// Size presets for consistent sizing across the app
const sizePresets = {
  sm: 16,   // Small: buttons, inline
  md: 24,   // Medium: cards, forms
  lg: 40,   // Large: full page loading
  xl: 60    // Extra large: modals
}

const resolvedSize = computed(() => {
  if (typeof props.size === 'number') return props.size
  if (sizePresets[props.size]) return sizePresets[props.size]
  // If it's a string with px/em/rem etc., return as-is
  if (typeof props.size === 'string' && /(px|em|rem|%)$/.test(props.size)) {
    return props.size
  }
  // Default fallback
  return 40
})

const spinnerStyle = computed(() => ({
  width:    normalized(resolvedSize.value),
  height:   normalized(resolvedSize.value)
}))

</script>

<style scoped>
.base-spinner-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  --spinner-color: v-bind(color);
}

.base-spinner-container--overlay {
  position: absolute;
  inset: 0;
  background: rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(1px);
  z-index: 50;
  border-radius: inherit;
}

.base-spinner-container--inline {
  display: inline-flex;
  flex-direction: row;
  padding: 0.25rem 0.5rem;
}

.base-spinner-text {
  font-size: 0.875rem;
  color: #6b7280;
  font-weight: 500;
}

.base-spinner-svg {
  width: 100%;
  height: 100%;
  animation: rotate 2s linear infinite;
  transform-origin: center center;
}

.base-spinner-track {
  stroke: rgba(0, 0, 0, 0.05);
}

.base-spinner-path {
  stroke-linecap: round;
  animation: dash 1.5s ease-in-out infinite;
  stroke: var(--spinner-color, #2563eb);
}

@keyframes rotate {
  100% { transform: rotate(360deg); }
}

@keyframes dash {
  0%   { stroke-dasharray: 1, 150;  stroke-dashoffset: 0;    }
  50%  { stroke-dasharray: 90, 150; stroke-dashoffset: -35;  }
  100% { stroke-dasharray: 90, 150; stroke-dashoffset: -124; }
}
</style>