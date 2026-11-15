<?php

declare(strict_types=1);

namespace App\Content;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Images as WebP (quality 85) with GD.
 *
 * - storeUpload(): an uploaded JPEG/PNG/GIF/WebP becomes `<dir>/<name>.webp` (scaled down to maxWidth);
 *   animated GIF/WebP/PNG are stored unchanged, so they keep moving.
 * - webpSibling(): `foo.png` → `foo.png.webp` next to it (app:images:webp; the web server hands it out to
 *   browsers that accept WebP, see public/.htaccess).
 */
final class ImageProcessor
{
    public const QUALITY = 85;
    public const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
    private const MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /**
     * @return string the stored file name (inside $dir)
     * @throws \RuntimeException with a short reason (too large, not an image, …)
     */
    public function storeUpload(UploadedFile $file, string $dir, string $name, int $maxWidth = 1600): string
    {
        if (!$file->isValid()) {
            throw new \RuntimeException($file->getErrorMessage());
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('The image is larger than 8 MB.');
        }
        $info = @getimagesize($file->getPathname());
        $mime = $info['mime'] ?? '';
        if (!isset(self::MIME[$mime])) {
            throw new \RuntimeException('Only JPEG, PNG, GIF or WebP images are allowed.');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The upload folder is not writable.');
        }

        $name = preg_replace('/[^a-z0-9_-]+/i', '-', $name) ?: 'image';
        if ($this->isAnimated($file->getPathname(), $mime)) {
            $target = $name . '.' . self::MIME[$mime];
            $file->move($dir, $target);

            return $target;
        }

        $target = $name . '.webp';
        if (!$this->convert($file->getPathname(), $dir . '/' . $target, $maxWidth)) {
            throw new \RuntimeException('The image could not be converted.');
        }

        return $target;
    }

    /** writes `<path>.webp`; false when skipped (animated, unsupported, not smaller than the original) */
    public function webpSibling(string $path, bool $force = false): bool
    {
        $target = $path . '.webp';
        if (!$force && is_file($target) && filemtime($target) >= filemtime($path)) {
            return true;
        }
        $mime = @getimagesize($path)['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true) || $this->isAnimated($path, $mime)) {
            return false;
        }
        if (!$this->convert($path, $target, 0)) {
            return false;
        }
        // keep the original when WebP does not help (tiny icons, already optimised PNGs)
        if (filesize($target) >= filesize($path)) {
            @unlink($target);

            return false;
        }

        return true;
    }

    /**
     * webpSibling() for every JPEG/PNG/GIF below $root (skips bundles/ and uploads/)
     *
     * @return array{converted: int, skipped: int, before: int, after: int}
     */
    public function webpTree(string $root, bool $force = false): array
    {
        $stats = ['converted' => 0, 'skipped' => 0, 'before' => 0, 'after' => 0];
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)),
            '/\.(png|jpe?g|gif)$/i',
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/bundles/') || str_contains($path, '/uploads/')) {
                continue;
            }
            if ($this->webpSibling($path, $force)) {
                $stats['converted']++;
                $stats['before'] += (int) filesize($path);
                $stats['after'] += (int) filesize($path . '.webp');
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    public function isAnimated(string $path, string $mime): bool
    {
        $data = @file_get_contents($path, false, null, 0, 4 * 1024 * 1024);
        if ($data === false) {
            return false;
        }

        return match ($mime) {
            // more than one frame (graphic control extension + image descriptor)
            'image/gif' => preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $data) > 1,
            'image/webp' => str_contains(substr($data, 0, 64), 'ANIM') || str_contains($data, 'ANMF'),
            'image/png' => str_contains(substr($data, 0, 256), 'acTL'),
            default => false,
        };
    }

    private function convert(string $source, string $target, int $maxWidth): bool
    {
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if ($image === false) {
            return false;
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);

        if ($maxWidth > 0 && imagesx($image) > $maxWidth) {
            $scaled = imagescale($image, $maxWidth, -1, IMG_BICUBIC);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
                imagesavealpha($image, true);
            }
        }

        $ok = imagewebp($image, $target, self::QUALITY);
        imagedestroy($image);

        return $ok && is_file($target) && filesize($target) > 0;
    }
}
