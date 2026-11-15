<?php

declare(strict_types=1);

namespace Fahoba\Waste;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;
use Illuminate\Console\Scheduling\Schedule;

final class WastePlugin extends Plugin
{
    protected function registerPlugin(): void
    {
        $catalog = $this->app->make(Catalog::class);
        $catalog->offer('widgets', WasteCollectionWidget::KEY, WasteCollectionWidget::class);
        $catalog->offer('messages', WasteCollectionWidget::KEY, WasteReminderSender::class);

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }

    protected function bootPlugin(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WasteReminderCommand::class]);
        }

        // Every quarter hour: each household has its own zone and time.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('fahoba:waste-reminders')
                ->everyFifteenMinutes()
                ->withoutOverlapping(30)
                ->onOneServer();
        });
    }
}
