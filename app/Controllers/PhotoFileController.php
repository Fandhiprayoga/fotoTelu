<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\PhotoSearchService;
use App\Libraries\PhotoStorageService;
use CodeIgniter\Exceptions\PageNotFoundException;
use RuntimeException;

class PhotoFileController extends BaseController
{
    public function show(string $uuid, string $variant)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store')->setHeader('X-Content-Type-Options', 'nosniff');
        $admin = auth()->loggedIn() && activeGroupCan('photos.manage');
        if (! auth()->loggedIn() || (! $admin && ! activeGroupCan('photos.search'))
            || ! preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $uuid)
            || ! in_array($variant, ['thumbnail', 'original', 'download'], true)
            || ($variant === 'download' && ! activeGroupCan('photos.download'))) {
            throw PageNotFoundException::forPageNotFound();
        }
        try {
            $photo = db_connect()->table('photos')->where('uuid', $uuid)->get()->getRowArray();
            if ($photo === null || ! (new PhotoSearchService())->authorized($photo, (int) auth()->id(), $admin)) {
                throw PageNotFoundException::forPageNotFound();
            }
            $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (! isset($types[$photo['mime']])) {
                throw PageNotFoundException::forPageNotFound();
            }
            $thumbnail = $variant === 'thumbnail';
            $path = (new PhotoStorageService())->path($thumbnail ? $photo['thumbnail_key'] : $photo['storage_key']);
            if (! is_file($path) || ! is_readable($path)) {
                throw PageNotFoundException::forPageNotFound();
            }
            $stem = pathinfo(str_replace('\\', '/', (string) $photo['name']), PATHINFO_FILENAME);
            $stem = trim(preg_replace('/[^A-Za-z0-9 _.-]/', '_', $stem) ?? '', '. ');
            $filename = substr($stem !== '' ? $stem : 'foto', 0, 100)
                . ($thumbnail ? '-pratinjau.jpg' : '.' . $types[$photo['mime']]);
            // CI streams the private path in chunks; never load the entire original into PHP memory.
            $response = $this->response->download($path, null);
            $response->setFileName($filename)->setContentType($thumbnail ? 'image/jpeg' : $photo['mime'], '')
                ->setHeader('Cache-Control', 'private, no-store')->setHeader('Pragma', 'no-cache')
                ->setHeader('X-Content-Type-Options', 'nosniff');
            return $variant === 'download' ? $response : $response->inline();
        } catch (RuntimeException $exception) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}