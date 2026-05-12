<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorNotion\Tests\Live;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorNotion\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Live test — hits api.notion.com when `CONNECTOR_NOTION_LIVE=1` and a
 * valid `CONNECTOR_NOTION_TOKEN` is present in the environment.
 *
 * Operators run this manually to validate credentials and / or record
 * fresh response-shape fixtures. CI does NOT run this suite by default
 * (the gate env-var is unset on CI runners).
 *
 * See README.md §Credential setup → §Live testsuite for the step-by-
 * step setup. Mirrors the AskMyDocs RUNBOOK Notion section verbatim.
 */
final class NotionLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONNECTOR_NOTION_LIVE') !== '1') {
            $this->markTestSkipped('CONNECTOR_NOTION_LIVE not set to 1 — live suite disabled.');
        }

        $token = getenv('CONNECTOR_NOTION_TOKEN');
        if ($token === false || trim((string) $token) === '') {
            $this->markTestSkipped('Missing credential env var: CONNECTOR_NOTION_TOKEN');
        }
    }

    #[Test]
    public function lists_users_via_real_api(): void
    {
        $response = Http::withToken((string) getenv('CONNECTOR_NOTION_TOKEN'))
            ->withHeaders(['Notion-Version' => '2022-06-28'])
            ->timeout(10)
            ->get('https://api.notion.com/v1/users');

        $this->assertTrue(
            $response->successful(),
            'Notion /v1/users returned non-2xx: '.$response->status(),
        );
        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayHasKey('results', $json);
    }

    #[Test]
    public function searches_pages_via_real_api(): void
    {
        $response = Http::withToken((string) getenv('CONNECTOR_NOTION_TOKEN'))
            ->withHeaders([
                'Notion-Version' => '2022-06-28',
                'Content-Type' => 'application/json',
            ])
            ->timeout(10)
            ->post('https://api.notion.com/v1/search', [
                'filter' => ['property' => 'object', 'value' => 'page'],
                'page_size' => 1,
            ]);

        $this->assertTrue(
            $response->successful(),
            'Notion /v1/search returned non-2xx: '.$response->status(),
        );
        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayHasKey('results', $json);
        $this->assertArrayHasKey('has_more', $json);
    }
}
