<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\FaceEngineClient;
use App\Libraries\PhotoDomain;
use App\Libraries\PhotoJobService;
use App\Libraries\PhotoStorageService;
use CodeIgniter\Exceptions\PageNotFoundException;
use DomainException;
use RuntimeException;
use Throwable;

/** Logical moves are deliberately limited to the current album in this MVP. */
class PhotoManagerController extends BaseController
{
    private function guard(string $permission = 'photos.manage'): void
    {
        $this->response->setHeader('Cache-Control', 'private, no-store')->setHeader('X-Content-Type-Options', 'nosniff');
        if (! auth()->loggedIn() || ! activeGroupCan('photos.manage') || ! activeGroupCan($permission)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function permissions(): array
    {
        $permissions = [];
        foreach (['photos.upload', 'photos.delete', 'albums.manage', 'albums.publish', 'photos.download'] as $permission) {
            $permissions[$permission] = activeGroupCan($permission);
        }
        return $permissions;
    }

    private function integer(mixed $value, bool $zero = false): int
    {
        if (! is_scalar($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value)
            || (float) $value > 2147483647 || (! $zero && (int) $value < 1)) {
            throw new DomainException('Pilihan album atau folder tidak valid.');
        }
        return (int) $value;
    }

    private function name(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new DomainException('Nama tidak valid.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 120 || preg_match('~[/\\\\\p{Cc}\p{Cf}]~u', $value)
            || in_array($value, ['.', '..'], true)) {
            throw new DomainException('Nama wajib diisi, maksimal 120 karakter, tanpa garis miring atau karakter kontrol.');
        }
        return $value;
    }

    private function row(string $table, int $id, bool $lock = false): array
    {
        $db = db_connect();
        $row = $lock ? PhotoDomain::lock($db, $table, $id) : $db->table($table)->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw new DomainException('Data tidak tersedia. Muat ulang halaman.');
        }
        return $row;
    }

    private function folder(int $albumId, int $id): ?array
    {
        if ($id === 0) {
            return null;
        }
        $row = $this->row('photo_folders', $id);
        if ((int) $row['album_id'] !== $albumId) {
            throw new DomainException('Folder tujuan harus berada di album yang sama.');
        }
        return $row;
    }

    /** Keep validation messages, but never expose database/transport exception text. */
    private function transaction(callable $callback): mixed
    {
        $db = db_connect();
        if (! $db->transBegin()) {
            throw new RuntimeException();
        }
        try {
            $result = $callback();
            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new RuntimeException();
            }
            return $result;
        } catch (Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    private function checked(bool $ok): void
    {
        if (! $ok) {
            throw new RuntimeException();
        }
    }

    private function revision(int $albumId): void
    {
        $db = db_connect();
        $this->checked($db->table('photo_albums')->where('id', $albumId)->set('revision', 'revision + 1', false)
            ->set('updated_at', PhotoDomain::now())->update());
        $ids = $db->table('photos')->select('id')->where('album_id', $albumId);
        $this->checked($db->table('photo_matches')->whereIn('photo_id', $ids)->delete());
    }

    private function albumUrl(int $albumId = 0, int $folderId = 0): string
    {
        return site_url('admin/photos' . ($albumId > 0 ? '/album/' . $albumId : ''))
            . ($albumId > 0 && $folderId > 0 ? '?folder=' . $folderId : '');
    }

    private function mutate(string $permission, callable $callback, int $albumId = 0)
    {
        $this->guard($permission);
        try {
            $message = $callback();
            return redirect()->to($this->albumUrl($albumId))->with('success', $message);
        } catch (DomainException $exception) {
            return redirect()->to($this->albumUrl($albumId))->with('error', $exception->getMessage());
        } catch (RuntimeException $exception) {
            return redirect()->to($this->albumUrl($albumId))->with('error', 'Perubahan belum dapat disimpan. Muat ulang halaman dan coba lagi.');
        }
    }

    private function engineState(): bool
    {
        try {
            // Avoid a network request and any token-related warning when unconfigured.
            if (trim(config('Photos')->serviceToken) === '') {
                return false;
            }
            return (new FaceEngineClient())->health()['ready'] === true;
        } catch (RuntimeException $exception) {
            return false;
        }
    }

    public function index()
    {
        $this->guard();
        $available = true;
        try {
            $db = db_connect();
            $count = $db->table('photos p')->selectCount('id')->where('p.album_id = a.id', null, false);
            $albums = $db->table('photo_albums a')->select('a.*')->selectSubquery($count, 'photo_count')
                ->orderBy('a.id', 'DESC')->get()->getResultArray();
        } catch (RuntimeException $exception) {
            $albums = [];
            $available = false;
        }
        return $this->renderView('photos/manager', ['title' => 'Pustaka Foto', 'page_title' => 'Pustaka Foto',
            'albums' => $albums, 'available' => $available, 'permissions' => $this->permissions(), 'engineReady' => $this->engineState()]);
    }

    public function album($id)
    {
        $this->guard();
        try {
            $id = $this->integer($id);
            $album = $this->row('photo_albums', $id);
            $folderId = $this->integer($this->request->getGet('folder') ?? '0', true);
            $this->folder($id, $folderId);
            $folders = db_connect()->table('photo_folders')->where('album_id', $id)->orderBy('name')->get()->getResultArray();
            $byId = array_column($folders, null, 'id');
            $breadcrumbs = [];
            $cursor = $folderId;
            while ($cursor > 0) {
                if (count($breadcrumbs) >= 20 || ! isset($byId[$cursor]) || isset($breadcrumbs[$cursor])) {
                    throw new DomainException('Struktur folder tidak valid.');
                }
                $breadcrumbs[$cursor] = $byId[$cursor];
                $cursor = (int) $byId[$cursor]['parent_id'];
            }
            $q = $this->request->getGet('q') ?? '';
            $q = is_string($q) ? mb_substr(trim($q), 0, 120) : '';
            $page = $this->integer($this->request->getGet('page') ?? '1');
            $builder = db_connect()->table('photos')->where('album_id', $id)->where('folder_id', $folderId ?: null);
            if ($q !== '') {
                $builder->like('name', $q);
            }
            $total = $builder->countAllResults(false);
            $pages = max(1, (int) ceil($total / 36));
            $page = min($page, $pages);
            $photos = $builder->orderBy('id', 'DESC')->limit(36, ($page - 1) * 36)->get()->getResultArray();
        } catch (DomainException $exception) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $this->renderView('photos/album', ['title' => $album['name'], 'page_title' => 'Pengelola Album',
            'album' => $album, 'folders' => $folders, 'folderId' => $folderId, 'breadcrumbs' => array_reverse($breadcrumbs),
            'photos' => $photos, 'total' => $total, 'page' => $page, 'pages' => $pages, 'q' => $q,
            'permissions' => $this->permissions(), 'engineReady' => $this->engineState()]);
    }

    /** Album names are display labels, not access or storage identifiers. */
    private function uniqueAlbumName(string $name, int $except = 0): void
    {
        $db = db_connect();
        if ($db->table('photo_albums')->where('name', $name)->where('id !=', $except)->countAllResults() > 0) {
            throw new DomainException('Nama album sudah digunakan. Pilih nama lain.');
        }
    }

    public function createAlbum()
    {
        return $this->mutate('albums.manage', function (): string {
            $name = $this->name($this->request->getPost('name'));
            $this->transaction(function () use ($name): void {
                $this->uniqueAlbumName($name);
                $now = PhotoDomain::now();
                $this->checked(db_connect()->table('photo_albums')->insert(['name' => $name, 'status' => 'draft',
                    'revision' => 1, 'created_by' => auth()->id(), 'created_at' => $now, 'updated_at' => $now]));
            });
            return 'Album dibuat sebagai draf. Tambahkan folder dan foto untuk memulai.';
        });
    }

    public function updateAlbum($id)
    {
        return $this->mutate('albums.manage', function () use ($id): string {
            $id = $this->integer($id);
            $name = $this->name($this->request->getPost('name'));
            $status = $this->request->getPost('status');
            if (! in_array($status, ['draft', 'published'], true)) {
                throw new DomainException('Status album tidak valid.');
            }
            $this->transaction(function () use ($id, $name, $status): void {
                $this->uniqueAlbumName($name, $id);
                $album = $this->row('photo_albums', $id, true);
                if ($status !== $album['status'] && ! activeGroupCan('albums.publish')) {
                    throw new DomainException('Peran aktif tidak memiliki izin penerbitan album.');
                }
                if ($status === 'published' && $album['status'] !== 'published' && $this->request->getPost('consent') !== '1') {
                    throw new DomainException('Konfirmasi hak dan persetujuan pemrosesan foto sebelum menerbitkan album.');
                }
                $this->checked(db_connect()->table('photo_albums')->where('id', $id)->update(['name' => $name,
                    'status' => $status, 'updated_at' => PhotoDomain::now()]));
                $this->revision($id);
            });
            return 'Album diperbarui. Hasil pencarian sebelumnya telah dicabut.';
        }, (int) $id);
    }

    public function deleteAlbum($id)
    {
        return $this->mutate('albums.manage', function () use ($id): string {
            if (! activeGroupCan('photos.delete')) {
                throw new DomainException('Peran aktif tidak memiliki izin penghapusan.');
            }
            $this->transaction(function () use ($id): void {
                $id = $this->integer($id);
                $album = $this->row('photo_albums', $id, true);
                if ($album['status'] === 'published' && ! activeGroupCan('albums.publish')) {
                    throw new DomainException('Izin penerbitan diperlukan untuk menghapus album terbit.');
                }
                $db = db_connect();
                if ($db->table('photos')->where('album_id', $id)->countAllResults() > 0
                    || $db->table('photo_folders')->where('album_id', $id)->countAllResults() > 0) {
                    throw new DomainException('Album belum kosong. Hapus foto dan folder, lalu tunggu penghapusan selesai.');
                }
                $this->checked($db->table('photo_albums')->where('id', $id)->delete());
            });
            return 'Album kosong telah dihapus.';
        });
    }

    private function uniqueFolder(int $albumId, int $parentId, string $name, int $except = 0): void
    {
        if (db_connect()->table('photo_folders')->where('album_id', $albumId)->where('parent_key', $parentId)
            ->where('name', $name)->where('id !=', $except)->countAllResults() > 0) {
            throw new DomainException('Nama folder sudah digunakan di lokasi ini.');
        }
    }

    /** Check every descendant after a move, including total path depth and cycles. */
    private function validateTree(int $albumId, int $movingId, int $parentId): void
    {
        $rows = db_connect()->table('photo_folders')->where('album_id', $albumId)->get()->getResultArray();
        $parents = [];
        foreach ($rows as $row) {
            $parents[(int) $row['id']] = (int) $row['parent_id'];
        }
        $parents[$movingId] = $parentId;
        foreach (array_keys($parents) as $cursor) {
            $seen = [];
            while ($cursor !== 0) {
                if (isset($seen[$cursor]) || ! array_key_exists($cursor, $parents)) {
                    throw new DomainException('Folder tidak dapat dipindah ke dirinya atau turunannya.');
                }
                $seen[$cursor] = true;
                if (count($seen) > 20) {
                    throw new DomainException('Kedalaman folder maksimal 20 tingkat.');
                }
                $cursor = $parents[$cursor];
            }
        }
    }

    public function createFolder()
    {
        $albumId = (int) $this->request->getPost('album_id');
        return $this->mutate('albums.manage', function () use ($albumId): string {
            $albumId = $this->integer($this->request->getPost('album_id'));
            $parentId = $this->integer($this->request->getPost('parent_id') ?? '0', true);
            $name = $this->name($this->request->getPost('name'));
            $this->transaction(function () use ($albumId, $parentId, $name): void {
                $this->row('photo_albums', $albumId, true);
                $this->folder($albumId, $parentId);
                $this->uniqueFolder($albumId, $parentId, $name);
                $this->validateTree($albumId, -1, $parentId);
                $now = PhotoDomain::now();
                $this->checked(db_connect()->table('photo_folders')->insert(['album_id' => $albumId,
                    'parent_id' => $parentId ?: null, 'parent_key' => $parentId, 'name' => $name,
                    'created_at' => $now, 'updated_at' => $now]));
            });
            return 'Folder baru telah dibuat.';
        }, $albumId);
    }

    public function updateFolder($id)
    {
        return $this->mutate('albums.manage', function () use ($id): string {
            $id = $this->integer($id);
            $snapshot = $this->row('photo_folders', $id);
            $albumId = (int) $snapshot['album_id'];
            $parentId = $this->integer($this->request->getPost('parent_id') ?? '0', true);
            $name = $this->name($this->request->getPost('name'));
            $this->transaction(function () use ($id, $albumId, $parentId, $name): void {
                $this->row('photo_albums', $albumId, true);
                $folder = $this->row('photo_folders', $id, true);
                $this->folder($albumId, $parentId);
                $this->uniqueFolder($albumId, $parentId, $name, $id);
                $this->validateTree($albumId, $id, $parentId);
                $this->checked(db_connect()->table('photo_folders')->where('id', $id)->update(['name' => $name,
                    'parent_id' => $parentId ?: null, 'parent_key' => $parentId, 'updated_at' => PhotoDomain::now()]));
                if ((int) $folder['parent_id'] !== $parentId) {
                    $this->revision($albumId);
                }
            });
            return 'Folder diperbarui. Pemindahan hanya tersedia di dalam album yang sama.';
        }, (int) $this->request->getPost('album_id'));
    }

    public function deleteFolder($id)
    {
        return $this->mutate('albums.manage', function () use ($id): string {
            if (! activeGroupCan('photos.delete')) {
                throw new DomainException('Peran aktif tidak memiliki izin penghapusan.');
            }
            $id = $this->integer($id);
            $snapshot = $this->row('photo_folders', $id);
            $this->transaction(function () use ($id, $snapshot): void {
                $this->row('photo_albums', (int) $snapshot['album_id'], true);
                $this->row('photo_folders', $id, true);
                $db = db_connect();
                if ($db->table('photo_folders')->where('parent_id', $id)->countAllResults() > 0
                    || $db->table('photos')->where('folder_id', $id)->countAllResults() > 0) {
                    throw new DomainException('Folder belum kosong, termasuk foto yang sedang dihapus.');
                }
                $this->checked($db->table('photo_folders')->where('id', $id)->delete());
            });
            return 'Folder kosong telah dihapus.';
        }, (int) $this->request->getPost('album_id'));
    }

    public function upload()
    {
        $this->guard('photos.upload');
        $albumId = (int) $this->request->getPost('album_id');
        try {
            $albumId = $this->integer($this->request->getPost('album_id'));
            $folderId = $this->integer($this->request->getPost('folder_id') ?? '0', true);
            if ($this->request->getPost('consent') !== '1') {
                throw new DomainException('Konfirmasi hak penggunaan dan persetujuan pemrosesan foto diperlukan.');
            }
            $this->row('photo_albums', $albumId);
            $this->folder($albumId, $folderId);
            $files = $this->request->getFileMultiple('photos') ?? [];
            if ($files === [] || count($files) > min(20, config('Photos')->maxBatch)) {
                throw new DomainException('Pilih 1–20 foto per unggahan.');
            }
            $storage = new PhotoStorageService();
            $report = [];
            foreach ($files as $file) {
                $stored = null;
                $committed = false;
                $label = mb_substr(preg_replace('~[/\\\\\p{Cc}\p{Cf}]~u', '_', $file->getClientName()) ?? 'Foto', 0, 120);
                try {
                    $name = $this->name($file->getClientName());
                    $stored = $storage->store($file);
                    $this->transaction(function () use ($stored, $name, $albumId, $folderId): void {
                        $this->row('photo_albums', $albumId, true);
                        $this->folder($albumId, $folderId);
                        $now = PhotoDomain::now();
                        $db = db_connect();
                        $hex = bin2hex(random_bytes(16));
                        $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
                            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
                        $this->checked($db->table('photos')->insert($stored + ['uuid' => $uuid,
                            'album_id' => $albumId, 'folder_id' => $folderId ?: null, 'name' => $name, 'status' => 'pending',
                            'face_count' => 0, 'created_by' => auth()->id(), 'created_at' => $now, 'updated_at' => $now]));
                        (new PhotoJobService($db))->enqueue((int) $db->insertID());
                    });
                    $committed = true;
                    $report[] = ['name' => $label, 'ok' => true, 'message' => 'Tersimpan · menunggu pemrosesan'];
                } catch (DomainException $exception) {
                    $report[] = ['name' => $label, 'ok' => false, 'message' => $exception->getMessage()];
                } catch (RuntimeException $exception) {
                    $report[] = ['name' => $label, 'ok' => false, 'message' => 'Gagal disimpan. Periksa format, ukuran, dan kesiapan penyimpanan.'];
                } finally {
                    if ($stored !== null && ! $committed) {
                        try {
                            $storage->delete($stored);
                        } catch (RuntimeException $exception) {
                            // No private path/error details are retained or shown.
                            $report[] = ['name' => $label, 'ok' => false, 'message' => 'Pembersihan berkas sementara perlu diperiksa administrator.'];
                        }
                    }
                }
            }
            return redirect()->to($this->albumUrl($albumId, $folderId))->with('photo_upload_report', $report);
        } catch (DomainException $exception) {
            return redirect()->to($this->albumUrl($albumId))->with('error', $exception->getMessage());
        } catch (RuntimeException $exception) {
            return redirect()->to($this->albumUrl($albumId))->with('error', 'Unggahan belum tersedia. Periksa penyimpanan dan coba lagi.');
        }
    }

    public function updatePhoto($id)
    {
        return $this->mutate('photos.manage', function () use ($id): string {
            $id = $this->integer($id);
            $snapshot = $this->row('photos', $id);
            $albumId = (int) $snapshot['album_id'];
            $folderId = $this->integer($this->request->getPost('folder_id') ?? '0', true);
            $name = $this->name($this->request->getPost('name'));
            $this->transaction(function () use ($id, $albumId, $folderId, $name): void {
                $this->row('photo_albums', $albumId, true);
                $photo = $this->row('photos', $id, true);
                if ($photo['status'] === 'deleting') {
                    throw new DomainException('Foto sedang dihapus dan tidak dapat diubah.');
                }
                $this->folder($albumId, $folderId);
                $this->checked(db_connect()->table('photos')->where('id', $id)->update(['name' => $name,
                    'folder_id' => $folderId ?: null, 'updated_at' => PhotoDomain::now()]));
                if ((int) $photo['folder_id'] !== $folderId) {
                    $this->revision($albumId);
                }
            });
            return 'Foto diperbarui. Pemindahan lintas album belum tersedia.';
        }, (int) $this->request->getPost('album_id'));
    }

    public function deletePhoto($id)
    {
        return $this->mutate('photos.delete', function () use ($id): string {
            (new PhotoJobService())->enqueue($this->integer($id), 'delete');
            return 'Akses foto dicabut. Penghapusan berkas dan indeks sedang diantrekan.';
        }, (int) $this->request->getPost('album_id'));
    }

    public function retryPhoto($id)
    {
        return $this->mutate('photos.upload', function () use ($id): string {
            $id = $this->integer($id);
            $photo = $this->row('photos', $id);
            if ($photo['status'] === 'deleting' && ! activeGroupCan('photos.delete')) {
                throw new DomainException('Izin penghapusan diperlukan untuk mengulang pembersihan.');
            }
            (new PhotoJobService())->retry($id);
            return 'Pekerjaan foto diantrekan ulang. Segarkan halaman untuk melihat perkembangan.';
        }, (int) $this->request->getPost('album_id'));
    }
}