<?php

declare(strict_types=1);

namespace App\Content;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Runtime\SafeFile;

/**
 * Images as WebP (quality 85) with GD.
 *
 * - storeUpload(): an uploaded JPEG/PNG/GIF/WebP becomes `<dir>/<name>.webp` (scaled down to maxWidth);
 *   animated GIF/WebP/PNG are stored unchanged, so they keep moving.
 * - variants(): width variants `<name>-<w>w.webp` next to a WebP original for `srcset` (see srcset()); storeUpload() creates
 *   them when it gets widths, app:images:variants backfills a folder.
 * - webpSibling(): `foo.png` → `foo.png.webp` next to it (app:images:webp; the web server hands it out to
 *   browsers that accept WebP, see public/.htaccess).
 */
final class ImageProcessor
{
    public const QUALITY = 85;
    public const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
    private const MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /**
     * @param list<int> $variantWidths widths for the `-<w>w.webp` variants of a (not animated) stored image, [] = none
     * @return string the stored file name (inside $dir)
     * @throws \RuntimeException with a short reason (too large, not an image, …)
     */
    public function storeUpload(UploadedFile $file, string $dir, string $name, int $maxWidth = 1600, array $variantWidths = []): string
    {
        if (!$file->isValid()) {
            throw new \RuntimeException($file->getErrorMessage());
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('The image is larger than 8 MB.');
        }
        $info = SafeFile::imageSize($file->getPathname());
        $mime = $info['mime'] ?? '';
        if (!isset(self::MIME[$mime])) {
            throw new \RuntimeException('Only JPEG, PNG, GIF or WebP images are allowed.');
        }
        if (!SafeFile::mkdir($dir)) {
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
        $this->variants($dir . '/' . $target, $variantWidths);

        return $target;
    }

    /**
     * Writes `<name>-<w>w.webp` next to the WebP file `$path` for every width below the image width (existing up-to-date
     * variants are kept unless `$force`). Animated images and variants themselves are skipped.
     *
     * @param list<int> $widths
     * @return int number of variants written
     */
    public function variants(string $path, array $widths, bool $force = false): int
    {
        $info = SafeFile::imageSize($path);
        if (self::isVariant($path) || $info === false || ($info['mime'] ?? '') !== 'image/webp' || $this->isAnimated($path, 'image/webp')) {
            return 0;
        }
        $written = 0;
        foreach ($widths as $width) {
            $target = self::variantPath($path, $width);
            if ($width >= $info[0] || $width <= 0) {
                continue;
            }
            if (!$force && is_file($target) && filemtime($target) >= filemtime($path)) {
                continue;
            }
            $written += (int) $this->convert($path, $target, $width);
        }

        return $written;
    }

    /**
     * @param list<int> $widths @return array{files: int, variants: int, bytes: int} variants() for every WebP below `$root`
     * @return array<mixed>
     */
    public function variantTree(string $root, array $widths, bool $force = false): array
    {
        $stats = ['files' => 0, 'variants' => 0, 'bytes' => 0];
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)),
            '/\.webp$/i',
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (self::isVariant($path) || preg_match('/\.(png|jpe?g|gif)\.webp$/i', $path)) {
                continue;
            }
            $stats['files']++;
            $stats['variants'] += $this->variants($path, $widths, $force);
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)) as $file) {
            if (self::isVariant($file->getPathname())) {
                $stats['bytes'] += (int) $file->getSize();
            }
        }

        return $stats;
    }

    /** `dir/foo.webp` + 480 → `dir/foo-480w.webp` */
    public static function variantPath(string $path, int $width): string
    {
        return preg_replace('/\.webp$/i', '-' . $width . 'w.webp', $path) ?? $path;
    }

    public static function isVariant(string $path): bool
    {
        return (bool) preg_match('/-\d+w\.webp$/i', $path);
    }

    /** removes the variants of `$path` (`-<w>w.webp` files next to it) */
    public static function removeVariants(string $path): void
    {
        foreach (glob(preg_replace('/\.webp$/i', '', $path) . '-*w.webp') ?: [] as $variant) {
            if (self::isVariant($variant)) {
                SafeFile::remove($variant);
            }
        }
    }

    /**
     * `srcset` value of an image: its existing variants plus the original (`$url` = public URL of the original without query, `$path` its
     * file, `$width` its pixel width). Null when there is no variant (single candidate: no srcset needed).
     */
    public static function srcset(string $path, string $url, int $width): ?string
    {
        $candidates = [];
        foreach (glob(preg_replace('/\.webp$/i', '', $path) . '-*w.webp') ?: [] as $variant) {
            if (self::isVariant($variant) && preg_match('/-(\d+)w\.webp$/i', $variant, $m)) {
                $candidates[(int) $m[1]] = preg_replace('/\.webp$/i', '', $url) . '-' . $m[1] . 'w.webp?v=' . filemtime($variant);
            }
        }
        if ($candidates === []) {
            return null;
        }
        $candidates[$width] = $url . '?v=' . filemtime($path);
        ksort($candidates);
        $parts = [];
        foreach ($candidates as $w => $u) {
            $parts[] = $u . ' ' . $w . 'w';
        }

        return implode(', ', $parts);
    }

    /** writes `<path>.webp`; false when skipped (animated, unsupported, not smaller than the original) */
    public function webpSibling(string $path, bool $force = false): bool
    {
        $target = $path . '.webp';
        if (!$force && is_file($target) && filemtime($target) >= filemtime($path)) {
            return true;
        }
        $mime = (SafeFile::imageSize($path) ?: [])['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true) || $this->isAnimated($path, $mime)) {
            return false;
        }
        if (!$this->convert($path, $target, 0)) {
            return false;
        }
        // keep the original when WebP does not help (tiny icons, already optimised PNGs)
        if (filesize($target) >= filesize($path)) {
            SafeFile::remove($target);

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

    /** Heuristic animation check on the first 4 MiB: GIF with more than one frame, WebP with ANIM/ANMF chunk, PNG with acTL chunk (APNG). `$mime` selects the format. */
    public function isAnimated(string $path, string $mime): bool
    {
        $data = SafeFile::read($path, 4 * 1024 * 1024);
        if ($data === null) {
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

    /**
     * Re-encodes an image file as WebP (quality QUALITY) at `$target`, scaling down to `$maxWidth` pixels when wider
     * (0 = keep size); transparency is preserved. Returns false when the source is no readable image or the
     * written file is missing/empty.
     */
    private function convert(string $source, string $target, int $maxWidth): bool
    {
        $data = SafeFile::read($source);
        // imagecreatefromstring() warns on data that is no image: check the header first
        $image = $data !== null && getimagesizefromstring($data) !== false ? imagecreatefromstring($data) : false;
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
