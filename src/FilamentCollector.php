<?php

namespace Porro\Filament;

use Composer\InstalledVersions;
use Porro\Core\Report;
use Porro\Laravel\Collector;
use Throwable;

/**
 * Filament and Livewire versions, Filament plugins, and per-panel checks.
 */
class FilamentCollector implements Collector
{
    public function collect(Report $report): void
    {
        $report->layer('filament', Report::installedVersion('filament/filament'), [
            'livewire' => Report::installedVersion('livewire/livewire'),
            'plugins' => $this->plugins(),
            'panels' => $this->panels(),
        ]);
    }

    /**
     * Installed packages that depend on Filament, i.e. its plugins.
     *
     * @return array<int, array{name: string, version: string|null}>
     */
    private function plugins(): array
    {
        $plugins = [];

        foreach (InstalledVersions::getInstalledPackagesByType('library') as $package) {
            if (str_starts_with($package, 'filament/') || $package === 'porro/filament-connector') {
                continue;
            }

            if (preg_match('/filament/i', $package)) {
                $plugins[] = ['name' => $package, 'version' => Report::installedVersion($package)];
            }
        }

        return $plugins;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function panels(): array
    {
        try {
            $filament = app('filament');
        } catch (Throwable) {
            return [];
        }

        $panels = [];

        foreach ($filament->getPanels() as $panel) {
            $panels[] = [
                'id' => $panel->getId(),
                'path' => $panel->getPath(),
                'login' => $panel->hasLogin(),
                // Filament 4 has built-in multi-factor authentication.
                'mfa' => method_exists($panel, 'isMultiFactorAuthenticationRequired') ? $panel->isMultiFactorAuthenticationRequired() : null,
            ];
        }

        return $panels;
    }
}
