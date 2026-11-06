import logger from '@/utils/services/logger'
import { ref, computed } from 'vue';
import { useStatementStore } from '@/stores/statement/statementStore';
import { useCompanyCurrency } from './useCompanyCurrency';

/**
 * Composable موحد لجميع عمليات جلب وتنسيق بيانات كشف الحساب
 * يوحّد المنطق بين ContactDetails و AccountStatement
 * 
 * الفائدة: مصدر واحد للحقيقة (Single Source of Truth)
 * - لا تكرار منطق البيانات
 * - أي تغيير في API يتم في مكان واحد
 * - سهولة الصيانة والاختبار
 */
export function useStatementData() {
  const statementStore = useStatementStore();
  const { currencySymbol, formatCurrencyLocale } = useCompanyCurrency();

  const loading = ref(false);
  const error = ref(null);
  const data = ref(null);

  // Pagination State
  const page = ref(1);
  const perPage = ref(50);
  const totalRecords = ref(0);
  const totalPages = computed(() => Math.ceil(totalRecords.value / perPage.value));

  // Error Handling Enhancements
  /**
   * تصنيف الأخطاء لتقديم تجربة مستخدم أفضل
   */
  const classifyError = (err, statusCode) => {
    if (statusCode === 401) {
      return {
        type: 'session_expired',
        message: 'انتهت جلستك. يرجى تسجيل الدخول من جديد.',
        retryable: true,
        showRetry: true
      };
    }
    if (statusCode === 403) {
      return {
        type: 'permission_denied',
        message: 'ليس لديك صلاحية لعرض هذه البيانات.',
        retryable: false,
        showRetry: false
      };
    }
    if (statusCode === 404) {
      return {
        type: 'not_found',
        message: 'البيانات المطلوبة غير موجودة.',
        retryable: false,
        showRetry: false
      };
    }
    if (statusCode === 500 || statusCode === 503) {
      return {
        type: 'server_error',
        message: 'حدث خطأ في السيرفر. يرجى المحاولة لاحقاً.',
        retryable: true,
        showRetry: true
      };
    }
    return {
      type: 'network_error',
      message: err?.message || 'فشل تحميل البيانات. تحقق من اتصالك.',
      retryable: true,
      showRetry: true
    };
  };

  // API: Fetch Statement Data (الموحد)
  /**
   * يجلب بيانات كشف الحساب الموحدة من backend
   * يدعم Caching و Error Handling المحسّن و Server-Side Pagination
   * 
   * @param {string} type - 'customers' | 'suppliers'
   * @param {number} id - معرف العميل/المورد
   * @param {object} params - معاملات الفلتر (start_date, end_date, page, per_page, etc.)
   * @param {object} options - خيارات إضافية { forceRefresh: false }
   * @returns {object} { status, data, error, classification }
   */
  const fetchStatementData = async (type, id, params = {}, options = {}) => {
    if (!type || !id) {
      error.value = {
        type: 'invalid_params',
        message: 'المعاملات غير صحيحة'
      };
      return { status: 'error', data: null, error: error.value, classification: null };
    }

    loading.value = true;
    error.value = null;

    try {
      // إضافة pagination parameters إلى الطلب
      const requestParams = {
        ...params,
        page: params.page || page.value,
        perPage: params.perPage || perPage.value
      };

      logger.debug('[useStatementData] Fetching statement:', { type, id, requestParams });

      const response = type === 'customers'
        ? await statementStore.fetchCustomerStatement(id, requestParams, options)
        : await statementStore.fetchSupplierStatement(id, requestParams, options);

      logger.debug('[useStatementData] API Response:', response);

      if (response?.status === 'success' && response?.data) {
        data.value = response.data;
        
        const pagination = response.meta || {};
        if (Object.keys(pagination).length) {
          totalRecords.value = response.data.transactionCount || pagination.total || pagination.returned || 0;
          page.value = pagination.page || pagination.currentPage || 1;
          perPage.value = pagination.perPage || 50;
        } else if (response.data?.total) {
          totalRecords.value = response.data.total;
        } else if (response.data?.transactionCount) {
          totalRecords.value = response.data.transactionCount;
        }
        
        return { 
          status: 'success', 
          data: data.value, 
          meta: response.meta || {},
          error: null,
          classification: null
        };
      } else {
        throw new Error(response?.message || 'فشل جلب البيانات');
      }
    } catch (err) {
      const statusCode = err?.response?.status;
      const classification = classifyError(err, statusCode);
      
      error.value = {
        type: classification.type,
        message: classification.message,
        original: err.message,
        statusCode,
        retryable: classification.retryable,
        showRetry: classification.showRetry
      };

      console.error('[useStatementData] Fetch error:', { type, id, params, error: err });
      
      return { 
        status: 'error', 
        data: null, 
        error: error.value,
        classification
      };
    } finally {
      loading.value = false;
    }
  };

  // Data Mapping & Normalization

  /**
   * استخراج الفواتير من البيانات الموحدة
   * يدعم تنسيق موحد للفواتير من كلا الملفين
   */
  const extractInvoices = (statementData) => {
    if (!statementData) {
      return [];
    }
    
    logger.debug('[useStatementData] API Response Structure:', {
      keys: Object.keys(statementData),
      hasSalesOnly: !!statementData.salesOnly,
      hasReferences: !!statementData.references,
      hasTransactions: !!statementData.transactions,
      salesOnlyItems: statementData.salesOnly?.items?.length || 0,
      fullResponse: statementData
    });
    
    if (!statementData?.salesOnly?.items) {
      logger.debug('[useStatementData] Sales-only invoices are not present in this statement.');
      return [];
    }
    
    return statementData.salesOnly.items.map(inv => ({
      id: inv.id,
      date: inv.date || inv.saleDate || inv.createdAt,
      invoiceNumber: inv.invoiceNumber,
      reference: inv.reference,
      totalAmount: Number(inv.totalAmount || inv.netTotalAmount || 0),
      paidAmount: Number(inv.paidAmount || 0),
      outstanding: Number(inv.outstanding || 0),
      dueDate: inv.dueDate,
      daysOverdue: inv.daysOverdue !== undefined ? Number(inv.daysOverdue) : null,
      status: inv.status,
      dynamicStatus: inv.dynamicStatus,
      
      // ✅ CRITICAL: Include items_count and has_journal fields
      itemsCount: Number(inv.itemsCount || 0),
      hasJournal: Boolean(inv.hasJournal),
      journalEntryId: inv.journalEntryId || null,
      
      // Fields for backward compatibility
      total: Number(inv.netTotalAmount || inv.totalAmount || 0),
      paid: Number(inv.paidAmount || 0)
    }));
  };

  /**
   * استخراج الدفعات من البيانات الموحدة
   */
  const extractPayments = (statementData) => {
    if (!statementData?.references?.items) return [];
    return statementData.references.items
      .filter(r => ['receipt', 'refund', 'payment'].includes(String(r.type).toLowerCase()))
      .map(p => ({
        id: p.id,
        date: p.date,
        amount: Number(p.totalAmount || p.amount || 0),
        type: p.type === 'refund' ? 'return_payment' : 'receipt',
        reference: p.reference,
        referenceLabel: p.referenceLabel,
        description: p.description,
        sourceLabel: p.sourceLabel,
        sourcePaymentType: p.sourcePaymentType,
        paymentReferenceNumber: p.paymentReferenceNumber,
        invoiceNumber: p.invoiceNumber,
        allocatedInvoiceNumbers: p.allocatedInvoiceNumbers,
        paymentMethodName: p.paymentMethodName,
        journalEntryId: p.journalEntryId,
        hasJournal: p.hasJournal
      }));
  };

  /**
   * استخراج الحركات المحاسبية من البيانات الموحدة
   */
  const extractTransactions = (statementData) => {
    if (!statementData?.transactions) return [];
    
    return statementData.transactions.map(t => ({
      // Core fields
      id: t.id || t.sourceId,
      date: t.date,
      reference: t.reference,
      referenceId: t.referenceId,
      referenceLabel: t.referenceLabel,
      invoiceNumber: t.invoiceNumber,
      paymentReferenceNumber: t.paymentReferenceNumber,
      description: t.description,
      
      // ⚠️ transaction_type is the source of truth for transaction type classification
      transactionType: t.transactionType,
      
      // Fallback for compatibility
      type: t.transactionType || t.type,
      
      // Amount fields - convert to number for calculation
      debit: Number(t.debit || 0),
      credit: Number(t.credit || 0),
      balance: Number(t.balance || 0),
      balanceNature: t.balanceNature || 'zero',
      
      // Additional metadata from API
      sourceType: t.sourceType,
      sourceId: t.sourceId,
      costCenterId: t.costCenterId,
      actualPaymentType: t.actualPaymentType,
      statusCode: t.statusCode,
      statusLabel: t.statusLabel
    }));
  };

  /**
   * استخراج المرتجعات من البيانات الموحدة
   */
  const extractReturns = (statementData) => {
    if (!statementData?.references?.items) return [];
    return statementData.references.items.filter(r =>
      ['sales_return', 'return', 'purchase_return'].includes(String(r.type).toLowerCase())
    );
  };

  // Computed: Totals & Analysis

  const invoices = computed(() => extractInvoices(data.value) || []);
  const payments = computed(() => extractPayments(data.value) || []);
  const transactions = computed(() => extractTransactions(data.value) || []);
  const returns = computed(() => extractReturns(data.value) || []);

  /**
   * حساب الأرصدة الإجمالية
   */
  const totals = computed(() => {
    if (!data.value) {
      return {
        opening: 0,
        closing: 0,
        totalDebit: 0,
        totalCredit: 0,
        invoicesCount: 0,
        invoicesTotal: 0,
        paymentsTotal: 0,
        returnsTotal: 0,
        remainingBalance: 0
      };
    }

    const invoiceList = invoices.value;
    const paymentList = payments.value;
    const returnList = returns.value;

    return {
      opening: Number(data.value.openingBalance || 0),
      closing: Number(data.value.closingBalance || 0),
      totalDebit: Number(data.value.totalDebit || 0),
      totalCredit: Number(data.value.totalCredit || 0),
      invoicesCount: invoiceList.length,
      invoicesTotal: invoiceList.reduce((s, i) => s + Number(i.totalAmount || 0), 0),
      paymentsTotal: paymentList.reduce((s, p) => s + Number(p.amount || 0), 0),
      returnsTotal: returnList.reduce((s, r) => s + Number(r.totalAmount || 0), 0),
      remainingBalance: Math.abs(Number(data.value.closingBalance || 0))
    };
  });

  /**
   * حساب الرصيد الدائن المتاح (Available Credit)
   * للعملاء: رصيد سالب = ائتمان لهم
   * للموردين: رصيد موجب = ائتمان لدينا
   */
  const availableCredit = computed(() => {
    if (!data.value) return 0;
    const balance = Number(data.value.closingBalance || 0);
    return balance < 0 ? Math.abs(balance) : 0;
  });

  /**
   * تحليل أعمار الديون (Aging Analysis)
   * يقسم الفواتير المستحقة حسب العمر (بالأيام)
   */
  const agingAnalysis = computed(() => {
    const invoiceList = invoices.value;

    const breakdown = {
      current: 0,      // لم تستحق بعد
      '_1to30': 0,     // 1-30 يوم متأخر
      '_31to60': 0,    // 31-60 يوم متأخر
      '_60plus': 0     // أكثر من 60 يوم متأخر
    };

    logger.debug('[useStatementData] Aging Analysis - Processing invoices:', invoiceList.length);

    invoiceList.forEach((inv, idx) => {
      // تصفية: فقط الفواتير غير المسددة/المدفوعة
      const status = String(inv.dynamicStatus || inv.status || '').toLowerCase();
      
      // تخطي الفواتير المسددة/المدفوعة بالكامل
      const paidStatuses = ['paid', 'settled', 'closed_by_return', 'cancelled', 'rejected'];
      if (paidStatuses.includes(status)) {
        logger.debug(`  [${idx}] Skipped (status=${status}):`, inv);
        return;
      }

      // يجب أن يكون هناك مبلغ مستحق
      const outstanding = Number(inv.outstanding || 0);
      if (outstanding <= 0) {
        logger.debug(`  [${idx}] Skipped (outstanding=${outstanding}):`, inv);
        return;
      }

      logger.debug(`  [${idx}] Invoice before aging calc:`, {
        id: inv.id,
        totalAmount: inv.totalAmount,
        paidAmount: inv.paidAmount,
        outstanding: outstanding,
        status
      });

      // Use days_overdue from API if available, otherwise calculate
      let daysOverdue = null;
      
      if (inv.daysOverdue !== undefined && inv.daysOverdue !== null) {
        daysOverdue = Number(inv.daysOverdue);
        logger.debug(`  [${idx}] Using API days_overdue: ${daysOverdue}`);
      } else {
        // Fallback: calculate from due_date or invoice date
        const today = new Date();
        let dueDate = null;
        
        if (inv.dueDate && String(inv.dueDate).trim()) {
          // Parse due_date explicitly if provided
          const parts = String(inv.dueDate).split('-');
          if (parts.length === 3) {
            dueDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
          } else {
            dueDate = new Date(inv.dueDate);
          }
        } else if (inv.date || inv.saleDate || inv.createdAt) {
          // Calculate from invoice date + 30 days
          const invDateStr = String(inv.date || inv.saleDate || inv.createdAt || '').trim();
          const parts = invDateStr.split('-');
          let invoiceDate = null;
          if (parts.length >= 3) {
            invoiceDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
          } else {
            invoiceDate = new Date(invDateStr);
          }
          dueDate = new Date(invoiceDate.getFullYear(), invoiceDate.getMonth(), invoiceDate.getDate() + 30);
        }
        
        if (dueDate) {
          const todayAtMidnight = new Date(today.getFullYear(), today.getMonth(), today.getDate());
          const dueDateAtMidnight = new Date(dueDate.getFullYear(), dueDate.getMonth(), dueDate.getDate());
          daysOverdue = Math.floor((todayAtMidnight - dueDateAtMidnight) / (1000 * 60 * 60 * 24));
          logger.debug(`  [${idx}] Calculated days_overdue: ${daysOverdue}`);
        } else {
          daysOverdue = 0;
          logger.debug(`  [${idx}] No valid date found, using 0`);
        }
      }

      // تصنيف حسب العمر
      if (daysOverdue <= 0) {
        // لم تستحق بعد
        breakdown.current += outstanding;
        logger.debug(`    → current (+${outstanding}, total now: ${breakdown.current})`);
      } else if (daysOverdue <= 30) {
        // 1-30 يوم متأخر
        breakdown._1to30 += outstanding;
        logger.debug(`    → _1to30 (+${outstanding}, total now: ${breakdown._1to30})`);
      } else if (daysOverdue <= 60) {
        // 31-60 يوم متأخر
        breakdown._31to60 += outstanding;
        logger.debug(`    → _31to60 (+${outstanding}, total now: ${breakdown._31to60})`);
      } else {
        // أكثر من 60 يوم متأخر
        breakdown._60plus += outstanding;
        logger.debug(`    → _60plus (+${outstanding}, total now: ${breakdown._60plus})`);
      }
    });

    logger.debug('[useStatementData] Aging Analysis Result:', breakdown);
    return breakdown;
  });

  /**
   * تحديد نوع التنبيه (Alert Type)
   * يحدد الحالة الحرجة للحساب
   */
  const alertStatus = computed(() => {
    if (!data.value) return 'healthy';

    const balance = Number(data.value.closingBalance || 0);
    const overdueAmount = agingAnalysis.value._31to60 + agingAnalysis.value._60plus;

    if (overdueAmount > 0) return 'overdue_critical'; // متأخر أكثر من 30 يوم
    if (balance > 0) return 'outstanding'; // مبلغ معلق للدفع
    if (balance < 0) return 'credit'; // رصيد دائن
    return 'healthy'; // حساب صحي
  });

  // Pagination Helper

  /**
   * دعم pagination من الـ client-side (للبيانات الصغيرة)
   * في المستقبل: يمكن التحول للـ server-side pagination بتمرير page/per_page
   */
  const paginate = (items, pageNumber = 1, pageSize = 20) => {
    const start = (pageNumber - 1) * pageSize;
    const end = start + pageSize;
    return {
      items: items.slice(start, end),
      total: items.length,
      page: pageNumber,
      pageSize,
      totalPages: Math.ceil(items.length / pageSize),
      hasNextPage: end < items.length,
      hasPrevPage: pageNumber > 1
    };
  };

  // Clear Cache

  const clearCache = () => {
    statementStore.clear();
    data.value = null;
    error.value = null;
  };

  // Exports

  return {
    // State
    loading,
    error,
    data,

    // Pagination State
    page,
    perPage,
    totalRecords,
    totalPages,

    // Methods
    fetchStatementData,
    extractInvoices,
    extractPayments,
    extractTransactions,
    extractReturns,
    paginate,
    clearCache,

    // Computed (Data)
    invoices,
    payments,
    transactions,
    returns,

    // Computed (Analysis)
    totals,
    availableCredit,
    agingAnalysis,
    alertStatus,

    // Exports for template
    currencySymbol,
    formatCurrencyLocale
  };
}
