<?php

declare(strict_types=1);

namespace JBackup;

use RuntimeException;
use ZipArchive;

final class SourceExport
{
    public static function create(array $sources): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Extension PHP Zip belum tersedia untuk membuat Excel.');
        }
        $file = tempnam(sys_get_temp_dir(), 'jbackup-sources-');
        if ($file === false) {
            throw new RuntimeException('File Excel sementara tidak dapat dibuat.');
        }
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
            @unlink($file);
            throw new RuntimeException('File Excel tidak dapat dibuat.');
        }
        try {
            $zip->addFromString('[Content_Types].xml', self::contentTypes());
            $zip->addFromString('_rels/.rels', self::rootRelations());
            $zip->addFromString('xl/workbook.xml', self::workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelations());
            $zip->addFromString('xl/styles.xml', self::styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($sources));
        } finally {
            $zip->close();
        }
        return $file;
    }

    private static function sheet(array $sources): string
    {
        $headers = ['Nama Sumber', 'Mode Arsip', 'Subfolder Hasil', 'Path Sumber', 'Aktif'];
        $rows = [self::row(1, $headers, 1, 22)];
        foreach (array_values($sources) as $index => $source) {
            $paths = array_map(static function (array $path): string {
                $value = (string) ($path['path'] ?? '');
                $alias = (string) ($path['alias'] ?? '');
                return $alias === basename(rtrim($value, '/')) ? $value : $alias . '=' . $value;
            }, (array) ($source['paths'] ?? []));
            $values = [
                (string) ($source['name'] ?? ''),
                ($source['archive_mode'] ?? 'combined') === 'separate' ? 'Terpisah' : 'Gabung',
                (string) ($source['output_subdirectory'] ?? ''),
                implode("\n", $paths),
                !empty($source['enabled']) ? 'Ya' : 'Tidak',
            ];
            $height = min(100, max(20, 16 * (count($paths) + 1)));
            $rows[] = self::row($index + 2, $values, 2, $height);
        }
        $lastRow = max(1, count($sources) + 1);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols><col min="1" max="1" width="28" customWidth="1"/><col min="2" max="2" width="18" customWidth="1"/><col min="3" max="3" width="25" customWidth="1"/><col min="4" max="4" width="72" customWidth="1"/><col min="5" max="5" width="12" customWidth="1"/></cols>'
            . '<sheetData>' . implode('', $rows) . '</sheetData>'
            . '<autoFilter ref="A1:E' . $lastRow . '"/>'
            . '</worksheet>';
    }

    private static function row(int $number, array $values, int $style, int $height): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $column = chr(65 + $index);
            $cells[] = '<c r="' . $column . $number . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">'
                . self::xml((string) $value) . '</t></is></c>';
        }
        return '<row r="' . $number . '" ht="' . $height . '" customHeight="1">' . implode('', $cells) . '</row>';
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sumber Backup" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function workbookRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF217346"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD9E2DC"/></left><right style="thin"><color rgb="FFD9E2DC"/></right><top style="thin"><color rgb="FFD9E2DC"/></top><bottom style="thin"><color rgb="FFD9E2DC"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
            . '</styleSheet>';
    }
}
