<?php

declare(strict_types=1);

use App\Libraries\FaceEngineClient;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Photos;

final class PhotoFaceEngineTest extends CIUnitTestCase
{
    private function responseClient(): PhotoFaceResponseClient
    {
        return new PhotoFaceResponseClient();
    }

    public function testSelfieQualityErrorsGiveSafeGuidance(): void
    {
        foreach (['selfie_no_face_detected', 'selfie_multiple_faces_detected',
            'selfie_exactly_one_face_required', 'selfie_face_too_small',
            'selfie_face_out_of_frame', 'selfie_face_too_blurry', 'invalid_image',
            'image_processing_failed'] as $detail) {
            try {
                $this->responseClient()->decode(json_encode(['detail' => $detail]), 422);
                $this->fail('Invalid selfie accepted');
            } catch (DomainException $e) {
                $this->assertNotSame('', $e->getMessage());
                $this->assertStringNotContainsString($detail, $e->getMessage());
            }
        }
    }

    public function testUnknownRemoteErrorsRemainPrivate(): void
    {
        foreach ([[422, '/selfie'], [503, '/selfie'], [422, '/photos/test']] as [$status, $route]) {
            try {
                $this->responseClient()->decode('{"detail":"private-service-detail"}', $status, $route);
                $this->fail('Remote failure accepted');
            } catch (RuntimeException $e) {
                $this->assertStringNotContainsString('private-service-detail', $e->getMessage());
            }
        }
    }

    public function testSuccessfulResponseStillReturnsValidatedObject(): void
    {
        $this->assertSame(['ready' => true], $this->responseClient()->decode('{"ready":true}', 200, '/health'));
    }

    public function testWrongModelRejectedEvenOnHealth(): void
    {
        $engine = new class extends FaceEngineClient {
            protected function request(string $method, string $route, array $payload = [], bool $json = false): array
            {
                return ['model_version' => 'wrong', 'ready' => true];
            }
        };
        $this->expectException(RuntimeException::class);
        $engine->health();
    }

    public function testSearchRejectsInvalidRemoteScore(): void
    {
        $config = new Photos();
        $engine = new class($config) extends FaceEngineClient {
            protected function request(string $method, string $route, array $payload = [], bool $json = false): array
            {
                return ['model_version' => $this->config->modelVersion,
                    'matches' => [['photo_id' => '00000000-0000-0000-0000-000000000001', 'score' => 99]]];
            }
        };
        $vector = array_fill(0, 128, 0.0);
        $vector[0] = 1.0;
        $this->expectException(RuntimeException::class);
        $engine->search($vector, ['00000000-0000-0000-0000-000000000001']);
    }
}

final class PhotoFaceResponseClient extends FaceEngineClient
{
    public function decode(string $body, int $status, string $route = '/selfie'): array
    {
        return $this->parseResponse($body, $status, $route);
    }
}