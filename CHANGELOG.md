# Changelog

All notable changes to `padosoft/askmydocs-connector-notion` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v1.0.0 — Initial release (2026-05-12)

Initial extraction from the AskMyDocs v4.5 inline connector framework into a standalone, reusable Laravel package.

### Added

- `NotionConnector` — full `ConnectorInterface` implementation (`key`, `displayName`, `iconUrl`, `oauthScopes`, `initiateOAuth`, `handleOAuthCallback`, `syncFull`, `syncIncremental`, `disconnect`, `health`).
- `Support\NotionPaginator` — eager + lazy `next_cursor` traversal with three-way exception split (auth / api / pagination-limit). Memory-bounded by `page_size`; lazy mode short-circuits incremental syncs as soon as the watermark is crossed.
- `Support\NotionBlockToMarkdown` — recursive block-tree → markdown converter. Supports paragraph / heading 1-3 / bulleted-list / numbered-list / to-do / quote / callout / code (with language hint) / divider / table / link-to-page / bookmark / embed / link-preview / inline-link annotations (bold / italic / code / strikethrough). Unknown block types render as visible HTML-comment markers.
- `NotionServiceProvider` — merges this package's `config/notion.php` under `connectors.providers.notion`; publishes the config + brand SVG under the `connector-notion-config` and `connector-notion-assets` tags.
- `composer.json::extra.askmydocs.connectors` — auto-registers the connector with the base package's registry on `composer require`. Zero edits to host app config required.
- `config/notion.php` — env-driven provider settings (client_id, client_secret, redirect_uri, api_version).
- `public/icons/notion.svg` — brand asset.
- 43 PHPUnit tests covering OAuth state-token round-trip, OAuth code exchange, sync (full + incremental), watermark short-circuit, archive-driven soft delete via the IoC contract, health probe, pagination-limit truncation, exception taxonomy, and the markdown converter.
- Opt-in live test (`tests/Live/NotionLiveTest.php`) that hits `api.notion.com/v1/users` and `/v1/search` when `CONNECTOR_NOTION_LIVE=1`.
- CI matrix: PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

### Decisions

- **Standalone-agnostic** — this package never imports a host class. Every host-side concern (job dispatch, KB disk writes, PII redaction, audit emission, soft-delete by remote-id) routes through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract` v1.1.0+. The host binds its own implementation in a service provider.
- **`NotionBlockChunker` stays in the host** — the source-aware chunker depends on `ChunkerInterface` + `ConvertedDocument` value objects that live in AskMyDocs' v3 ingestion pipeline and are NOT part of the connector framework. The host wires the chunker against the synthetic MIME `application/vnd.notion.page+json` this connector emits.
- **Workspace OAuth state token is single-use** — replay of the same `state=` returns `false` on the second consume call (cache key removed before the verify result returns).
- **Notion tokens never expire** — `expires_at` is persisted as `null`; `vault->getAccessToken()` never considers them stale. Revocation is a workspace-UI operation only.
