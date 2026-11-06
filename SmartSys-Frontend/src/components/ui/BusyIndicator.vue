<template>
  <Transition name="busy-fade">
    <div 
      v-show="shouldShow"
      class="busy-indicator"
      :class="{
        'busy-indicator--overlay': overlay,
        'busy-indicator--inline': !overlay,
        'busy-indicator--small': size === 'sm',
        'busy-indicator--block': block
      }"
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <div class="busy-indicator__animation" :class="`busy-indicator__animation--${type}`">
        <template v-if="type === 'dots'">
          <span v-for="i in 3" :key="i" class="busy-indicator__dot"></span>
        </template>
        <template v-else-if="type === 'spinner'">
          <svg class="busy-indicator__spinner" viewBox="0 0 50 50">
            <circle cx="25" cy="25" r="20" fill="none" stroke="currentColor" stroke-width="5" />
          </svg>
        </template>
      </div>
      
      <span v-if="text" class="busy-indicator__text">{{ text }}</span>
    </div>
  </Transition>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  // Type of busy animation
  type: { 
    type: String, 
    default: 'dots',
    validator: (v) => ['dots', 'spinner'].includes(v)
  },
  // Size
  size: { 
    type: String, 
    default: 'md',
    validator: (v) => ['sm', 'md', 'lg'].includes(v)
  },
  // Text to show next to indicator
  text: { type: String, default: '' },
  // Overlay mode (covers parent with backdrop)
  overlay: { type: Boolean, default: false },
  // Block display (full width)
  block: { type: Boolean, default: false },
  // Delay before showing (ms) - prevents flash on fast loads (default: 300ms)
  delay: { type: Number, default: 300 }
})

const shouldShow = ref(false)
let timeoutId = null

onMounted(() => {
  // ✅ Enterprise pattern: only show if loading takes > delay
  timeoutId = setTimeout(() => {
    shouldShow.value = true
  }, props.delay)
})

onUnmounted(() => {
  if (timeoutId) clearTimeout(timeoutId)
})
</script>

<style scoped>
.busy-indicator {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  color: #2563eb;
}

.busy-indicator--inline {
  padding: 0.25rem 0.5rem;
}

.busy-indicator--overlay {
  position: absolute;
  inset: 0;
  background: rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(1px);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  z-index: 50;
  border-radius: inherit;
}

.busy-indicator--block {
  display: flex;
  width: 100%;
  justify-content: center;
}

/* Dots Animation */
.busy-indicator__animation--dots {
  display: flex;
  gap: 0.25rem;
}

.busy-indicator__dot {
  width: 0.5rem;
  height: 0.5rem;
  background: currentColor;
  border-radius: 50%;
  animation: busy-dot 1.4s ease-in-out infinite both;
}

.busy-indicator__dot:nth-child(1) { animation-delay: -0.32s; }
.busy-indicator__dot:nth-child(2) { animation-delay: -0.16s; }

@keyframes busy-dot {
  0%, 80%, 100% { transform: scale(0); opacity: 0.5; }
  40% { transform: scale(1); opacity: 1; }
}

/* Spinner Animation */
.busy-indicator__animation--spinner {
  width: 1.5rem;
  height: 1.5rem;
}

.busy-indicator--small .busy-indicator__animation--spinner {
  width: 1rem;
  height: 1rem;
}

.busy-indicator__spinner {
  width: 100%;
  height: 100%;
  animation: busy-spin 1s linear infinite;
}

.busy-indicator__spinner circle {
  stroke-dasharray: 80;
  stroke-dashoffset: 60;
  stroke-linecap: round;
}

@keyframes busy-spin {
  100% { transform: rotate(360deg); }
}

/* Text */
.busy-indicator__text {
  font-size: 0.875rem;
  color: #6b7280;
  font-weight: 500;
}

.busy-indicator--small {
  gap: 0.375rem;
}

.busy-indicator--small .busy-indicator__text {
  font-size: 0.75rem;
}

/* Transition */
.busy-fade-enter-active,
.busy-fade-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.busy-fade-enter-from,
.busy-fade-leave-to {
  opacity: 0;
  transform: scale(0.95);
}
</style>
