<?php

declare(strict_types=1);

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Phase D6 — render a report payload as a PDF.
 *
 * Simple + robust by design: report title, the date window, then one table
 * per payload section (the same sections the CSV/XLSX exporters walk) with
 * numeric cells right-aligned. Rendered through the exports/report Blade
 * view ({{ }} escaping only — cell data is user-originated) and dompdf.
 *
 * Library note: dompdf needs only ext-dom + ext-mbstring (the container
 * lacks gd/zip/intl, ruling out mPDF). Remote resources are disabled and
 * chroot is pinned — the view embeds no images (gd is absent anyway).
 *
 * Fidelity limit: dompdf has no Arabic text shaping/bidi — Arabic names
 * render as standalone (disconnected) glyphs via the bundled DejaVu Sans
 * font rather than crashing. Swapping to mPDF once the image gains gd
 * would fix shaping.
 */
final class ReportPdfExporter
{
    public function __construct(
        private readonly ReportSectionWalker $walker = new ReportSectionWalker(),
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toPdf(array $payload, string $title, string $dateFrom, string $dateTo): string
    {
        $html = view('exports.report', [
            'title' => $title,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'sections' => $this->walker->sections($payload),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', storage_path());
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
