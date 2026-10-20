<?php

use Composer\InstalledVersions;
use Porro\Core\Report;
use Porro\Filament\FilamentCollector;
use Porro\Laravel\Reporter;

it('adds a Filament layer to the Laravel report', function () {
    $report = app(Reporter::class)->build()->toArray();
    $layer = collect($report['layers'])->firstWhere('name', 'filament');

    expect($layer)->not->toBeNull()
        ->and($layer['version'])->toBe(Report::installedVersion('filament/filament'))->not->toBeNull()
        ->and($layer['data']['livewire'])->toBe(Report::installedVersion('livewire/livewire'));
});

it('reports each panel and whether it has a login', function () {
    $layer = collect(app(Reporter::class)->build()->toArray()['layers'])->firstWhere('name', 'filament');

    expect($layer['data']['panels'])->toHaveCount(1)
        ->and($layer['data']['panels'][0])->toMatchArray(['id' => 'admin', 'path' => 'admin', 'login' => true]);

    // Multi-factor authentication only exists from Filament 4.
    $mfa = $layer['data']['panels'][0]['mfa'];
    version_compare((string) InstalledVersions::getVersion('filament/filament'), '4.0.0', '>=')
        ? expect($mfa)->toBeFalse()
        : expect($mfa)->toBeNull();
});

it('lists Filament plugins but not Filament itself or this connector', function () {
    $layer = collect(app(Reporter::class)->build()->toArray()['layers'])->firstWhere('name', 'filament');
    $names = array_column($layer['data']['plugins'], 'name');

    expect($names)->each->not->toStartWith('filament/')
        ->and($names)->not->toContain('porro/filament-connector');
});

it('is registered as a Porro collector', function () {
    expect(collect(app()->tagged('porro.collectors'))->map(fn ($c) => $c::class))->toContain(FilamentCollector::class);
});
