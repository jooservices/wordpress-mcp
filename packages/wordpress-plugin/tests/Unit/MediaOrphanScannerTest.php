<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Services\MediaOrphanScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MediaOrphanScannerTest extends TestCase
{
    private const MINIMAL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $GLOBALS['wp_test_postmeta'] = [];
        $GLOBALS['wp_test_wpdb_col_results'] = [];
        $GLOBALS['wp_test_wpdb_row_results'] = [];
    }

    /**
     * @return array{path: string, url: string|null, size: int, mime: string|null, width: int|null, height: int|null}|null
     */
    private function findItem(array $items, string $relative): ?array
    {
        foreach ($items as $item) {
            if ($item['path'] === $relative) {
                return $item;
            }
        }

        return null;
    }

    #[Test]
    public function it_reports_mime_size_and_dimensions_for_an_orphan_image(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2025/06/loose-' . uniqid() . '.png';
        $full = $basedir . '/' . $relative;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }

        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        file_put_contents($full, $png);

        $result = (new MediaOrphanScanner())->findOrphanFiles();
        $match = $this->findItem($result['items'], $relative);

        unlink($full);

        self::assertNotNull($match);
        self::assertSame('image/png', $match['mime']);
        self::assertSame(1, $match['width']);
        self::assertSame(1, $match['height']);
        self::assertSame(strlen($png), $match['size']);
    }

    #[Test]
    public function it_reports_size_without_dimensions_for_a_non_image_orphan(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2025/06/notes-' . uniqid() . '.txt';
        $full = $basedir . '/' . $relative;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }

        $content = 'just some text, not an image';
        file_put_contents($full, $content);

        $result = (new MediaOrphanScanner())->findOrphanFiles();
        $match = $this->findItem($result['items'], $relative);

        unlink($full);

        self::assertNotNull($match);
        self::assertNull($match['width']);
        self::assertNull($match['height']);
        self::assertSame(strlen($content), $match['size']);
    }

    #[Test]
    public function it_excludes_files_already_referenced_by_an_attachment(): void
    {
        $basedir = sys_get_temp_dir() . '/jooservices-mcp-test-uploads';
        $relative = '2025/06/attached-' . uniqid() . '.png';
        $full = $basedir . '/' . $relative;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }

        $png = base64_decode(self::MINIMAL_PNG_BASE64, true);
        self::assertNotFalse($png);
        file_put_contents($full, $png);

        $GLOBALS['wp_test_wpdb_col_results'] = [$relative];

        $result = (new MediaOrphanScanner())->findOrphanFiles();
        $match = $this->findItem($result['items'], $relative);

        unlink($full);

        self::assertNull($match, 'A file already known via _wp_attached_file must not be reported as orphan.');
    }
}
