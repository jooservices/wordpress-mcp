<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1>JOOservices WordPress - MCP</h1>

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
    <p class="description">Revoke disables a token immediately but keeps the record for audit. Delete permanently removes revoked connections from the list.</p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>User</th>
                <th>Scopes</th>
                <th>Status</th>
                <th>Created</th>
                <th>Last used</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($connections as $row) : ?>
                <?php
                $isActive = (int) $row['active'] === 1;
                $scopeList = json_decode((string) ($row['scopes'] ?? '[]'), true);
                $scopeList = is_array($scopeList) ? array_values(array_map(strval(...), $scopeList)) : [];
                ?>
                <tr>
                    <td><?php echo esc_html((string) $row['id']); ?></td>
                    <td><?php echo esc_html((string) $row['name']); ?></td>
                    <td><?php echo esc_html((string) get_userdata((int) $row['user_id'])?->display_name); ?></td>
                    <td><code><?php echo esc_html(implode(', ', $scopeList)); ?></code></td>
                    <td>
                        <?php if ($isActive) : ?>
                            <strong style="color:#007017;">Active</strong>
                        <?php else : ?>
                            <strong style="color:#8a2424;">Revoked</strong>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string) $row['created_at']); ?></td>
                    <td><?php echo esc_html((string) ($row['last_used_at'] ?? '—')); ?></td>
                    <td>
                        <?php if ($isActive) : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('chatgpt_connector', 'chatgpt_nonce'); ?>
                                <input type="hidden" name="connection_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                                <button type="submit" name="revoke_connection" class="button" onclick="return confirm('Revoke this connection? The token will stop working immediately.');">Revoke</button>
                            </form>
                        <?php else : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('chatgpt_connector', 'chatgpt_nonce'); ?>
                                <input type="hidden" name="connection_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                                <button type="submit" name="delete_connection" class="button button-link-delete" onclick="return confirm('Permanently delete this revoked connection?');">Delete permanently</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
