<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/** Renderiza únicamente plantillas Blade propias con recursos locales y sin código ejecutable. */
class ReportPdfService
{
    public function write(string $path, array $data, bool $individual = false): int
    {
        $work = storage_path('app/private/report-pdf');
        if (!is_dir($work) && !mkdir($work, 0700, true) && !is_dir($work)) {
            throw new RuntimeException('No fue posible preparar el motor PDF.');
        }
        $options = new Options([
            'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans', 'tempDir' => $work, 'fontCache' => $work,
            'chroot' => [$work, base_path('vendor/dompdf/dompdf/lib/fonts')],
        ]);
        $pdf = new Dompdf($options);
        $data['logoDataUri'] = 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('images/sysforest-logo.jpeg')));
        $pdf->loadHtml(view($individual ? 'reports.pdf.tree' : 'reports.pdf.table', $data)->render(), 'UTF-8');
        $pdf->setPaper('A4', $individual ? 'portrait' : 'landscape');
        $pdf->render();
        $pdf->addInfo('Title', $data['title']);
        $pdf->addInfo('Author', 'SYSFOREST');
        $pdf->addInfo('Creator', 'SYSFOREST');
        $canvas = $pdf->getCanvas();
        $canvas->page_text(
            $individual ? 410 : 650,
            $individual ? 810 : 565,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal'),
            8,
            [0.35, 0.4, 0.35]
        );
        if (file_put_contents($path, $pdf->output()) === false) {
            throw new RuntimeException('No fue posible completar el PDF.');
        }

        return $individual ? 1 : count($data['rows']);
    }
}
