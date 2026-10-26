<?php

namespace App\Support;

use ZipArchive;

/** Rejects XLSX archives that are invalid or expand beyond safe limits before a reader opens them. */
final class XlsxArchiveGuard
{
    public const MAX_ENTRIES = 1000;

    /** @return string|null an error message, or null when the archive is safe to read */
    public static function problem(string $path, int $maxExpandedBytes = 40 * 1024 * 1024): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return 'The XLSX archive is invalid.';
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                return 'The workbook contains too many archive entries.';
            }
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    return 'The XLSX archive is invalid.';
                }
                $size += $entry['size'];
                if ($size > $maxExpandedBytes) {
                    return 'The expanded workbook is larger than '.intdiv($maxExpandedBytes, 1024 * 1024).' MiB.';
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }
}
