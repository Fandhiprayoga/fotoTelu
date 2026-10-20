<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\PhotoDomain;
use App\Libraries\SelfieService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PhotoCleanup extends BaseCommand
{
    protected $group = 'Photos';
    protected $name = 'photos:cleanup';
    protected $description = 'Hapus izin kedaluwarsa dan coba ulang maksimal 100 pembersihan berkas selfie tertunda.';

    public function run(array $params)
    {
        $db = db_connect();
        if (! $db->table('photo_matches')->where('expires_at <=', PhotoDomain::now())->delete()) {
            CLI::error('Izin pencarian belum dapat dibersihkan.');
            return EXIT_ERROR;
        }
        $purged = (new SelfieService(db: $db))->purgePending();
        $pending = $db->table('photo_selfie_purges')->countAllResults();
        CLI::write('Izin pencarian kedaluwarsa telah dibersihkan.');
        CLI::write("Berkas selfie dibersihkan: {$purged}; masih tertunda: {$pending}.");
        return EXIT_SUCCESS;
    }
}