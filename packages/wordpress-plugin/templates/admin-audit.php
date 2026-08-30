<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @var array<string, string> $filters
 * @var array<string, mixed> $stats
 * @var list<array<string, mixed>> $rows
 * @var string $exportUrl
 */
?>
<div class="wrap">
    <h1>MCP Audit Log</h1>

    <form method="get" style="margin: 1em 0; display: flex; gap: 0.5em; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="page" value="chatgpt-audit" />
        <label>Action
            <input type="text" name="action" value="<?php echo esc_attr($filters['action'] ?? ''); ?>" placeholder="read, create, denied…" />
        </label>
        <label>Resource type
            <input type="text" name="resource_type" value="<?php echo esc_attr($filters['resource_type'] ?? ''); ?>" placeholder="post, media…" />
        </label>
        <label>Since
            <input type="datetime-local" name="since" value="<?php echo esc_attr($filters['since'] ?? ''); ?>" />
        </label>
        <label>Until
            <input type="datetime-local" name="until" value="<?php echo esc_attr($filters['until'] ?? ''); ?>" />
        </label>
        <button type="submit" class="button">Filter</button>
        <a href="<?php echo esc_url($exportUrl); ?>" class="button">Export CSV</a>
    </form>

    <p>
        <strong><?php echo esc_html((string) $stats['total']); ?></strong> requests
        &middot; <strong><?php echo esc_html((string) $stats['success']); ?></strong> success
        &middot; <strong><?php echo esc_html((string) $stats['error']); ?></strong> error
        <?php if ($stats['avg_duration_ms'] !== null) : ?>
            &middot; avg <strong><?php echo esc_html(number_format((float) $stats['avg_duration_ms'], 1)); ?>ms</strong>
        <?php endif; ?>
    </p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Connection</th>
                <th>Request ID</th>
                <th>Action</th>
                <th>Resource</th>
                <th>Success</th>
                <th>Duration</th>
                <th>Time</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row['id']); ?></td>
                    <td><?php echo esc_html((string) ($row['connection_id'] ?? '—')); ?></td>
                    <td><code><?php echo esc_html((string) $row['request_id']); ?></code></td>
                    <td><?php echo esc_html((string) $row['action']); ?></td>
                    <td><?php echo esc_html((string) $row['resource_type'] . ' #' . ($row['resource_id'] ?? '')); ?></td>
                    <td><?php echo ((bool) $row['success']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo $row['duration_ms'] !== null ? esc_html((string) $row['duration_ms']) . 'ms' : '—'; ?></td>
                    <td><?php echo esc_html((string) $row['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []) : ?>
                <tr>
                    <td colspan="8">No requests match these filters.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
