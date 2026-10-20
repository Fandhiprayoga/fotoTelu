<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Photos;
use DomainException;
use RuntimeException;
use Throwable;

/** Internal-only transport. No raw remote errors, headers or biometric values escape. */
class FaceEngineClient
{
    protected Photos $config;
    private ?PhotoStorageService $storage;

    public function __construct(?Photos $config = null, ?PhotoStorageService $storage = null)
    {
        $this->config = $config ?? config(Photos::class);
        $this->storage = $storage;
    }

    public function health(): array
    {
        $result = $this->request('GET', '/health');
        $this->version($result);
        if (($result['ready'] ?? null) !== true) {
            throw new RuntimeException('Layanan pencarian wajah belum siap.');
        }
        return $result;
    }

    public function encodeSelfie(string $path): array
    {
        $result = $this->request('POST', '/selfie', $this->multipart($path));
        $this->version($result);
        if (! is_array($result['embedding'] ?? null)) {
            throw new RuntimeException('Jawaban layanan selfie tidak valid.');
        }
        $result['embedding'] = self::validateEmbedding($result['embedding'] ?? []);
        return $result;
    }

    public function indexPhoto(array $photo): array
    {
        $this->uuid((string) ($photo['uuid'] ?? ''));
        if (! preg_match('/\A[a-f0-9]{64}\z/D', (string) ($photo['checksum'] ?? ''))) {
            throw new RuntimeException('Identitas berkas foto tidak valid.');
        }
        $storage = $this->storage ??= new PhotoStorageService(config: $this->config);
        $result = $this->request('POST', '/photos/' . $photo['uuid'],
            $this->multipart($storage->path($photo['storage_key'])) + ['checksum' => $photo['checksum']]);
        $this->version($result);
        if (! isset($result['faces']) || ! is_array($result['faces']) || ! array_is_list($result['faces']) || count($result['faces']) > 200) {
            throw new RuntimeException('Jawaban layanan wajah tidak valid.');
        }
        foreach ($result['faces'] as $face) {
            if (! is_array($face) || ! is_string($face['id'] ?? null) || ! is_array($face['box'] ?? null)
                || ! array_is_list($face['box']) || count($face['box']) !== 4) {
                throw new RuntimeException('Jawaban layanan wajah tidak valid.');
            }
            $this->uuid($face['id']);
            foreach ($face['box'] as $component) {
                if ((! is_int($component) && ! is_float($component)) || ! is_finite((float) $component) || $component < 0) {
                    throw new RuntimeException('Jawaban layanan wajah tidak valid.');
                }
            }
        }
        return $result;
    }

    public function deletePhoto(string $uuid): void
    {
        $this->uuid($uuid);
        $result = $this->request('DELETE', '/photos/' . $uuid);
        if (($result['success'] ?? null) !== true) {
            throw new RuntimeException('Penghapusan indeks foto belum berhasil.');
        }
    }

    public function search(array $embedding, array $photoUuids): array
    {
        $embedding = self::validateEmbedding($embedding);
        if (count($photoUuids) > 20000 || ! array_is_list($photoUuids)) {
            throw new RuntimeException('Album terlalu besar untuk satu pencarian.');
        }
        foreach ($photoUuids as $uuid) {
            if (! is_string($uuid)) {
                throw new RuntimeException('Identitas foto tidak valid.');
            }
            $this->uuid($uuid);
        }
        $result = $this->request('POST', '/search', ['embedding' => $embedding,
            'model_version' => $this->config->modelVersion, 'photo_ids' => $photoUuids,
            'threshold' => $this->config->threshold], true);
        $this->version($result);
        if (! is_array($result['matches'] ?? null) || ! array_is_list($result['matches']) || count($result['matches']) > 200) {
            throw new RuntimeException('Jawaban pencarian foto tidak valid.');
        }
        foreach ($result['matches'] as $match) {
            if (! is_array($match) || ! is_string($match['photo_id'] ?? null)
                || ! self::validScore($match['score'] ?? null)) {
                throw new RuntimeException('Jawaban pencarian foto tidak valid.');
            }
            $this->uuid($match['photo_id']);
        }
        return $result;
    }

    public static function validScore(mixed $score): bool
    {
        return (is_float($score) || is_int($score)) && is_finite((float) $score) && $score >= -1 && $score <= 1;
    }

    public static function validateEmbedding(array $embedding): array
    {
        if (count($embedding) !== 128 || ! array_is_list($embedding)) {
            throw new RuntimeException('Data selfie tidak valid. Unggah ulang selfie.');
        }
        $norm = 0.0;
        foreach ($embedding as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value) || abs($value) > 1.0e10) {
                throw new RuntimeException('Data selfie tidak valid. Unggah ulang selfie.');
            }
            $norm += $value * $value;
        }
        if (! is_finite($norm) || $norm < 1.0e-12) {
            throw new RuntimeException('Data selfie tidak valid. Unggah ulang selfie.');
        }
        return array_map(static fn ($value): float => (float) $value, $embedding);
    }

    protected function version(array $result): void
    {
        if (($result['model_version'] ?? null) !== $this->config->modelVersion) {
            throw new RuntimeException('Versi model wajah tidak sesuai. Hubungi administrator.');
        }
    }

    private function uuid(string $uuid): void
    {
        if (! preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $uuid)) {
            throw new RuntimeException('Identitas foto tidak valid.');
        }
    }

    private function multipart(string $path): array
    {
        if (! function_exists('curl_init') || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('Berkas foto atau koneksi layanan tidak tersedia.');
        }
        $info = @getimagesize($path);
        $mime = $info['mime'] ?? '';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Format berkas foto tidak didukung.');
        }
        return ['file' => new \CURLFile($path, $mime, 'photo')];
    }

    /** Override only in trusted tests; real transport is bounded and never follows redirects. */
    protected function request(string $method, string $route, array $payload = [], bool $json = false): array
    {
        $url = parse_url($this->config->faceUrl);
        if (trim($this->config->serviceToken) === '' || preg_match('/[\r\n]/', $this->config->serviceToken)
            || ! is_array($url) || ! in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! function_exists('curl_init')) {
            throw new RuntimeException('Konfigurasi layanan wajah belum tersedia.');
        }
        $handle = curl_init($this->config->faceUrl . $route);
        $body = '';
        try {
            $headers = ['X-Service-Token: ' . $this->config->serviceToken, 'Accept: application/json'];
            if ($json) {
                $headers[] = 'Content-Type: application/json';
            }
            curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 45, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 1048576) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                }]);
            if ($method === 'POST') {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $json ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload);
            }
            if (curl_exec($handle) === false) {
                throw new RuntimeException('Layanan wajah belum dapat dihubungi.');
            }
            return $this->parseResponse($body, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $route);
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Layanan wajah belum dapat memproses permintaan. Coba lagi dengan foto yang jelas.');
        } finally {
            curl_close($handle);
        }
    }

    /** Only allowlisted selfie guidance is public; never expose raw remote details. */
    protected function parseResponse(string $body, int $status, string $route): array
    {
        $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($result) || array_is_list($result)) {
            throw new RuntimeException('Jawaban layanan wajah tidak valid.');
        }
        if ($route === '/selfie' && in_array($status, [415, 422], true)) {
            $messages = [
                'selfie_no_face_detected' => 'Wajah belum terdeteksi. Hadap lurus ke kamera, dekatkan wajah ke panduan oval, dan gunakan cahaya dari depan lalu ambil foto ulang.',
                'selfie_multiple_faces_detected' => 'Lebih dari satu wajah terdeteksi. Pastikan hanya Anda yang terlihat, termasuk tidak ada wajah pada poster atau layar di belakang, lalu ambil foto ulang.',
                'invalid_image' => 'Foto tidak dapat dibaca. Gunakan JPEG, PNG, atau WebP yang valid.',
                'selfie_exactly_one_face_required' => 'Selfie harus memuat tepat satu wajah yang jelas. Hadapkan wajah ke kamera dengan pencahayaan cukup dan pastikan tidak ada wajah lain.',
                'selfie_face_too_small' => 'Wajah pada selfie terlalu kecil. Dekatkan wajah ke kamera lalu ambil foto ulang.',
                'selfie_face_out_of_frame' => 'Wajah terpotong atau berada di luar bingkai. Pastikan seluruh wajah terlihat lalu ambil foto ulang.',
                'selfie_face_too_blurry' => 'Wajah pada selfie terlalu buram. Tambahkan pencahayaan, tahan kamera tetap stabil, dan ambil foto ulang.',
                'image_processing_failed' => 'Foto belum dapat diproses. Ambil foto ulang atau pilih berkas JPEG, PNG, atau WebP lain.',
            ];
            $detail = $result['detail'] ?? null;
            if (is_string($detail) && isset($messages[$detail])) {
                throw new DomainException($messages[$detail]);
            }
        }
        if ($status !== 200) {
            throw new RuntimeException('Layanan wajah belum dapat memproses permintaan.');
        }
        return $result;
    }
}