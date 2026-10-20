<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePhotoDomain extends Migration
{
    private function integer(bool $nullable = false): array
    {
        return ['type' => 'INT', 'unsigned' => true, 'null' => $nullable];
    }

    private function text(int $length = 255, bool $nullable = false): array
    {
        return ['type' => 'VARCHAR', 'constraint' => $length, 'null' => $nullable];
    }

    private function fields(array $fields, bool $updated = true): void
    {
        $this->forge->addField(['id' => $this->integer() + ['auto_increment' => true]] + $fields + [
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ] + ($updated ? ['updated_at' => ['type' => 'DATETIME', 'null' => false]] : []));
        $this->forge->addPrimaryKey('id');
    }

    private function create(string $table): void
    {
        $this->forge->createTable($table, false, $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : []);
    }

    public function up(): void
    {
        $users = config('Auth')->tables['users'];
        $this->fields([
            'name' => $this->text(),
            'status' => $this->text(16) + ['default' => 'draft'],
            'revision' => $this->integer() + ['default' => 1],
            'created_by' => $this->integer(true),
        ]);
        $this->forge->addForeignKey('created_by', $users, 'id', '', 'SET NULL');
        $this->create('photo_albums');

        $this->fields([
            'album_id' => $this->integer(),
            'parent_id' => $this->integer(true),
            // Controllers must set parent_key = parent_id ?? 0, normalize names,
            // and enforce acyclic nesting. Root uniqueness is not nullable.
            'parent_key' => $this->integer() + ['default' => 0],
            'name' => $this->text(190),
        ]);
        $this->forge->addUniqueKey(['album_id', 'parent_key', 'name'], 'photo_folder_sibling');
        $this->forge->addUniqueKey(['id', 'album_id'], 'photo_folder_album');
        $this->forge->addForeignKey('album_id', 'photo_albums', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey(['parent_id', 'album_id'], 'photo_folders', ['id', 'album_id'], '', 'RESTRICT');
        $this->create('photo_folders');

        $this->fields([
            'uuid' => $this->text(36),
            'album_id' => $this->integer(),
            'folder_id' => $this->integer(true),
            'name' => $this->text(),
            'storage_key' => $this->text(80),
            'thumbnail_key' => $this->text(80),
            'mime' => $this->text(32),
            'bytes' => $this->integer(),
            'width' => $this->integer(),
            'height' => $this->integer(),
            'checksum' => $this->text(64),
            'status' => $this->text(16) + ['default' => 'pending'],
            'face_count' => $this->integer() + ['default' => 0],
            'model_version' => $this->text(80, true),
            'error' => $this->text(255, true),
            'created_by' => $this->integer(true),
        ]);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey(['album_id', 'status', 'model_version']);
        $this->forge->addForeignKey('album_id', 'photo_albums', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey(['folder_id', 'album_id'], 'photo_folders', ['id', 'album_id'], '', 'RESTRICT');
        $this->forge->addForeignKey('created_by', $users, 'id', '', 'SET NULL');
        $this->create('photos');

        $this->fields([
            'user_id' => $this->integer(),
            'storage_key' => $this->text(80),
            'embedding' => ['type' => 'TEXT', 'null' => false],
            'model_version' => $this->text(80),
            'version' => $this->text(32),
            // NULL is a revoked selfie awaiting retry of physical deletion.
            'consented_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addUniqueKey('user_id');
        $this->forge->addForeignKey('user_id', $users, 'id', '', 'CASCADE');
        $this->create('photo_selfies');

        $this->fields([
            'photo_id' => $this->integer(),
            'action' => $this->text(16) + ['default' => 'index'],
            'state' => $this->text(16) + ['default' => 'pending'],
            'attempts' => $this->integer() + ['default' => 0],
            'available_at' => ['type' => 'DATETIME', 'null' => false],
            'lease_until' => ['type' => 'DATETIME', 'null' => true],
            'claim_token' => $this->text(32, true),
            'error' => $this->text(255, true),
        ]);
        $this->forge->addKey(['state', 'available_at']);
        $this->forge->addKey(['photo_id', 'action']);
        $this->forge->addForeignKey('photo_id', 'photos', 'id', '', 'CASCADE');
        $this->create('photo_jobs');

        $this->fields([
            'user_id' => $this->integer(),
            'photo_id' => $this->integer(),
            'album_revision' => $this->integer(),
            'selfie_version' => $this->text(32),
            'model_version' => $this->text(80),
            'score' => ['type' => 'DOUBLE', 'null' => false],
            'expires_at' => ['type' => 'DATETIME', 'null' => false],
        ], false);
        $this->forge->addUniqueKey(['user_id', 'photo_id']);
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('user_id', $users, 'id', '', 'CASCADE');
        $this->forge->addForeignKey('photo_id', 'photos', 'id', '', 'CASCADE');
        $this->create('photo_matches');
    }

    public function down(): void
    {
        foreach (['photo_matches', 'photo_jobs', 'photo_selfies', 'photos', 'photo_folders', 'photo_albums'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}