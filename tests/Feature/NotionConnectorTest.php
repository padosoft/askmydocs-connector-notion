<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorNotion\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorNotion\NotionConnector;
use Padosoft\AskMyDocsConnectorNotion\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorNotion\Tests\TestCase;

/**
 * Feature tests for {@see NotionConnector}.
 *
 * The connector is exercised against `Http::fake()` (no real Notion
 * calls) and a spy implementation of {@see ConnectorIngestionContract}
 * so we can assert what the connector hands off to the host pipeline
 * without needing a real ingest job.
 */
final class NotionConnectorTest extends TestCase
{
    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
        // Connector wires `Storage::disk('local')` for KB writes via
        // the spy's resolveKbSourcePath — fake it.
        Storage::fake('local');

        config()->set('connectors.providers.notion.client_id', 'cid');
        config()->set('connectors.providers.notion.client_secret', 'csec');
        config()->set('connectors.providers.notion.redirect_uri', 'http://localhost/cb');
    }

    private function connector(): NotionConnector
    {
        return $this->app->make(NotionConnector::class);
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function seedActiveCredential(
        int $installationId,
        string $access = 'AT-xyz',
        array $extra = [],
        string $tenantId = 'default',
    ): void {
        ConnectorCredential::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installationId,
            'encrypted_access_token' => Crypt::encryptString($access),
            'encrypted_refresh_token' => null,
            'expires_at' => Carbon::now()->addYears(10),
            'extra_json' => $extra === [] ? null : $extra,
        ]);
    }

    public function test_initiate_oauth_returns_notion_auth_url_with_state_token(): void
    {
        $installation = $this->makeInstallation();
        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://api.notion.com/v1/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('user', $query['owner']);
        $this->assertNotEmpty($query['state'] ?? '');
    }

    public function test_handle_oauth_callback_persists_credentials_and_emits_install_audit(): void
    {
        $installation = $this->makeInstallation();

        Cache::flush();
        $url = $this->connector()->initiateOAuth($installation->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = (string) ($query['state'] ?? '');

        Http::fake([
            'api.notion.com/v1/oauth/token' => Http::response([
                'access_token' => 'token-xyz',
                'bot_id' => 'bot-id-1',
                'workspace_id' => 'ws-uuid',
                'workspace_name' => 'My Workspace',
                'token_type' => 'bearer',
            ], 200),
        ]);

        $request = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $request);

        $this->assertSame('token-xyz', $this->app->make(OAuthCredentialVault::class)->getAccessToken($installation->id));
        $this->assertSame(
            'My Workspace',
            $this->app->make(OAuthCredentialVault::class)->getExtraKey($installation->id, 'workspace_name'),
        );

        $audit = $this->spy->audits;
        $this->assertNotEmpty($audit);
        $this->assertSame('installed', $audit[0]['eventType']);
        $this->assertSame('notion', $audit[0]['connectorKey']);
        $this->assertSame('ws-uuid', $audit[0]['metadata']['workspace_id']);
    }

    public function test_handle_oauth_callback_rejects_invalid_state_token(): void
    {
        $installation = $this->makeInstallation();
        $request = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => 'fabricated']);

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('state token');

        $this->connector()->handleOAuthCallback($installation->id, $request);
    }

    public function test_handle_oauth_callback_rejects_missing_code(): void
    {
        $installation = $this->makeInstallation();
        $request = Request::create('/cb', 'GET', ['state' => 'whatever']);

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('code');

        $this->connector()->handleOAuthCallback($installation->id, $request);
    }

    public function test_health_returns_healthy_when_users_me_succeeds(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.notion.com/v1/users/me' => Http::response(['object' => 'user', 'type' => 'bot'], 200),
        ]);

        $health = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $health->state);
    }

    public function test_health_returns_errored_on_401(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.notion.com/v1/users/me' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $health = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $health->state);
    }

    public function test_health_returns_errored_when_no_credentials(): void
    {
        $installation = $this->makeInstallation();
        $health = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $health->state);
        $this->assertStringContainsString('access token', $health->message ?? '');
    }

    public function test_disconnect_clears_credentials_and_emits_audit(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        $this->connector()->disconnect($installation->id);

        $this->assertNull($this->app->make(OAuthCredentialVault::class)->getAccessToken($installation->id));
        $events = array_column($this->spy->audits, 'eventType');
        $this->assertContains('disconnected', $events);
    }

    public function test_sync_full_throws_when_no_access_token(): void
    {
        $installation = $this->makeInstallation();

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->syncFull($installation->id);
    }

    public function test_sync_full_dispatches_ingestion_per_page_via_contract(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [
                    [
                        'id' => 'page-1234',
                        'archived' => false,
                        'last_edited_time' => '2026-05-01T10:00:00.000Z',
                        'created_time' => '2026-04-30T09:00:00.000Z',
                        'parent' => ['type' => 'workspace'],
                        'properties' => [
                            'Name' => [
                                'type' => 'title',
                                'title' => [['plain_text' => 'My Doc']],
                            ],
                        ],
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
            // Block-tree fetch returns one paragraph.
            'api.notion.com/v1/blocks/*' => Http::response([
                'results' => [[
                    'type' => 'paragraph',
                    'paragraph' => ['rich_text' => [['plain_text' => 'Hello']]],
                    'has_children' => false,
                    'id' => 'b-1',
                ]],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(1, $result->documentsAdded);
        $this->assertCount(1, $this->spy->dispatches);
        $dispatch = $this->spy->dispatches[0];
        $this->assertSame('My Doc', $dispatch['title']);
        $this->assertSame('default', $dispatch['tenantId']);
        $this->assertSame('application/vnd.notion.page+json', $dispatch['mimeType']);
        $this->assertStringContainsString('page-1234', $dispatch['relativePath']);
        $this->assertSame('notion', $dispatch['metadata']['connector']);
        $this->assertSame('page-1234', $dispatch['metadata']['notion_page_id']);
        $this->assertArrayHasKey('converter_hints', $dispatch['metadata']);
    }

    public function test_sync_incremental_archived_page_triggers_soft_delete_via_contract(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);
        $this->spy->remoteIdsThatMatch['page-archived'] = 'default';

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [[
                    'id' => 'page-archived',
                    'archived' => true,
                    'last_edited_time' => '2026-05-10T10:00:00.000Z',
                    'properties' => [],
                ]],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $since = Carbon::parse('2026-04-01T00:00:00Z');
        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(1, $result->documentsRemoved);
        $this->assertCount(1, $this->spy->deletions);
        $this->assertSame('notion_page_id', $this->spy->deletions[0]['metadata_key']);
        $this->assertSame('page-archived', $this->spy->deletions[0]['remote_id']);
    }

    public function test_sync_incremental_breaks_when_batch_older_than_watermark(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        Http::fake([
            'api.notion.com/v1/search' => Http::sequence()
                ->push([
                    'results' => [[
                        'id' => 'page-stale',
                        'archived' => false,
                        'last_edited_time' => '2024-01-01T10:00:00.000Z',
                        'properties' => [],
                    ]],
                    'next_cursor' => 'should-not-fetch',
                    'has_more' => true,
                ], 200)
                ->push([
                    'results' => [['id' => 'should-not-see']],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
        ]);

        $since = Carbon::parse('2026-04-01T00:00:00Z');
        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(0, $result->documentsUpdated);
        // Only the first /search call should have been issued.
        Http::assertSentCount(1);
    }

    public function test_sync_incremental_null_since_falls_back_to_full_sync(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame(0, $result->documentsAdded);
        // Audit modes: should be 'full', not 'incremental', because of fallback.
        $modes = array_filter(array_map(static fn ($a) => $a['metadata']['mode'] ?? null, $this->spy->audits));
        $this->assertContains('full', $modes);
    }
}
