<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Photos;
use RuntimeException;
use Throwable;

class PhotoStorageService
{
    private string $root;
    private Photos $config;

    /** $writable is a private WRITABLE root override, never a request parameter. */
    public function __construct(?string $writable = null, ?Photos $config = null)
    {
        $this->config = $config ?? config(Photos::class);
        $base = rtrim($writable ?? WRITEPATH, '/\\');
        if (! is_dir($base) || is_link($base) || ($real = realpath($base)) === false) {
            throw new RuntimeException('Direktori penyimpanan foto tidak tersedia.');
        }
        $this->root = $real . DIRECTORY_SEPARATOR . 'photos';
        if (is_link($this->root) || (! is_dir($this->root) && ! @mkdir($this->root, 0700))) {
            throw new RuntimeException('Direktori penyimpanan foto tidak tersedia.');
        }
        if (! @chmod($this->root, 0700) || realpath($this->root) !== $this->root) {
            throw new RuntimeException('Penyimpanan foto harus bersifat privat.');
        }
    }

    /** Resolve only random flat keys; missing valid keys are allowed for idempotent deletion. */
    public function path(string $key): string
    {
        if (! preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp|enc)\z/D', $key)
            || is_link($this->root) || realpath($this->root) !== $this->root) {
            throw new RuntimeException('Kunci penyimpanan foto tidak valid.');
        }
        $path = $this->root . DIRECTORY_SEPARATOR . $key;
        if (is_link($path) || (file_exists($path) && (! is_file($path) || realpath($path) !== $path))) {
            throw new RuntimeException('Berkas foto tidak valid.');
        }
        return $path;
    }

    public function read(string $key): string
    {
        $path = $this->path($key);
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Berkas foto tidak tersedia.');
        }
        return $bytes;
    }

    /** Also used for encrypted selfie originals. Exclusive create, never overwrite. */
    public function write(string $key, string $bytes): void
    {
        $path = $this->path($key);
        $previous = umask(0077);
        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($previous);
        }
        if ($handle === false) {
            throw new RuntimeException('Berkas foto tidak dapat disimpan.');
        }
        try {
            if (! @chmod($path, 0600)) {
                throw new RuntimeException('Berkas foto harus bersifat privat.');
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Berkas foto tidak dapat disimpan.');
                }
                $offset += $written;
            }
            if (! fflush($handle)) {
                throw new RuntimeException('Berkas foto tidak dapat disimpan.');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw new RuntimeException('Berkas foto tidak dapat disimpan.');
        }
        fclose($handle);
    }

    public function delete(array $photo): void
    {
        foreach (array_unique(array_filter([$photo['storage_key'] ?? null, $photo['thumbnail_key'] ?? null])) as $key) {
            $path = $this->path($key);
            if (file_exists($path) && ! @unlink($path)) {
                throw new RuntimeException('Berkas foto belum dapat dihapus.');
            }
        }
    }

    public function store(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->hasMoved() || $file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Unggahan foto tidak valid atau gagal diterima.');
        }
        $source = $file->getTempName();
        $size = @filesize($source);
        if ($size === false || $size < 1 || $size > $this->config->maxUploadBytes) {
            throw new RuntimeException('Ukuran foto melebihi batas unggahan.');
        }
        $info = @getimagesize($source);
        $types = [IMAGETYPE_JPEG => ['image/jpeg', 'jpg', 'imagecreatefromjpeg'],
            IMAGETYPE_PNG => ['image/png', 'png', 'imagecreatefrompng'],
            IMAGETYPE_WEBP => ['image/webp', 'webp', 'imagecreatefromwebp']];
        if ($info === false || ! isset($types[$info[2]])) {
            throw new RuntimeException('Gunakan foto JPEG, PNG, atau WebP yang valid.');
        }
        [$mime, $extension, $decoder] = $types[$info[2]];
        [$width, $height] = $info;
        $pixels = (float) $width * $height;
        if ($width < 1 || $height < 1 || $width > 16384 || $height > 16384 || $pixels > $this->config->maxPixels) {
            throw new RuntimeException('Resolusi foto melebihi batas pemrosesan.');
        }
        if (! function_exists($decoder) || ! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            throw new RuntimeException('Dukungan pemrosesan foto belum tersedia.');
        }
        $limit = trim((string) ini_get('memory_limit'));
        $budget = (float) $limit;
        $unit = strtolower(substr($limit, -1));
        $budget *= match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        // Decoder, source buffer, thumbnail, PHP overhead. Reject before decoding.
        if ($budget > 0 && memory_get_usage(true) + $pixels * 8 + $size * 2 + 16777216 > $budget) {
            throw new RuntimeException('Foto terlalu besar untuk memori pemrosesan yang tersedia.');
        }
        $image = @$decoder($source);
        if ($image === false) {
            throw new RuntimeException('Foto rusak atau tidak dapat dibaca.');
        }
        $key = bin2hex(random_bytes(16)) . '.' . $extension;
        $thumbKey = bin2hex(random_bytes(16)) . '.jpg';
        $thumbnail = null;
        try {
            // Originals stay private; thumbnail is re-encoded, stripping metadata.
            $original = @file_get_contents($source);
            if ($original === false || strlen($original) !== $size) {
                throw new RuntimeException('Unggahan foto tidak dapat dibaca.');
            }
            $scale = min(1, 640 / max($width, $height));
            $tw = max(1, (int) round($width * $scale));
            $th = max(1, (int) round($height * $scale));
            $thumbnail = imagecreatetruecolor($tw, $th);
            imagefill($thumbnail, 0, 0, imagecolorallocate($thumbnail, 255, 255, 255));
            if (! imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $tw, $th, $width, $height)) {
                throw new RuntimeException('Pratinjau foto tidak dapat dibuat.');
            }
            ob_start();
            try {
                $ok = imagejpeg($thumbnail, null, 85);
                $thumbBytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! $ok || ! is_string($thumbBytes)) {
                throw new RuntimeException('Pratinjau foto tidak dapat dibuat.');
            }
            $this->write($key, $original);
            $this->write($thumbKey, $thumbBytes);
            return ['storage_key' => $key, 'thumbnail_key' => $thumbKey, 'mime' => $mime,
                'bytes' => $size, 'width' => $width, 'height' => $height, 'checksum' => hash('sha256', $original)];
        } catch (Throwable $e) {
            $this->delete(['storage_key' => $key, 'thumbnail_key' => $thumbKey]);
            throw new RuntimeException('Foto tidak dapat disimpan atau diproses.');
        } finally {
            imagedestroy($image);
            if ($thumbnail instanceof \GdImage) {
                imagedestroy($thumbnail);
            }
        }
    }
}