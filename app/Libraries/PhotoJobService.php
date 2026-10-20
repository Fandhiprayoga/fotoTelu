<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Photos;
use RuntimeException;
use Throwable;

class PhotoJobService
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

    public function enqueue(int $photoId, string $action = 'index'): void
    {
        if (! in_array($action, ['index', 'delete'], true)) {
            throw new RuntimeException('Jenis pekerjaan foto tidak valid.');
        }
        PhotoDomain::transaction($this->db, function () use ($photoId, $action): void {
            $photo = PhotoDomain::lock($this->db, 'photos', $photoId);
            if ($photo === null || ($action === 'index' && $photo['status'] === 'deleting')) {
                throw new RuntimeException();
            }
            $now = PhotoDomain::now();
            $this->db->table('photos')->where('id', $photoId)->update([
                'status' => $action === 'delete' ? 'deleting' : 'pending', 'error' => null, 'updated_at' => $now,
            ]);
            $this->db->table('photo_matches')->where('photo_id', $photoId)->delete();
            $existing = $this->db->table('photo_jobs')->where('photo_id', $photoId)->where('action', $action)
                ->whereIn('state', ['pending', 'working'])->get()->getRowArray();
            if ($existing !== null) {
                return;
            }
            if (! $this->db->table('photo_jobs')->insert(['photo_id' => $photoId, 'action' => $action,
                'state' => 'pending', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now])) {
                throw new RuntimeException();
            }
        });
    }

    /** Explicit operator retry starts a new bounded attempt cycle, including failed purges. */
    public function retry(int $photoId): void
    {
        $photo = $this->db->table('photos')->where('id', $photoId)->get()->getRowArray();
        if ($photo === null) {
            throw new RuntimeException('Foto tidak tersedia.');
        }
        $this->enqueue($photoId, $photo['status'] === 'deleting' ? 'delete' : 'index');
    }

    private function claim(): ?array
    {
        $now = PhotoDomain::now();
        // A worker may die on its fifth attempt; never leave a permanently working lease.
        $exhausted = $this->db->table('photo_jobs')->where('state', 'working')->where('lease_until <=', $now)
            ->where('attempts >=', 5)->limit(16)->get()->getResultArray();
        foreach ($exhausted as $expired) {
            PhotoDomain::transaction($this->db, function () use ($expired, $now): void {
                PhotoDomain::lock($this->db, 'photos', (int) $expired['photo_id']);
                $live = PhotoDomain::lock($this->db, 'photo_jobs', (int) $expired['id']);
                if ($live === null || $live['state'] !== 'working' || (int) $live['attempts'] < 5
                    || $live['lease_until'] === null || $live['lease_until'] > $now) {
                    return;
                }
                $error = 'Batas percobaan pekerjaan foto tercapai. Coba ulang melalui pengelola foto.';
                $this->db->table('photo_jobs')->where('id', $live['id'])->update([
                    'state' => 'failed', 'claim_token' => null, 'lease_until' => null, 'error' => $error, 'updated_at' => $now,
                ]);
                if ($live['action'] === 'index') {
                    $this->db->table('photos')->where('id', $live['photo_id'])->whereIn('status', ['pending', 'failed'])
                        ->update(['status' => 'failed', 'error' => $error, 'updated_at' => $now]);
                } else {
                    $this->db->table('photos')->where('id', $live['photo_id'])->where('status', 'deleting')
                        ->update(['error' => $error, 'updated_at' => $now]);
                }
            });
        }
        $candidates = $this->db->table('photo_jobs')->where('attempts <', 5)->groupStart()
            ->groupStart()->where('state', 'pending')->where('available_at <=', $now)->groupEnd()
            ->orGroupStart()->where('state', 'working')->where('lease_until <=', $now)->groupEnd()
            ->groupEnd()->orderBy('id')->limit(16)->get()->getResultArray();
        foreach ($candidates as $job) {
            $token = bin2hex(random_bytes(16));
            $builder = $this->db->table('photo_jobs')->where('id', $job['id'])->where('attempts', $job['attempts'])
                ->where('state', $job['state']);
            if ($job['state'] === 'working') {
                $builder->where('claim_token', $job['claim_token'])->where('lease_until <=', $now);
            } else {
                $builder->where('available_at <=', $now);
            }
            $ok = $builder->update(['state' => 'working', 'attempts' => (int) $job['attempts'] + 1,
                'claim_token' => $token, 'lease_until' => PhotoDomain::now(90), 'updated_at' => $now]);
            if ($ok && $this->db->affectedRows() === 1) {
                return $this->db->table('photo_jobs')->where('id', $job['id'])->where('claim_token', $token)->get()->getRowArray();
            }
        }
        return null;
    }

    private function owned(array $job): bool
    {
        return $this->db->table('photo_jobs')->where('id', $job['id'])->where('state', 'working')
            ->where('claim_token', $job['claim_token'])->where('lease_until >', PhotoDomain::now())->countAllResults() === 1;
    }

    /** One claim at a time. true means work was claimed (including a retryable failure). */
    public function runOne(): bool
    {
        $job = $this->claim();
        if ($job === null) {
            return false;
        }
        try {
            $photo = $this->db->table('photos')->where('id', $job['photo_id'])->get()->getRowArray();
            if ($photo === null) {
                $this->finish($job);
                return true;
            }
            if ($job['action'] === 'delete') {
                // Engine deletion writes a permanent tombstone before any local cleanup.
                $this->engine->deletePhoto($photo['uuid']);
                PhotoDomain::transaction($this->db, function () use ($job, $photo): void {
                    $live = PhotoDomain::lock($this->db, 'photos', (int) $photo['id']);
                    PhotoDomain::lock($this->db, 'photo_jobs', (int) $job['id']);
                    if (! $this->owned($job) || $live === null || $live['status'] !== 'deleting') {
                        return;
                    }
                    // Idempotent file deletion permits retries after partial unlink/DB failure.
                    ($this->storage ??= new PhotoStorageService(config: $this->config))->delete($live);
                    if (! $this->db->table('photos')->where('id', $live['id'])->where('status', 'deleting')->delete()) {
                        throw new RuntimeException();
                    }
                    // FK removes jobs and grants only after successful physical/index purge.
                });
                return true;
            }
            if ($photo['status'] === 'deleting' || ! in_array($photo['status'], ['pending', 'failed'], true)) {
                $this->finish($job);
                return true;
            }
            $result = $this->engine->indexPhoto($photo);
            if (($result['model_version'] ?? '') !== $this->config->modelVersion || ! is_array($result['faces'] ?? null)
                || ! array_is_list($result['faces']) || count($result['faces']) > 200) {
                throw new RuntimeException();
            }
            PhotoDomain::transaction($this->db, function () use ($job, $photo, $result): void {
                $live = PhotoDomain::lock($this->db, 'photos', (int) $photo['id']);
                PhotoDomain::lock($this->db, 'photo_jobs', (int) $job['id']);
                if (! $this->owned($job)) {
                    return;
                }
                if ($live !== null && in_array($live['status'], ['pending', 'failed'], true)
                    && $live['checksum'] === $photo['checksum'] && $live['uuid'] === $photo['uuid']) {
                    if (! $this->db->table('photos')->where('id', $photo['id'])->where('checksum', $photo['checksum'])
                        ->whereIn('status', ['pending', 'failed'])->update([
                            'status' => $result['faces'] === [] ? 'no_face' : 'ready', 'face_count' => count($result['faces']),
                            'model_version' => $this->config->modelVersion, 'error' => null, 'updated_at' => PhotoDomain::now(),
                        ])) {
                        throw new RuntimeException();
                    }
                }
                $this->finish($job);
            });
        } catch (Throwable $e) {
            // Do not retain exception text: transports/DB drivers may contain private data.
            $this->fail($job, $photo ?? null);
        }
        return true;
    }

    private function finish(array $job): void
    {
        $this->db->table('photo_jobs')->where('id', $job['id'])->where('claim_token', $job['claim_token'])
            ->where('state', 'working')->where('lease_until >', PhotoDomain::now())
            ->update(['state' => 'done', 'lease_until' => null, 'claim_token' => null, 'error' => null, 'updated_at' => PhotoDomain::now()]);
    }

    private function fail(array $job, ?array $snapshot): void
    {
        PhotoDomain::transaction($this->db, function () use ($job, $snapshot): void {
            PhotoDomain::lock($this->db, 'photos', (int) $job['photo_id']);
            PhotoDomain::lock($this->db, 'photo_jobs', (int) $job['id']);
            if (! $this->owned($job)) {
                return;
            }
            $exhausted = (int) $job['attempts'] >= 5;
            $message = 'Layanan pemrosesan foto belum tersedia. Coba ulang melalui pengelola foto.';
            $this->db->table('photo_jobs')->where('id', $job['id'])->where('claim_token', $job['claim_token'])->update([
                'state' => $exhausted ? 'failed' : 'pending', 'available_at' => PhotoDomain::now(min(3600, 15 * (2 ** ((int) $job['attempts'] - 1)))),
                'lease_until' => null, 'claim_token' => null, 'error' => $message, 'updated_at' => PhotoDomain::now(),
            ]);
            if ($job['action'] === 'index' && $snapshot !== null) {
                $this->db->table('photos')->where('id', $job['photo_id'])->where('checksum', $snapshot['checksum'])
                    ->whereIn('status', ['pending', 'failed'])
                    ->update(['status' => $exhausted ? 'failed' : 'pending', 'error' => $message, 'updated_at' => PhotoDomain::now()]);
            } elseif ($job['action'] === 'delete') {
                $this->db->table('photos')->where('id', $job['photo_id'])->where('status', 'deleting')
                    ->update(['error' => $message, 'updated_at' => PhotoDomain::now()]);
            }
        });
    }
}