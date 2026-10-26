<?php

namespace App\Services;

use Native\Desktop\Facades\Notification;

class DesktopNotifier
{
    public function queuedActions(int $count): void
    {
        if ($count === 0 || ! config('nativephp-internal.running')) {
            return;
        }

        Notification::new()
            ->title('Sanctum Keeper')
            ->message("{$count} confirmed file action(s) are ready in the local queue.")
            ->silent()
            ->show();
    }
}
