<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Encryption\EncrypterInterface;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Photos;
use DomainException;
use RuntimeException;
use Throwable;

class SelfieService
{
    private BaseConnection $db;
    private FaceEngineClient $engine;
    private ?PhotoStorageService $storage;
    private Photos $config;

    public function __construct(?BaseConnection $db = null, ?FaceEngineClient $engine = null,
        ?PhotoStorageService $storage = null, ?Photos $config = null)
    {
        $this->db = $db ?? db_connect();
        $this->config = $config ?? config(Photos::class);
        $this->storage = $storage;
        $this->engine = $engine ?? new FaceEngineClient($this->config, $storage);
    }

    /** Internal metadata only. Do not serialize the encrypted embedding into HTTP responses. */
    public function get(int $userId): ?array
    {
        return $this->db->table('photo_selfies')
            ->select('id,user_id,embedding,model_version,version,consented_at,created_at,updated_at')
            ->where('user_id', $userId)->get()->getRowArray();
    }

    private function encrypter(): EncrypterInterface
    {
        // An explicitly configured encryption.key takes precedence over local fallback.
        // Switching keys does not migrate existing ciphertext: retain the old key
        // until existing selfies have been purged or explicitly migrated.
        if (trim((string) config('Encryption')->key) !== '') {
            try {
                return service('encrypter');
            } catch (Throwable $e) {
                throw new RuntimeException('Enkripsi selfie belum tersedia.');
            }
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $this->config->localEncryptionKey) === 1) {
            $encryption = clone config('Encryption');
            $encryption->key = $this->config->localEncryptionKey;
            return \Config\Services::encrypter($encryption, false);
        }
        throw new RuntimeException('Kunci enkripsi selfie belum dikonfigurasi.');
    }

    private function storage(): PhotoStorageService
    {
        return $this->storage ??= new PhotoStorageService(config: $this->config);
    }

    public function embedding(array $selfie): array
    {
        $encrypter = $this->encrypter();
        try {
            if (($selfie['model_version'] ?? '') !== $this->config->modelVersion || empty($selfie['consented_at'])) {
                throw new RuntimeException();
            }
            $ciphertext = base64_decode((string) ($selfie['embedding'] ?? ''), true);
            if ($ciphertext === false || $ciphertext === '') {
                throw new RuntimeException();
            }
            $data = json_decode($encrypter->decrypt($ciphertext), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['model_version'] ?? null) !== $this->config->modelVersion
                || ! is_array($data['embedding'] ?? null)) {
                throw new RuntimeException();
            }
            return FaceEngineClient::validateEmbedding($data['embedding']);
        } catch (Throwable $e) {
            throw new RuntimeException('Selfie tersimpan tidak dapat digunakan. Silakan unggah ulang.');
        }
    }

    public function save(int $userId, UploadedFile $file, bool $consent): void
    {
        if (! $consent || $userId < 1) {
            throw new RuntimeException('Persetujuan penyimpanan dan pencarian selfie diperlukan.');
        }
        $encrypter = $this->encrypter();
        $health = $this->engine->health();
        if (($health['ready'] ?? false) !== true || ($health['model_version'] ?? '') !== $this->config->modelVersion) {
            throw new RuntimeException('Layanan wajah belum siap atau versi model tidak sesuai.');
        }
        $temporary = $this->storage()->store($file);
        $key = bin2hex(random_bytes(16)) . '.enc';
        $old = null;
        try {
            $encoded = $this->engine->encodeSelfie($this->storage()->path($temporary['storage_key']));
            if (($encoded['model_version'] ?? '') !== $this->config->modelVersion) {
                throw new RuntimeException();
            }
            $vector = FaceEngineClient::validateEmbedding($encoded['embedding'] ?? []);
            $embedding = base64_encode($encrypter->encrypt(json_encode([
                'model_version' => $this->config->modelVersion, 'embedding' => $vector,
            ], JSON_THROW_ON_ERROR)));
            $this->storage()->write($key, $encrypter->encrypt($this->storage()->read($temporary['storage_key'])));
            $old = PhotoDomain::transaction($this->db, function () use ($userId, $key, $embedding): ?array {
                if (PhotoDomain::lock($this->db, PhotoDomain::users(), $userId) === null) {
                    throw new RuntimeException();
                }
                $old = $this->db->table('photo_selfies')->where('user_id', $userId)->get()->getRowArray();
                if ($old !== null) {
                    $this->queuePurge($old['storage_key']);
                }
                $now = PhotoDomain::now();
                $data = ['user_id' => $userId, 'storage_key' => $key, 'embedding' => $embedding,
                    'model_version' => $this->config->modelVersion, 'version' => bin2hex(random_bytes(16)),
                    'consented_at' => $now, 'updated_at' => $now];
                $ok = $old === null
                    ? $this->db->table('photo_selfies')->insert($data + ['created_at' => $now])
                    : $this->db->table('photo_selfies')->where('user_id', $userId)->update($data);
                if (! $ok || ! $this->db->table('photo_matches')->where('user_id', $userId)->delete()) {
                    throw new RuntimeException();
                }
                return $old;
            });
        } catch (Throwable $e) {
            $this->cleanupUncommitted(['storage_key' => $key]);
            if ($e instanceof DomainException) {
                throw $e;
            }
            throw new RuntimeException('Selfie belum dapat disimpan. Gunakan foto jelas dengan tepat satu wajah; selfie sebelumnya tetap tersimpan.');
        } finally {
            $this->cleanupUncommitted($temporary);
        }
        // The new active selfie is saved. A failed old-file purge remains queued;
        // success here deliberately does not promise complete physical erasure.
        if ($old !== null) {
            try {
                $this->purgeKey($old['storage_key']);
            } catch (Throwable $e) {
                log_message('warning', 'Pembersihan selfie lama masih tertunda.');
            }
        }
    }

    /** Revoke immediately; unlink failure leaves both revoked metadata and a durable journal. */
    public function delete(int $userId): void
    {
        $old = PhotoDomain::transaction($this->db, function () use ($userId): ?array {
            PhotoDomain::lock($this->db, PhotoDomain::users(), $userId);
            return $this->revoke($userId);
        });
        if ($old !== null) {
            try {
                $this->purgeKey($old['storage_key']);
            } catch (Throwable $e) {
                throw new RuntimeException('Persetujuan dicabut; pembersihan berkas selfie masih tertunda.');
            }
        }
    }

    /** The callback must delete the user on this same DB connection and throw/return false on failure. */
    public function deleteAccount(int $userId, callable $deleteUser): void
    {
        $old = PhotoDomain::transaction($this->db, function () use ($userId, $deleteUser): ?array {
            if (PhotoDomain::lock($this->db, PhotoDomain::users(), $userId) === null) {
                throw new RuntimeException();
            }
            $old = $this->revoke($userId);
            if ($deleteUser() === false
                || $this->db->table(PhotoDomain::users())->where('id', $userId)->countAllResults() !== 0) {
                throw new RuntimeException();
            }
            return $old;
        });
        // Never unlink inside the account transaction: rollback must retain its file.
        // This journal has no user FK, so it survives the account's cascade.
        if ($old !== null) {
            try {
                $this->purgeKey($old['storage_key']);
            } catch (Throwable $e) {
                log_message('warning', 'Akun dihapus; pembersihan berkas selfie masih tertunda.');
            }
        }
    }

    /** Caller holds the user lock and transaction. No physical deletion here. */
    private function revoke(int $userId): ?array
    {
        $row = $this->db->table('photo_selfies')->where('user_id', $userId)->get()->getRowArray();
        if ($row !== null) {
            $this->queuePurge($row['storage_key']);
        }
        if (! $this->db->table('photo_matches')->where('user_id', $userId)->delete()
            || ! $this->db->table('photo_selfies')->where('user_id', $userId)->update([
                'version' => bin2hex(random_bytes(16)), 'consented_at' => null,
                'embedding' => '', 'updated_at' => PhotoDomain::now(),
            ])) {
            throw new RuntimeException();
        }
        return $row;
    }

    /** Must run within the same transaction that drops the previous reference. */
    private function queuePurge(string $key): void
    {
        if ($this->db->table('photo_selfie_purges')->where('storage_key', $key)->countAllResults() === 0
            && ! $this->db->table('photo_selfie_purges')->insert([
                'storage_key' => $key, 'created_at' => PhotoDomain::now(),
            ])) {
            throw new RuntimeException();
        }
    }

    private function purgeKey(string $key): void
    {
        $row = $this->db->table('photo_selfies')->where('storage_key', $key)->get()->getRowArray();
        // Defensive guard: never purge a currently consented file.
        if ($row !== null && $row['consented_at'] !== null) {
            throw new RuntimeException();
        }
        $this->storage()->delete(['storage_key' => $key]);
        PhotoDomain::transaction($this->db, function () use ($key, $row): void {
            // A replacement may have committed during unlink. Do not remove it.
            if ($row !== null && ! $this->db->table('photo_selfies')->where('id', $row['id'])
                ->where('version', $row['version'])->where('storage_key', $key)
                ->where('consented_at', null)->delete()) {
                throw new RuntimeException();
            }
            if (! $this->db->table('photo_selfie_purges')->where('storage_key', $key)->delete()) {
                throw new RuntimeException();
            }
        });
    }

    /** At most 100 entries per invocation; failures remain durable for the next run. */
    public function purgePending(): int
    {
        $rows = $this->db->table('photo_selfie_purges')->orderBy('created_at')->orderBy('storage_key')
            ->limit(100)->get()->getResultArray();
        $purged = 0;
        foreach ($rows as $row) {
            try {
                $this->purgeKey($row['storage_key']);
                $purged++;
            } catch (Throwable $e) {
                log_message('warning', 'Pembersihan selfie masih tertunda.');
            }
        }
        return $purged;
    }

    /** Failed preparation/plaintext cleanup must not mask a committed replacement. */
    private function cleanupUncommitted(array $files): void
    {
        foreach (array_unique(array_filter([$files['storage_key'] ?? null, $files['thumbnail_key'] ?? null])) as $key) {
            try {
                PhotoDomain::transaction($this->db, function () use ($key): void { $this->queuePurge($key); });
                $this->purgeKey($key);
            } catch (Throwable $e) {
                log_message('warning', 'Pembersihan berkas sementara selfie masih tertunda.');
            }
        }
    }
}