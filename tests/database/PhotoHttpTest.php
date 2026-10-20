<?php

declare(strict_types=1);

use App\Libraries\PhotoDomain;
use App\Libraries\PhotoStorageService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real routes, filters, Shield session auth and private storage; no network or live DB. */
final class PhotoHttpTest extends CIUnitTestCase
{
    use DatabaseTestTrait {
        loadDependencies as private loadTestDatabaseDependencies;
        migrateDatabase as private migrateAppDatabase;
    }
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $DBGroup = 'tests';
    protected $namespace = 'App';
    protected $refresh = true;
    protected $migrateOnce = false;
    protected $seed = '';

    private array $users = [];
    private array $fixturePaths = [];
    private bool $createdStorageDirectory = false;
    private string $previousServiceToken;
    private string $jpeg;
    private int $album;
    private array $photo;
    private const SELFIE_VERSION = 'dddddddddddddddddddddddddddddddd';

    public function loadDependencies()
    {
        // Fail BEFORE connecting or migrating if an environment override points elsewhere.
        $database = config('Database');
        self::assertSame('testing', ENVIRONMENT);
        self::assertSame('tests', $database->defaultGroup);
        self::assertSame('SQLite3', $database->tests['DBDriver']);
        self::assertSame(':memory:', $database->tests['database']);
        self::assertEmpty($database->tests['DSN']);
        $this->loadTestDatabaseDependencies();
        self::assertSame('SQLite3', $this->db->DBDriver);
        self::assertSame(':memory:', $this->db->database);
        self::assertSame($this->db, db_connect(), 'Controllers must use the isolated test connection.');
    }

    protected function migrateDatabase()
    {
        // Shield must precede App foreign keys. Settings is used by filters and layout.
        $this->migrations->setNamespace('CodeIgniter\\Shield')->latest('tests');
        $this->migrations->setNamespace('CodeIgniter\\Settings')->latest('tests');
        $this->migrateAppDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        helper(['auth', 'setting', 'form']);
        $this->previousServiceToken = config('Photos')->serviceToken;
        config('Photos')->serviceToken = ''; // Health UI explicitly skips HTTP when unconfigured.
        Services::resetSingle('auth');
        $_SESSION = [];
        $this->withSession([]);
        self::assertTrue($this->getPrivateProperty($this->app, 'enableFilters'));

        foreach (['user', 'other', 'manager', 'admin'] as $role) {
            $group = $role === 'other' ? 'user' : $role;
            $provider = auth()->getProvider();
            $user = new User(['username' => 'photo-http-' . bin2hex(random_bytes(8)), 'active' => 1]);
            self::assertTrue($provider->save($user));
            $user = $provider->findById($provider->getInsertID());
            $user->addGroup($group);
            $this->users[$role] = $user;
        }

        $now = PhotoDomain::now();
        self::assertTrue($this->db->table('photo_albums')->insert([
            'name' => 'HTTP synthetic album', 'status' => 'published', 'revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $this->album = (int) $this->db->insertID();

        // Controller uses `new PhotoStorageService`, not a replaceable CI service.
        // Use exclusive random keys in its actual private root, never existing files.
        $this->createdStorageDirectory = ! is_dir(WRITEPATH . 'photos');
        $storage = new PhotoStorageService();
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        try {
            self::assertTrue(imagejpeg($image, null, 90));
            $this->jpeg = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        self::assertSame('image/jpeg', (new finfo(FILEINFO_MIME_TYPE))->buffer($this->jpeg));
        $key = bin2hex(random_bytes(16));
        $thumbnail = bin2hex(random_bytes(16));
        foreach ([$key . '.jpg', $thumbnail . '.jpg'] as $filename) {
            $this->fixturePaths[] = $storage->path($filename);
            $storage->write($filename, $this->jpeg);
        }
        $this->photo = [
            'uuid' => substr($key, 0, 8) . '-' . substr($key, 8, 4) . '-' . substr($key, 12, 4)
                . '-' . substr($key, 16, 4) . '-' . substr($key, 20),
            'album_id' => $this->album, 'name' => 'Synthetic HTTP.jpg',
            'storage_key' => $key . '.jpg', 'thumbnail_key' => $thumbnail . '.jpg',
            'mime' => 'image/jpeg', 'bytes' => strlen($this->jpeg), 'width' => 1, 'height' => 1,
            'checksum' => hash('sha256', $this->jpeg), 'status' => 'ready', 'face_count' => 1,
            'model_version' => config('Photos')->modelVersion, 'created_at' => $now, 'updated_at' => $now,
        ];
        self::assertTrue($this->db->table('photos')->insert($this->photo));
        $this->photo['id'] = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->fixturePaths as $path) {
                if (is_file($path)) {
                    self::assertTrue(unlink($path), 'Synthetic fixture must be removed.');
                }
            }
            if ($this->createdStorageDirectory && is_dir(WRITEPATH . 'photos')
                && count(scandir(WRITEPATH . 'photos')) === 2) {
                self::assertTrue(rmdir(WRITEPATH . 'photos'));
            }
            if (isset($this->previousServiceToken)) {
                config('Photos')->serviceToken = $this->previousServiceToken;
            }
            // Shield migrations persist between App refreshes; do not accumulate users.
            foreach ($this->users as $user) {
                $this->db->table(PhotoDomain::users())->where('id', $user->id)->delete();
            }
            Services::resetSingle('auth');
            Services::resetSingle('security');
            Services::resetSingle('settings');
            $_SESSION = [];
        } finally {
            parent::tearDown();
        }
    }

    private function loginAs(string $role, ?string $activeGroup = null): void
    {
        $_SESSION = [];
        Services::resetSingle('auth');
        $this->actingAs($this->users[$role]); // Shield's real session login helper.
        session()->set('active_group', $activeGroup ?? ($role === 'other' ? 'user' : $role));
        $this->withSession();
    }

    private function request(string $method, string $path, ?array $data = null): TestResponse
    {
        // A new HTTP request must rehydrate Shield from the saved session, not a cached user.
        Services::resetSingle('auth');
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        Services::resetSingle('security');
        return $this->call($method, $path, $data);
    }

    private function fileUrl(string $variant = 'download'): string
    {
        return 'photo-files/' . $this->photo['uuid'] . '/' . $variant;
    }

    private function assertPrivate(TestResponse $result): void
    {
        $response = $result->response();
        $cache = strtolower($response->getHeaderLine('Cache-Control'));
        self::assertContains('private', array_map('trim', explode(',', $cache)));
        self::assertContains('no-store', array_map('trim', explode(',', $cache)));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertFalse($response->hasHeader('Debugbar-Link'));
        self::assertStringNotContainsString('id="debug-icon"', (string) $response->getBody());
        self::assertStringNotContainsString('id="debug-bar"', (string) $response->getBody());
        self::assertStringNotContainsString('ci-debugbar', (string) $response->getBody());
    }

    private function grant(): void
    {
        $now = PhotoDomain::now();
        $userId = (int) $this->users['user']->id;
        self::assertTrue($this->db->table('photo_selfies')->insert([
            'user_id' => $userId, 'storage_key' => bin2hex(random_bytes(16)) . '.enc',
            // Authorization uses metadata only. No original selfie, encryption key or engine needed.
            'embedding' => 'synthetic-unused-ciphertext', 'version' => self::SELFIE_VERSION,
            'model_version' => config('Photos')->modelVersion, 'consented_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        self::assertTrue($this->db->table('photo_matches')->insert([
            'user_id' => $userId, 'photo_id' => $this->photo['id'], 'album_revision' => 1,
            'selfie_version' => self::SELFIE_VERSION, 'model_version' => config('Photos')->modelVersion,
            'score' => 0.99, 'expires_at' => PhotoDomain::now(600), 'created_at' => $now,
        ]));
    }

    private function assertFileDenied(string $variant): void
    {
        // CI's feature harness propagates a 404 exception instead of rendering the error page.
        try {
            $result = $this->request('GET', $this->fileUrl($variant));
        } catch (PageNotFoundException $exception) {
            self::assertSame(404, $exception->getCode());
            return;
        }
        $result->assertStatus(404);
        self::assertNotInstanceOf(DownloadResponse::class, $result->response());
        self::assertStringNotContainsString($this->jpeg, (string) $result->response()->getBody());
    }

    public static function anonymousRoutes(): iterable
    {
        yield 'manager list' => ['admin/photos'];
        yield 'search' => ['photos/search'];
        yield 'raw file' => ['photo-files/00000000-0000-0000-0000-000000000000/download'];
    }

    #[DataProvider('anonymousRoutes')]
    public function testAnonymousRequestsRedirectToLogin(string $path): void
    {
        $result = $this->request('GET', $path);
        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('login'));
    }

    public function testActiveUserGroupCannotInheritPhotoManagementFromAnotherGroup(): void
    {
        $this->users['user']->addGroup('admin');
        $this->loginAs('user', 'user');
        try {
            $result = $this->request('GET', 'admin/photos');
        } catch (PageNotFoundException $exception) {
            self::fail('Expected the configured permission-filter redirect, got HTTP 404. Active group: '
                . activeGroup() . '; route filters: ' . json_encode(service('router')->getFilters())
                . '; resolved permission alias: ' . config('Filters')->aliases['permission']
                . '; previous exception: ' . ($exception->getPrevious()?->getMessage() ?? 'none'));
        }
        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('dashboard'));
        self::assertSame('user', session('active_group'));
    }

    public function testOrdinaryUserCannotAccessPhotoManager(): void
    {
        $this->loginAs('user');
        $result = $this->request('GET', 'admin/photos');
        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('dashboard'));
        self::assertSame('user', session('active_group'));
    }

    public function testManagerCannotAccessSelfServiceSearch(): void
    {
        $this->loginAs('manager');
        $result = $this->request('GET', 'photos/search');
        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('dashboard'));
    }

    public function testAdminManagerPagesRenderAndArePrivate(): void
    {
        $this->loginAs('admin');
        foreach (['admin/photos' => 'Pustaka Foto', 'admin/photos/album/' . $this->album => 'Synthetic HTTP.jpg'] as $path => $marker) {
            $result = $this->request('GET', $path);
            $result->assertStatus(200);
            self::assertStringContainsString($marker, (string) $result->response()->getBody());
            self::assertStringContainsString('HTTP synthetic album', (string) $result->response()->getBody());
            $this->assertPrivate($result);
        }
    }

    public function testUserSearchPageIsPrivateAndDoesNotGrantAccessFromQueryFlags(): void
    {
        $this->loginAs('user');
        $result = $this->request('GET', 'photos/search?album=' . $this->album . '&results=1');
        $result->assertStatus(200);
        self::assertStringContainsString('HTTP synthetic album', (string) $result->response()->getBody());
        self::assertStringNotContainsString($this->photo['uuid'], (string) $result->response()->getBody());
        $this->assertPrivate($result);
        self::assertSame(0, $this->db->table('photo_matches')->countAllResults());
        $this->assertFileDenied('download');
    }

    public function testMissingCsrfCannotCreateAlbum(): void
    {
        $this->loginAs('admin');
        $before = $this->db->table('photo_albums')->countAllResults();
        try {
            $result = $this->request('POST', 'admin/photos/albums', ['name' => 'Must not exist']);
            $result->assertStatus(config('Security')->redirect ? 302 : 403);
            if (config('Security')->redirect) {
                self::assertNotEmpty(session('error'), 'A redirect alone does not prove CSRF rejected the POST.');
            }
        } catch (SecurityException $exception) {
            self::assertFalse(config('Security')->redirect);
            self::assertSame(403, $exception->getCode());
        }
        self::assertSame($before, $this->db->table('photo_albums')->countAllResults());
        self::assertSame(0, $this->db->table('photo_albums')->where('name', 'Must not exist')->countAllResults());
    }

    public function testValidCsrfCreatesDraftAlbumThroughRealFilters(): void
    {
        $this->loginAs('admin');
        $form = $this->request('GET', 'admin/photos');
        $form->assertStatus(200);
        $token = csrf_hash();
        self::assertStringContainsString($token, (string) $form->response()->getBody());
        $this->withSession(); // Preserve the actual session CSRF token generated by GET.
        $result = $this->request('POST', 'admin/photos/albums', [csrf_token() => $token, 'name' => 'CSRF-created album']);
        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('admin/photos'));
        $album = $this->db->table('photo_albums')->where('name', 'CSRF-created album')->get()->getRowArray();
        self::assertNotNull($album);
        self::assertSame('draft', $album['status']);
        self::assertSame((int) $this->users['admin']->id, (int) $album['created_by']);
        self::assertSame(1, (int) $album['revision']);
        $this->assertPrivate($result);
    }

    public function testPublishedReadyPhotoRequiresGrantAndCurrentSelfieForEveryVariant(): void
    {
        $this->loginAs('user');
        foreach (['thumbnail', 'original', 'download'] as $variant) {
            $this->assertFileDenied($variant);
        }
        $this->grant();
        foreach (['thumbnail', 'original', 'download'] as $variant) {
            $result = $this->request('GET', $this->fileUrl($variant));
            $result->assertStatus(200);
            self::assertInstanceOf(DownloadResponse::class, $result->response());
            $this->assertPrivate($result);
        }
    }

    public static function invalidatedGrants(): iterable
    {
        foreach (['cross-user', 'expired', 'draft', 'revoked', 'replaced-selfie', 'missing-selfie',
            'album-revision', 'below-threshold', 'not-ready', 'photo-model', 'selfie-model', 'grant-model'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidatedGrants')]
    public function testLiveGrantInvalidationDeniesAllFileVariants(string $case): void
    {
        $this->grant();
        $this->loginAs('user');
        $before = $this->request('GET', $this->fileUrl());
        $before->assertStatus(200);
        self::assertInstanceOf(DownloadResponse::class, $before->response());
        $userId = (int) $this->users['user']->id;
        switch ($case) {
            case 'cross-user': $this->loginAs('other'); break;
            case 'expired': $this->db->table('photo_matches')->update(['expires_at' => PhotoDomain::now(-60)]); break;
            case 'draft': $this->db->table('photo_albums')->update(['status' => 'draft']); break;
            case 'revoked': $this->db->table('photo_selfies')->update(['consented_at' => null]); break;
            case 'replaced-selfie': $this->db->table('photo_selfies')->update(['version' => str_repeat('e', 32)]); break;
            case 'missing-selfie': $this->db->table('photo_selfies')->where('user_id', $userId)->delete(); break;
            case 'album-revision': $this->db->table('photo_albums')->update(['revision' => 2]); break;
            case 'below-threshold': $this->db->table('photo_matches')->update(['score' => config('Photos')->threshold - 0.01]); break;
            case 'not-ready': $this->db->table('photos')->update(['status' => 'pending']); break;
            case 'photo-model': $this->db->table('photos')->update(['model_version' => 'obsolete']); break;
            case 'selfie-model': $this->db->table('photo_selfies')->update(['model_version' => 'obsolete']); break;
            case 'grant-model': $this->db->table('photo_matches')->update(['model_version' => 'obsolete']); break;
        }
        foreach (['thumbnail', 'original', 'download'] as $variant) {
            $this->assertFileDenied($variant);
        }
    }

    public function testFinalDownloadHeadersAndStreamContainOnlySyntheticJpeg(): void
    {
        $this->grant();
        $this->loginAs('user');
        $result = $this->request('GET', $this->fileUrl());
        $result->assertStatus(200);
        $response = $result->response();
        self::assertInstanceOf(DownloadResponse::class, $response);
        // FeatureTestTrait returns before send(); finalize exactly as DownloadResponse::send does.
        $response->buildHeaders();
        $this->assertPrivate($result);
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertSame((string) strlen($this->jpeg), $response->getHeaderLine('Content-Length'));
        self::assertSame('binary', $response->getHeaderLine('Content-Transfer-Encoding'));
        self::assertStringStartsWith('attachment;', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('Synthetic HTTP.jpg', $response->getHeaderLine('Content-Disposition'));
        ob_start();
        try {
            $response->sendBody();
            self::assertSame($this->jpeg, ob_get_contents(), 'No toolbar, HTML or error bytes may enter the stream.');
        } finally {
            ob_end_clean();
        }
    }
}