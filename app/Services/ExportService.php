<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ExportService
{
    public static function xlsx(string $filename, array $headers, iterable $rows): BinaryFileResponse
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $filename) ?: 'export';

        $allRows = [$headers];
        foreach ($rows as $row) {
            $allRows[] = is_array($row) ? $row : iterator_to_array($row);
        }

        $path = tempnam(sys_get_temp_dir(), 'export_');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
        $zip->addFromString('_rels/.rels', self::RELS);
        $zip->addFromString('xl/workbook.xml', self::WORKBOOK);
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
        $zip->addFromString('xl/styles.xml', self::STYLES);
        $zip->addFromString('xl/worksheets/sheet1.xml', self::buildSheetXml($allRows));
        $zip->close();

        return response()->download($path, $safe.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    private static function buildSheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .self::buildColsXml($rows)
            .'<sheetData>';

        $r = 0;
        foreach ($rows as $row) {
            $r++;
            if ($r === 1) {
                $xml .= '<row r="1" ht="24" customHeight="1">';
                $style = ' s="1"';
            } else {
                $xml .= '<row r="'.$r.'">';
                $style = $r % 2 === 1 ? ' s="3"' : ' s="2"';
            }
            $c = 0;
            foreach ($row as $value) {
                $c++;
                $ref = self::columnLetter($c).$r;
                if (is_int($value) || (is_float($value) && is_finite($value))) {
                    $xml .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'
                        .self::escape((string) ($value ?? '')).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        $colCount = count($rows[0] ?? []);
        if ($colCount > 0) {
            $xml .= '<autoFilter ref="A1:'.self::columnLetter($colCount).'1"/>';
        }

        return $xml.'</worksheet>';
    }

    private static function buildColsXml(array $rows): string
    {
        $widths = [];
        foreach ($rows as $row) {
            $c = 0;
            foreach ($row as $value) {
                $c++;
                $longest = max(array_map('mb_strlen', preg_split('/\r\n|\r|\n/', (string) ($value ?? ''))));
                $widths[$c] = max($widths[$c] ?? 0, $longest);
            }
        }
        if (! $widths) {
            return '';
        }

        $xml = '<cols>';
        foreach ($widths as $col => $longest) {
            $width = min(max($longest + 3, 9), 55);
            $xml .= '<col min="'.$col.'" max="'.$col.'" width="'.sprintf('%.2F', $width).'" customWidth="1"/>';
        }

        return $xml.'</cols>';
    }

    private static function columnLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    private static function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private const CONTENT_TYPES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';

    private const RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

    private const WORKBOOK = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>';

    private const WORKBOOK_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    private const STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<fonts count="2">'
        .'<font><sz val="11"/><color rgb="FF222222"/><name val="Calibri"/></font>'
        .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        .'</fonts>'
        .'<fills count="4">'
        .'<fill><patternFill patternType="none"/></fill>'
        .'<fill><patternFill patternType="gray125"/></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF222222"/><bgColor rgb="FF222222"/></patternFill></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FFF6F8FB"/><bgColor rgb="FFF6F8FB"/></patternFill></fill>'
        .'</fills>'
        .'<borders count="2">'
        .'<border><left/><right/><top/><bottom/><diagonal/></border>'
        .'<border><left/><right/><top/><bottom style="thin"><color rgb="FFE6EFF5"/></bottom><diagonal/></border>'
        .'</borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="4">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
        .'<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'
        .'</cellXfs>'
        .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        .'</styleSheet>';
}
