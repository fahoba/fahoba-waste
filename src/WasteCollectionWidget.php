<?php

declare(strict_types=1);

namespace Fahoba\Waste;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Fahoba\PluginApi\Dashboard\DataWidget;
use Fahoba\PluginApi\Dashboard\HasSettings;
use Fahoba\PluginApi\Dashboard\SettingField;
use Fahoba\PluginApi\Dashboard\WidgetContent;
use Fahoba\PluginApi\Dashboard\WidgetContext;
use Fahoba\PluginApi\Http\Outbound;
use Illuminate\Support\Facades\Cache;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Throwable;

/**
 * The next bins, from the waste calendar the municipality publishes as iCal.
 *
 * Tomorrow's collection says so — that is the evening someone has to take
 * the bin out. The file is fetched at most every six hours per address: a
 * collection plan changes a few times a year, and the tile's own cache asks
 * every minute.
 */
final class WasteCollectionWidget implements DataWidget, HasSettings
{
    public const KEY = 'fahoba/waste';

    public const TTL = 21_600;

    public const MAX_BYTES = 5_000_000;

    /** When the evening reminder may go out; the first is the default. */
    public const REMIND_TIMES = ['18:00', '17:00', '19:00', '20:00', '21:00'];

    /** Keyword in the title → colour dot; the first that matches wins. */
    private const DOTS = [
        'bio' => '🟤',
        'papier' => '🔵',
        'paper' => '🔵',
        'karton' => '🔵',
        'gelb' => '🟡',
        'plastik' => '🟡',
        'verpackung' => '🟡',
        'packaging' => '🟡',
        'glas' => '🟢',
        'glass' => '🟢',
        'rest' => '⚫',
        'general' => '⚫',
        'sperr' => '🟠',
        'bulky' => '🟠',
        'problem' => '🔴',
        'sonder' => '🔴',
        'hazard' => '🔴',
    ];

    public function __construct(private readonly Outbound $outbound) {}

    public function label(): string
    {
        return __('Waste collection');
    }

    public function description(): string
    {
        return __('The next bins from your municipality’s waste calendar (iCal address).');
    }

    public function area(): ?string
    {
        return null;
    }

    public function settings(): array
    {
        return [
            SettingField::text('url', __('Address of the waste calendar'), required: true),
            SettingField::select('days', __('Look ahead'), [
                '7' => __('One week'),
                '14' => __('Two weeks'),
                '31' => __('One month'),
            ]),
            SettingField::text('only', __('Only these kinds (comma separated)')),
            SettingField::select('remind', __('Reminder the evening before'), [
                'off' => __('Off'),
                'parents' => __('To the parents'),
                'family' => __('To the whole family'),
            ]),
            SettingField::select('remind_at', __('Reminder at'), array_combine(self::REMIND_TIMES, self::REMIND_TIMES)),
        ];
    }

    public function content(WidgetContext $context, array $settings): WidgetContent
    {
        $url = self::address($settings['url'] ?? null);

        if ($url === null) {
            return WidgetContent::metric('🗑️', __('Enter the address of the waste calendar in the tile’s settings.'));
        }

        $text = $this->file($url);

        if ($text === null) {
            return WidgetContent::metric('🗑️', __('The waste calendar cannot be reached.'));
        }

        $days = (int) ($settings['days'] ?? 14);
        $today = CarbonImmutable::now($context->timezone)->startOfDay();
        $rows = [];

        foreach ($this->collections($text, $context->timezone, $today, $today->addDays(max(1, $days)), self::kinds($settings['only'] ?? null)) as $pickup) {
            $rows[] = ['text' => self::dot($pickup['title']).' '.$pickup['title'], 'detail' => $this->when($pickup['day'], $today, $context)];
        }

        return $rows === []
            ? WidgetContent::metric('🗑️', trans_choice('No collection in the next day.|No collection in the next :count days.', $days))
            : WidgetContent::list($rows);
    }

    /** `webcal://` is plain HTTPS by another name. */
    public static function address(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = (string) preg_replace('#^webcals?://#i', 'https://', trim($url));

        return preg_match('#^https?://[^/\s]+#i', $url) === 1 ? $url : null;
    }

    public static function dot(string $title): string
    {
        $lower = mb_strtolower($title);

        foreach (self::DOTS as $word => $dot) {
            if (str_contains($lower, $word)) {
                return $dot;
            }
        }

        return '🗑️';
    }

    /** @return list<string> lower-case words; empty means every kind */
    public static function kinds(mixed $only): array
    {
        return array_values(array_filter(array_map(
            static fn (string $word): string => mb_strtolower(trim($word)),
            explode(',', is_string($only) ? $only : ''),
        ), static fn (string $word): bool => $word !== ''));
    }

    /**
     * Collections from today until before `$to`, one row per day and kind,
     * in date order.
     *
     * @param  list<string>  $kinds
     * @return list<array{day: CarbonImmutable, title: string}>
     */
    public function collections(string $text, string $zone, CarbonImmutable $from, CarbonImmutable $to, array $kinds = []): array
    {
        try {
            $calendar = Reader::read($text, Reader::OPTION_FORGIVING);

            if (! $calendar instanceof VCalendar) {
                return [];
            }

            $calendar = $calendar->expand($from->utc(), $to->utc(), new DateTimeZone($zone));
        } catch (Throwable) {
            return [];
        }

        $found = [];

        foreach ($calendar->select('VEVENT') as $vevent) {
            $title = trim((string) ($vevent->SUMMARY ?? ''));
            $start = $vevent->DTSTART ?? null;

            if ($title === '' || $start === null) {
                continue;
            }

            $day = $start->hasTime()
                ? CarbonImmutable::instance($start->getDateTime(new DateTimeZone($zone)))->startOfDay()
                : CarbonImmutable::parse($start->getValue(), $zone)->startOfDay();

            if ($day < $from || $day >= $to) {
                continue;
            }

            if ($kinds !== [] && ! array_filter($kinds, static fn (string $word): bool => str_contains(mb_strtolower($title), $word))) {
                continue;
            }

            $found[$day->format('Y-m-d').'|'.$title] = ['day' => $day, 'title' => $title];
        }

        ksort($found);

        return array_values($found);
    }

    private function when(CarbonImmutable $day, CarbonImmutable $today, WidgetContext $context): string
    {
        $offset = (int) $today->diffInDays($day);

        if ($offset === 0) {
            return __('Today');
        }

        if ($offset === 1) {
            return __('Tomorrow – put it out tonight');
        }

        $pattern = (new IntlDatePatternGenerator($context->locale))->getBestPattern('EEEdMMM');
        $formatter = new IntlDateFormatter($context->locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $context->timezone, null, $pattern ?: 'EEE d MMM');

        return (string) $formatter->format($day->toDateTimeImmutable());
    }

    /** The calendar file, cached per address; null when unreachable or no calendar. */
    public function file(string $url): ?string
    {
        $key = 'fahoba-waste:'.sha1($url);
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $response = $this->outbound->householdGet($url, 15);
        $text = $response?->successful() ? $response->body() : null;

        if ($text === null || strlen($text) > self::MAX_BYTES || ! str_contains($text, 'BEGIN:VCALENDAR')) {
            return null;
        }

        Cache::put($key, $text, self::TTL);

        return $text;
    }
}
