/**
 * useLoader — thin wrapper around the global UI store loader.
 *
 * For per-component loading states, use useLoading.js instead.
 * This composable controls the global full-screen overlay loader.
 */
import { useUiStore } from '@/stores/ui/uiStore'

export function useLoader() {
  const ui = useUiStore()

  const showLoader = (show = true) => {
    if (show) ui.showLoader()
    else ui.hideLoader()
  }

  const hideLoader = () => ui.hideLoader()

  return { showLoader, hideLoader }
}
