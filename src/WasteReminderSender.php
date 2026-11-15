<?php

declare(strict_types=1);

namespace Fahoba\Waste;

use Fahoba\PluginApi\Messages\MessageSender;

final class WasteReminderSender implements MessageSender
{
    public function label(): string
    {
        return __('Waste collection');
    }

    public function description(): string
    {
        return __('The evening before a collection, for every waste tile whose reminder is switched on.');
    }
}
