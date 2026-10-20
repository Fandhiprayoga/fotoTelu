<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\FaceEngineClient;
use App\Libraries\PhotoSearchService;
use App\Libraries\SelfieService;
use CodeIgniter\Exceptions\PageNotFoundException;
use DomainException;
use RuntimeException;

class PhotoSearchController extends BaseController
{
    private function guard(bool $selfie = false): void
    {
        $this->response->setHeader('Cache-Control', 'private, no-store')->setHeader('X-Content-Type-Options', 'nosniff');
        if (! auth()->loggedIn() || ! activeGroupCan('photos.search') || ($selfie && ! activeGroupCan('selfies.manage'))) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function selectedAlbum(mixed $id): ?array
    {
        if (! is_string($id) || ! preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) || (float) $id > 2147483647) {
            return null;
        }
        return db_connect()->table('photo_albums')->select('id,name,status')->where('id', (int) $id)
            ->where('status', 'published')->get()->getRowArray();
    }

    public function index()
    {
        $this->guard();
        $db = db_connect();
        $albums = $db->table('photo_albums')->select('id,name')->where('status', 'published')->orderBy('name')->get()->getResultArray();
        $album = $this->selectedAlbum($this->request->getGet('album'));
        $selfie = (new SelfieService())->get((int) auth()->id());
        // Extract metadata, then discard the service row containing the encrypted embedding.
        $selfieStatus = $selfie === null ? null : ['consented_at' => $selfie['consented_at'],
            'updated_at' => $selfie['updated_at'], 'usable' => ! empty($selfie['consented_at'])
                && $selfie['model_version'] === config('Photos')->modelVersion];
        unset($selfie);
        $submitted = $album !== null && $this->request->getGet('results') === '1';
        $results = [];
        if ($submitted) {
            // GET only rehydrates this account's grants. A query flag never creates access.
            $candidates = $db->table('photo_matches m')->select('p.*')->join('photos p', 'p.id = m.photo_id')
                ->where('m.user_id', (int) auth()->id())->where('p.album_id', $album['id'])
                ->orderBy('m.score', 'DESC')->orderBy('p.id')->limit(200)->get()->getResultArray();
            $search = new PhotoSearchService();
            foreach ($candidates as $photo) {
                // Do not use the administrative bypass even for an admin's own search page.
                if ($search->authorized($photo, (int) auth()->id(), false)) {
                    $results[] = ['uuid' => $photo['uuid'], 'name' => $photo['name'],
                        'width' => $photo['width'], 'height' => $photo['height']];
                }
            }
        }
        $engineReady = false;
        try {
            if (trim(config('Photos')->serviceToken) !== '') {
                $engineReady = (new FaceEngineClient())->health()['ready'] === true;
            }
        } catch (RuntimeException $exception) {
            // Only a boolean reaches the view, never URLs, tokens or remote errors.
        }
        return $this->renderView('photos/search', ['title' => 'Temukan Foto Saya', 'page_title' => 'Pencarian Foto',
            'albums' => $albums, 'selectedAlbum' => $album, 'selfieStatus' => $selfieStatus,
            'submitted' => $submitted, 'results' => $results, 'engineReady' => $engineReady,
            'canManageSelfie' => activeGroupCan('selfies.manage'), 'canDownload' => activeGroupCan('photos.download')]);
    }

    public function search()
    {
        $this->guard();
        try {
            $album = $this->selectedAlbum($this->request->getPost('album_id'));
            if ($album === null) {
                throw new DomainException('Pilih satu album yang sudah diterbitkan.');
            }
            (new PhotoSearchService())->search((int) auth()->id(), (int) $album['id']);
            return redirect()->to(site_url('photos/search') . '?album=' . (int) $album['id'] . '&results=1');
        } catch (DomainException $exception) {
            return redirect()->to(site_url('photos/search'))->with('error', $exception->getMessage());
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('photos/search'))->with('error',
                'Pencarian belum dapat dilakukan. Pastikan selfie tersimpan, album masih terbit, dan layanan wajah sudah dikonfigurasi serta siap.');
        }
    }

    public function saveSelfie()
    {
        $this->guard(true);
        try {
            $file = $this->request->getFile('selfie');
            if ($file === null || ! $file->isValid()) {
                throw new DomainException('Pilih atau ambil foto selfie yang valid terlebih dahulu.');
            }
            if ($this->request->getPost('consent') !== '1') {
                throw new DomainException('Persetujuan penyimpanan dan pemrosesan selfie wajib diberikan.');
            }
            (new SelfieService())->save((int) auth()->id(), $file, true);
            return redirect()->to(site_url('photos/search'))->with('success', 'Selfie pribadi tersimpan. Hasil pencarian lama telah dicabut.');
        } catch (DomainException $exception) {
            return redirect()->to(site_url('photos/search'))->with('error', $exception->getMessage());
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('photos/search'))->with('error',
                'Selfie belum dapat disimpan. Gunakan JPEG, PNG, atau WebP dengan tepat satu wajah yang jelas. Pastikan layanan wajah dan enkripsi telah dikonfigurasi.');
        }
    }

    public function deleteSelfie()
    {
        $this->guard(true);
        try {
            (new SelfieService())->delete((int) auth()->id());
            return redirect()->to(site_url('photos/search'))->with('success', 'Selfie dan data wajah Anda dihapus. Semua hasil pencarian sebelumnya dicabut.');
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('photos/search'))->with('error',
                'Penghapusan belum selesai. Coba hapus kembali; apabila masih gagal, hubungi administrator untuk memastikan pencabutan dan pembersihan data.');
        }
    }
}