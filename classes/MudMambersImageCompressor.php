<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;

/** Resize + recompress activity feed images (GD). */
final class MudMambersImageCompressor
{
    public static function isEnabled(Grav $grav): bool
    {
        return (bool) MudMambersConfig::get($grav, 'activity_image_compress_enabled', true)
            && function_exists('imagecreatefromjpeg')
            && function_exists('imagejpeg');
    }

    public static function maxEdgePx(Grav $grav): int
    {
        return max(800, min(4096, (int) MudMambersConfig::get($grav, 'activity_image_max_edge_px', 2048)));
    }

    public static function jpegQuality(Grav $grav): int
    {
        return max(50, min(92, (int) MudMambersConfig::get($grav, 'activity_image_jpeg_quality', 82)));
    }

    public static function maxIngestBytes(Grav $grav): int
    {
        if (!self::isEnabled($grav)) {
            return MudMambersActivity::maxImageBytes($grav);
        }

        $mb = max(1, (int) MudMambersConfig::get($grav, 'activity_upload_max_mb', 15));

        return $mb * 1048576;
    }

    /** @return string|null New file extension (jpg|png|webp|gif) or null when untouched/failed. */
    public static function optimize(Grav $grav, string $path, string $mime): ?string
    {
        if (!self::isEnabled($grav) || !is_file($path)) {
            return self::extensionFromMime($mime);
        }

        if ($mime === 'image/gif') {
            return 'gif';
        }

        $image = self::loadImage($path, $mime);
        if ($image === null) {
            return self::extensionFromMime($mime);
        }

        $image = self::resizeIfNeeded($image, self::maxEdgePx($grav));
        $targetExt = self::targetExtension($image, $mime);
        $quality = self::jpegQuality($grav);
        $finalPath = self::siblingPath($path, $targetExt);

        $saved = self::saveImage($image, $finalPath, $targetExt, $quality);
        imagedestroy($image);

        if (!$saved) {
            return self::extensionFromMime($mime);
        }

        if ($finalPath !== $path && is_file($path)) {
            @unlink($path);
        }

        $maxStored = MudMambersActivity::maxImageBytes($grav);
        $edge = self::maxEdgePx($grav);
        $loadMime = $targetExt === 'png' ? 'image/png' : ($targetExt === 'webp' ? 'image/webp' : 'image/jpeg');

        while (is_file($finalPath) && filesize($finalPath) > $maxStored && $quality > 58) {
            $quality -= 8;
            $retry = self::loadImage($finalPath, $loadMime);
            if ($retry === null) {
                break;
            }
            self::saveImage($retry, $finalPath, $targetExt, $quality);
            imagedestroy($retry);
        }

        while (is_file($finalPath) && filesize($finalPath) > $maxStored && $edge > 960) {
            $edge = (int) ($edge * 0.85);
            $retry = self::loadImage($finalPath, $loadMime);
            if ($retry === null) {
                break;
            }
            $retry = self::resizeIfNeeded($retry, $edge);
            self::saveImage($retry, $finalPath, $targetExt, max(58, $quality - 6));
            imagedestroy($retry);
        }

        return $targetExt;
    }

    private static function siblingPath(string $path, string $ext): string
    {
        return preg_replace('/\.[^.]+$/', '.' . $ext, $path) ?? ($path . '.' . $ext);
    }

    private static function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    /** @return \GdImage|null */
    private static function loadImage(string $path, string $mime)
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };

        return $image instanceof \GdImage ? $image : null;
    }

    /** @param \GdImage $image */
    private static function resizeIfNeeded($image, int $maxEdge)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= $maxEdge && $height <= $maxEdge) {
            return $image;
        }

        $scale = min($maxEdge / $width, $maxEdge / $height);
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($newW, $newH);
        if ($canvas === false) {
            return $image;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, $newW, $newH, $transparent);
        }
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($image);

        return $canvas;
    }

    /** @param \GdImage $image */
    private static function targetExtension($image, string $mime): string
    {
        if ($mime === 'image/png' && self::hasVisibleAlpha($image)) {
            return 'png';
        }
        if ($mime === 'image/webp' && function_exists('imagewebp') && self::hasVisibleAlpha($image)) {
            return 'webp';
        }

        return 'jpg';
    }

    /** @param \GdImage $image */
    private static function hasVisibleAlpha($image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $stepX = max(1, (int) floor($width / 24));
        $stepY = max(1, (int) floor($height / 24));

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24;
                if ($alpha > 0 && $alpha < 127) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param \GdImage $image */
    private static function saveImage($image, string $path, string $ext, int $quality): bool
    {
        return match ($ext) {
            'png' => imagepng($image, $path, 6),
            'webp' => function_exists('imagewebp') ? imagewebp($image, $path, $quality) : false,
            default => imagejpeg($image, $path, $quality),
        };
    }
}
