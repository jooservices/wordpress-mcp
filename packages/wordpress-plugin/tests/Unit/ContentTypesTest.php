<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Tests\Unit;

use JOOservices\WordPressMcp\Support\ContentTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentTypesTest extends TestCase
{
    #[Test]
    public function it_accepts_post_and_page_only(): void
    {
        self::assertTrue(ContentTypes::isSupported(ContentTypes::POST));
        self::assertTrue(ContentTypes::isSupported(ContentTypes::PAGE));
        self::assertFalse(ContentTypes::isSupported('product'));
    }

    #[Test]
    public function it_normalizes_supported_types(): void
    {
        self::assertSame(ContentTypes::POST, ContentTypes::normalize('post'));
        self::assertSame(ContentTypes::PAGE, ContentTypes::normalize('page'));
        self::assertNull(ContentTypes::normalize('product'));
    }
}
