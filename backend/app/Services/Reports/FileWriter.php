<?php

namespace App\Services\Reports;

use RuntimeException;
use ZipArchive;

/**
 * Formatos de exportación (PROC-061): CSV, XLSX y PDF tabular sin dependencias externas. El diseño definitivo de las
 * plantillas XLSX/PDF (logo, encabezado, pie) queda por definir con negocio (spec 09 §12-15).
 */
final class FileWriter
{
    /** @param list<string> $header @param list<list<scalar|null>> $rows */
    public static function write(string $format, string $title, array $header, array $rows): string
    {
        return match ($format) {
            'XLSX' => self::xlsx($title, $header, $rows),
            'PDF' => self::pdf($title, $header, $rows),
            default => self::csv($header, $rows),
        };
    }

    public static function csv(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) {
            // Evita inyección de fórmulas al abrir el CSV en una hoja de cálculo.
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row), ',', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    public static function xlsx(string $title, array $header, array $rows): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + (($i - 1) % 26)).$s;
            }

            return $s;
        };
        $sheet = '';
        foreach (array_merge([$header], $rows) as $r => $row) {
            $cells = '';
            foreach (array_values($row) as $c => $v) {
                $ref = $col($c).($r + 1);
                $cells .= is_int($v) || is_float($v)
                    ? "<c r=\"{$ref}\"><v>{$v}</v></c>"
                    : "<c r=\"{$ref}\" t=\"inlineStr\"".($r === 0 ? ' s="1"' : '').'><is><t>'.$esc($v ?? '').'</t></is></c>';
            }
            $sheet .= '<row r="'.($r + 1)."\">{$cells}</row>";
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo generar el XLSX');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$esc(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title), 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** PDF tabular A4 horizontal con Helvetica (WinAnsi), paginado. */
    public static function pdf(string $title, array $header, array $rows): string
    {
        $enc = fn ($s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $s));
        $widths = array_fill(0, max(1, count($header)), (int) floor(780 / max(1, count($header))));
        $clip = fn ($v, $w) => mb_strimwidth((string) $v, 0, max(4, (int) ($w / 4.6)), '…');
        $line = function (array $cells, float $y, bool $bold) use ($widths, $enc, $clip) {
            $x = 30;
            $s = '';
            foreach (array_values($cells) as $i => $v) {
                $s .= 'BT /'.($bold ? 'F2' : 'F1')." 8 Tf {$x} {$y} Td (".$enc($clip($v ?? '', $widths[$i] ?? 60)).') Tj ET'."\n";
                $x += $widths[$i] ?? 60;
            }

            return $s;
        };
        $pages = [];
        $chunks = array_chunk($rows, 38) ?: [[]];
        foreach ($chunks as $n => $chunk) {
            $s = 'BT /F2 13 Tf 30 560 Td ('.$enc($title).') Tj ET'."\n".'BT /F1 8 Tf 30 546 Td ('.$enc('Generado '.now()->format('d/m/Y H:i').' · página '.($n + 1).' de '.count($chunks)).') Tj ET'."\n";
            $s .= $line($header, 525, true)."0.6 w 30 520 m 812 520 l S\n";
            $y = 507;
            foreach ($chunk as $row) {
                $s .= $line($row, $y, false);
                $y -= 12.5;
            }
            $pages[] = $s;
        }
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach ($pages as $content) {
            $objects[] = '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream";
            $contentId = count($objects);
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $kids[] = count($objects).' 0 R';
        }
        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$obj}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
