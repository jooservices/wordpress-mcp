<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var array{enabled: bool, max: int, window_seconds: int} $settings */
/** @var bool $saved */
?>
<div class="wrap">
    <h1>WordPress - MCP Settings</h1>

    <?php if ($saved) : ?>
        <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
    <?php endif; ?>

    <?php if (isset($_GET['settings-error']) && $_GET['settings-error'] === 'constants') : ?>
        <div class="notice notice-warning">
            <p>Rate limit values are locked by <code>wp-config.php</code> constants and cannot be changed here.</p>
        </div>
    <?php endif; ?>

    <?php
    $constantsLocked = defined('MCP_RATE_LIMIT_ENABLED')
        || defined('MCP_RATE_LIMIT_MAX')
        || defined('MCP_RATE_LIMIT_WINDOW_SECONDS');
?>

    <h2>REST API rate limiting</h2>
    <p>Limits MCP requests per connection token. Applies to every authenticated REST call from the MCP server.</p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('chatgpt_save_settings'); ?>
        <input type="hidden" name="action" value="chatgpt_save_settings">
        <table class="form-table">
            <tr>
                <th scope="row">Enable rate limiting</th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="rate_limit_enabled"
                            value="1"
                            <?php checked($settings['enabled']); ?>
                            <?php disabled($constantsLocked); ?>
                        >
                        Limit requests per connection
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="rate_limit_max">Max requests</label></th>
                <td>
                    <input
                        name="rate_limit_max"
                        id="rate_limit_max"
                        type="number"
                        min="1"
                        step="1"
                        class="small-text"
                        value="<?php echo esc_attr((string) $settings['max']); ?>"
                        <?php disabled($constantsLocked); ?>
                        required
                    >
                    <p class="description">Maximum requests allowed within the window (default: 120).</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="rate_limit_window_seconds">Window (seconds)</label></th>
                <td>
                    <input
                        name="rate_limit_window_seconds"
                        id="rate_limit_window_seconds"
                        type="number"
                        min="1"
                        step="1"
                        class="small-text"
                        value="<?php echo esc_attr((string) $settings['window_seconds']); ?>"
                        <?php disabled($constantsLocked); ?>
                        required
                    >
                    <p class="description">Rolling window length in seconds (default: 60).</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Save settings', 'primary', 'save_settings', true, $constantsLocked ? ['disabled' => 'disabled'] : []); ?>
    </form>

    <?php if ($constantsLocked) : ?>
        <p>
            Override via <code>wp-config.php</code>:
            <code>MCP_RATE_LIMIT_ENABLED</code>,
            <code>MCP_RATE_LIMIT_MAX</code>,
            <code>MCP_RATE_LIMIT_WINDOW_SECONDS</code>.
        </p>
    <?php endif; ?>
</div>
