<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @var string|null $scannedAt
 * @var list<array{id: int, title: string, attached_file: string, edit_url: string}> $brokenAttachments
 * @var array{items: list<array{path: string, url: string|null, size: int, mime: string|null, width: int|null, height: int|null}>, truncated: bool} $orphanFiles
 */

$scanUrl = add_query_arg(['page' => 'jooservices-media', 'scan' => '1'], admin_url('admin.php'));
?>
<div class="wrap">
    <h1>Media</h1>
    <p>Finds two kinds of inconsistency between the Media Library and the uploads directory on disk. Results are cached — a background job re-scans daily, and this page reads that cache instead of re-walking the filesystem on every view. Not exposed to MCP clients directly for the same reason: a full filesystem walk is too slow for a single tool call.</p>

    <p>
        <?php if ($scannedAt !== null) : ?>
            Last scanned: <strong><?php echo esc_html($scannedAt); ?></strong> (UTC)
        <?php else : ?>
            Never scanned yet.
        <?php endif; ?>
    </p>

    <p>
        <a href="<?php echo esc_url($scanUrl); ?>" class="button button-primary">Run scan now</a>
    </p>

    <?php if ($scannedAt !== null) : ?>
        <h2>Broken attachments (<?php echo esc_html((string) count($brokenAttachments)); ?>)</h2>
        <p>Attachment exists in the Media Library, but its file is missing on disk.</p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Expected file</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($brokenAttachments as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $row['id']); ?></td>
                        <td><a href="<?php echo esc_url($row['edit_url']); ?>"><?php echo esc_html($row['title']); ?></a></td>
                        <td><code><?php echo esc_html($row['attached_file']); ?></code></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('jooservices_media_orphans', 'jooservices_media_nonce'); ?>
                                <input type="hidden" name="delete_broken_attachment" value="<?php echo esc_attr((string) $row['id']); ?>">
                                <button
                                    type="submit"
                                    class="button button-link-delete"
                                    title="Delete record"
                                    aria-label="Delete record"
                                    onclick="return confirm('Permanently delete this attachment record? It has no file behind it.');"
                                ><span class="dashicons dashicons-trash"></span></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($brokenAttachments === []) : ?>
                    <tr>
                        <td colspan="4">None found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 style="margin-top:2em;">Orphan files (<?php echo esc_html((string) count($orphanFiles['items'])); ?><?php echo $orphanFiles['truncated'] ? '+' : ''; ?>)</h2>
        <p>File exists in the uploads directory, but no attachment (original or generated size) references it. Review before deleting — some files may be managed by other plugins or themes.</p>
        <p>Rows marked <strong>scaled derivative</strong> have a WordPress-style <code>-scaled</code> filename suffix, but this is a filename guess, not a verified relationship to any other row — it can be wrong (e.g. two unrelated uploads that merely share a base name). Confirm dimensions/mime before assuming one row is derived from another.</p>
        <?php if ($orphanFiles['truncated']) : ?>
            <p><strong>Scan capped before finishing.</strong> Re-run after clearing some results to see more.</p>
        <?php endif; ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Relative path</th>
                    <th>MIME</th>
                    <th>Size</th>
                    <th>Dimensions</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orphanFiles['items'] as $item) : ?>
                    <?php $isScaled = (bool) preg_match('/-scaled\.[^.\/]+$/', $item['path']); ?>
                    <tr>
                        <td>
                            <code><?php echo esc_html($item['path']); ?></code>
                            <?php if ($isScaled) : ?>
                                <span class="dashicons dashicons-image-crop" title="Filename ends in -scaled — not a verified relationship, just a naming hint."></span>
                                <em>scaled derivative</em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $item['mime'] !== null ? esc_html($item['mime']) : '—'; ?></td>
                        <td><?php echo esc_html(size_format($item['size'], 1) ?: (string) $item['size']); ?></td>
                        <td>
                            <?php echo $item['width'] !== null && $item['height'] !== null
                                ? esc_html($item['width'] . ' × ' . $item['height'])
                                : '—'; ?>
                        </td>
                        <td>
                            <?php if ($item['url'] !== null) : ?>
                                <a
                                    href="<?php echo esc_url($item['url']); ?>"
                                    class="button"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    title="View file"
                                    aria-label="View file"
                                ><span class="dashicons dashicons-visibility"></span></a>
                            <?php endif; ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('jooservices_media_orphans', 'jooservices_media_nonce'); ?>
                                <input type="hidden" name="delete_orphan_file" value="<?php echo esc_attr($item['path']); ?>">
                                <button
                                    type="submit"
                                    class="button button-link-delete"
                                    title="Delete file"
                                    aria-label="Delete file"
                                    onclick="return confirm('Permanently delete this file from disk? This cannot be undone.');"
                                ><span class="dashicons dashicons-trash"></span></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($orphanFiles['items'] === []) : ?>
                    <tr>
                        <td colspan="5">None found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
