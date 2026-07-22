<?php

namespace App\Support\Wavex;

use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

final class WavexContactSheetReader
{
    /**
     * @return array{0: int, 1: int} phone column index, name column index
     */
    public static function detectColumnIndices(array $headerRow): array
    {
        $phoneIdx = 0;
        $nameIdx = 1;
        foreach ($headerRow as $i => $col) {
            $l = strtolower(trim((string) $col));
            if (in_array($l, ['phone', 'mobile', 'tel', 'رقم'], true)) {
                $phoneIdx = (int) $i;
            }
            if (in_array($l, ['name', 'الاسم', 'display'], true)) {
                $nameIdx = (int) $i;
            }
        }

        return [$phoneIdx, $nameIdx];
    }

    /**
     * @return list<string>
     */
    public static function rowToStringArray(Row $row): array
    {
        $out = [];
        foreach ($row->getCells() as $cell) {
            $v = $cell->getValue();
            if ($v instanceof DateTimeInterface) {
                $out[] = $v->format('Y-m-d H:i:s');
            } elseif (is_int($v) || is_float($v)) {
                $out[] = (string) $v;
            } else {
                $out[] = $v !== null ? (string) $v : '';
            }
        }

        return $out;
    }

    /**
     * Read phone/name pairs from CSV, TXT, or XLSX (first sheet). First row is treated as header.
     *
     * @return \Generator<int, array{phone: string, display_name: ?string}>
     */
    public static function iterateRows(string $absolutePath, ?string $extension = null): \Generator
    {
        $ext = $extension ? strtolower($extension) : strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $reader = match ($ext) {
            'csv', 'txt' => new CsvReader,
            'xlsx' => new XlsxReader,
            default => throw new \InvalidArgumentException('Unsupported file type. Use csv, txt, or xlsx.'),
        };

        $reader->open($absolutePath);
        try {
            $headerDone = false;
            $phoneIdx = 0;
            $nameIdx = 1;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = self::rowToStringArray($row);
                    if ($cells === []) {
                        continue;
                    }
                    if (! $headerDone) {
                        [$phoneIdx, $nameIdx] = self::detectColumnIndices($cells);
                        $headerDone = true;

                        continue;
                    }
                    $phone = trim((string) ($cells[$phoneIdx] ?? ''));
                    if ($phone === '') {
                        continue;
                    }
                    $rawName = isset($cells[$nameIdx]) ? trim((string) $cells[$nameIdx]) : '';

                    yield [
                        'phone' => $phone,
                        'display_name' => $rawName !== '' ? $rawName : null,
                    ];
                }

                break;
            }
        } finally {
            $reader->close();
        }
    }
}
