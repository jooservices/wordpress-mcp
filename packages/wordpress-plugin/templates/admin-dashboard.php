<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1>JOOservices</h1>
    <p>Manage the JOOservices WordPress integration.</p>
    <div class="card" style="max-width:760px; padding:20px;">
        <h2>MCP</h2>
        <p>Create scoped MCP connections and retrieve the one-time connection token.</p>
        <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=chatgpt-connector')); ?>">Open MCP</a></p>
        <h2>Audit Log</h2>
        <p>Review and export authenticated MCP requests.</p>
        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=chatgpt-audit')); ?>">Open Audit Log</a></p>
        <h2>Settings</h2>
        <p>Configure REST rate limiting for MCP connections.</p>
        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=chatgpt-settings')); ?>">Open Settings</a></p>
    </div>
</div>
