<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a SiteReportService::generate() payload as a PDF, using the same
 * data the HTML mail is built from so the two never drift.
 *
 * dompdf only understands CSS 2.1-ish rules (table layout, no flex/grid) —
 * see resources/views/reports/site-report-pdf.blade.php.
 */
class SiteReportPdf
{
    public function render(array $report): string
    {
        return Pdf::loadView('reports.site-report-pdf', ['report' => $report])
            ->setPaper('a4')
            ->output();
    }
}
