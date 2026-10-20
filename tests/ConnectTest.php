<?php

use Illuminate\Support\Facades\Http;
use Porro\Core\CrontabIo;
use Porro\Filament\Tests\FakeCrontab;

it('connects a Filament site and offers to set up cron', function () {
    $env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($env, "APP_NAME=Test\n");
    $this->app->loadEnvironmentFrom(basename($env));
    $this->app->useEnvironmentPath(dirname($env));
    $this->app->instance(CrontabIo::class, $crontab = new FakeCrontab);
    Http::fake(['porro.test/*' => Http::response(['ok' => true], 202)]);
    $seed = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    $this->artisan('porro:connect', ['key' => 'porro_01jabcdefghjkmnpqrstvwxyz0_'.$seed])
        ->expectsConfirmation('Reports run on the scheduler, which needs one cron line. Add it to this server’s crontab now?', 'yes')
        ->assertSuccessful();

    expect($crontab->contents)->toContain('artisan schedule:run');
    Http::assertSent(fn ($request) => collect(json_decode($request->body(), true)['layers'])->contains('name', 'filament'));
});
