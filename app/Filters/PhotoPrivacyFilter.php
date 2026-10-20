<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class PhotoPrivacyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtolower($request->getMethod()) !== 'post') {
            return;
        }
        $path = trim($request->getUri()->getPath(), '/');
        $bucket = str_ends_with($path, 'register') ? 'register' : (str_contains($path, 'admin/photos') ? 'upload' : 'selfie');
        $limit = $bucket === 'upload' ? 30 : 10;
        $identity = auth()->loggedIn() ? (string) auth()->id() : $request->getIPAddress();
        if (! service('throttler')->check('photo-' . $bucket . '-' . hash('sha256', $identity), $limit, 60)) {
            return service('response')->setStatusCode(429)->setHeader('Retry-After', '60')
                ->setHeader('Cache-Control', 'private, no-store')
                ->setBody('Terlalu banyak permintaan. Silakan coba lagi dalam satu menit.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('Pragma', 'no-cache')->setHeader('X-Content-Type-Options', 'nosniff');
    }
}