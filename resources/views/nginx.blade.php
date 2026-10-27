<x-filament-panels::page>
    @include('porro-filament::styles')

    <div class="porro-health">
        <p class="porro-health-small"><x-filament::link :href="$blockingUrl">← {{ __('Blocking') }}</x-filament::link></p>
        <p class="porro-health-muted">{{ __('nginx turns blocked IPs away before PHP runs. Porro keeps a deny file up to date; nginx reads it. Three one-time steps: the first once for the site, the other two on every server it runs on.') }}</p>

        @unless ($enforcement['production'])
            <x-filament::section>
                <p>{{ __('This copy isn’t production, so it doesn’t write the file. Do these steps for the production site and its servers.') }}</p>
            </x-filament::section>
        @endunless

        @php
            $servers = $progress['servers'];
            $onServers = fn (int $n) => ($servers && $n === $servers ? '✓ ' : '').($servers === 0 ? __('No server has checked in yet') : trans_choice('Done on :n of :count server|Done on :n of :count servers', $servers, ['n' => $n, 'count' => $servers]));
        @endphp

        <x-filament::section :heading="__('1 · Turn on nginx mode')" :description="__('Once, for the site').' · '.($enforcement['nginx'] ? '✓ '.__('Done') : __('To do'))">
            <div class="porro-health-stack">
                <p>{{ __('Add this to the site’s environment: .env, or on Forge, the site’s Environment tab. Porro then writes the deny file every minute.') }}</p>
                <div class="porro-health-copyable"><pre class="porro-health-code">{{ 'PORRO_BLOCK_NGINX=true' }}</pre><x-filament::button size="sm" color="gray" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.copy); copied = true; setTimeout(() => copied = false, 1500)" data-copy="{{ 'PORRO_BLOCK_NGINX=true' }}"><span x-text="copied ? '✓' : {{ \Illuminate\Support\Js::from(__('Copy')) }}">{{ __('Copy') }}</span></x-filament::button></div>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('2 · Add one line to the site’s nginx config')" :description="__('On every server').' · '.$onServers($progress['included'])">
            <div class="porro-health-stack">
                <p>{{ __('Inside server { }, above the first location. On Forge: open the site, then Edit Nginx Configuration from the Files menu, and save.') }}</p>
                <div class="porro-health-copyable"><pre class="porro-health-code">{{ $enforcement['include'] }}</pre><x-filament::button size="sm" color="gray" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.copy); copied = true; setTimeout(() => copied = false, 1500)" data-copy="{{ $enforcement['include'] }}"><span x-text="copied ? '✓' : {{ \Illuminate\Support\Js::from(__('Copy')) }}">{{ __('Copy') }}</span></x-filament::button></div>
                <p class="porro-health-muted porro-health-small">{{ __('Where it goes, in a trimmed copy of this site’s config:') }}</p>
                <pre class="porro-health-code porro-health-example">@foreach ($example as $row)<span @class(['porro-health-add' => $row['add']])>{{ $row['line'] }}@if ($row['add'])  <em>← {{ __('add this line') }}</em>@endif</span>@endforeach</pre>
                @if ($realIpInFile)
                    <p class="porro-health-small">{{ __('Behind Cloudflare: the file also carries Cloudflare’s real IP lines, so nginx turns away the visitor and not Cloudflare. Nothing else to add. If this config already sets real_ip_header, set PORRO_NGINX_REAL_IP=false.') }}</p>
                @elseif ($behindCloudflare)
                    <p class="porro-health-small">{{ __('This site is behind Cloudflare but PORRO_NGINX_REAL_IP is off, so the config needs Cloudflare’s real IP lines too. Without them nginx sees Cloudflare’s address and would deny Cloudflare, not the visitor:') }}</p>
                    <div class="porro-health-copyable"><pre class="porro-health-code">{{ $enforcement['realIp'] }}</pre><x-filament::button size="sm" color="gray" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.copy); copied = true; setTimeout(() => copied = false, 1500)" data-copy="{{ $enforcement['realIp'] }}"><span x-text="copied ? '✓' : {{ \Illuminate\Support\Js::from(__('Copy')) }}">{{ __('Copy') }}</span></x-filament::button></div>
                @else
                    <p class="porro-health-muted porro-health-small">{{ __('Behind Cloudflare? Porro adds Cloudflare’s real IP lines to the file for you once you open this page through it, or once a Cloudflare token is set.') }}</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('3 · Reload nginx when the list changes')" :description="__('On every server').' · '.$onServers($progress['loaded'])">
            <div class="porro-health-stack">
                <p>{{ __('A scheduled job that runs every minute as root. On Forge: Server → Scheduler → New job, user root, frequency every minute.') }}</p>
                <div class="porro-health-copyable"><pre class="porro-health-code">{{ $enforcement['reload'] }}</pre><x-filament::button size="sm" color="gray" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.copy); copied = true; setTimeout(() => copied = false, 1500)" data-copy="{{ $enforcement['reload'] }}"><span x-text="copied ? '✓' : {{ \Illuminate\Support\Js::from(__('Copy')) }}">{{ __('Copy') }}</span></x-filament::button></div>
                <p class="porro-health-muted porro-health-small">{{ __('It reloads only when the file has changed and nginx -t passes, so a bad config never takes the site down.') }}</p>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('Servers')" :description="__('Each checks itself every minute')">
            @if (empty($enforcement['servers']))
                <p>{{ __('No server has checked in yet. Once step 1 is done, each server checks itself every minute and shows up here.') }}</p>
            @else
                <ul class="porro-health-list">
                    @foreach ($enforcement['servers'] as $server)
                        <li class="porro-health-item porro-health-entry">
                            <span class="porro-health-mono porro-health-body">{{ $server['name'] }}</span>
                            @if ($server['ok'] && $behindCloudflare && $server['realIp'] === false)
                                <x-filament::badge color="warning" size="sm">{{ __('Real IP lines missing') }}</x-filament::badge>
                            @endif
                            <span class="porro-health-muted porro-health-small">@if ($server['reloaded']){{ __('reloaded :ago', ['ago' => $server['reloaded']]) }} · @endif{{ __('checked :ago', ['ago' => $server['checked']]) }}</span>
                            <x-filament::badge :color="$server['ok'] ? 'success' : ($server['state'] === 'not_included' ? 'danger' : 'warning')" size="sm">{{ $server['label'] }}</x-filament::badge>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="porro-health-muted porro-health-small porro-health-gap">
                {{ __('Each server checks by looking, never by running anything: the file exists, a readable nginx config includes it, and the reload job has run since the last change.') }}
                @unless ($sharedCache) {{ __('This site’s cache isn’t shared between servers, so only this server shows here. Use Redis or the database cache to see them all.') }} @endunless
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
