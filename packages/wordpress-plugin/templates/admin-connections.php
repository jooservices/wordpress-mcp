<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1>JOOservices ChatGPT Connector</h1>

    <?php if (! empty($newToken)) : ?>
        <div class="notice notice-success">
            <p><strong>Connection created.</strong> Copy this token now — it will not be shown again:</p>
            <code style="display:block;padding:8px;background:#f0f0f0;word-break:break-all;"><?php echo esc_html((string) $newToken); ?></code>
        </div>
    <?php endif; ?>

    <h2>Create connection</h2>
    <form method="post">
        <?php wp_nonce_field('chatgpt_connector', 'chatgpt_nonce'); ?>
        <table class="form-table">
            <tr>
                <th><label for="connection_name">Name</label></th>
                <td><input name="connection_name" id="connection_name" type="text" value="ChatGPT" class="regular-text" required></td>
            </tr>
            <tr>
                <th>Scopes</th>
                <td>
                    <?php foreach ($scopes as $scope) : ?>
                        <label style="display:block;">
                            <input type="checkbox" name="scopes[]" value="<?php echo esc_attr($scope); ?>" checked>
                            <?php echo esc_html($scope); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>
        <?php submit_button('Create connection', 'primary', 'create_connection'); ?>
    </form>

    <h2>Existing connections</h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>User</th>
                <th>Scopes</th>
                <th>Active</th>
                <th>Created</th>
                <th>Last used</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($connections as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row['id']); ?></td>
                    <td><?php echo esc_html((string) $row['name']); ?></td>
                    <td><?php echo esc_html((string) get_userdata((int) $row['user_id'])?->display_name); ?></td>
                    <td><code><?php echo esc_html((string) $row['scopes']); ?></code></td>
                    <td><?php echo (int) $row['active'] === 1 ? 'Yes' : 'No'; ?></td>
                    <td><?php echo esc_html((string) $row['created_at']); ?></td>
                    <td><?php echo esc_html((string) ($row['last_used_at'] ?? '—')); ?></td>
                    <td>
                        <?php if ((int) $row['active'] === 1) : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('chatgpt_connector', 'chatgpt_nonce'); ?>
                                <input type="hidden" name="connection_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                                <button type="submit" name="revoke_connection" class="button" onclick="return confirm('Revoke this connection?');">Revoke</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
