<template>
  <div v-if="type === 'text-paragraph'" class="skeleton-paragraph" role="status" aria-busy="true" aria-label="جاري التحميل...">
    <div 
      v-for="i in lines" 
      :key="i"
      class="skeleton skeleton--text"
      :class="animationClass"
      :style="getLineStyle(i)"
    />
    <span class="sr-only">جاري التحميل...</span>
  </div>
  
  <div 
    v-else
    class="skeleton"
    :class="[
      typeClass,
      animationClass,
      { 'skeleton--circle': circle }
    ]"
    :style="skeletonStyle"
    role="status"
    aria-busy="true"
    aria-label="جاري التحميل..."
  >
    <span class="sr-only">جاري التحميل...</span>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  // Type of skeleton element
  type: { 
    type: String, 
    default: 'text', // 'text', 'rect', 'circle', 'table-row', 'card', 'text-paragraph'
    validator: (v) => ['text', 'rect', 'circle', 'table-row', 'card', 'avatar', 'text-paragraph'].includes(v)
  },
  // Number of lines for text-paragraph type
  lines: { type: Number, default: 3 },
  // Size presets
  size: { type: String, default: 'md' }, // 'sm', 'md', 'lg', 'xl'
  // Custom width (CSS value)
  width: { type: String, default: null },
  // Custom height (CSS value)
  height: { type: String, default: null },
  // Border radius
  radius: { type: String, default: null },
  // Animation type
  animation: { 
    type: String, 
    default: 'wave', // 'wave', 'shimmer' (GPU), 'pulse', 'none'
    validator: (v) => ['wave', 'shimmer', 'pulse', 'none'].includes(v)
  },
  // For avatar type
  circle: { type: Boolean, default: false }
})

const typeClass = computed(() => `skeleton--${props.type}`)
const animationClass = computed(() => `skeleton--${props.animation}`)

const sizeMap = {
  sm: { h: '1rem', w: '4rem' },
  md: { h: '1.5rem', w: '8rem' },
  lg: { h: '2rem', w: '12rem' },
  xl: { h: '3rem', w: '16rem' }
}

// ✅ Enterprise pattern: variable line widths for realistic text skeletons
const getLineStyle = (index) => {
  const widths = ['100%', '85%', '90%', '70%', '95%', '60%']
  const lineHeights = ['1.5rem', '1.25rem', '1.5rem', '1.25rem', '1.5rem', '1rem']
  
  return {
    width: widths[(index - 1) % widths.length],
    height: lineHeights[(index - 1) % lineHeights.length],
    marginBottom: '0.5rem',
    borderRadius: '0.25rem'
  }
}

const skeletonStyle = computed(() => {
  const s = sizeMap[props.size] || sizeMap.md
  return {
    width: props.width || (props.type === 'circle' || props.type === 'avatar' ? s.h : s.w),
    height: props.height || s.h,
    borderRadius: props.radius || (props.circle || props.type === 'circle' || props.type === 'avatar' ? '50%' : '0.375rem')
  }
})
</script>

<style scoped>
.skeleton {
  background: linear-gradient(
    90deg,
    #f0f0f0 25%,
    #e0e0e0 50%,
    #f0f0f0 75%
  );
  background-size: 200% 100%;
  display: inline-block;
  position: relative;
  overflow: hidden;
}

.skeleton--wave {
  animation: skeleton-wave 1.5s ease-in-out infinite;
}

.skeleton--pulse {
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}

/* No animation */
.skeleton--none {
  animation: none;
  background: #e0e0e0;
}

@keyframes skeleton-wave {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

@keyframes skeleton-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

/* GPU-accelerated shimmer for large tables */
.skeleton--shimmer {
  position: relative;
  overflow: hidden;
}

.skeleton--shimmer::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(
    90deg,
    transparent 0%,
    rgba(255, 255, 255, 0.4) 50%,
    transparent 100%
  );
  transform: translateX(-100%);
  animation: skeleton-shimmer 1.5s infinite;
}

@keyframes skeleton-shimmer {
  100% { transform: translateX(100%); }
}

/* Types */
.skeleton--text {
  display: block;
  margin-bottom: 0.5rem;
}

.skeleton--card {
  border-radius: 0.75rem;
  width: 100%;
  height: 8rem;
}

.skeleton--table-row {
  display: flex;
  gap: 1rem;
  width: 100%;
  padding: 0.75rem;
}

.skeleton--table-row::before,
.skeleton--table-row::after {
  content: '';
  background: inherit;
  border-radius: 0.25rem;
}

/* Text paragraph container */
.skeleton-paragraph {
  display: flex;
  flex-direction: column;
  width: 100%;
}

.skeleton-paragraph .skeleton:last-child {
  width: 40% !important; /* Last line shorter like real paragraphs */
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
