/**
 * useSearchDropdown - Unified Search Dropdown Composable with Keyboard Navigation
 * 
 * يوفر منطق موحد للبحث مع قائمة منسدلة وتحديث الموضع التلقائي
 * 
 * ✨ FIX: أضيف debounce (300ms) داخل الكومبوزابل نفسه بدل ما كل صفحة
 *    تعيد تطبيقه يدويًا (كان بيتسبب في استدعاء API على كل حرف).
 * ✨ FIX: أضيف دعم فعلي لـ options.onClear — يُستدعى تلقائيًا لما يتفضى
 *    مربع البحث، بدل ما يكون parameter بلا تأثير كما كان سابقًا.
 * 
 * يُستخدم في:
 * - PurchaseManagement (بحث عن الفاتورة)
 * - CashVouchers (بحث عن السند)
 * - ReturnsManagement (بحث عن المرتجع)
 * - ReturnForm (بحث عن الفاتورة + keyboard nav) — لسه لم يُطبَّق، معلّق
 * 
 * Features:
 * - Search results with dropdown
 * - Debounced search (300ms) with automatic onSearch/onClear callbacks
 * - Automatic dropdown position update on scroll/resize
 * - Optional keyboard navigation (up/down/enter/escape)
 * - Auto-hide on blur
 * - Teleport to body for z-index management
 * 
 * options:
 *   onSearch(query): يُستدعى بعد 300ms من توقف الكتابة، لما query غير فارغ
 *   onClear(): يُستدعى فورًا لما يتفضى مربع البحث (بدون debounce)
 *   onSelectResult(result): يُستدعى عند اختيار نتيجة من القائمة
 */

import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';

export const useSearchDropdown = (options = {}) => {
  // States
  const search = ref('');
  const searchResults = ref([]);
  const isLoadingSearch = ref(false);
  const showSearchDropdown = ref(false);
  
  // Refs
  const searchInputRef = ref(null);
  const searchDropdownRef = ref(null);

  // Dropdown Positioning (for Teleport)
  const searchDropdownPosition = ref({});
  const searchDropdownPositionUpdate = ref(0); // Trigger for position recalculation
  
  // Keyboard Navigation (Optional)
  const highlightedIdx = ref(-1);

  // Timers for Debounce/Scroll/Resize
  let searchDebounceTimer = null;
  let scrollThrottleTimer = null;
  let resizeDebounceTimer = null;

  // Calculate Dropdown Position
  /**
   * حساب موضع القائمة المنسدلة بناءً على موضع input
   * يُستخدم عند: focus, scroll, resize
   */
  const updateDropdownPosition = () => {
    if (!searchInputRef.value) return;

    const rect = searchInputRef.value.getBoundingClientRect();
    const windowHeight = window.innerHeight;
    const dropdownMaxHeight = 320; // max-h-80 ~ 320px
    
    // تحديد ما إذا كان يجب وضع القائمة أسفل أم أعلى
    const spaceBelow = windowHeight - rect.bottom;
    const showAbove = spaceBelow < dropdownMaxHeight && rect.top > dropdownMaxHeight;

    searchDropdownPosition.value = {
      position: 'fixed',
      left: `${rect.left}px`,
      width: `${rect.width}px`,
      maxHeight: '320px',
      top: showAbove ? `${rect.top - dropdownMaxHeight}px` : `${rect.bottom}px`,
      zIndex: 99999,
    };
  };

  // Handle Scroll & Resize
  /**
   * مستمع scroll بـ throttle (60fps)
   * يحدّث موضع القائمة عندما يتمرر المستخدم
   */
  const handleScroll = () => {
    if (scrollThrottleTimer) return;
    scrollThrottleTimer = setTimeout(() => {
      if (showSearchDropdown.value) {
        updateDropdownPosition();
      }
      scrollThrottleTimer = null;
    }, 16); // ~60fps
  };

  /**
   * مستمع resize بـ debounce
   * يحدّث موضع القائمة عندما يغير المستخدم حجم النافذة
   */
  const handleResize = () => {
    clearTimeout(resizeDebounceTimer);
    resizeDebounceTimer = setTimeout(() => {
      if (showSearchDropdown.value) {
        updateDropdownPosition();
      }
    }, 100);
  };

  // Search Input Blur Handler
  /**
   * إخفاء القائمة عند تركيز خارج الـ input
   * تأخير صغير للسماح بـ click على النتائج
   */
  const handleSearchBlur = () => {
    setTimeout(() => {
      // فقط أخفِ إذا كان التركيز بعيداً عن dropdown أيضاً
      if (document.activeElement !== searchDropdownRef.value) {
        showSearchDropdown.value = false;
        highlightedIdx.value = -1;
      }
    }, 150);
  };

  // Select Search Result
  /**
   * تحديد نتيجة بحث معينة
   * يُطلق حدث 'onSelectResult' للمكون الأب
   */
  const selectSearchResult = (result) => {
    if (options.onSelectResult) {
      options.onSelectResult(result);
    }
    showSearchDropdown.value = false;
    highlightedIdx.value = -1;
  };

  // Keyboard Navigation (Optional)
  /**
   * التنقل لأسفل في النتائج (Arrow Down)
   * يزيد highlighted index وينقل focus
   */
  const selectNextResult = () => {
    if (!searchResults.value.length) return;
    highlightedIdx.value = Math.min(
      highlightedIdx.value + 1,
      searchResults.value.length - 1
    );
  };

  /**
   * التنقل لأعلى في النتائج (Arrow Up)
   * يقلل highlighted index
   */
  const selectPrevResult = () => {
    if (highlightedIdx.value > 0) {
      highlightedIdx.value--;
    }
  };

  /**
   * تحديد النتيجة المسلطة الضوء عليها (Enter)
   * يختار النتيجة الحالية
   */
  const selectHighlightedResult = () => {
    if (highlightedIdx.value >= 0 && searchResults.value[highlightedIdx.value]) {
      selectSearchResult(searchResults.value[highlightedIdx.value]);
    }
  };

  // Watch Search Query (مع Debounce + onClear)
  /**
   * ✨ FIX: مراقبة query البحث مع debounce حقيقي (300ms):
   * - لو فيه نص: انتظر 300ms من توقف الكتابة ثم نادِ onSearch
   * - لو اتفضى: نادِ onClear فورًا (بدون تأخير) عشان الجدول يرجع لحالته الطبيعية
   */
  watch(search, (newValue) => {
    clearTimeout(searchDebounceTimer);
    highlightedIdx.value = -1;

    if (newValue && newValue.trim().length > 0) {
      showSearchDropdown.value = true;
      searchDebounceTimer = setTimeout(() => {
        if (options.onSearch) {
          options.onSearch(newValue);
        }
      }, 300);
    } else {
      searchResults.value = [];
      if (options.onClear) {
        options.onClear();
      }
    }
  });

  // Watch showSearchDropdown
  /**
   * عند فتح القائمة: حدّث الموضع
   */
  watch(showSearchDropdown, (isOpen) => {
    if (isOpen) {
      // تأخير صغير للسماح لـ DOM بالتحديث
      setTimeout(() => {
        updateDropdownPosition();
      }, 0);
    }
  });

  // Watch Position Update Trigger
  /**
   * عند استدعاء updateDropdownPosition خارجياً
   */
  watch(searchDropdownPositionUpdate, () => {
    if (showSearchDropdown.value) {
      updateDropdownPosition();
    }
  });

  // Lifecycle: Setup Listeners
  onMounted(() => {
    window.addEventListener('scroll', handleScroll, true); // Capture phase
    window.addEventListener('resize', handleResize);
  });

  // Lifecycle: Cleanup Listeners
  onBeforeUnmount(() => {
    window.removeEventListener('scroll', handleScroll, true);
    window.removeEventListener('resize', handleResize);
    clearTimeout(scrollThrottleTimer);
    clearTimeout(resizeDebounceTimer);
    clearTimeout(searchDebounceTimer);
  });

  return {
    // States
    search,
    searchResults,
    isLoadingSearch,
    showSearchDropdown,

    // Refs
    searchInputRef,
    searchDropdownRef,

    // Position
    searchDropdownPosition,
    searchDropdownPositionUpdate,

    // Keyboard Navigation
    highlightedIdx,

    // Methods
    updateDropdownPosition,
    handleScroll,
    handleResize,
    handleSearchBlur,
    selectSearchResult,

    // Keyboard Navigation Methods
    selectNextResult,
    selectPrevResult,
    selectHighlightedResult,
  };
};