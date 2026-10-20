<?php

declare(strict_types=1);

use App\Libraries\FaceEngineClient;
use App\Libraries\PhotoDomain;
use App\Libraries\PhotoJobService;
use App\Libraries\PhotoSearchService;
use App\Libraries\PhotoStorageService;
use App\Libraries\SelfieService;
use CodeIgniter\Encryption\Handlers\OpenSSLHandler;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Photos;
use Config\Services;

/** No network, real models, personal images, production DB, or external service. */
final class PhotoDomainTest extends CIUnitTestCase
{
    use DatabaseTestTrait { migrateDatabase as private migrateAppDatabase; }

    protected $namespace = 'App';
    protected $refresh = true;
    protected $migrateOnce = false;
    protected $seed = '';
    private Photos $photosConfig;
    private PhotoDomainEngineStub $engine;
    private PhotoStorageService $storage;
    private SelfieService $selfies;
    private PhotoSearchService $search;
    private PhotoJobService $jobs;
    private string $directory;
    private string $oldEncryptionKey;
    private int $user;
    private int $otherUser;
    private int $album;
    private array $photo;

    protected function migrateDatabase()
    {
        // Shield must exist before App's user foreign keys. Only App is refreshed;
        // do not migrate test-support or unrelated package migrations.
        $this->migrations->setNamespace('CodeIgniter\\Shield')->latest('tests');
        $this->migrateAppDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->photosConfig = new Photos();
        $this->photosConfig->threshold = 0.55;
        $this->directory = sys_get_temp_dir() . '/photo-db-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->storage = new PhotoStorageService($this->directory, $this->photosConfig);
        $this->engine = new PhotoDomainEngineStub($this->photosConfig);
        $encryption = config('Encryption');
        $this->oldEncryptionKey = $encryption->key;
        $encryption->key = str_repeat('test-only-not-a-production-key-', 2);
        Services::injectMock('encrypter', new OpenSSLHandler($encryption));
        $this->selfies = new SelfieService($this->db, $this->engine, $this->storage, $this->photosConfig);
        $this->search = new PhotoSearchService($this->db, $this->engine, $this->selfies, $this->photosConfig);
        $this->jobs = new PhotoJobService($this->db, $this->engine, $this->storage, $this->photosConfig);
        $this->user = $this->createUser();
        $this->otherUser = $this->createUser();
        $now = PhotoDomain::now();
        $this->db->table('photo_albums')->insert(['name' => 'Album', 'status' => 'published',
            'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->album = (int) $this->db->insertID();
        $this->photo = $this->createPhoto();
        $this->seedSelfie();
        $this->engine->matches = [['photo_id' => $this->photo['uuid'], 'score' => 0.8]];
    }

    protected function tearDown(): void
    {
        if (isset($this->oldEncryptionKey)) {
            config('Encryption')->key = $this->oldEncryptionKey;
            Services::resetSingle('encrypter');
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory . '/photos/*') ?: [] as $path) {
                unlink($path);
            }
            foreach (glob($this->directory . '/*') ?: [] as $path) {
                is_dir($path) ? rmdir($path) : unlink($path);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function createUser(): int
    {
        $this->db->table(PhotoDomain::users())->insert(['username' => 'photo-' . bin2hex(random_bytes(8)), 'active' => 1]);
        return (int) $this->db->insertID();
    }

    private function createPhoto(array $overrides = []): array
    {
        $now = PhotoDomain::now();
        $hex = bin2hex(random_bytes(16));
        $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20);
        $data = array_replace(['uuid' => $uuid, 'album_id' => $this->album,
            'name' => 'Synthetic.png', 'storage_key' => $hex . '.png', 'thumbnail_key' => $hex . '.jpg',
            'mime' => 'image/png', 'bytes' => 10, 'width' => 1, 'height' => 1, 'checksum' => str_repeat('a', 64),
            'status' => 'ready', 'face_count' => 1, 'model_version' => $this->photosConfig->modelVersion,
            'created_at' => $now, 'updated_at' => $now], $overrides);
        $this->db->table('photos')->insert($data);
        return ['id' => (int) $this->db->insertID()] + $data;
    }

    private function seedSelfie(): void
    {
        $now = PhotoDomain::now();
        $cipher = base64_encode(service('encrypter')->encrypt(json_encode([
            'embedding' => PhotoDomainEngineStub::vector(), 'model_version' => $this->photosConfig->modelVersion,
        ], JSON_THROW_ON_ERROR)));
        $this->db->table('photo_selfies')->insert(['user_id' => $this->user, 'storage_key' => str_repeat('c', 32) . '.enc',
            'embedding' => $cipher, 'version' => str_repeat('d', 32), 'model_version' => $this->photosConfig->modelVersion,
            'consented_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function testSearchGrantsAreUserScopedDeduplicatedAndThresholded(): void
    {
        $pending = $this->createPhoto(['status' => 'pending']);
        $oldModel = $this->createPhoto(['model_version' => 'old-model']);
        $this->engine->matches = [
            ['photo_id' => $this->photo['uuid'], 'score' => 0.6],
            ['photo_id' => $this->photo['uuid'], 'score' => 0.9],
            ['photo_id' => $pending['uuid'], 'score' => 0.99],
            ['photo_id' => $oldModel['uuid'], 'score' => 0.99],
            ['photo_id' => '00000000-0000-0000-0000-000000000000', 'score' => 0.99],
        ];
        $result = $this->search->search($this->user, $this->album);
        $this->assertCount(1, $result);
        $this->assertSame(0.9, $result[0]['score']);
        $this->assertSame([$this->photo['uuid']], $this->engine->scope);
        $this->assertTrue($this->search->authorized($this->photo, $this->user, false));
        $this->assertFalse($this->search->authorized($this->photo, $this->otherUser, false));
        $this->assertFalse($this->search->authorized($pending, $this->user, false));
        $this->engine->matches = [['photo_id' => $this->photo['uuid'], 'score' => 0.54]];
        $this->assertSame([], $this->search->search($this->user, $this->album));
        $this->assertFalse($this->search->authorized($this->photo, $this->user, false));
    }

    public function testLiveAlbumRevisionPublicationExpiryAndSelfieVersion(): void
    {
        $this->search->search($this->user, $this->album);
        $this->db->table('photo_albums')->where('id', $this->album)->update(['status' => 'draft']);
        $this->assertFalse($this->search->authorized($this->photo, $this->user, false));
        $this->db->table('photo_albums')->where('id', $this->album)->update(['status' => 'published', 'revision' => 2]);
        $this->assertFalse($this->search->authorized($this->photo, $this->user, false));
        $this->search->search($this->user, $this->album);
        $this->db->table('photo_matches')->where('user_id', $this->user)->update(['expires_at' => PhotoDomain::now(-1)]);
        $this->assertFalse($this->search->authorized($this->photo, $this->user, false));
        $this->search->search($this->user, $this->album);
        $this->db->table('photo_selfies')->where('user_id', $this->user)->update(['version' => str_repeat('e', 32)]);
        $this->assertFalse($this->search->authorized($this->photo, $this->user, false));
    }

    public function testFailureRevokesPreviousSearchAndNeverLeaksRemoteError(): void
    {
        $this->search->search($this->user, $this->album);
        $this->engine->unavailable = true;
        try {
            $this->search->search($this->user, $this->album);
            $this->fail('Failed search succeeded');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('private-engine-error', $e->getMessage());
        }
        $this->assertSame(0, $this->db->table('photo_matches')->where('user_id', $this->user)->countAllResults());
    }

    public function testPublicationChangeDuringInferenceCannotGrantAccess(): void
    {
        $this->engine->onSearch = function (): void {
            $this->db->table('photo_albums')->where('id', $this->album)->update(['revision' => 2]);
        };
        try {
            $this->search->search($this->user, $this->album);
            $this->fail('Stale publication accepted');
        } catch (RuntimeException $e) {
            $this->assertSame(0, $this->db->table('photo_matches')->countAllResults());
        }
    }

    public function testSelfieChangeDuringInferenceCannotGrantAccess(): void
    {
        $this->engine->onSearch = function (): void {
            $this->db->table('photo_selfies')->where('user_id', $this->user)->update(['version' => str_repeat('e', 32)]);
        };
        try {
            $this->search->search($this->user, $this->album);
            $this->fail('Stale selfie accepted');
        } catch (RuntimeException $e) {
            $this->assertSame(0, $this->db->table('photo_matches')->countAllResults());
        }
    }

    public function testWrongModelAndNonfiniteRemoteScoresFailClosed(): void
    {
        $this->engine->matches = [['photo_id' => $this->photo['uuid'], 'score' => NAN]];
        $this->expectException(RuntimeException::class);
        $this->search->search($this->user, $this->album);
    }

    public function testAdminPreviewReadsLiveStateNotCallerSuppliedStatus(): void
    {
        $this->db->table('photos')->where('id', $this->photo['id'])->update(['status' => 'failed']);
        $this->assertTrue($this->search->authorized($this->photo, $this->otherUser, true));
        $this->db->table('photos')->where('id', $this->photo['id'])->update(['status' => 'deleting']);
        $this->assertFalse($this->search->authorized($this->photo, $this->otherUser, true));
    }

    public function testSelfieDecryptionAndMissingKeyFailClosed(): void
    {
        $selfie = $this->selfies->get($this->user);
        $this->assertSame(PhotoDomainEngineStub::vector(), $this->selfies->embedding($selfie));
        $this->assertArrayNotHasKey('storage_key', $selfie);
        config('Encryption')->key = '';
        $this->expectException(RuntimeException::class);
        $this->selfies->embedding($selfie);
    }

    public function testConsentIsRequiredAndOldSelfiePreserved(): void
    {
        $before = $this->selfies->get($this->user);
        try {
            $this->selfies->save($this->user, new UploadedFile('/invalid', 'photo.png'), false);
            $this->fail('Missing consent accepted');
        } catch (RuntimeException $e) {
            $this->assertSame($before, $this->selfies->get($this->user));
        }
    }

    private function syntheticUpload(): UploadedFile
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required for selfie storage tests.');
        }
        $path = $this->directory . '/synthetic.png';
        $image = imagecreatetruecolor(32, 32);
        imagepng($image, $path);
        imagedestroy($image);
        return new class($path, 'synthetic.png', 'image/png', filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool { return true; }
        };
    }

    public function testSelfieReplacementEncryptsOriginalAndInvalidatesGrants(): void
    {
        $oldKey = str_repeat('c', 32) . '.enc';
        $this->storage->write($oldKey, 'old-encrypted-original');
        $before = $this->selfies->get($this->user);
        $this->search->search($this->user, $this->album);
        $this->selfies->save($this->user, $this->syntheticUpload(), true);
        $after = $this->selfies->get($this->user);
        $this->assertNotSame($before['version'], $after['version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $after['version']);
        $row = $this->db->table('photo_selfies')->where('user_id', $this->user)->get()->getRowArray();
        $ciphertext = $this->storage->read($row['storage_key']);
        $this->assertSame(file_get_contents($this->directory . '/synthetic.png'), service('encrypter')->decrypt($ciphertext));
        $this->assertNotSame(file_get_contents($this->directory . '/synthetic.png'), $ciphertext);
        $this->assertFileDoesNotExist($this->storage->path($oldKey));
        $this->assertCount(1, glob($this->directory . '/photos/*'));
        $this->assertSame(PhotoDomainEngineStub::vector(), $this->selfies->embedding($after));
        $this->assertFalse($this->search->authorized($this->photo, $this->user, false));
    }

    public function testEncodeFailurePreservesOldSelfieAndCleansPlaintext(): void
    {
        $this->storage->write(str_repeat('c', 32) . '.enc', 'old-encrypted-original');
        $before = $this->selfies->get($this->user);
        $this->engine->onEncode = static function (): void { throw new RuntimeException('private-engine-error'); };
        try {
            $this->selfies->save($this->user, $this->syntheticUpload(), true);
            $this->fail('Failed encoding succeeded');
        } catch (RuntimeException $e) {
            $this->assertSame($before, $this->selfies->get($this->user));
            $this->assertCount(1, glob($this->directory . '/photos/*'));
            $this->assertStringNotContainsString('private-engine-error', $e->getMessage());
        }
    }

    public function testQualityFailurePreservesOldSelfieAndCleansPlaintext(): void
    {
        $this->storage->write(str_repeat('c', 32) . '.enc', 'old-encrypted-original');
        $before = $this->selfies->get($this->user);
        $message = 'Wajah pada selfie terlalu buram. Ambil foto ulang.';
        $this->engine->onEncode = static function () use ($message): void { throw new DomainException($message); };
        try {
            $this->selfies->save($this->user, $this->syntheticUpload(), true);
            $this->fail('Invalid selfie accepted');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($before, $this->selfies->get($this->user));
            $this->assertCount(1, glob($this->directory . '/photos/*'));
        }
    }

    public function testFailedSelfieUnlinkRevokesAndRetainsRetryableMetadata(): void
    {
        $this->search->search($this->user, $this->album);
        $storage = new class($this->directory) extends PhotoStorageService {
            public function delete(array $photo): void { throw new RuntimeException('Berkas belum dapat dihapus.'); }
        };
        $service = new SelfieService($this->db, $this->engine, $storage, $this->photosConfig);
        try {
            $service->delete($this->user);
            $this->fail('Unlink failure ignored');
        } catch (RuntimeException $e) {
            $this->assertNull($service->get($this->user)['consented_at']);
            $this->assertSame('', $service->get($this->user)['embedding']);
            $this->assertSame(1, $this->db->table('photo_selfie_purges')->countAllResults());
            $this->assertFalse($this->search->authorized($this->photo, $this->user, false));
        }
        $this->selfies->delete($this->user);
        $this->assertNull($this->selfies->get($this->user));
        $this->assertSame(0, $this->db->table('photo_selfie_purges')->countAllResults());
    }

    private function faultStorage(): PhotoDomainPurgeStorageStub
    {
        return new PhotoDomainPurgeStorageStub($this->directory, $this->photosConfig);
    }

    public function testReplacementPurgeFailureKeepsNewActiveSelfieAndDurableOldKey(): void
    {
        $oldKey = str_repeat('c', 32) . '.enc';
        $this->storage->write($oldKey, 'old-encrypted-original');
        $storage = $this->faultStorage();
        $storage->failKeys = [$oldKey];
        $service = new SelfieService($this->db, $this->engine, $storage, $this->photosConfig);
        $service->save($this->user, $this->syntheticUpload(), true);
        $active = $service->get($this->user);
        $this->assertNotNull($active['consented_at']);
        $this->assertSame(PhotoDomainEngineStub::vector(), $service->embedding($active));
        $this->assertFileExists($storage->path($oldKey));
        $this->assertSame(1, $this->db->table('photo_selfie_purges')->where('storage_key', $oldKey)->countAllResults());
        $this->assertSame(0, $service->purgePending());
        $this->assertSame(1, $this->selfies->purgePending());
        $this->assertSame($active, $service->get($this->user));
        $this->assertFileDoesNotExist($storage->path($oldKey));
    }

    public function testFailedRevocationThenReplacementNeverLosesOldPurgeKey(): void
    {
        $oldKey = str_repeat('c', 32) . '.enc';
        $this->storage->write($oldKey, 'old-encrypted-original');
        $storage = $this->faultStorage();
        $storage->failKeys = [$oldKey];
        $service = new SelfieService($this->db, $this->engine, $storage, $this->photosConfig);
        try {
            $service->delete($this->user);
            $this->fail('Expected pending physical purge');
        } catch (RuntimeException $e) {
            $this->assertSame('', $service->get($this->user)['embedding']);
        }
        $service->save($this->user, $this->syntheticUpload(), true);
        $active = $service->get($this->user);
        $this->assertNotNull($active['consented_at']);
        $this->assertSame(1, $this->db->table('photo_selfie_purges')->where('storage_key', $oldKey)->countAllResults());
        $this->assertSame(1, $this->selfies->purgePending());
        $this->assertSame($active, $service->get($this->user));
    }

    public function testPurgeIsBoundedAndMissingFilesAreIdempotent(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->db->table('photo_selfie_purges')->insert([
                'storage_key' => sprintf('%032x.enc', $i), 'created_at' => PhotoDomain::now(),
            ]);
        }
        $this->assertSame(100, $this->selfies->purgePending());
        $this->assertSame(1, $this->db->table('photo_selfie_purges')->countAllResults());
        $this->assertSame(1, $this->selfies->purgePending());
        $this->assertSame(0, $this->selfies->purgePending());
    }

    public function testPurgeCannotDeleteAnActiveSelfie(): void
    {
        $key = str_repeat('c', 32) . '.enc';
        $this->storage->write($key, 'active-encrypted-original');
        $this->db->table('photo_selfie_purges')->insert(['storage_key' => $key, 'created_at' => PhotoDomain::now()]);
        $this->assertSame(0, $this->selfies->purgePending());
        $this->assertFileExists($this->storage->path($key));
        $this->assertNotNull($this->selfies->get($this->user)['consented_at']);
    }

    public function testAccountDeletionKeepsJournalAcrossCascadeAndPurgesOnlyAfterCommit(): void
    {
        $key = str_repeat('c', 32) . '.enc';
        $this->storage->write($key, 'encrypted-original');
        $storage = $this->faultStorage();
        $storage->failKeys = [$key];
        $service = new SelfieService($this->db, $this->engine, $storage, $this->photosConfig);
        $service->deleteAccount($this->user, function () use ($key): bool {
            $this->assertFileExists($this->storage->path($key));
            $this->assertSame('', $this->selfies->get($this->user)['embedding']);
            $this->assertSame(1, $this->db->table('photo_selfie_purges')->where('storage_key', $key)->countAllResults());
            return $this->db->table(PhotoDomain::users())->where('id', $this->user)->delete();
        });
        $this->assertSame(0, $this->db->table(PhotoDomain::users())->where('id', $this->user)->countAllResults());
        $this->assertNull($service->get($this->user));
        $this->assertFileExists($storage->path($key));
        $this->assertSame(1, $this->db->table('photo_selfie_purges')->countAllResults());
        $this->assertSame(1, $this->selfies->purgePending());
        $this->assertFileDoesNotExist($storage->path($key));
    }

    public function testAccountDeletionRollbackRetainsSelfieAndFile(): void
    {
        $key = str_repeat('c', 32) . '.enc';
        $this->storage->write($key, 'encrypted-original');
        $before = $this->selfies->get($this->user);
        try {
            $this->selfies->deleteAccount($this->user, function () use ($key): bool {
                $this->assertFileExists($this->storage->path($key));
                $this->db->table(PhotoDomain::users())->where('id', $this->user)->delete();
                return false;
            });
            $this->fail('Failed account deletion committed');
        } catch (RuntimeException $e) {
            $this->assertSame($before, $this->selfies->get($this->user));
            $this->assertFileExists($this->storage->path($key));
            $this->assertSame(0, $this->db->table('photo_selfie_purges')->countAllResults());
        }
    }

    public function testAccountDeletedDuringEncodingPreventsInFlightSave(): void
    {
        $this->engine->onEncode = function (): void {
            $this->selfies->deleteAccount($this->user,
                fn () => $this->db->table(PhotoDomain::users())->where('id', $this->user)->delete());
        };
        try {
            $this->selfies->save($this->user, $this->syntheticUpload(), true);
            $this->fail('Save resurrected a deleted account selfie');
        } catch (RuntimeException $e) {
            $this->assertNull($this->selfies->get($this->user));
            $this->assertSame(0, $this->db->table(PhotoDomain::users())->where('id', $this->user)->countAllResults());
            $this->assertCount(0, glob($this->directory . '/photos/*'));
        }
    }

    public function testReplacementDuringRevokedUnlinkSurvivesConditionalRowDeletion(): void
    {
        $storage = $this->faultStorage();
        $storage->onDelete = function (): void {
            $this->selfies->save($this->user, $this->syntheticUpload(), true);
        };
        $service = new SelfieService($this->db, $this->engine, $storage, $this->photosConfig);
        $service->delete($this->user);
        $active = $service->get($this->user);
        $this->assertNotNull($active['consented_at']);
        $this->assertSame(PhotoDomainEngineStub::vector(), $service->embedding($active));
        $this->assertSame(0, $this->db->table('photo_selfie_purges')->countAllResults());
    }

    public function testEnvironmentEncryptionKeyPrecedesLocalFallback(): void
    {
        $this->photosConfig->localEncryptionKey = str_repeat('a', 64);
        $this->assertSame(PhotoDomainEngineStub::vector(), $this->selfies->embedding($this->selfies->get($this->user)));
    }

    public function testPrivateSecretsLoaderRejectsInsecureMalformedAndSymlinkFiles(): void
    {
        // Only synthetic test secrets; never read the application's secret file.
        $path = $this->directory . '/synthetic-secrets.json';
        $valid = ['service_token' => str_repeat('a', 64), 'encryption_key' => str_repeat('b', 64)];
        file_put_contents($path, json_encode($valid));
        chmod($path, 0600);
        $this->assertSame($valid, Photos::readPrivateSecrets($path));
        foreach ([['mode' => 0644, 'bytes' => json_encode($valid)],
            ['mode' => 0600, 'bytes' => '{partial'],
            ['mode' => 0600, 'bytes' => json_encode(array_replace($valid, ['encryption_key' => 'invalid']))],
            ['mode' => 0600, 'bytes' => json_encode(array_replace($valid, ['service_token' => []]))]] as $case) {
            file_put_contents($path, $case['bytes']);
            chmod($path, $case['mode']);
            try {
                Photos::readPrivateSecrets($path);
                $this->fail('Invalid secret configuration accepted');
            } catch (RuntimeException $e) {
                $this->assertStringNotContainsString($valid['encryption_key'], $e->getMessage());
            }
        }
        $link = $this->directory . '/synthetic-link.json';
        symlink($path, $link);
        $this->expectException(RuntimeException::class);
        Photos::readPrivateSecrets($link);
    }

    public function testSelfieDeletionRemovesEncryptedOriginalAndGrants(): void
    {
        $key = str_repeat('c', 32) . '.enc';
        $this->storage->write($key, 'encrypted-original-fixture');
        $this->search->search($this->user, $this->album);
        $this->selfies->delete($this->user);
        $this->assertNull($this->selfies->get($this->user));
        $this->assertFileDoesNotExist($this->storage->path($key));
        $this->assertFalse($this->search->authorized($this->photo, $this->user, false));
        $this->selfies->delete($this->user);
    }

    public function testIndexAndNoFaceJobsComplete(): void
    {
        $this->jobs->enqueue((int) $this->photo['id']);
        $this->jobs->enqueue((int) $this->photo['id']);
        $this->assertSame(1, $this->db->table('photo_jobs')->countAllResults());
        $this->assertTrue($this->jobs->runOne());
        $this->assertSame('ready', $this->db->table('photos')->where('id', $this->photo['id'])->get()->getRowArray()['status']);
        $this->assertSame('done', $this->db->table('photo_jobs')->get()->getRowArray()['state']);
        $this->engine->faces = [];
        $this->jobs->retry((int) $this->photo['id']);
        $this->assertTrue($this->jobs->runOne());
        $this->assertSame('no_face', $this->db->table('photos')->where('id', $this->photo['id'])->get()->getRowArray()['status']);
        $this->assertFalse($this->jobs->runOne());
    }

    public function testIndexCannotOverwriteConcurrentDeleteOrChecksumChange(): void
    {
        $this->jobs->enqueue((int) $this->photo['id']);
        $this->engine->onIndex = function (): void {
            $this->jobs->enqueue((int) $this->photo['id'], 'delete');
        };
        $this->jobs->runOne();
        $this->assertSame('deleting', $this->db->table('photos')->where('id', $this->photo['id'])->get()->getRowArray()['status']);
        $this->assertFalse($this->search->authorized($this->photo, $this->user, true));
        $this->engine->onIndex = null;
        $this->jobs->runOne();
        $this->assertSame(0, $this->db->table('photos')->where('id', $this->photo['id'])->countAllResults());
        $this->assertSame([$this->photo['uuid']], $this->engine->deleted);
    }

    public function testIndexCannotMarkChangedChecksumReady(): void
    {
        $this->jobs->enqueue((int) $this->photo['id']);
        $this->engine->onIndex = function (): void {
            $this->db->table('photos')->where('id', $this->photo['id'])->update(['checksum' => str_repeat('b', 64)]);
        };
        $this->jobs->runOne();
        $this->assertSame('pending', $this->db->table('photos')->where('id', $this->photo['id'])->get()->getRowArray()['status']);
    }

    public function testExpiredLeaseIsReclaimedAndClaimTokenChanges(): void
    {
        $this->jobs->enqueue((int) $this->photo['id']);
        $this->db->table('photo_jobs')->update(['state' => 'working', 'attempts' => 1,
            'claim_token' => str_repeat('a', 32), 'lease_until' => PhotoDomain::now(-1)]);
        $this->jobs->runOne();
        $job = $this->db->table('photo_jobs')->get()->getRowArray();
        $this->assertSame('done', $job['state']);
        $this->assertSame(2, (int) $job['attempts']);
        $this->assertNull($job['claim_token']);
    }

    public function testFifthExpiredLeaseFailsWithoutSixthEngineAttempt(): void
    {
        $this->jobs->enqueue((int) $this->photo['id']);
        $this->db->table('photo_jobs')->update(['state' => 'working', 'attempts' => 5,
            'claim_token' => str_repeat('a', 32), 'lease_until' => PhotoDomain::now(-1)]);
        $this->assertFalse($this->jobs->runOne());
        $job = $this->db->table('photo_jobs')->get()->getRowArray();
        $this->assertSame('failed', $job['state']);
        $this->assertSame(5, (int) $job['attempts']);
        $this->assertSame('failed', $this->db->table('photos')->where('id', $this->photo['id'])->get()->getRowArray()['status']);
    }

    public function testUnexpiredLeaseCannotBeStolen(): void
    {
        $this->jobs->enqueue((int) $this->photo['id']);
        $token = str_repeat('b', 32);
        $this->db->table('photo_jobs')->update(['state' => 'working', 'attempts' => 1,
            'claim_token' => $token, 'lease_until' => PhotoDomain::now(90)]);
        $this->assertFalse($this->jobs->runOne());
        $this->assertSame($token, $this->db->table('photo_jobs')->get()->getRowArray()['claim_token']);
    }

    public function testDeleteFailureRetainsMetadataFilesAndDurableJobForManualRetry(): void
    {
        $this->storage->write($this->photo['storage_key'], 'private-original');
        $this->storage->write($this->photo['thumbnail_key'], 'private-thumbnail');
        $this->jobs->enqueue((int) $this->photo['id'], 'delete');
        $this->engine->unavailable = true;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertTrue($this->jobs->runOne());
            $this->db->table('photo_jobs')->update(['available_at' => PhotoDomain::now(-1)]);
        }
        $job = $this->db->table('photo_jobs')->get()->getRowArray();
        $this->assertSame('failed', $job['state']);
        $this->assertSame(5, (int) $job['attempts']);
        $this->assertStringNotContainsString('private-engine-error', $job['error']);
        $this->assertFileExists($this->storage->path($this->photo['storage_key']));
        $this->assertFalse($this->jobs->runOne());
        $this->engine->unavailable = false;
        $this->jobs->retry((int) $this->photo['id']);
        $this->jobs->runOne();
        $this->assertSame(0, $this->db->table('photos')->where('id', $this->photo['id'])->countAllResults());
        $this->assertSame(0, $this->db->table('photo_jobs')->countAllResults());
        $this->assertFileDoesNotExist($this->storage->path($this->photo['storage_key']));
        $this->assertFileDoesNotExist($this->storage->path($this->photo['thumbnail_key']));
    }

    public function testPhotoAndUserForeignKeysCascadePrivateMetadata(): void
    {
        $this->search->search($this->user, $this->album);
        $this->db->table(PhotoDomain::users())->where('id', $this->user)->delete();
        $this->assertSame(0, $this->db->table('photo_selfies')->where('user_id', $this->user)->countAllResults());
        $this->assertSame(0, $this->db->table('photo_matches')->where('user_id', $this->user)->countAllResults());
        $this->assertSame(1, $this->db->table('photos')->where('id', $this->photo['id'])->countAllResults());
    }

    public function testSiblingRootFolderUniqueness(): void
    {
        $folder = ['album_id' => $this->album, 'parent_id' => null, 'parent_key' => 0, 'name' => 'normalized',
            'created_at' => PhotoDomain::now(), 'updated_at' => PhotoDomain::now()];
        $this->db->table('photo_folders')->insert($folder);
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->db->table('photo_folders')->insert($folder);
    }
}

class PhotoDomainPurgeStorageStub extends PhotoStorageService
{
    public array $failKeys = [];
    public ?Closure $onDelete = null;

    public function delete(array $photo): void
    {
        if (in_array($photo['storage_key'] ?? '', $this->failKeys, true)) {
            throw new RuntimeException('private-unlink-error');
        }
        $callback = $this->onDelete;
        $this->onDelete = null;
        if ($callback !== null) {
            $callback();
        }
        parent::delete($photo);
    }
}

class PhotoDomainEngineStub extends FaceEngineClient
{
    public array $matches = [];
    public array $faces = [['id' => '00000000-0000-0000-0000-000000000001', 'box' => [0, 0, 1, 1]]];
    public array $scope = [];
    public array $deleted = [];
    public bool $unavailable = false;
    public ?Closure $onSearch = null;
    public ?Closure $onIndex = null;
    public ?Closure $onEncode = null;

    public static function vector(): array
    {
        $vector = array_fill(0, 128, 0.0);
        $vector[0] = 1.0;
        return $vector;
    }

    public function health(): array
    {
        if ($this->unavailable) {
            throw new RuntimeException('private-engine-error');
        }
        return ['ready' => true, 'model_version' => $this->config->modelVersion];
    }

    public function encodeSelfie(string $path): array
    {
        $this->health();
        ($this->onEncode ?? static function (): void {})();
        return ['model_version' => $this->config->modelVersion, 'embedding' => self::vector()];
    }

    public function search(array $embedding, array $photoUuids): array
    {
        $this->health();
        $this->scope = $photoUuids;
        ($this->onSearch ?? static function (): void {})();
        return ['matches' => $this->matches, 'model_version' => $this->config->modelVersion];
    }

    public function indexPhoto(array $photo): array
    {
        $this->health();
        ($this->onIndex ?? static function (): void {})();
        return ['faces' => $this->faces, 'model_version' => $this->config->modelVersion];
    }

    public function deletePhoto(string $uuid): void
    {
        $this->health();
        $this->deleted[] = $uuid;
    }
}