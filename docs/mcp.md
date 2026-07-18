# MCP Policy

This starter works completely without MCP (Model Context Protocol) — no MCP
server or adapter is installed by default, and nothing in `composer.json`
or `package.json` depends on one. WP-CLI (`wp agency *`, core `wp`
commands) remains the primary automation surface for both humans and
agents; treat any MCP setup below as an additive, optional convenience on
top of it, never a replacement.

## Policy by environment

- **Local development**: an MCP server (e.g. the official
  [WordPress MCP Adapter](https://github.com/Automattic/wordpress-mcp)) is
  optional and may be added for local experimentation. Scope its
  credentials to a local-only application password or user, never an
  administrator's primary login.
- **Staging**: MCP access is restricted and temporary — grant it only for
  the duration of a specific task, with credentials that expire or are
  revoked immediately after. Do not leave a standing MCP connection wired
  to staging.
- **Production**: MCP is disabled. No MCP server, adapter, or credential
  should exist against a production WordPress install.

## Rules that apply everywhere MCP is used

- **Least privilege**: an MCP connection's credential should have the
  minimum role/capability needed for the task, not an administrator
  account by default.
- **Customer users never create agent credentials.** Application passwords
  are already restricted to `manage_options` users by
  `AgencyPlatform\Security\ApplicationPasswords` (see `AGENTS.md`) —
  `client_editor`/`client_shop_manager` accounts cannot generate the
  credentials an MCP connection would need, by design.
- **WP-CLI remains primary.** Scripted, auditable, repeatable operations
  (`scripts/*`, `wp agency *`) are the source of truth for how this
  project is operated; an MCP connection is a convenience layer for
  interactive/exploratory agent work, not a substitute for those scripts
  in CI or deploy automation.

## If you add one

Document which environment(s) it's wired to, which credential it uses, and
its scope, in this file or in `ops/` alongside the rest of this project's
operational contracts — an undocumented MCP connection is itself a
guardrail gap.
