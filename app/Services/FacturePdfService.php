<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FacturePdfService
{
    private const PAGE_W = 595.28;

    private const PAGE_H = 841.89;

    private const LEFT = 50.0;

    private const RIGHT = 545.28;

    private const DARK = '0.13 0.15 0.19';

    private const GRAY = '0.42 0.45 0.50';

    private const GREEN = '0.09 0.50 0.30';

    private const AMBER = '0.71 0.45 0.05';

    private const RED = '0.86 0.15 0.15';

    private const HELVETICA_WIDTHS = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
        40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
        56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
        64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
        80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
        96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
        104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556,
        111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556,
        118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260,
        125 => 334, 126 => 584, 128 => 556, 151 => 1000, 176 => 400,
    ];

    private array $ops = [];

    public static function download(string $filename, array $facture): BinaryFileResponse
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $filename) ?: 'facture';

        $path = tempnam(sys_get_temp_dir(), 'facture_');
        file_put_contents($path, (new self)->build($facture));

        return response()->download($path, $safe.'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    private function build(array $facture): string
    {
        $this->layout($facture);
        $stream = implode("\n", $this->ops);

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    private function layout(array $f): void
    {
        $school = $f['school'];

        $this->text(self::LEFT, 64, 15, 'F2', $school['name'] ?? '');
        $y = 82;
        foreach ($this->schoolLines($f) as $line) {
            $this->text(self::LEFT, $y, 9, 'F1', $line, self::GRAY);
            $y += 13;
        }

        $this->textRight(self::RIGHT, 64, 19, 'F2', 'FACTURE');
        $this->textRight(self::RIGHT, 84, 9.5, 'F1', 'N° '.$f['numero'], self::GRAY);
        $this->textRight(self::RIGHT, 97, 9.5, 'F1', 'Date d\'émission : '.$f['date'], self::GRAY);
        $this->textRight(self::RIGHT, 110, 9.5, 'F1', 'Année scolaire : '.$f['year_label'], self::GRAY);

        $this->text(self::LEFT, 172, 8, 'F2', 'FACTURÉ À', self::GRAY);
        $this->text(self::LEFT, 189, 10.5, 'F2', implode(', ', $f['client']['names']));
        $cy = 203;
        foreach ($this->clientAddressLines($f['client']) as $line) {
            $this->text(self::LEFT, $cy, 9.5, 'F1', $line, self::GRAY);
            $cy += 13;
        }

        $top = 256;
        $this->rect(self::LEFT, $top, self::RIGHT - self::LEFT, 24, '0.95 0.96 0.97');
        $this->text(self::LEFT + 10, $top + 16, 9, 'F2', 'Désignation');
        $this->textRight(self::RIGHT - 10, $top + 16, 9, 'F2', 'Montant');

        $rowY = $top + 44;
        $this->text(self::LEFT + 10, $rowY, 10, 'F1', 'Frais de scolarité — Année scolaire '.$f['year_label']);
        if ($f['nombre_eleves'] > 0) {
            $pluriel = $f['nombre_eleves'] > 1 ? 's' : '';
            $this->text(self::LEFT + 10, $rowY + 13, 8.5, 'F1', $f['nombre_eleves'].' élève'.$pluriel.' inscrit'.$pluriel, self::GRAY);
        }
        $this->textRight(self::RIGHT - 10, $rowY, 10, 'F1', self::euros($f['total']));
        $this->line(self::LEFT, $rowY + 24, self::RIGHT, $rowY + 24);

        $ty = $rowY + 48;
        foreach ($this->totalRows($f) as [$label, $value, $font, $color]) {
            $this->text(330, $ty, 10, $font, $label, $color);
            $this->textRight(self::RIGHT - 10, $ty, 10, $font, $value, $color);
            $ty += 17;
        }

        $bandY = $ty + 14;
        if ($f['acquittee']) {
            $this->rect(self::LEFT, $bandY, self::RIGHT - self::LEFT, 34, '0.91 0.96 0.92');
            $label = 'FACTURE ACQUITTÉE'.($f['acquittee_le'] ? ' LE '.$f['acquittee_le'] : '');
            $this->textCenter($bandY + 21, 11, 'F2', $label, self::GREEN);
        } else {
            $this->rect(self::LEFT, $bandY, self::RIGHT - self::LEFT, 34, '0.99 0.95 0.88');
            $this->textCenter($bandY + 21, 11, 'F2', 'Facture non acquittée — reste à payer : '.self::euros($f['reste']), self::AMBER);
        }

        $this->line(self::LEFT, 778, self::RIGHT, 778);
        if (! empty($f['vat_mention'])) {
            $this->textCenter(792, 8.5, 'F1', $f['vat_mention'], self::GRAY);
        }
    }

    private function schoolLines(array $f): array
    {
        $school = $f['school'];
        $lines = preg_split('/\r\n|\r|\n/', trim((string) ($school['address'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $lines[] = trim(($school['zipcode'] ?? '').' '.($school['city'] ?? ''));
        $lines[] = implode(' · ', array_filter([$school['email'] ?? null, $school['phone'] ?? null]));
        if (! empty($school['siret'])) {
            $lines[] = 'SIRET : '.$school['siret'];
        }
        if ($f['assujetti'] && ! empty($school['vat_number'])) {
            $lines[] = 'N° TVA : '.$school['vat_number'];
        }

        return array_values(array_filter($lines));
    }

    private function clientAddressLines(array $client): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) ($client['address'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $lines[] = trim(($client['zipcode'] ?? '').' '.($client['city'] ?? ''));

        return array_values(array_filter($lines));
    }

    private function totalRows(array $f): array
    {
        $rows = [];
        if ($f['assujetti']) {
            $ht = $f['total'] / 1.2;
            $rows[] = ['Total HT', self::euros($ht, true), 'F1', self::GRAY];
            $rows[] = ['TVA (20 %)', self::euros($f['total'] - $ht, true), 'F1', self::GRAY];
            $rows[] = ['Total TTC', self::euros($f['total']), 'F2', self::DARK];
        } else {
            $rows[] = ['Montant total', self::euros($f['total']), 'F2', self::DARK];
        }
        $rows[] = ['Montant réglé', self::euros($f['paye']), 'F1', self::GRAY];
        if (! empty($f['exonere'])) {
            $rows[] = ['Exonéré', self::euros($f['exonere']), 'F1', self::GRAY];
        }
        $rows[] = ['Reste à payer', self::euros($f['reste']), 'F2', $f['reste'] > 0 ? self::RED : self::GREEN];

        return $rows;
    }

    private function text(float $x, float $yTop, float $size, string $font, string $value, string $color = self::DARK): void
    {
        $this->ops[] = sprintf(
            'BT /%s %.2F Tf %s rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
            $font,
            $size,
            $color,
            $x,
            self::PAGE_H - $yTop,
            self::escape($value)
        );
    }

    private function textRight(float $xRight, float $yTop, float $size, string $font, string $value, string $color = self::DARK): void
    {
        $this->text($xRight - self::width($value, $size), $yTop, $size, $font, $value, $color);
    }

    private function textCenter(float $yTop, float $size, string $font, string $value, string $color = self::DARK): void
    {
        $this->text((self::PAGE_W - self::width($value, $size)) / 2, $yTop, $size, $font, $value, $color);
    }

    private function rect(float $x, float $yTop, float $w, float $h, string $color): void
    {
        $this->ops[] = sprintf('%s rg %.2F %.2F %.2F %.2F re f', $color, $x, self::PAGE_H - $yTop - $h, $w, $h);
    }

    private function line(float $x1, float $yTop1, float $x2, float $yTop2): void
    {
        $this->ops[] = sprintf(
            '0.85 0.87 0.89 RG 0.7 w %.2F %.2F m %.2F %.2F l S',
            $x1,
            self::PAGE_H - $yTop1,
            $x2,
            self::PAGE_H - $yTop2
        );
    }

    private static function euros(int|float $value, bool $cents = false): string
    {
        return number_format($value, $cents ? 2 : 0, ',', ' ').' €';
    }

    private static function width(string $value, float $size): float
    {
        $units = 0;
        foreach (str_split(self::toWinAnsi($value)) as $char) {
            $units += self::HELVETICA_WIDTHS[ord($char)] ?? 556;
        }

        return $units * $size / 1000;
    }

    private static function escape(string $value): string
    {
        return strtr(self::toWinAnsi($value), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function toWinAnsi(string $value): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $value);

        return preg_replace('/[\x00-\x1F]/', '', $converted === false ? '' : $converted);
    }
}
