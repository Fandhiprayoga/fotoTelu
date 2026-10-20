<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Photos;
use RuntimeException;
use Throwable;

class PhotoSearchService
{
    private BaseConnection $db;
    private FaceEngineClient $engine;
    private SelfieService $selfies;
    private Photos $config;

    public function __construct(?BaseConnection $db = null, ?FaceEngineClient $engine = null,
        ?SelfieService $selfies = null, ?Photos $config = null)
    {
        $this->db = $db ?? db_connect();
        $this->config = $config ?? config(Photos::class);
        $this->engine = $engine ?? new FaceEngineClient($this->config);
        $this->selfies = $selfies ?? new SelfieService($this->db, $this->engine, config: $this->config);
    }

    /** Returns private photo metadata plus cosine score; never an identity probability. */
    public function search(int $userId, int $albumId): array
    {
        // Revoke previous grants even if this search subsequently fails.
        PhotoDomain::transaction($this->db, function () use ($userId): void {
            if ($userId < 1 || PhotoDomain::lock($this->db, PhotoDomain::users(), $userId) === null
                || ! $this->db->table('photo_matches')->where('user_id', $userId)->delete()) {
                throw new RuntimeException();
            }
        });
        $album = $this->db->table('photo_albums')->where('id', $albumId)->where('status', 'published')->get()->getRowArray();
        $selfie = $this->selfies->get($userId);
        if ($album === null) {
            throw new RuntimeException('Album belum diterbitkan atau tidak tersedia.');
        }
        if ($selfie === null || empty($selfie['consented_at']) || $selfie['model_version'] !== $this->config->modelVersion) {
            throw new RuntimeException('Simpan selfie dengan persetujuan dan versi model yang sesuai terlebih dahulu.');
        }
        $eligible = $this->db->table('photos')->where('album_id', $albumId)->where('status', 'ready')
            ->where('model_version', $this->config->modelVersion)->orderBy('id')->limit(20001)->get()->getResultArray();
        if (count($eligible) > 20000) {
            throw new RuntimeException('Album melebihi 20.000 foto siap cari. Hubungi administrator untuk membagi album.');
        }
        $map = array_column($eligible, null, 'uuid');
        try {
            $response = $this->engine->search($this->selfies->embedding($selfie), array_keys($map));
            if (($response['model_version'] ?? '') !== $this->config->modelVersion
                || ! is_array($response['matches'] ?? null) || ! array_is_list($response['matches'])
                || count($response['matches']) > 200) {
                throw new RuntimeException();
            }
            $hits = [];
            foreach ($response['matches'] as $match) {
                if (! is_array($match) || ! is_string($match['photo_id'] ?? null)
                    || ! FaceEngineClient::validScore($match['score'] ?? null)) {
                    throw new RuntimeException();
                }
                $uuid = $match['photo_id'];
                // Remote index is never the authority for album scope or access.
                if (! isset($map[$uuid]) || $match['score'] < $this->config->threshold) {
                    continue;
                }
                $hits[$uuid] = max($hits[$uuid] ?? -1.0, (float) $match['score']);
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Pencarian belum dapat dilakukan. Periksa selfie atau coba kembali nanti.');
        }
        return PhotoDomain::transaction($this->db, function () use ($albumId, $userId, $album, $selfie, $hits, $map): array {
            // Publication/revision and selfie version are re-read under write locks.
            $liveAlbum = PhotoDomain::lock($this->db, 'photo_albums', $albumId);
            PhotoDomain::lock($this->db, PhotoDomain::users(), $userId);
            $liveSelfie = $this->selfies->get($userId);
            if ($liveAlbum === null || $liveAlbum['status'] !== 'published'
                || (int) $liveAlbum['revision'] !== (int) $album['revision'] || $liveSelfie === null
                || empty($liveSelfie['consented_at']) || $liveSelfie['version'] !== $selfie['version']
                || $liveSelfie['model_version'] !== $this->config->modelVersion) {
                throw new RuntimeException();
            }
            $results = [];
            // Lock photos in stable PK order to avoid opposite-order worker locks.
            $uuids = array_keys($hits);
            usort($uuids, static fn ($a, $b): int => (int) $map[$a]['id'] <=> (int) $map[$b]['id']);
            foreach ($uuids as $uuid) {
                $photo = PhotoDomain::lock($this->db, 'photos', (int) $map[$uuid]['id']);
                if ($photo === null || (int) $photo['album_id'] !== $albumId || $photo['status'] !== 'ready'
                    || $photo['model_version'] !== $this->config->modelVersion || $photo['uuid'] !== $uuid
                    || $photo['checksum'] !== $map[$uuid]['checksum']) {
                    continue;
                }
                if ($this->db->table('photo_matches')->onConstraint(['user_id', 'photo_id'])->upsert([
                    'user_id' => $userId, 'photo_id' => $photo['id'], 'album_revision' => $album['revision'],
                    'selfie_version' => $selfie['version'], 'model_version' => $this->config->modelVersion,
                    'score' => $hits[$uuid], 'expires_at' => PhotoDomain::now(900), 'created_at' => PhotoDomain::now(),
                ]) === false) {
                    throw new RuntimeException();
                }
                $results[] = $photo + ['score' => $hits[$uuid]];
            }
            usort($results, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['uuid'], $b['uuid']));
            return $results;
        });
    }

    /** Admin must be computed by trusted permission checks, never by request input. */
    public function authorized(array $photo, int $userId, bool $admin): bool
    {
        if (empty($photo['id'])) {
            return false;
        }
        try {
            if ($admin) {
                return $this->db->table('photos')->where('id', $photo['id'])->where('status !=', 'deleting')->countAllResults() === 1;
            }
            if ($userId < 1) {
                return false;
            }
            return $this->db->table('photo_matches m')->join('photos p', 'p.id = m.photo_id')
                ->join('photo_albums a', 'a.id = p.album_id')
                ->join('photo_selfies s', 's.user_id = m.user_id')
                ->where('p.id', $photo['id'])->where('m.user_id', $userId)
                ->where('p.status', 'ready')->where('a.status', 'published')
                ->where('p.model_version', $this->config->modelVersion)
                ->where('s.model_version', $this->config->modelVersion)
                ->where('m.model_version', $this->config->modelVersion)
                ->where('m.album_revision = a.revision', null, false)
                ->where('m.selfie_version = s.version', null, false)
                ->where('s.consented_at IS NOT NULL', null, false)
                ->where('m.expires_at >', PhotoDomain::now())->where('m.score >=', $this->config->threshold)
                ->where('m.score <=', 1)->where('m.score >=', -1)->countAllResults() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}