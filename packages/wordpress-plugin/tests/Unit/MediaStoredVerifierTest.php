<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Support\MediaStoredVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaStoredVerifierTest extends TestCase
{
    private const MINIMAL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $pngBytes;

    private string $pngPath;

    protected function setUp(): void
    {
        $decoded = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($decoded);
        $this->pngBytes = $decoded;

        $path = tempnam(sys_get_temp_dir(), 'mcp-media-');
        self::assertNotFalse($path);
        $this->pngPath = $path;
        file_put_contents($this->pngPath, $this->pngBytes);

        $GLOBALS['wp_test_attachment_files'] = [
            501 => $this->pngPath,
        ];
        $GLOBALS['wp_test_attachment_urls'] = [
            501 => 'https://example.test/wp-content/uploads/test.png',
        ];
        $GLOBALS['wp_test_attachment_mimes'] = [
            501 => 'image/png',
        ];
        $GLOBALS['wp_test_attachment_metadata'] = [
            501 => [
                'width' => 1,
                'height' => 1,
                'sizes' => ['thumbnail' => ['file' => 'test.png']],
            ],
        ];
        $GLOBALS['wp_test_postmeta'] = [];
        $GLOBALS['wp_test_posts'] = [
            501 => new \WP_Post(501, '', 'attachment'),
        ];
        $GLOBALS['wp_test_post_fields'] = [
            501 => [
                'post_excerpt' => '',
                'post_content' => '',
            ],
        ];
        $GLOBALS['wp_test_post_titles'] = [
            501 => 'Sample',
        ];
        $GLOBALS['wp_test_remote_get'] = [
            'https://example.test/wp-content/uploads/test.png' => $this->pngBytes,
        ];
        $GLOBALS['wp_test_filters'] = [];
    }

    protected function tearDown(): void
    {
        if (is_file($this->pngPath)) {
            unlink($this->pngPath);
        }
    }

    #[Test]
    public function it_verifies_a_stored_attachment_and_public_url(): void
    {
        add_filter('jooservices_mcp_skip_public_url_verify', static fn(): bool => false);

        $result = MediaStoredVerifier::verifyAttachment(501, hash('sha256', $this->pngBytes));

        self::assertNull($result['step']);
        self::assertTrue($result['verification']['passed']);
        self::assertTrue($result['verification']['sha256_match']);
        self::assertTrue($result['verification']['public_url_ok']);
    }

    #[Test]
    public function it_detects_checksum_mismatch_after_file_mutation(): void
    {
        add_filter('jooservices_mcp_skip_public_url_verify', static fn(): bool => true);

        $result = MediaStoredVerifier::verifyAttachment(501, 'deadbeef');

        self::assertSame('post_validate.sha256_mismatch', $result['step']);
        self::assertFalse($result['verification']['passed']);
    }

    #[Test]
    public function it_verifies_written_metadata_fields(): void
    {
        $GLOBALS['wp_test_post_titles'][501] = 'Title';
        $GLOBALS['wp_test_postmeta'][501] = ['_wp_attachment_image_alt' => 'Alt'];
        $GLOBALS['wp_test_post_fields'][501] = [
            'post_excerpt' => 'Caption',
            'post_content' => 'Description',
        ];

        $result = MediaStoredVerifier::verifyMetadata(501, [
            'title' => 'Title',
            'alt_text' => 'Alt',
            'caption' => 'Caption',
            'description' => 'Description',
        ]);

        self::assertNull($result['step']);
        self::assertTrue($result['verification']['passed']);
    }

    #[Test]
    public function it_fails_public_url_verification_when_response_is_not_an_image(): void
    {
        $GLOBALS['wp_test_remote_get']['https://example.test/wp-content/uploads/test.png'] = 'not-an-image';

        $result = MediaStoredVerifier::verifyPublicUrl('https://example.test/wp-content/uploads/test.png');

        self::assertFalse($result['ok']);
        self::assertSame('public_url.decode', $result['step']);
    }
}
