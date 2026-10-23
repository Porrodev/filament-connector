<?php

namespace Porro\Filament;

use Porro\Core\Health\SiteHealth;
use Porro\Core\Report;

/**
 * Filament's own checks for the Site health panel: newer Filament and
 * Livewire releases on the major versions the site runs.
 */
class FilamentHealth
{
    public function check(SiteHealth $health): void
    {
        $health->updates(
            ['filament/filament' => 'Filament', 'livewire/livewire' => 'Livewire'],
            ['filament/filament' => Report::installedVersion('filament/filament'), 'livewire/livewire' => Report::installedVersion('livewire/livewire')],
        );
    }
}
