<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Photos;

class PhotoSetup extends BaseCommand
{
    protected $group = 'FotoTelu';
    protected $name = 'photos:setup';
    protected $description = 'Membuat rahasia lokal privat tanpa menampilkannya atau mengganti kunci yang sudah ada.';

    public function run(array $params)
    {
        $path = WRITEPATH . 'photo-secrets.json';
        $oldMask = umask(0077);
        $temporary = null;
        try {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat !== false) {
                if (($stat['mode'] & 0170000) !== 0100000 || ! @chmod($path, 0600)) {
                    throw new \RuntimeException();
                }
                Photos::readPrivateSecrets($path);
                CLI::write('Konfigurasi privat valid; izin 0600 diterapkan, kunci tidak diubah.', 'yellow');
                CLI::write('encryption.key mengungguli kunci lokal. Pergantian kunci tidak memigrasikan selfie lama; migrasikan atau hapus dengan kunci lama terlebih dahulu.', 'yellow');
                return EXIT_SUCCESS;
            }
            // Publish only a complete, private JSON file; never leave partial JSON
            // at the final path and never overwrite a concurrent setup's secrets.
            $temporary = @tempnam(WRITEPATH, '.photo-secrets-');
            if ($temporary === false || ! @chmod($temporary, 0600)) {
                throw new \RuntimeException();
            }
            $handle = @fopen($temporary, 'wb');
            if ($handle === false) {
                throw new \RuntimeException();
            }
            try {
                $payload = json_encode(['service_token' => bin2hex(random_bytes(32)),
                    'encryption_key' => bin2hex(random_bytes(32))], JSON_THROW_ON_ERROR);
                $offset = 0;
                while ($offset < strlen($payload)) {
                    $written = @fwrite($handle, substr($payload, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException();
                    }
                    $offset += $written;
                }
                if (! @fflush($handle) || (function_exists('fsync') && ! @fsync($handle))) {
                    throw new \RuntimeException();
                }
            } finally {
                fclose($handle);
            }
            Photos::readPrivateSecrets($temporary);
            if (! @link($temporary, $path)) {
                throw new \RuntimeException();
            }
            CLI::write('Rahasia lokal berhasil dibuat. Simpan backup terenkripsi; jangan hapus kunci selama selfie masih tersimpan.', 'green');
            CLI::write('encryption.key mengungguli kunci lokal. Pergantian kunci tidak memigrasikan selfie lama; migrasikan atau hapus dengan kunci lama terlebih dahulu.', 'yellow');
            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error('Konfigurasi privat tidak dapat dibuat atau divalidasi; kunci tidak diganti. Periksa berkas reguler, JSON valid, dan izin 0600.');
            return EXIT_ERROR;
        } finally {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            umask($oldMask);
        }
    }
}