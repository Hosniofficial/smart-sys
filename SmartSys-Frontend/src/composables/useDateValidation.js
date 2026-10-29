import { ref, computed } from 'vue';
import { useToast } from '@/composables/useToast';

/**
 * useDateValidation - Comprehensive date range validation for filter forms
 * 
 * This composable validates date ranges to prevent invalid data from being sent to the API.
 * It handles:
 * - Empty/invalid date values
 * - Start date after end date
 * - Dates in the future
 * - Dates too far in the past (optional max range)
 * - Timezone-aware comparison
 * 
 * Usage in components:
 * ```typescript
 * const { 
 *   validateDateRange,
 *   validationError,
 *   isValidRange,
 *   clearError
 * } = useDateValidation();
 * 
 * // In filter function:
 * if (!validateDateRange(startDate.value, endDate.value)) {
 *   return; // Error message already shown via toast
 * }
 * // Continue with API call
 * ```
 */

export function useDateValidation(defaultOptions = {}) {
  const { showToast } = useToast();
  
  // Store last error for debugging
  const validationError = ref(null);
  const validationErrorAr = ref(null);
  
  const defaultOpts = {
    allowFutureDate: false,
    maxDaysRange: 365,
    showToast: true,
    returnErrors: false,
    ...defaultOptions
  };

  /**
   * Normalize date string to Date object (handling YYYY-MM-DD format)
   */
  const normalizeDate = (dateValue) => {
    if (!dateValue) return null;
    
    if (dateValue instanceof Date) {
      return dateValue;
    }
    
    if (typeof dateValue === 'string') {
      // Handle ISO format (YYYY-MM-DD)
      const parsed = new Date(dateValue);
      if (!isNaN(parsed.getTime())) {
        // Ensure the date is treated as UTC midnight to avoid timezone issues
        const [year, month, day] = dateValue.split('-').map(Number);
        if (year && month && day) {
          return new Date(year, month - 1, day);
        }
        return parsed;
      }
    }
    
    return null;
  };

  /**
   * Check if date is today or earlier (ignoring time)
   */
  const isDateTodayOrEarlier = (date) => {
    const today = new Date();
    const todayNormalized = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const dateNormalized = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    return dateNormalized <= todayNormalized;
  };

  /**
   * Calculate days between two dates
   */
  const daysBetween = (start, end) => {
    const msPerDay = 24 * 60 * 60 * 1000;
    return Math.floor((end.getTime() - start.getTime()) / msPerDay);
  };

  /**
   * Main validation function
   */
  const validateDateRange = (
    startDateInput,
    endDateInput,
    options
  ) => {
    const opts = { ...defaultOpts, ...options };
    const result = validateDateRangeDetailed(startDateInput, endDateInput, opts);
    
    if (!result.valid) {
      validationError.value = result.error || null;
      validationErrorAr.value = result.errorAr || null;
      
      if (opts.showToast && result.errorAr) {
        showToast(result.errorAr, 'error', 4000);
      }
    } else {
      clearError();
    }
    
    return result.valid;
  };

  /**
   * Detailed validation function that returns error info
   */
  const validateDateRangeDetailed = (
    startDateInput,
    endDateInput,
    options
  ) => {
    const opts = { ...defaultOpts, ...options };
    
    // Parse dates
    const startDate = normalizeDate(startDateInput);
    const endDate = normalizeDate(endDateInput);

    // Check if both dates are provided
    if (!startDate || !endDate) {
      return {
        valid: false,
        error: 'Both start and end dates are required',
        errorAr: 'تاريخ البداية والنهاية مطلوبان'
      };
    }

    // Check if dates are valid
    if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
      return {
        valid: false,
        error: 'Invalid date format. Please use YYYY-MM-DD format',
        errorAr: 'صيغة التاريخ غير صحيحة. يرجى استخدام صيغة YYYY-MM-DD'
      };
    }

    // Check if start date is before end date
    if (startDate > endDate) {
      return {
        valid: false,
        error: 'Start date cannot be after end date',
        errorAr: 'تاريخ البداية لا يمكن أن يكون بعد تاريخ النهاية'
      };
    }

    // Check for future dates
    if (!opts.allowFutureDate && endDate > new Date()) {
      const today = new Date();
      const todayNormalized = new Date(today.getFullYear(), today.getMonth(), today.getDate());
      const endDateNormalized = new Date(endDate.getFullYear(), endDate.getMonth(), endDate.getDate());
      
      if (endDateNormalized > todayNormalized) {
        return {
          valid: false,
          error: 'End date cannot be in the future',
          errorAr: 'لا يمكن تحديد تاريخ نهاية في المستقبل'
        };
      }
    }

    // Check maximum date range
    if (opts.maxDaysRange && opts.maxDaysRange > 0) {
      const days = daysBetween(startDate, endDate);
      if (days > opts.maxDaysRange) {
        return {
          valid: false,
          error: `Date range cannot exceed ${opts.maxDaysRange} days. Selected range: ${days} days`,
          errorAr: `نطاق التاريخ لا يمكن أن يتجاوز ${opts.maxDaysRange} يوم. النطاق المحدد: ${days} يوم`
        };
      }
    }

    // Check minimum date boundary (default: 1 year ago)
    let minDate = opts.minDate;
    if (!minDate) {
      const oneYearAgo = new Date();
      oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);
      minDate = oneYearAgo;
    }

    if (startDate < minDate) {
      return {
        valid: false,
        error: 'Start date is too far in the past',
        errorAr: 'تاريخ البداية بعيد جداً في الماضي'
      };
    }

    // Check maximum date boundary (default: today + 1 day, or custom)
    let maxDate = opts.maxDate;
    if (!maxDate) {
      const tomorrow = new Date();
      tomorrow.setDate(tomorrow.getDate() + 1);
      maxDate = tomorrow;
    }

    if (endDate > maxDate) {
      return {
        valid: false,
        error: 'End date is beyond the allowed range',
        errorAr: 'تاريخ النهاية خارج النطاق المسموح به'
      };
    }

    return { valid: true };
  };

  /**
   * Clear validation errors
   */
  const clearError = () => {
    validationError.value = null;
    validationErrorAr.value = null;
  };

  /**
   * Check if current validation has error
   */
  const isValidRange = computed(() => validationError.value === null);

  return {
    // Main validation function (returns boolean)
    validateDateRange,
    
    // Detailed validation function (returns object with error details)
    validateDateRangeDetailed,
    
    // State
    validationError,
    validationErrorAr,
    isValidRange,
    
    // Utilities
    clearError,
    normalizeDate,
    daysBetween,
    isDateTodayOrEarlier
  };
}
