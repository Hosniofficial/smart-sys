// Centralized helpers for Settings view
// Provides dirty-state tracking and snapshot management per section

import { computed, reactive, toRaw } from 'vue'

export function useDirtyTracking(sections) {
  // sections is an object like { general: ref({}), invoice: ref({}), printers: ref({}), users?: ref([]) }
  const sectionKeys = Object.keys(sections || {})

  // ── snapshots MUST be reactive so Vue tracks mutations and re-evaluates
  // isDirty computed whenever a snapshot is set or cleared.
  // A plain object would NOT trigger Vue's dependency tracking, causing isDirty
  // to stay stale even after setSnapshotsFromCurrent() is called.
  const snapshots = reactive(Object.fromEntries(sectionKeys.map(k => [k, null])))

  function clone(obj) {
    try {
      return JSON.parse(JSON.stringify(toRaw(obj)))
    } catch (_) {
      return toRaw(obj)
    }
  }

  function setSnapshotsFromCurrent() {
    for (const key of sectionKeys) {
      if (sections[key]) snapshots[key] = clone(sections[key].value)
    }
  }

  function isSectionDirty(sectionKey) {
    if (!sections[sectionKey]) return false
    // Accessing snapshots[sectionKey] here registers it as a reactive dependency,
    // so any computed that calls isSectionDirty() will re-run when the snapshot changes.
    if (!snapshots[sectionKey]) return false
    try {
      return JSON.stringify(sections[sectionKey].value) !== JSON.stringify(snapshots[sectionKey])
    } catch (_) {
      return false
    }
  }

  // isDirty re-evaluates automatically because:
  //   1. snapshots is reactive → mutations trigger re-run
  //   2. each sections[k].value is a Vue ref → mutations trigger re-run
  const isDirty = computed(() => sectionKeys.some(k => isSectionDirty(k)))

  function resetSectionToSnapshot(sectionKey) {
    if (!sections[sectionKey] || !snapshots[sectionKey]) return
    sections[sectionKey].value = clone(snapshots[sectionKey])
  }

  return {
    isDirty,
    isSectionDirty,
    setSnapshotsFromCurrent,
    resetSectionToSnapshot,
  }
}
