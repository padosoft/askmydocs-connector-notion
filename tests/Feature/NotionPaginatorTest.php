<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorNotion\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorPaginationLimitException;
use Padosoft\AskMyDocsConnectorNotion\Support\NotionPaginator;
use Padosoft\AskMyDocsConnectorNotion\Tests\TestCase;

/**
 * Validates the next_cursor loop semantics against Notion-shaped
 * payloads (results / next_cursor / has_more).
 */
final class NotionPaginatorTest extends TestCase
{
    public function test_walk_terminates_on_has_more_false(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::sequence()
                ->push([
                    'results' => [['id' => 'page-1'], ['id' => 'page-2']],
                    'next_cursor' => 'cursor-2',
                    'has_more' => true,
                ], 200)
                ->push([
                    'results' => [['id' => 'page-3']],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
        ]);

        $pages = (new NotionPaginator)->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));

        $this->assertCount(3, $pages);
        $this->assertSame('page-1', $pages[0]['id']);
        $this->assertSame('page-3', $pages[2]['id']);
    }

    public function test_walk_handles_single_page_response(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [['id' => 'only-page']],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $pages = (new NotionPaginator)->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));

        $this->assertCount(1, $pages);
    }

    public function test_walk_handles_empty_results(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $pages = (new NotionPaginator)->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));

        $this->assertSame([], $pages);
    }

    public function test_walk_throws_connector_auth_exception_on_401(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response(['message' => 'API token is invalid.'], 401),
        ]);

        $this->expectException(ConnectorAuthException::class);

        (new NotionPaginator)->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));
    }

    public function test_walk_throws_connector_auth_exception_on_403(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response(['message' => 'Forbidden.'], 403),
        ]);

        $this->expectException(ConnectorAuthException::class);

        (new NotionPaginator)->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));
    }

    public function test_walk_throws_connector_api_exception_on_5xx(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response(['message' => 'Internal Server Error'], 500),
        ]);

        $this->expectException(ConnectorApiException::class);

        (new NotionPaginator)->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));
    }

    public function test_walk_throws_connector_api_exception_on_429(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response(['message' => 'Rate limit exceeded.'], 429),
        ]);

        $this->expectException(ConnectorApiException::class);

        (new NotionPaginator)->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ));
    }

    public function test_walk_lazy_yields_one_batch_at_a_time(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::sequence()
                ->push([
                    'results' => [['id' => 'p1'], ['id' => 'p2']],
                    'next_cursor' => 'c2',
                    'has_more' => true,
                ], 200)
                ->push([
                    'results' => [['id' => 'p3']],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
        ]);

        $batches = [];
        foreach ((new NotionPaginator)->walkLazy(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        )) as $batch) {
            $batches[] = array_map(static fn ($r) => $r['id'], $batch);
        }

        $this->assertSame([['p1', 'p2'], ['p3']], $batches);
    }

    public function test_walk_lazy_allows_early_break_to_skip_remaining_network_calls(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::sequence()
                ->push([
                    'results' => [['id' => 'fresh']],
                    'next_cursor' => 'c2',
                    'has_more' => true,
                ], 200)
                ->push([
                    'results' => [['id' => 'should-not-fetch']],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
        ]);

        $seen = [];
        foreach ((new NotionPaginator)->walkLazy(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        )) as $batch) {
            foreach ($batch as $row) {
                $seen[] = $row['id'];
            }
            break;
        }

        $this->assertSame(['fresh'], $seen);
        Http::assertSentCount(1);
    }

    public function test_walk_throws_when_max_pages_reached_with_has_more(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [['id' => 'page-x']],
                'next_cursor' => 'cursor-next',
                'has_more' => true,
            ], 200),
        ]);

        $this->expectException(ConnectorPaginationLimitException::class);

        (new NotionPaginator)->walk(fn (?string $cursor) => Http::post(
            'https://api.notion.com/v1/search',
            $cursor === null ? [] : ['start_cursor' => $cursor],
        ), maxPages: 2);
    }

    public function test_pagination_limit_exception_exposes_max_pages(): void
    {
        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [['id' => 'partial-page']],
                'next_cursor' => 'cursor-next',
                'has_more' => true,
            ], 200),
        ]);

        try {
            (new NotionPaginator)->walk(fn (?string $cursor) => Http::post(
                'https://api.notion.com/v1/search',
                $cursor === null ? [] : ['start_cursor' => $cursor],
            ), maxPages: 2);
            $this->fail('Expected ConnectorPaginationLimitException');
        } catch (ConnectorPaginationLimitException $e) {
            $this->assertSame(2, $e->maxPages);
            $this->assertSame([], $e->partialResults);
        }
    }
}
