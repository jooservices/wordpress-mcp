<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1>ChatGPT Audit Log</h1>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Connection</th>
                <th>Request ID</th>
                <th>Action</th>
                <th>Resource</th>
                <th>Success</th>
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
                    <td><?php echo (int) $row['success'] === 1 ? 'Yes' : 'No'; ?></td>
                    <td><?php echo esc_html((string) $row['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
