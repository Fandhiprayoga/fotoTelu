<?php

declare(strict_types=1);

use App\Libraries\FaceEngineClient;
use App\Libraries\PhotoStorageService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Photos;

final class PhotoStorageTest extends CIUnitTestCase
{
    private string $directory;
    private PhotoStorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/photo-unit-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->storage = new PhotoStorageService($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/photos/*') ?: [] as $path) {
            unlink($path);
        }
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testTraversalAndUnexpectedExtensionsAreRejected(): void
    {
        foreach (['../secret', '/etc/passwd', str_repeat('a', 32) . '.php', str_repeat('a', 32) . '.jpg/../x',
            str_repeat('A', 32) . '.jpg', 'file.jpg', str_repeat('a', 32) . ".jpg\0"] as $key) {
            try {
                $this->storage->path($key);
                $this->fail('Unsafe key accepted');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('tidak valid', $e->getMessage());
            }
        }
    }

    public function testPrivateExclusiveWriteReadAndIdempotentDelete(): void
    {
        $key = str_repeat('a', 32) . '.enc';
        $this->storage->write($key, 'encrypted-test-fixture');
        $this->assertSame('encrypted-test-fixture', $this->storage->read($key));
        $this->assertSame(0600, fileperms($this->storage->path($key)) & 0777);
        $this->assertSame(0700, fileperms(dirname($this->storage->path($key))) & 0777);
        try {
            $this->storage->write($key, 'overwrite');
            $this->fail('Existing file overwritten');
        } catch (RuntimeException $e) {
            $this->assertSame('encrypted-test-fixture', $this->storage->read($key));
        }
        $this->storage->delete(['storage_key' => $key]);
        $this->storage->delete(['storage_key' => $key]);
        $this->assertFileDoesNotExist($this->storage->path($key));
    }

    public function testSymlinkIsRejected(): void
    {
        $key = str_repeat('b', 32) . '.jpg';
        symlink(__FILE__, $this->directory . '/photos/' . $key);
        $this->expectException(RuntimeException::class);
        $this->storage->read($key);
    }

    public function testFailedUploadIsRejectedWithoutFiles(): void
    {
        $file = new UploadedFile('/not-an-upload', 'photo.png', 'image/png', 0, UPLOAD_ERR_PARTIAL);
        try {
            $this->storage->store($file);
            $this->fail('Partial upload accepted');
        } catch (RuntimeException $e) {
            $this->assertSame([], glob($this->directory . '/photos/*'));
        }
    }

    public function testSanitizedThumbnailAndContentBasedMime(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required for image processing.');
        }
        $image = imagecreatetruecolor(800, 400);
        $source = $this->directory . '/source.png';
        imagepng($image, $source);
        imagedestroy($image);
        $file = new class($source, '../../evil.php', 'text/plain', filesize($source), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool { return true; }
        };
        $photo = $this->storage->store($file);
        $this->assertSame('image/png', $photo['mime']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $photo['storage_key']);
        $this->assertSame(hash_file('sha256', $source), $photo['checksum']);
        $thumb = getimagesize($this->storage->path($photo['thumbnail_key']));
        $this->assertSame([640, 320], [$thumb[0], $thumb[1]]);
        $this->assertSame('image/jpeg', $thumb['mime']);
        $this->storage->delete($photo);
    }

    public function testPixelAndByteLimitsRejectBeforeDecode(): void
    {
        $source = $this->directory . '/source.png';
        // Public-domain synthetic one-pixel PNG, not a biometric fixture.
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1cAAAAASUVORK5CYII='));
        $file = new class($source, 'photo.png', 'image/png', filesize($source), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool { return true; }
        };
        $config = new Photos();
        $config->maxPixels = 0;
        try {
            (new PhotoStorageService($this->directory, $config))->store($file);
            $this->fail('Pixel cap bypassed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Resolusi', $e->getMessage());
        }
        $config->maxUploadBytes = 1;
        $this->expectException(RuntimeException::class);
        (new PhotoStorageService($this->directory, $config))->store($file);
    }

    public function testMissingServiceTokenFailsClosed(): void
    {
        $config = new Photos();
        $config->serviceToken = '';
        $this->expectException(RuntimeException::class);
        (new FaceEngineClient($config))->health();
    }

    public function testEmbeddingAndScoreValidation(): void
    {
        $this->assertFalse(FaceEngineClient::validScore(NAN));
        $this->assertFalse(FaceEngineClient::validScore(INF));
        $this->assertFalse(FaceEngineClient::validScore('0.9'));
        $this->assertFalse(FaceEngineClient::validScore(1.1));
        $this->assertTrue(FaceEngineClient::validScore(0.55));
        foreach ([[], array_fill(0, 128, 0), array_fill(0, 128, NAN), array_fill(0, 128, '1')] as $vector) {
            try {
                FaceEngineClient::validateEmbedding($vector);
                $this->fail('Invalid vector accepted');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('selfie', $e->getMessage());
            }
        }
        $vector = array_fill(0, 128, 0.0);
        $vector[0] = 1.0;
        $this->assertSame($vector, FaceEngineClient::validateEmbedding($vector));
    }
}