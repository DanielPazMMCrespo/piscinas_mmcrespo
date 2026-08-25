<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyRecord;
use App\Models\Pool;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

class DgsPdfReportService
{
    /**
     * Generates a strict legal DGS-compliant sanitary logbook PDF for a given pool and month.
     */
    public function generateMonthlyReport(Pool $pool, Carbon $date)
    {
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        // 1. Fetch daily records for the given month
        $records = DailyRecord::query()
            ->with('user')
            ->where('pool_id', $pool->id)
            ->whereBetween('registado_em', [$startOfMonth, $endOfMonth])
            ->orderBy('registado_em', 'asc')
            ->get();

        // 2. Aggregate data by day (group by date)
        $groupedRecords = $records->groupBy(fn ($r) => $r->registado_em->format('Y-m-d'));

        // 3. Render the PDF
        $pdf = Pdf::loadView('pdf.dgs-report', [
            'pool' => $pool,
            'month' => $startOfMonth->translatedFormat('F Y'),
            'date' => $date,
            'groupedRecords' => $groupedRecords,
            'startOfMonth' => $startOfMonth,
            'endOfMonth' => $endOfMonth,
        ]);

        $pdf->setPaper('a4', 'landscape');

        return $pdf;
    }
}
