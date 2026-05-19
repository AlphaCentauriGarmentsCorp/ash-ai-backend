<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * PoCodeGenerator — shared identifier generator for prefixed
 * year-scoped sequential codes.
 *
 * Format: `{PREFIX}-{YYYY}-{NNNNNN}`
 *   QUO-2026-000001  (quotations)
 *   INQ-2026-000001  (inquiries)
 *
 * Counter is per-prefix, per-year, and `lockForUpdate()` prevents
 * concurrent assignment of the same number. Each prefix has its
 * own model + table:
 *   QUO → Quotation::quotation_id
 *   INQ → Inquiry::inquiry_code
 *
 * NOTE: this is a refactor of the original
 * QuotationService::generatePoCode(). The QuotationService now
 * delegates here; existing behavior for QUO codes is preserved.
 */
class PoCodeGenerator
{
    /**
     * Generate the next code for the given prefix.
     *
     * MUST be called inside a transaction (the lockForUpdate() row
     * lock is only meaningful with surrounding transaction state).
     * Callers in this codebase already do that — both
     * QuotationService::store() and InquiryService::create() wrap in
     * DB::transaction().
     */
    public function generate(string $prefix = 'QUO'): string
    {
        $year = now()->year;

        $lastCode = $this->lastCodeForPrefix($prefix, $year);

        $lastNumber = 0;
        if (is_string($lastCode) && preg_match('/-(\d+)$/', $lastCode, $matches)) {
            $lastNumber = (int) $matches[1];
        }

        $nextNumber = $lastNumber + 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $nextNumber);
    }

    /**
     * Look up the most recent code for a given prefix in the given year.
     * Centralizes the table/column mapping per prefix.
     */
    protected function lastCodeForPrefix(string $prefix, int $year): ?string
    {
        switch ($prefix) {
            case 'QUO':
                return Quotation::whereYear('created_at', $year)
                    ->lockForUpdate()
                    ->orderByDesc('id')
                    ->value('quotation_id');

            case 'INQ':
                return Inquiry::whereYear('created_at', $year)
                    ->lockForUpdate()
                    ->orderByDesc('id')
                    ->value('inquiry_code');

            default:
                // Defensive fallback — unknown prefix gets year-restarted
                // numbering starting at 1. New prefixes should add a
                // case here.
                return null;
        }
    }
}
