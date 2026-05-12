<h1 align="center">askmydocs-connector-notion</h1>

<p align="center">
  <strong>Notion connector for AskMyDocs — workspace-OAuth + page/block sync + native block-aware markdown rendering.</strong><br/>
  Drop-in Laravel package. <code>composer require</code> it from any AskMyDocs install and the Notion connector appears in the admin UI on the next request.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-notion/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-notion/tests.yml?branch=main&label=tests"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-notion"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-notion.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-notion"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-notion.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [AI vibe-coding pack included](#-ai-vibe-coding-pack-included)
4. [Architecture at a glance](#architecture-at-a-glance)
5. [Installation](#installation)
6. [Credential setup (junior-proof, step by step)](#credential-setup-junior-proof-step-by-step)
7. [Activation inside AskMyDocs](#activation-inside-askmydocs)
8. [What gets ingested](#what-gets-ingested)
9. [Sync semantics](#sync-semantics)
10. [Testing](#testing)
11. [Live testsuite](#live-testsuite)
12. [Troubleshooting](#troubleshooting)
13. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow — but the knowledge people actually want to query lives in Notion.

This package is the smallest possible surface for shipping that integration:

- A `NotionConnector` that implements `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`.
- A `NotionPaginator` that walks `next_cursor` semantics for every Notion paginated endpoint with the right exception split (auth vs api vs truncation).
- A `NotionBlockToMarkdown` converter that flattens Notion's recursive block tree into clean GitHub-flavoured markdown — paragraphs, headings, bulleted / numbered lists, to-dos, tables, code with language hints, quotes, callouts, embeds, link-to-page, inline-link annotations.
- A composer.json that auto-registers via `extra.askmydocs.connectors`. Zero edits to your host app's config required.

> **`composer require padosoft/askmydocs-connector-notion`. Done.**

## Features

- 🔌 **Zero-config installation** — composer-extra discovery auto-registers the connector at boot.
- 🔐 **Workspace OAuth2** — Notion's public-integration flow with CSRF state token round-trip, single-use replay-resistant.
- ♻️ **Incremental sync** — short-circuits as soon as a search batch crosses the `last_edited_time` watermark; never paginates further than necessary.
- 🗑️ **Archive reconciliation** — pages flipped to `archived: true` soft-delete the corresponding `knowledge_documents` row via the host's deletion service.
- 🧠 **Source-aware metadata** — `multi_select` properties surface as tags, `people` as owner, `status` resolves to active / inactive, `last_edited_time` buckets to `this_week | this_month | this_quarter | older`. Hosts that wire a reranker honour these signals out of the box.
- 🧩 **Block-aware markdown** — Notion table blocks render as pipe markdown, code blocks preserve language hints, link-to-page produces clickable references, unknown block types degrade to `<!-- notion: <type> -->` HTML comments so coverage gaps are visible.
- 📦 **Memory-safe pagination** — `walkLazy()` yields one batch at a time; entire workspaces sync with bounded memory.
- 🚦 **Failure-loud exception taxonomy** — 401 / 403 → `ConnectorAuthException` (permanent), 5xx / 429 → `ConnectorApiException` (transient retryable), `maxPages` cap → `ConnectorPaginationLimitException` (operator must raise cap).
- 🏢 **Per-tenant isolated** — every credential read and ingestion dispatch is scoped to the active `TenantContext`.
- 🧪 **Test-friendly** — pure-PHP unit tests for the markdown converter, `Http::fake()` feature tests for the connector, opt-in live test that hits real api.notion.com when `CONNECTOR_NOTION_LIVE=1`.

## 🚀 AI vibe-coding pack included

This package was built with a vibe-coding pack of Claude Code skills and rules (`.claude/` directory in the parent AskMyDocs repo) that codify the architectural invariants — the IoC contract that keeps this package standalone-agnostic, the Notion API quirks the connector navigates, the failure-loud exception taxonomy, the memory-safe pagination contract.

If you're using Claude Code to fork or extend this package, point the agent at the parent repo's `.claude/` pack and it stays inside the invariants automatically. No tribal-knowledge drift.

## Architecture at a glance

```
                ┌─────────────────────────┐
Composer        │ padosoft/askmydocs-     │
require ───────▶│ connector-notion        │
                │ (this package)          │
                └────────────┬────────────┘
                             │
                             │ auto-registered via composer
                             │ extra.askmydocs.connectors
                             ▼
                ┌──────────────────────────────┐
                │ padosoft/askmydocs-connector-│
                │ base v1.1.0+                 │
                │ ConnectorRegistry            │
                └────────────┬─────────────────┘
                             │
                             │ resolves NotionConnector
                             ▼
                ┌──────────────────────────────┐
                │ NotionConnector::syncFull()  │
                │  • POST /v1/search           │
                │  • GET /v1/blocks/{id}/...   │
                │  • NotionBlockToMarkdown     │
                │  • SourceAwareMetadata       │
                └────────────┬─────────────────┘
                             │
                             │ ConnectorIngestionContract
                             │ (IoC bridge — host implements)
                             ▼
                ┌──────────────────────────────┐
                │ Host app (AskMyDocs):        │
                │  • Storage::put → KB disk    │
                │  • IngestDocumentJob         │
                │  • kb_canonical_audit row    │
                │  • PII redactor at boundary  │
                └──────────────────────────────┘
```

The IoC bridge is the key design decision: this package never imports `App\Jobs\IngestDocumentJob`, `App\Models\KnowledgeDocument`, or any other host class. It dispatches every host-side concern through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. The host binds its own implementation in a service provider; this package stays standalone-agnostic so it can run inside AskMyDocs Community Edition, AskMyDocs Pro, or any third-party Laravel app that wants Notion-backed RAG.

## Installation

```bash
composer require padosoft/askmydocs-connector-notion
```

The package follows Laravel's auto-discovery convention so no manual provider registration is required. After install, run:

```bash
php artisan vendor:publish --tag=connector-notion-config   # optional — for env-var overrides
php artisan vendor:publish --tag=connector-notion-assets   # optional — copies notion.svg to public/connectors
```

The `connector-base` migrations ship in the parent package (`padosoft/askmydocs-connector-base`) and auto-load via its service provider; no extra `migrate` step is needed.

## Credential setup (junior-proof, step by step)

Notion uses a public-integration OAuth2 flow. You need a client_id, client_secret, and a redirect URI registered with Notion. Follow EVERY step — skipping a checkbox in the Notion UI is the single most common cause of `403 restricted_resource` later.

### 1. Create the Notion integration

1. Open <https://www.notion.so/my-integrations> in your browser. Sign in.
2. Click **"+ New integration"** (top-left).
3. **Basic Information**:
    - Name: `AskMyDocs` (or any label that makes sense for your tenant)
    - Logo: skip (optional)
    - Associated workspace: pick the workspace whose pages you want to ingest
4. **Capabilities** (most important — this is the checklist):
    - **Content Capabilities**: tick **Read content**. Leave **Update content** and **Insert content** unticked — the connector is read-only.
    - **Comment Capabilities**: tick **Read comments**.
    - **User Capabilities**: select **Read user information including email addresses**.
5. Click **"Submit"**. The integration page appears.

### 2. Capture the secret

1. Under **Internal Integration Secret**, click **"Show"**. Copy the value (starts with `secret_` or `ntn_`).

### 3. Share at least one page with the integration

Notion integrations only see pages explicitly shared with them. Do this once per workspace.

1. In your Notion workspace, open or create a page you want to ingest.
2. Top-right corner of that page click **"..."** (three dots) → scroll to **"Add connections"** → search for your integration's name → click it. Confirm.
3. The integration can now read this page (and any sub-pages).

### 4. Write credentials to `.env`

In your AskMyDocs host app's `.env`:

```dotenv
CONNECTOR_NOTION_CLIENT_ID=<your-integration-client-id>
CONNECTOR_NOTION_CLIENT_SECRET=<your-integration-client-secret>
CONNECTOR_NOTION_REDIRECT_URI=https://your-app.example.com/api/admin/connectors/notion/oauth/callback
# Optional — pin Notion API version. Default 2022-06-28.
# CONNECTOR_NOTION_API_VERSION=2022-06-28
```

If you're testing OAuth locally and don't have a publicly-routable HTTPS redirect URI, use a tunnel (Cloudflare Tunnel, ngrok, Tailscale Funnel) so Notion can call your callback.

### 5. Verify (curl)

```bash
curl -s https://api.notion.com/v1/users \
  -H "Authorization: Bearer <integration-secret>" \
  -H "Notion-Version: 2022-06-28"
```

Expected (truncated):

```json
{"object":"list","results":[{"object":"user","id":"...","name":"...","type":"bot"}]}
```

If you see `401 unauthorized — API token is invalid`: re-copy the secret from step 2.

### 6. Common errors

- `404 path not found — Could not find page with ID` — Page not shared with the integration (step 3).
- `403 restricted_resource` — The integration's capabilities don't include the action you tried; revisit step 1.4.
- `400 invalid_request — code grant code is invalid` — The callback was hit twice (the OAuth code is single-use) or the redirect URI doesn't match step 4 exactly (trailing slashes matter).

## Activation inside AskMyDocs

After `composer require` + the env vars above:

1. Run the host app's admin UI.
2. Navigate to **Settings → Connectors**.
3. The **Notion** card appears with an **Install** button.
4. Click **Install** → browser redirects to Notion → operator authorises → returns to the admin UI → status flips to `active`.
5. The first full sync fires within the cadence window (default 15 minutes; configurable via `CONNECTOR_DEFAULT_SYNC_CADENCE_MINUTES`). To trigger immediately, click **Sync now**.

## What gets ingested

For every Notion page the integration can see:

- **Markdown body** — block tree rendered via `NotionBlockToMarkdown`. Page title prepended as `# Title` so the host's chunker indexes it.
- **Frontmatter / metadata** captured under `metadata.converter_hints.notion`:
  - `database_id` (when the page lives inside a database)
  - `properties` — scalarised property panel (status, dates, owners, multi-select tags, etc.)
  - `tags` — `multi_select` option names flat-promoted
  - `last_edited_by` — name or id
- **`_derived` reranker signals** under `metadata.converter_hints._derived`:
  - `search_tags`, `status_active`, `recency_bucket`, `owner`

The synthetic MIME `application/vnd.notion.page+json` routes the document to the host's Notion-aware chunker when one is installed.

## Sync semantics

- **Full sync** — `POST /v1/search` with `filter.object=page`, no time bound. Every hit fetches its block tree (`GET /v1/blocks/{id}/children`, recursive up to 5 levels), renders to markdown, and dispatches one ingestion per page. Memory bounded by `page_size=100`; pagination capped at 100 pages by default (raise via the paginator's `maxPages` arg if your workspace exceeds 10k pages).
- **Incremental sync** — same `/search` call sorted by `last_edited_time` descending. The walker short-circuits as soon as a batch contains a page older than `$since`, so daily syncs cost one network round-trip on quiet workspaces.
- **Archive reconciliation** — pages flipped to `archived: true` in `/search` results route through `ConnectorIngestionContract::softDeleteByRemoteId('notion_page_id', ...)`. The host's deletion service finds the matching `knowledge_documents` row (tenant-scoped) and soft-deletes it.
- **Disconnect** — clears local credentials only. Notion has no programmatic revoke endpoint; operators must remove the integration from their workspace settings to fully revoke.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite has three flavours:

| Suite     | What it covers                                                                                  | Network |
|-----------|-------------------------------------------------------------------------------------------------|---------|
| Unit      | `NotionBlockToMarkdown` — pure PHP, ~20 block-shape cases.                                       | None    |
| Feature   | `NotionPaginator` + `NotionConnector` against `Http::fake()`. ~25 scenarios incl. OAuth, sync,   | None    |
|           | health, soft-delete, watermark short-circuit, pagination-limit truncation.                       |         |
| Live      | Opt-in — actually hits api.notion.com. Skipped unless `CONNECTOR_NOTION_LIVE=1`.                  | Real    |

CI runs Default (Unit + Feature) against PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

## Live testsuite

The live suite is **opt-in** so CI never pays for real API calls. To run it:

```bash
export CONNECTOR_NOTION_LIVE=1
export CONNECTOR_NOTION_TOKEN=<your-integration-secret>
vendor/bin/phpunit --testsuite=Live
```

This calls `/v1/users` and `/v1/search` once each to validate credentials and the response-shape contract.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `403 restricted_resource` during sync | Integration capability for the resource is unticked | Re-open the integration page on notion.so, tick the missing capability under step 1.4, click **Save** |
| `404 path not found` for a known page | Page not explicitly shared with the integration | Open the page → "..." → "Add connections" → pick the integration |
| Sync truncates with `maxPages` error | Workspace has more pages than the cap | Raise `maxPages` in the connector call site (default 100) or trigger another sync — the next run picks up where the previous one stopped |
| `Notion OAuth callback state token invalid` | The OAuth callback was hit twice OR the cache TTL expired (default 600s) | Restart the install from the admin UI; the state token re-issues on the next click |
| Tokens never refresh | Notion tokens DO NOT expire by design | This is intentional. To revoke, delete the integration in the Notion workspace UI. |

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
