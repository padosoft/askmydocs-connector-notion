<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorNotion;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Notion connector package.
 *
 * Merges the Notion provider block into the host's `connectors.php`
 * config tree (under `providers.notion`). Publishes both the config
 * fragment + the brand asset for hosts that want to customise either.
 *
 * Auto-registration into the connector registry happens at the base
 * package level via composer's `extra.askmydocs.connectors` discovery
 * — the entry is in this package's composer.json.
 */
class NotionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/notion.php', 'connectors.providers.notion');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/notion.php' => config_path('connectors-notion.php'),
            ], 'connector-notion-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-notion-assets');
        }
    }
}
