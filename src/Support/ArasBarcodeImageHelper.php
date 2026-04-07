<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Support;

final class ArasBarcodeImageHelper
{
    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public static function prepareResponse(array $response, string $integrationCode, ?string $saveDirectory = null): array
    {
        if (!empty($response['ZebraZpl'][0]) && is_string($response['ZebraZpl'][0])) {
            $normalizedZpl = self::normalizeZplOrientation($response['ZebraZpl'][0]);
            $response['ZebraZpl'][0] = $normalizedZpl;

            $renderedImage = self::zplToBase64Image($normalizedZpl);
            if ($renderedImage !== null) {
                $response['Images'] = [$renderedImage];
            }
        }

        if (!empty($response['Images']) && is_array($response['Images'])) {
            foreach ($response['Images'] as $index => $imageBase64) {
                if (is_string($imageBase64)) {
                    $response['Images'][$index] = self::normalizeImageOrientation($imageBase64);
                }
            }
        }

        $response['SavedFiles'] = self::saveImages($response['Images'] ?? [], $integrationCode, $saveDirectory);

        return $response;
    }

    /**
     * @param array<string,mixed> $response
     */
    public static function firstImageDataUri(array $response): ?string
    {
        $imageBase64 = $response['Images'][0] ?? null;
        if (!is_string($imageBase64) || $imageBase64 === '') {
            return null;
        }

        return 'data:image/png;base64,' . $imageBase64;
    }

    public static function normalizeZplOrientation(string $zpl): string
    {
        $zpl = trim($zpl);
        if ($zpl === '') {
            return $zpl;
        }

        $hasOrientation = preg_match('/\^PO([NRIB])/i', $zpl) === 1;
        $zpl = preg_replace('/\^PO([NRIB])/i', '^PON', $zpl) ?? $zpl;

        if (!$hasOrientation) {
            $zpl = preg_replace('/\^XA/i', '^XA^PON', $zpl, 1) ?? $zpl;
        }

        return $zpl;
    }

    public static function zplToBase64Image(string $zpl, int $dpmm = 8): ?string
    {
        $zpl = trim($zpl);
        if ($zpl === '') {
            return null;
        }

        [$widthInch, $heightInch] = self::zplLabelSize($zpl, $dpmm);
        $url = sprintf(
            'https://api.labelary.com/v1/printers/%ddpmm/labels/%sx%s/0/',
            $dpmm,
            rtrim(rtrim(number_format($widthInch, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($heightInch, 2, '.', ''), '0'), '.')
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $zpl,
            CURLOPT_HTTPHEADER => [
                'Accept: image/png',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $binary = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($binary) || $binary === '' || $httpCode >= 400) {
            return null;
        }

        return base64_encode($binary);
    }

    public static function zplToImageDataUri(string $zpl, int $dpmm = 8): ?string
    {
        $base64 = self::zplToBase64Image($zpl, $dpmm);
        if ($base64 === null) {
            return null;
        }

        return 'data:image/png;base64,' . $base64;
    }

    public static function normalizeImageOrientation(string $imageBase64): string
    {
        $binary = base64_decode($imageBase64, true);
        if ($binary === false || !function_exists('imagecreatefromstring')) {
            return $imageBase64;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return $imageBase64;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width >= $height) {
            imagedestroy($image);
            return $imageBase64;
        }

        $rotated = imagerotate($image, 90, 0);
        imagedestroy($image);

        if ($rotated === false) {
            return $imageBase64;
        }

        ob_start();
        imagepng($rotated);
        $rotatedBinary = ob_get_clean();
        imagedestroy($rotated);

        if (!is_string($rotatedBinary) || $rotatedBinary === '') {
            return $imageBase64;
        }

        return base64_encode($rotatedBinary);
    }

    public static function zplLabelSize(string $zpl, int $dpmm = 8): array
    {
        $widthDots = 799;
        $heightDots = 799;

        if (preg_match('/\^PW(\d+)/i', $zpl, $widthMatch)) {
            $widthDots = max(1, (int) $widthMatch[1]);
        }

        if (preg_match('/\^LL(\d+)/i', $zpl, $heightMatch)) {
            $heightDots = max(1, (int) $heightMatch[1]);
        }

        $divisor = max(1, $dpmm) * 25.4;
        $widthInch = max(1.0, $widthDots / $divisor);
        $heightInch = max(1.0, $heightDots / $divisor);

        return [$widthInch, $heightInch];
    }

    /**
     * @param mixed $images
     * @return array<int,string>
     */
    private static function saveImages(mixed $images, string $integrationCode, ?string $saveDirectory): array
    {
        if (!is_array($images) || $saveDirectory === null || !is_dir($saveDirectory)) {
            return [];
        }

        $savedFiles = [];

        foreach ($images as $imageBase64) {
            if (!is_string($imageBase64) || $imageBase64 === '') {
                continue;
            }

            $binary = base64_decode($imageBase64, true);
            if ($binary === false) {
                continue;
            }

            $filePath = rtrim($saveDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $integrationCode . '.png';
            if (@file_put_contents($filePath, $binary) !== false) {
                $savedFiles[] = $filePath;
            }
        }

        return $savedFiles;
    }
}
