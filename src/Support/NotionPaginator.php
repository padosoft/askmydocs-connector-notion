<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorNotion\Support;

use Generator;
use Illuminate\Http\Client\Response;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorPaginationLimitException;

/**
 * `next_cursor` pagination walker for Notion endpoints.
 *
 * Notion's paginated endpoints (search, blocks.children.list,
 * databases.query, users.list) share the same shape:
 *
 *   POST/GET /v1/<resource>
 *     body/query: { start_cursor?: string, page_size?: number, ... }
 *     200: { results: [...], next_cursor: string|null, has_more: bool }
 *
 * Two traversal modes:
 *
 *   - {@see walk()} — eager. Materialises every result into a single
 *     array. Use when the result set is bounded and small (block
 *     children of a single page, users list).
 *
 *   - {@see walkLazy()} — lazy. Yields one batch at a time as a
 *     {@see Generator<list<array<string,mixed>>>}, allowing the caller
 *     to process incrementally AND short-circuit (`break` out of the
 *     `foreach`) without paying for subsequent network calls. Use for
 *     workspace-wide search and any endpoint where the result set is
 *     potentially large.
 *
 * Exception taxonomy:
 *   - HTTP 401 / 403 → {@see ConnectorAuthException}
 *     (the job runner treats this as a permanent failure)
 *   - Any other non-2xx → {@see ConnectorApiException}
 *     (transient; the job runner retries per its backoff policy)
 *   - Non-JSON body → {@see ConnectorApiException}
 *   - `maxPages` reached while `has_more === true` →
 *     {@see ConnectorPaginationLimitException} (caller catches +
 *     records `errors[]` in SyncResult; partial truncation is NOT
 *     a silent success)
 */
final class NotionPaginator
{
    /**
     * Eager traversal — materialise the full result set into one list.
     *
     * Implemented as a thin wrapper over {@see walkLazy()} so the two
     * code paths share the same exception semantics. Prefer
     * `walkLazy()` for any potentially-large endpoint.
     *
     * @param  \Closure(?string $cursor): Response  $fetch
     * @return list<array<string,mixed>>
     */
    public function walk(\Closure $fetch, int $maxPages = 100): array
    {
        $out = [];
        foreach ($this->walkLazy($fetch, $maxPages) as $batch) {
            foreach ($batch as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Lazy traversal — yield one batch at a time. The caller drives
     * the loop, so an incremental sync can break out as soon as a
     * batch contains entries older than the watermark (Notion search
     * sorts by `last_edited_time` desc, so subsequent batches are
     * guaranteed to be older).
     *
     * Memory characteristics: only one batch (≤ `page_size` rows)
     * lives in PHP memory at any moment, regardless of total result
     * set size.
     *
     * @param  \Closure(?string $cursor): Response  $fetch
     * @return Generator<int, list<array<string,mixed>>>
     */
    public function walkLazy(\Closure $fetch, int $maxPages = 100): Generator
    {
        $cursor = null;
        $page = 0;

        do {
            $response = $fetch($cursor);
            if (! $response instanceof Response) {
                throw new \RuntimeException(
                    'NotionPaginator: fetch closure must return an Illuminate Http Response instance.'
                );
            }

            if (! $response->successful()) {
                $body = substr((string) $response->body(), 0, 200);
                $message = sprintf('Notion API call failed: HTTP %d %s', $response->status(), $body);

                if ($response->status() === 401 || $response->status() === 403) {
                    throw new ConnectorAuthException($message);
                }

                throw new ConnectorApiException($message);
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new ConnectorApiException('Notion API returned non-JSON body.');
            }

            $batch = [];
            foreach (($payload['results'] ?? []) as $row) {
                if (is_array($row)) {
                    $batch[] = $row;
                }
            }

            yield $batch;

            $hasMore = (bool) ($payload['has_more'] ?? false);
            $next = $payload['next_cursor'] ?? null;
            $cursor = (is_string($next) && $next !== '') ? $next : null;

            if (! $hasMore || $cursor === null) {
                return;
            }

            $page++;
            if ($page >= $maxPages && $hasMore) {
                throw new ConnectorPaginationLimitException(
                    maxPages: $maxPages,
                );
            }
        } while (true);
    }
}
