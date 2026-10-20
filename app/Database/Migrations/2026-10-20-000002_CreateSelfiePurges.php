<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** No user FK: pending physical erasure must survive account deletion. */
class CreateSelfiePurges extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'storage_key' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => false],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('storage_key');
        $this->forge->addKey('created_at');
        $this->forge->createTable('photo_selfie_purges', false,
            $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : []);
    }

    public function down(): void
    {
        $this->forge->dropTable('photo_selfie_purges', true);
    }
}