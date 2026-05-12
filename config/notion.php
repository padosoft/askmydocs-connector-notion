<?php

/*
|--------------------------------------------------------------------------
| Notion connector configuration
|--------------------------------------------------------------------------
|
| Provider settings for `padosoft/askmydocs-connector-notion`.
|
| The base package merges this block under
| `config('connectors.providers.notion')`, so concrete connector code
| reads its config via the standard
| `config('connectors.providers.notion.<key>')` path.
|
| All knobs accept env-var overrides — set them in your host app's
| `.env` (see the package README §Credential setup).
|
*/

return [
    'client_id' => env('CONNECTOR_NOTION_CLIENT_ID'),
    'client_secret' => env('CONNECTOR_NOTION_CLIENT_SECRET'),
    'redirect_uri' => env(
        'CONNECTOR_NOTION_REDIRECT_URI',
        env('APP_URL', 'http://localhost').'/api/admin/connectors/notion/oauth/callback'
    ),
    'oauth_authorize_url' => 'https://api.notion.com/v1/oauth/authorize',
    'oauth_token_url' => 'https://api.notion.com/v1/oauth/token',
    // Notion has no programmatic revoke endpoint as of API v2022-06-28;
    // operators must disconnect inside the Notion workspace UI to fully
    // revoke an integration.
    'oauth_revoke_url' => null,
    'api_base' => 'https://api.notion.com/v1',
    // Notion-Version header value. Pin to a known-good revision; bump
    // when Notion ships a backward-compatible version we've validated.
    'api_version' => env('CONNECTOR_NOTION_API_VERSION', '2022-06-28'),
];
