# Changes

## 1.0.1 (2026070806)

Initial public release. A Moodle admin tool that turns the site into a remote
MCP (Model Context Protocol) server with a built-in OAuth 2.1 authorization
server:

- MCP streamable-HTTP transport (JSON-RPC 2.0, protocol versions 2025-03-26
  and 2025-06-18) with per-session handling, Origin validation and HTTPS
  enforcement.
- Two authentication modes: Moodle web service tokens and the built-in
  OAuth 2.1 server (authorization-code grant, mandatory PKCE S256,
  refresh-token rotation with reuse detection, opaque hash-stored access
  tokens, RFC 8707 resource binding, RFC 7591 dynamic client registration,
  RFC 7009 revocation, RFC 8414 / RFC 9728 discovery).
- Consent screen on top of the normal Moodle login, self-service "Connected
  MCP apps" profile page, and admin client management.
- Tool sources: external functions of selected web service definitions plus
  tools contributed by other plugins through the collect_tool_providers hook,
  with per-tool governance and mcp:read / mcp:write scopes.
- Rate limiting, audit events (token issue/refuse/revoke, consent, client
  registration, failed authentication, tool calls), a GDPR privacy provider
  and a scheduled purge task.
