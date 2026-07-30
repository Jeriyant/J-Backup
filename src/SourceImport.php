<?php

declare(strict_types=1);

namespace JBackup;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class SourceImport
{
    private const MAX_ROWS = 1000;

    public static function read(string $file, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return match ($extension) {
            'csv' => self::readCsv($file),
            'xlsx' => self::readXlsx($file),
            default => throw new RuntimeException(
                'Format file harus .xlsx atau .csv.'
            ),
        };
    }

    public static function normalize(array $rows): array
    {
        if (count($rows) < 2) {
            throw new RuntimeException(
                'File impor harus memiliki header dan minimal satu baris data.'
            );
        }
        $headers = array_map(
            static fn (mixed $value): string => self::header((string) $value),
            array_shift($rows)
        );
        foreach (['nama_sumber', 'path_sumber'] as $required) {
            if (!in_array($required, $headers, true)) {
                throw new RuntimeException(
                    "Kolom wajib {$required} tidak ditemukan."
                );
            }
        }

        $result = [];
        foreach ($rows as $index => $row) {
            $values = [];
            foreach ($headers as $column => $header) {
                if ($header !== '') {
                    $values[$header] = trim((string) ($row[$column] ?? ''));
                }
            }
            if (implode('', $values) === '') {
                continue;
            }
            $line = $index + 2;
            $name = $values['nama_sumber'] ?? '';
            $sourceCode = $values['kode_sumber']
                ?? $values['kode']
                ?? $values['source_code']
                ?? '';
            $paths = preg_split(
                '/\r?\n|\s*\|\s*/',
                $values['path_sumber'] ?? '',
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($name === '' || $paths === false || $paths === []) {
                throw new RuntimeException(
                    "Baris {$line}: nama_sumber dan path_sumber wajib diisi."
                );
            }
            $result[] = [
                '_row' => $line,
                'source_code' => $sourceCode !== ''
                    ? $sourceCode
                    : Database::sourceCodeFromName($name),
                'name' => $name,
                'archive_mode' => self::archiveMode(
                    $values['mode_arsip'] ?? ''
                ),
                'output_subdirectory' => $values['subfolder_hasil'] ?? '',
                'paths' => array_values(array_map('trim', $paths)),
                'enabled' => self::enabled($values['aktif'] ?? ''),
            ];
            if (count($result) > self::MAX_ROWS) {
                throw new RuntimeException(
                    'File impor melebihi batas 1.000 sumber.'
                );
            }
        }
        if ($result === []) {
            throw new RuntimeException('File impor tidak memiliki baris data.');
        }
        return $result;
    }

    private static function readCsv(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('File CSV tidak dapat dibaca.');
        }
        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                return [];
            }
            $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? '';
            $delimiters = [',', ';', "\t"];
            usort(
                $delimiters,
                static fn (string $left, string $right): int =>
                    substr_count($firstLine, $right)
                    <=> substr_count($firstLine, $left)
            );
            $delimiter = $delimiters[0];
            rewind($handle);
            $rows = [];
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($rows === [] && isset($row[0])) {
                    $row[0] = preg_replace(
                        '/^\xEF\xBB\xBF/',
                        '',
                        (string) $row[0]
                    ) ?? '';
                }
                $rows[] = $row;
                if (count($rows) > self::MAX_ROWS + 1) {
                    throw new RuntimeException(
                        'File impor melebihi batas 1.000 sumber.'
                    );
                }
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private static function readXlsx(string $file): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Extension PHP Zip belum tersedia. Jalankan kembali installer.'
            );
        }
        if (!function_exists('simplexml_load_string')) {
            throw new RuntimeException(
                'Extension PHP XML belum tersedia. Jalankan kembali installer.'
            );
        }
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new RuntimeException('Workbook Excel tidak dapat dibuka.');
        }
        try {
            $sharedStrings = self::sharedStrings($zip);
            $worksheet = self::zipEntry(
                $zip,
                self::firstWorksheetPath($zip)
            );
            if ($worksheet === false) {
                throw new RuntimeException(
                    'Worksheet pertama tidak ditemukan dalam file Excel.'
                );
            }
            $xml = self::xml($worksheet, 'Worksheet Excel rusak.');
            $xml->registerXPathNamespace(
                'x',
                'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
            );
            $rows = [];
            foreach ($xml->xpath('//x:sheetData/x:row') ?: [] as $row) {
                $columns = [];
                foreach ($row->c as $cell) {
                    $reference = (string) $cell['r'];
                    preg_match('/^[A-Z]+/', $reference, $matches);
                    $column = self::columnIndex($matches[0] ?? 'A');
                    $type = (string) $cell['t'];
                    $value = '';
                    if ($type === 'inlineStr') {
                        $value = implode(
                            '',
                            array_map(
                                static fn (SimpleXMLElement $text): string =>
                                    (string) $text,
                                $cell->xpath('.//*[local-name()="t"]') ?: []
                            )
                        );
                    } else {
                        $raw = (string) ($cell->v ?? '');
                        $value = $type === 's'
                            ? ($sharedStrings[(int) $raw] ?? '')
                            : $raw;
                    }
                    $columns[$column] = $value;
                }
                if ($columns !== []) {
                    $maximum = max(array_keys($columns));
                    $rows[] = array_map(
                        static fn (int $column): string =>
                            (string) ($columns[$column] ?? ''),
                        range(0, $maximum)
                    );
                }
                if (count($rows) > self::MAX_ROWS + 1) {
                    throw new RuntimeException(
                        'File impor melebihi batas 1.000 sumber.'
                    );
                }
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        $content = self::zipEntry($zip, 'xl/sharedStrings.xml', false);
        if ($content === false) {
            return [];
        }
        $xml = self::xml($content, 'Daftar teks Excel rusak.');
        $xml->registerXPathNamespace(
            'x',
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
        );
        $strings = [];
        foreach ($xml->xpath('//x:si') ?: [] as $item) {
            $strings[] = implode(
                '',
                array_map(
                    static fn (SimpleXMLElement $text): string => (string) $text,
                    $item->xpath('.//*[local-name()="t"]') ?: []
                )
            );
        }
        return $strings;
    }

    private static function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbookContent = self::zipEntry(
            $zip,
            'xl/workbook.xml',
            false
        );
        $relationsContent = self::zipEntry(
            $zip,
            'xl/_rels/workbook.xml.rels',
            false
        );
        if ($workbookContent === false || $relationsContent === false) {
            return 'xl/worksheets/sheet1.xml';
        }
        $workbook = self::xml(
            $workbookContent,
            'Struktur workbook Excel rusak.'
        );
        $workbook->registerXPathNamespace(
            'x',
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
        );
        $sheets = $workbook->xpath('//x:sheets/x:sheet') ?: [];
        if ($sheets === []) {
            return 'xl/worksheets/sheet1.xml';
        }
        $attributes = $sheets[0]->attributes(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
        );
        $relationId = (string) ($attributes['id'] ?? '');
        if ($relationId === '') {
            return 'xl/worksheets/sheet1.xml';
        }

        $relations = self::xml(
            $relationsContent,
            'Relasi workbook Excel rusak.'
        );
        $relations->registerXPathNamespace(
            'r',
            'http://schemas.openxmlformats.org/package/2006/relationships'
        );
        foreach ($relations->xpath('//r:Relationship') ?: [] as $relation) {
            if ((string) $relation['Id'] !== $relationId) {
                continue;
            }
            $target = str_replace('\\', '/', (string) $relation['Target']);
            if (str_starts_with($target, '/')) {
                return ltrim($target, '/');
            }
            return self::normalizeArchivePath('xl/' . $target);
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function normalizeArchivePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private static function zipEntry(
        ZipArchive $zip,
        string $name,
        bool $required = true
    ): string|false {
        $statistics = $zip->statName($name);
        if ($statistics === false) {
            if ($required) {
                throw new RuntimeException(
                    "Komponen {$name} tidak ditemukan dalam file Excel."
                );
            }
            return false;
        }
        if ((int) ($statistics['size'] ?? 0) > 20 * 1024 * 1024) {
            throw new RuntimeException(
                'Isi workbook Excel terlalu besar untuk diproses.'
            );
        }
        $content = $zip->getFromName($name);
        if ($content === false && $required) {
            throw new RuntimeException(
                "Komponen {$name} tidak dapat dibaca."
            );
        }
        return $content;
    }

    private static function xml(string $content, string $message): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string(
                $content,
                SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_COMPACT
            );
            if (!$xml instanceof SimpleXMLElement) {
                throw new RuntimeException($message);
            }
            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function header(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    private static function archiveMode(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['terpisah', 'separate', 'satu_per_path'], true)
            ? 'separate'
            : 'combined';
    }

    private static function enabled(string $value): bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return true;
        }
        return !in_array($value, ['0', 'tidak', 'no', 'false', 'nonaktif'], true);
    }

    private static function columnIndex(string $letters): int
    {
        $result = 0;
        foreach (str_split($letters) as $letter) {
            $result = $result * 26 + ord($letter) - 64;
        }
        return max(0, $result - 1);
    }
}
