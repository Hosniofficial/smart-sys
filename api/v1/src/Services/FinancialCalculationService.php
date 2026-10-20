<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\MonologHandler;

/**
 * Provides shared calculations for invoice totals, taxes, discounts, and balances.
 */
class FinancialCalculationService
{
    private $logger;

    public function __construct()
    {
        $this->logger = MonologHandler::getInstance('financial_calculation');
    }

    /** Add tax and apply the discount before or after tax; round the result to two decimals. */
    public function calculateGrandTotal(
        float $baseAmount,
        float $taxAmount,
        float $discountAmount = 0.0,
        string $discountType = 'after_tax'
    ): float {
        try {
            $baseAmount = max(0.0, (float)$baseAmount);
            $taxAmount = max(0.0, (float)$taxAmount);
            $discountAmount = max(0.0, (float)$discountAmount);

            if ($discountType === 'before_tax') {
                $grandTotal = ($baseAmount - $discountAmount) + $taxAmount;
            } else {
                $grandTotal = ($baseAmount + $taxAmount) - $discountAmount;
            }

            return round($grandTotal, 2);

        } catch (\Throwable $e) {
            $this->logger->error('Error calculating grand total', [
                'base_amount' => $baseAmount,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /** Subtract a discount from the gross amount; context currently does not change the calculation. */
    public function calculateNetTotal(
        float $grossAmount,
        float $discountAmount = 0.0,
        string $context = 'discount_only'
    ): float {
        try {
            $grossAmount = max(0.0, (float)$grossAmount);
            $discountAmount = max(0.0, (float)$discountAmount);

            if ($context === 'extract_tax') {
                return round($grossAmount - $discountAmount, 2);
            }

            return round($grossAmount - $discountAmount, 2);

        } catch (\Throwable $e) {
            $this->logger->error('Error calculating net total', [
                'gross_amount' => $grossAmount,
                'discount_amount' => $discountAmount,
                'context' => $context,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /** Calculate a fixed or percentage tax and apply the selected rounding mode. */
    public function calculateTax(
        float $baseAmount,
        float $taxValue,
        string $taxType = 'percentage',
        string $roundingMode = 'round'
    ): float {
        try {
            $baseAmount = max(0.0, (float)$baseAmount);
            $taxValue = max(0.0, (float)$taxValue);

            $taxAmount = 0.0;

            if ($taxType === 'fixed') {
                $taxAmount = $taxValue;
            } else {
                if ($taxValue > 0) {
                    $taxAmount = $baseAmount * ($taxValue / 100);
                }
            }

            if ($roundingMode === 'ceil') {
                $taxAmount = ceil($taxAmount * 100) / 100;
            } elseif ($roundingMode === 'floor') {
                $taxAmount = floor($taxAmount * 100) / 100;
            } else {
                $taxAmount = round($taxAmount, 2);
            }

            return $taxAmount;

        } catch (\Throwable $e) {
            $this->logger->error('Error calculating tax', [
                'base_amount' => $baseAmount,
                'tax_value' => $taxValue,
                'tax_type' => $taxType,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /** Extract the pre-tax amount from a tax-inclusive total. */
    public function calculateTaxInclusivePrice(
        float $grossAmount,
        float $taxRate
    ): float {
        try {
            $grossAmount = max(0.0, (float)$grossAmount);
            $taxRate = max(0.0, (float)$taxRate);

            if ($taxRate === 0.0) {
                return $grossAmount;
            }

            $netAmount = $grossAmount / (1 + ($taxRate / 100));
            return round($netAmount, 2);

        } catch (\Throwable $e) {
            $this->logger->error('Error calculating tax-inclusive price', [
                'gross_amount' => $grossAmount,
                'tax_rate' => $taxRate,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Calculate a fixed or percentage discount, capped at the base amount.
     *
     * Percentage values above 100 are treated as fixed amounts.
     */
    public function calculateDiscount(
        float $baseAmount,
        float $discountValue,
        string $discountType = 'percentage'
    ): float {
        try {
            $baseAmount = max(0.0, (float)$baseAmount);
            $discountValue = max(0.0, (float)$discountValue);

            $discountAmount = 0.0;

            if ($discountType === 'fixed') {
                $discountAmount = min($discountValue, $baseAmount);
            } else {
                if ($discountValue > 0 && $discountValue <= 100) {
                    $discountAmount = $baseAmount * ($discountValue / 100);
                } elseif ($discountValue > 100) {
                    $discountAmount = min($discountValue, $baseAmount);
                }
            }

            return round($discountAmount, 2);

        } catch (\Throwable $e) {
            $this->logger->error('Error calculating discount', [
                'base_amount' => $baseAmount,
                'discount_value' => $discountValue,
                'discount_type' => $discountType,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /** Return the non-negative balance after subtracting the paid amount. */
    public function calculateRemaining(
        float $totalAmount,
        float $paidAmount
    ): float {
        try {
            $totalAmount = max(0.0, (float)$totalAmount);
            $paidAmount = max(0.0, (float)$paidAmount);

            $remaining = max(0.0, $totalAmount - $paidAmount);
            return round($remaining, 2);

        } catch (\Throwable $e) {
            $this->logger->error('Error calculating remaining', [
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Calculate discount, net total, tax, grand total, and amount due.
     *
     * @return array{net_total: float, discount_amount: float, tax_amount: float, grand_total: float, amount_due: float}
     */
    public function calculateCompleteBreakdown(
        float $baseAmount,
        float $discountValue = 0.0,
        string $discountType = 'percentage',
        float $taxValue = 0.0,
        string $taxType = 'percentage',
        float $paidAmount = 0.0
    ): array {
        try {
            $discountAmount = $this->calculateDiscount($baseAmount, $discountValue, $discountType);

            $netTotal = $baseAmount - $discountAmount;

            $taxAmount = $this->calculateTax($netTotal, $taxValue, $taxType);

            $grandTotal = $this->calculateGrandTotal($netTotal, $taxAmount, 0.0, 'after_tax');

            $amountDue = $this->calculateRemaining($grandTotal, $paidAmount);

            return [
                'net_total' => $netTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'amount_due' => $amountDue
            ];

        } catch (\Throwable $e) {
            $this->logger->error('Error calculating complete breakdown', [
                'base_amount' => $baseAmount,
                'discount_value' => $discountValue,
                'tax_value' => $taxValue,
                'paid_amount' => $paidAmount,
                'error' => $e->getMessage()
            ]);

            return [
                'net_total' => 0.0,
                'discount_amount' => 0.0,
                'tax_amount' => 0.0,
                'grand_total' => 0.0,
                'amount_due' => 0.0
            ];
        }
    }
}
