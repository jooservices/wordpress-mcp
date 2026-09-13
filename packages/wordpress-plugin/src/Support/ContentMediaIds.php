<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Support;

/**
 * Attachment IDs that post content actually embeds as media — not every
 * Gutenberg block `"id"` (headings, navigation links, reusable blocks).
 */
final class ContentMediaIds
{
    /**
     * @return list<int>
     */
    public static function fromContent(string $content): array
    {
        $ids = [];

        foreach (self::collect($content) as $id) {
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_map(intval(...), array_keys($ids));
    }

    /**
     * @return list<int>
     */
    private static function collect(string $content): array
    {
        $found = [];

        if (preg_match_all('/\bwp-image-(\d+)\b/', $content, $matches)) {
            foreach ($matches[1] as $id) {
                $found[] = (int) $id;
            }
        }

        if (preg_match_all('/data-id=["\'](\d+)["\']/', $content, $matches)) {
            foreach ($matches[1] as $id) {
                $found[] = (int) $id;
            }
        }

        if (preg_match_all('/\bids=["\'](\d+(?:\s*,\s*\d+)*)["\']/', $content, $matches)) {
            foreach ($matches[1] as $group) {
                foreach (preg_split('/\s*,\s*/', $group) ?: [] as $id) {
                    $found[] = (int) $id;
                }
            }
        }

        if (preg_match_all('/<!--\s+wp:(image|gallery)\s+(\{.*?\})\s+-->/s', $content, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                $attrs = json_decode($block[2], true);

                if (! is_array($attrs)) {
                    continue;
                }

                if ($block[1] === 'image' && isset($attrs['id'])) {
                    $found[] = (int) $attrs['id'];
                }

                if ($block[1] === 'gallery' && isset($attrs['ids']) && is_array($attrs['ids'])) {
                    foreach ($attrs['ids'] as $id) {
                        $found[] = (int) $id;
                    }
                }
            }
        }

        return $found;
    }
}
