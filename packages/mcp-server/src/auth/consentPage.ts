import { OAUTH_SCOPES } from "./types.js";

export type ConsentPageInput = {
  requestId: string;
  action: string;
  clientName: string;
  redirectHost: string;
  scopes: string[];
};

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function describeScope(scope: string): string {
  if (scope === OAUTH_SCOPES.WRITE) {
    return "Create, change and delete WordPress content, media, users, plugins and settings";
  }

  if (scope === OAUTH_SCOPES.READ) {
    return "Read WordPress content, media, users, comments and settings";
  }

  return scope;
}

/** Owner consent page shown by `/authorize`; posts the decision to `/oauth/approve`. */
export function renderConsentPage(input: ConsentPageInput): string {
  const scopes = input.scopes.map((scope) => `<li>${escapeHtml(describeScope(scope))}</li>`).join("");

  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Approve WordPress MCP connection</title>
<style>
body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:24px;background:#f6f7f7;color:#1d2327}
main{max-width:460px;margin:40px auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px}
h1{font-size:20px;margin:0 0 12px}code{background:#f0f0f1;padding:1px 4px;border-radius:3px}
label{display:block;margin:16px 0 4px;font-weight:600}input{width:100%;box-sizing:border-box;padding:8px;font-size:16px}
.row{display:flex;gap:12px;margin-top:20px}button{flex:1;padding:10px;font-size:16px;border-radius:4px;border:1px solid #2271b1;cursor:pointer}
.approve{background:#2271b1;color:#fff}.deny{background:#fff;color:#2271b1}
</style>
</head>
<body>
<main>
<h1>Allow <strong>${escapeHtml(input.clientName)}</strong> to use this WordPress site?</h1>
<p>After approval it will be sent back to <code>${escapeHtml(input.redirectHost)}</code> and can:</p>
<ul>${scopes}</ul>
<form method="post" action="${escapeHtml(input.action)}">
<input type="hidden" name="request_id" value="${escapeHtml(input.requestId)}">
<label for="secret">Approval secret</label>
<input id="secret" name="secret" type="password" autocomplete="current-password" autofocus>
<div class="row">
<button class="approve" type="submit" name="decision" value="approve">Approve</button>
<button class="deny" type="submit" name="decision" value="deny">Deny</button>
</div>
</form>
<p><small>The approval secret is <code>MCP_OAUTH_APPROVAL_SECRET</code> from the MCP server configuration. If you did not start this connection, choose Deny.</small></p>
</main>
</body>
</html>`;
}
