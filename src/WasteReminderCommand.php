<?php

declare(strict_types=1);

namespace Fahoba\Waste;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Dashboard\PlacedTiles;
use Fahoba\PluginApi\Messages\Message;
use Fahoba\PluginApi\Messages\MessageAudience;
use Fahoba\PluginApi\Messages\Messenger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The evening before a collection: “Tomorrow: Biotonne”.
 *
 * Scheduled every quarter hour, because each household has its own zone and
 * time; one reminder per household, address and kinds a day. The same tile
 * on the wall and on two phones is one reminder — the widest audience and
 * the earliest time win.
 */
final class WasteReminderCommand extends Command
{
    protected $signature = 'fahoba:waste-reminders';

    protected $description = 'Remind households the evening before a waste collection';

    public function handle(PlacedTiles $tiles, Messenger $messenger, WasteCollectionWidget $widget): int
    {
        foreach ($this->reminders($tiles) as $reminder) {
            $now = CarbonImmutable::now($reminder['timezone']);
            $done = 'fahoba-waste:reminded:'.$reminder['household'].':'.$reminder['id'];

            if ($now->format('H:i') < $reminder['at'] || Cache::get($done) === $now->toDateString()) {
                continue;
            }

            // Not marked done: allowed later today, it still goes out.
            if (! $messenger->allowed(WasteCollectionWidget::KEY, $reminder['household'])) {
                continue;
            }

            $text = $widget->file($reminder['url']);

            if ($text === null) {
                continue;
            }

            $tomorrow = $now->startOfDay()->addDay();
            $bins = array_column($widget->collections($text, $reminder['timezone'], $tomorrow, $tomorrow->addDay(), $reminder['kinds']), 'title');
            Cache::put($done, $now->toDateString(), 172_800);

            if ($bins === []) {
                continue;
            }

            $messenger->send(WasteCollectionWidget::KEY, $reminder['household'], new Message(
                title: __('Tomorrow: :bins', ['bins' => implode(', ', $bins)], $reminder['locale']),
                body: trans_choice('Put the bin out tonight.|Put the bins out tonight.', count($bins), [], $reminder['locale']),
                link: '/?area=dashboard',
                audience: $reminder['audience'],
                tag: 'bins-'.$reminder['id'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{household: int, timezone: string, locale: string, id: string, url: string, kinds: list<string>, at: string, audience: MessageAudience}>
     */
    private function reminders(PlacedTiles $tiles): array
    {
        $reminders = [];

        foreach ($tiles->of(WasteCollectionWidget::KEY) as $tile) {
            $audience = match ($tile->settings['remind'] ?? null) {
                'parents' => MessageAudience::Parents,
                'family' => MessageAudience::Family,
                default => null,
            };
            $url = WasteCollectionWidget::address($tile->settings['url'] ?? null);

            if ($audience === null || $url === null) {
                continue;
            }

            $kinds = WasteCollectionWidget::kinds($tile->settings['only'] ?? null);
            sort($kinds);
            $at = in_array($tile->settings['remind_at'] ?? null, WasteCollectionWidget::REMIND_TIMES, true)
                ? $tile->settings['remind_at']
                : WasteCollectionWidget::REMIND_TIMES[0];
            $id = substr(sha1($url.'|'.implode(',', $kinds)), 0, 12);
            $key = $tile->householdId.':'.$id;
            $known = $reminders[$key] ?? null;

            $reminders[$key] = [
                'household' => $tile->householdId,
                'timezone' => $tile->timezone,
                'locale' => $tile->locale,
                'id' => $id,
                'url' => $url,
                'kinds' => $kinds,
                'at' => $known === null ? $at : min($known['at'], $at),
                'audience' => $known !== null && $known['audience'] === MessageAudience::Family ? MessageAudience::Family : $audience,
            ];
        }

        return array_values($reminders);
    }
}
