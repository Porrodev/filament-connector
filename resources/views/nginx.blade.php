<x-filament-panels::page>
    @include('porro-filament::styles')

    <div class="porro-health">
        <p class="porro-health-small"><x-filament::link :href="$blockingUrl">← {{ __('Blocking') }}</x-filament::link></p>
        <p class="porro-health-muted">{{ __('nginx turns blocked IPs away before PHP runs. Porro writes a deny file on each server; nginx includes it and reloads when it changes. Two one-time steps, on every server this site runs on.') }}</p>

        @unless ($enforcement['nginx'])
            <x-filament::section>
                <div class="porro-health-stack">
                    <p>{{ __('nginx mode is off. Add this to the site’s environment first, so Porro starts writing the file every minute:') }}</p>
                    <pre class="porro-health-code"><code>PORRO_BLOCK_NGINX=true</code></pre>
                </div>
            </x-filament::section>
        @endunless

        @unless ($enforcement['production'])
            <x-filament::section>
                <p>{{ __('This copy isn’t production, so it doesn’t write the file. Set these steps up on the production servers.') }}</p>
            </x-filament::section>
        @endunless

        <x-filament::section :heading="__('Step 1 · include the file')" :description="__('In the site’s nginx config, inside server { }. On Forge: Site → Edit Nginx Configuration.')">
            <div class="porro-health-stack">
                @if ($behindCloudflare)
                    <p>{{ __('This site is behind Cloudflare, so the real IP lines come first: without them nginx sees Cloudflare’s address and would deny Cloudflare, not the visitor.') }}</p>
                    <pre class="porro-health-code"><code>{{ $enforcement['realIp'] }}{{ $enforcement['include'] }}</code></pre>
                @else
                    <pre class="porro-health-code"><code>{{ $enforcement['include'] }}</code></pre>
                    <p class="porro-health-muted porro-health-small">{{ __('Behind Cloudflare or another proxy? Its real IP lines have to come before the include, or nginx would deny the proxy instead of the visitor.') }}</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('Step 2 · reload nginx when the list changes')" :description="__('A scheduled job as root, every minute. On Forge: Server → Scheduler → New job, user root.')">
            <div class="porro-health-stack">
                <pre class="porro-health-code"><code>{{ $enforcement['reload'] }}</code></pre>
                <p class="porro-health-muted porro-health-small">{{ __('It reloads only when the file has changed and nginx -t passes, so a bad config never takes the site down.') }}</p>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('Servers')" :description="__('Each checks itself every minute')">
            @if (empty($enforcement['servers']))
                <p>{{ __('No server has reported yet. Once PORRO_BLOCK_NGINX is on, each server checks itself every minute and shows up here.') }}</p>
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
