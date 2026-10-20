<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\PhotoJobService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Run under a supervisor in production. Ctrl+C/SIGTERM stops after the current job. */
class PhotoWorker extends BaseCommand
{
    protected $group = 'Photos';
    protected $name = 'photos:work';
    protected $description = 'Proses antrean foto privat. Ctrl+C untuk berhenti setelah pekerjaan berjalan.';
    protected $usage = 'photos:work [--once]';
    protected $options = ['--once' => 'Proses maksimal satu pekerjaan lalu keluar.'];

    public function run(array $params)
    {
        $stop = false;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $handler = static function () use (&$stop): void { $stop = true; };
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGTERM, $handler);
        }
        $worker = new PhotoJobService();
        $once = CLI::getOption('once') !== null || in_array('--once', $params, true);
        do {
            $worked = $worker->runOne();
            if (! $worked && ! $once && ! $stop) {
                usleep(500000);
            }
        } while (! $once && ! $stop);
    }
}