import { onBeforeUnmount } from 'vue';
import { useBranchStore } from '@/stores/branch/branchStore';

export function useRequiredBranch() {
  const branchStore = useBranchStore();
  const preserveAllPreference = localStorage.getItem('selectedBranchId') === 'all';

  const ensureBranchSelected = async () => {
    await branchStore.fetchBranches();
    if (branchStore.selectedBranchId) return branchStore.selectedBranchId;

    const defaultBranchId = branchStore.resolveDefaultBranchId(branchStore.branches);
    if (defaultBranchId === null) return null;

    if (preserveAllPreference) {
      branchStore.setTemporaryBranch(defaultBranchId);
    } else {
      branchStore.setSelectedBranch(defaultBranchId);
    }
    return branchStore.selectedBranchId;
  };

  const setRequiredBranch = (branchId) => {
    if (branchId === null || branchId === undefined || branchId === '' || branchId === 'all') {
      return false;
    }
    return preserveAllPreference
      ? branchStore.setTemporaryBranch(branchId)
      : branchStore.setSelectedBranch(branchId);
  };

  onBeforeUnmount(() => {
    if (preserveAllPreference && localStorage.getItem('selectedBranchId') === 'all') {
      branchStore.setSelectedBranch(null);
    }
  });

  return { ensureBranchSelected, setRequiredBranch };
}
