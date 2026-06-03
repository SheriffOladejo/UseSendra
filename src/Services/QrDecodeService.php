<?php

namespace App\Services;

use Zxing\QrReader;

final class QrDecodeService
{
    /**
     * Decode first QR payload from an image file, or null if none / error.
     */
    public function decodeFile(string $absolutePath): ?string
    {
        if (!is_readable($absolutePath)) {
            return null;
        }

        try {
            $reader = new QrReader($absolutePath, QrReader::SOURCE_TYPE_FILE);
            $text = $reader->text();
            if (is_string($text) && $text !== '') {
                return $text;
            }
        } catch (\Throwable $e) {
            error_log('QrDecodeService: ' . $e->getMessage());
        }

        return null;
    }
}
