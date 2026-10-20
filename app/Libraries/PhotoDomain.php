<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/** Shared transactional primitives. All timestamps are UTC, including grant expiry. */
final class PhotoDomain
{
    public static function now(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    public static function transaction(BaseConnection $db, callable $callback): mixed
    {
        if (! $db->transBegin()) {
            throw new RuntimeException('Transaksi foto tidak dapat dimulai.');
        }
        try {
            $result = $callback();
            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new RuntimeException('Perubahan foto tidak dapat disimpan.');
            }
            return $result;
        } catch (Throwable $e) {
            $db->transRollback();
            throw new RuntimeException('Perubahan foto tidak dapat disimpan. Silakan coba lagi.');
        }
    }

    /** Locking reads avoid stale MySQL REPEATABLE READ snapshots after a lock wait. */
    public static function lock(BaseConnection $db, string $table, int $id): ?array
    {
        $builder = $db->table($table)->where('id', $id);
        if ($db->DBDriver === 'MySQLi') {
            return $db->query($builder->getCompiledSelect() . ' FOR UPDATE')->getRowArray();
        }
        if (! $db->table($table)->where('id', $id)->set('id', 'id', false)->update()) {
            throw new RuntimeException('Data foto tidak dapat dikunci.');
        }
        return $db->table($table)->where('id', $id)->get()->getRowArray();
    }

    public static function users(): string
    {
        return config('Auth')->tables['users'];
    }
}