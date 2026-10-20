import { useUiStore } from '@/stores/ui/uiStore'

export function useToast() {
  const ui = useUiStore()

  const showToast = (message, type = 'success', duration = 2000) => {
    ui.addToast(message, type, duration)
  }

  return { showToast }
}
