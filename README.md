# tool_oauthmcp — Moodle MCP server with OAuth 2.1

Turns a Moodle site into a remote **MCP** (Model Context Protocol) server:
a streamable-HTTP endpoint that publishes admin-selected Moodle web service
functions — and tools contributed by other plugins via hooks — as MCP tools
for clients such as Claude Code, Claude Desktop and **claude.ai custom
connectors**. It ships its own **OAuth 2.1 authorization server**, so a
remote client connects with the site URL alone, authenticating through the
normal Moodle login.

**Status: stable (1.0.0).** Moodle 4.5–5.x, PHP 8.2+.

## Features

- **MCP transport** — streamable HTTP, JSON-RPC 2.0, protocol versions
  `2025-03-26` and `2025-06-18`; `initialize`, `ping`, `tools/list`,
  `tools/call`; per-session `Mcp-Session-Id`, Origin validation, HTTPS
  enforcement.
- **Two authentication modes** — Moodle web service tokens (header bearer)
  and the built-in OAuth 2.1 server; either or both (setting).
- **OAuth 2.1 authorization server** — authorization-code grant with
  mandatory PKCE (S256), refresh-token rotation with reuse detection,
  opaque hash-stored access tokens, RFC 8707 resource binding, RFC 7591
  dynamic client registration, RFC 7009 revocation, RFC 8414 / RFC 9728
  discovery. Consent screen on top of the Moodle login; self-service
  "Connected MCP apps" profile page; admin client management.
- **Tool sources** — the external functions of selected web service
  definitions, plus tools other plugins contribute through the
  `collect_tool_providers` hook. Per-tool governance (enable/disable,
  read-only classification) and `mcp:read` / `mcp:write` scopes.
- **Governance & safety** — every call runs as the authenticated Moodle
  user with their own capabilities; rate limiting; audit events for token
  issue/refuse/revoke, consent, client registration, failed authentication
  and every tool call; a GDPR privacy provider; a scheduled purge task.

## Quick start A — web service token (no OAuth)

On install the plugin creates a dedicated external service, **"MCP server
(tool_oauthmcp)"**. By default it is both the tool inventory and the token
anchor: the functions you add to it become the MCP tools, and only tokens
minted for it are accepted (both defaults can be changed in the settings).

1. Site administration → Server → MCP server → enable the master switch.
2. Enable web services (Advanced features).
3. Site administration → Server → Web services → External services →
   "MCP server (tool_oauthmcp)" → Functions → add the functions you want to
   expose as tools.
4. Grant the `tool/oauthmcp:connect` capability to the intended users.
5. Mint a token for the "MCP server (tool_oauthmcp)" service.
6. Connect, e.g. with Claude Code:

```sh
claude mcp add moodle --transport http \
  --header "Authorization: Bearer <WSTOKEN>" \
  https://your.site/admin/tool/oauthmcp/server.php
```

Fine-tuning: the governance page (Site administration → Server → MCP server
→ MCP tool governance) lets you disable individual tools and mark tools as
read-only.

## Quick start B — OAuth 2.1 (claude.ai custom connector)

1. Do steps 1–4 above (master switch, web services, expose functions, grant
   `tool/oauthmcp:connect` to the connecting users).
2. Leave dynamic client registration enabled (default) under the OAuth
   settings.
3. Add the two webroot rewrites so the discovery documents resolve (see
   *Webserver setup* below) and confirm them on the diagnostics page
   (Site administration → Server → MCP server → MCP connectivity check).
4. In claude.ai, add a custom connector pointing at
   `https://your.site/admin/tool/oauthmcp/server.php`. The connector
   registers itself, sends the user through the Moodle login and the consent
   screen, and then lists the tools.

Users can review and revoke connected applications under their profile →
**Connected MCP apps**.

## Webserver setup

### Authorization header (all deployments)

MCP clients send the bearer token in the `Authorization` header. Apache
setups running PHP via `proxy_fcgi`/PHP-FPM strip that header by default
(Moodle core never notices, its web services use the `wstoken` parameter).
If the endpoint answers 401 despite a valid token, add this line to the
Apache configuration (server or vhost level) and reload:

```apache
SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1
```

nginx/PHP-FPM passes the header by default.

### `.well-known` discovery (OAuth / claude.ai only)

OAuth clients derive the authorization-server metadata URL from the issuer
(`$CFG->wwwroot`) at the **host root**, which is outside Moodle's control.
Add the rewrites shipped in [`.well-known-snippets/`](.well-known-snippets/)
(Apache and nginx variants, for both root and subdirectory installs) so that

- `/.well-known/oauth-authorization-server` → `oauth/asmeta.php`
- `/.well-known/oauth-protected-resource` → `oauth/prm.php`

The diagnostics page live-checks these. Without them, claude.ai custom
connectors cannot discover the server; header-authenticated clients (Claude
Code / Desktop with a bearer) are unaffected.

## Security

The authorization server is account-takeover-critical; HTTPS with a valid
certificate is required in production (the endpoint refuses plain HTTP). The
internal threat model and security-audit documentation are maintained
separately by Wunderbyte and made available to reviewers on request.

## Prior art

The external-function → JSON Schema mapping re-implements the idea pioneered
by [webservice_mcp](https://github.com/onbirdev/moodle-webservice_mcp). OAuth
grant and crypto handling use the vendored
[league/oauth2-server](https://github.com/thephpleague/oauth2-server)
(see [`thirdpartylibs.xml`](thirdpartylibs.xml)).

## License

GPL-3.0. Copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>.
