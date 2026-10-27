<x-filament-panels::page>
    @include('porro-filament::styles')

    <div class="porro-health">
        @if ($failed)
            <x-filament::section>
                <p>{{ __('The blocklist tables aren’t there yet. Run php artisan migrate on this site.') }}</p>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('Where blocks are enforced')">
            <ul class="porro-health-list">
                <li class="porro-health-item">
                    <div class="porro-health-body">
                        <p class="porro-health-title">{{ __('In the app') }}
                            <x-filament::badge :color="$enforcement['app'] ? 'success' : 'gray'" size="sm" style="display: inline-flex; margin-left: .25rem">{{ $enforcement['app'] ? __('Active') : __('Off') }}</x-filament::badge>
                        </p>
                        <p class="porro-health-muted">{{ __('Blocked IPs get a 403 before the app handles the request. Turn off with PORRO_BLOCK_APP=false.') }}</p>
                    </div>
                </li>
                <li class="porro-health-item">
                    <div class="porro-health-body porro-health-stack">
                        <p class="porro-health-title">Cloudflare WAF
                            <x-filament::badge :color="match (true) { ! $cloudflare['configured'] => 'gray', (bool) $cloudflare['error'] => 'danger', $cloudflare['connected'] && $cloudflare['production'] && (bool) $cloudflare['synced'] => 'success', default => 'warning' }" size="sm" style="display: inline-flex; margin-left: .25rem">
                                @if (! $cloudflare['configured'])
                                    {{ __('Not set up') }}
                                @elseif (! $cloudflare['connected'])
                                    {{ __('Token set, not connected') }}
                                @elseif ($cloudflare['error'])
                                    {{ __('Sync failed') }}
                                @elseif (! $cloudflare['production'])
                                    {{ __('Production only') }}
                                @elseif ($cloudflare['synced'])
                                    {{ __('Synced :ago', ['ago' => $cloudflare['synced']]) }}
                                @else
                                    {{ __('Waiting for the first sync') }}
                                @endif
                            </x-filament::badge>
                        </p>
                        @if ($cloudflare['connected'])
                            <p class="porro-health-muted">{{ __('Blocked at the edge, before traffic reaches the server · zone :zone · list porro_blocklist', ['zone' => $cloudflare['zone']]) }}@if ($cloudflare['result'] && $cloudflare['result']['shared']) · {{ trans_choice(':count already blocked by another site|:count already blocked by other sites', $cloudflare['result']['shared'], ['count' => $cloudflare['result']['shared']]) }}@endif</p>
                        @elseif ($cloudflare['configured'])
                            <p class="porro-health-muted">{{ __('Run this on the server to find the zone, create the porro_blocklist list and add the rule next to your existing ones:') }}</p>
                            <pre class="porro-health-code"><code>php artisan porro:cloudflare connect</code></pre>
                        @else
                            <p class="porro-health-muted">{{ __('Blocks at Cloudflare’s edge for sites behind it. Create a token with Account Filter Lists Edit, Zone WAF Edit and Zone Read, then:') }}</p>
                            <pre class="porro-health-code"><code>PORRO_CLOUDFLARE_TOKEN=your-token-here
php artisan porro:cloudflare connect</code></pre>
                        @endif
                        @if ($cloudflare['error'])
                            <p style="color: rgb(220 38 38)">{{ $cloudflare['error'] }}</p>
                        @endif
                    </div>
                </li>
                <li class="porro-health-item">
                    <div class="porro-health-body porro-health-stack">
                        <p class="porro-health-title">nginx
                            <x-filament::badge :color="$enforcement['nginx'] && $enforcement['production'] && $enforcement['nginxWritten'] ? 'success' : ($enforcement['nginx'] && $enforcement['production'] ? 'warning' : 'gray')" size="sm" style="display: inline-flex; margin-left: .25rem">
                                @if (! $enforcement['nginx'])
                                    {{ __('Not set up') }}
                                @elseif (! $enforcement['production'])
                                    {{ __('Production only') }}
                                @elseif ($enforcement['nginxWritten'])
                                    {{ __('File written :ago', ['ago' => $enforcement['nginxWritten']]) }}
                                @else
                                    {{ __('Waiting for the first sync') }}
                                @endif
                            </x-filament::badge>
                        </p>
                        @if ($enforcement['nginx'])
                            <p class="porro-health-muted">{{ __('On each server: include the file in the site’s nginx config (Forge: Site → Edit Nginx Configuration, inside server { }), real IP lines first when behind Cloudflare.') }}</p>
                            <pre class="porro-health-code"><code>{{ $enforcement['realIp'] }}{{ $enforcement['include'] }}</code></pre>
                            <p class="porro-health-muted">{{ __('Then reload nginx when the list changes: a scheduled job as root, every minute (Forge: Server → Scheduler).') }}</p>
                            <pre class="porro-health-code"><code>{{ $enforcement['reload'] }}</code></pre>
                        @else
                            <p class="porro-health-muted">{{ __('For sites not behind Cloudflare: set PORRO_BLOCK_NGINX=true and this page shows the two one-time steps for each server.') }}</p>
                        @endif
                    </div>
                </li>
            </ul>
        </x-filament::section>

        <x-filament::section :heading="__('Blocklist')" :description="trans_choice(':count entry|:count entries', count($blocks), ['count' => count($blocks)])">
            @if ($canManage)
                <form wire:submit="addBlock" class="porro-health-form">
                    <x-filament::input.wrapper style="flex: 0 1 18rem">
                        <x-filament::input type="text" wire:model="ip" required :placeholder="__('IP or range, e.g. 203.0.113.0/24')" :aria-label="__('IP or range')" />
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper style="flex: 1 1 10rem">
                        <x-filament::input type="text" wire:model="reason" :placeholder="__('Reason')" :aria-label="__('Reason')" />
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper style="flex: 0 0 10rem">
                        <x-filament::input.select wire:model="days" :aria-label="__('Expires')">
                            <option value="7">{{ __('For 7 days') }}</option>
                            <option value="30">{{ __('For 30 days') }}</option>
                            <option value="0">{{ __('Never expires') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <x-filament::button type="submit">{{ __('Block') }}</x-filament::button>
                </form>
            @endif
            @include('porro-filament::entries', ['entries' => $blocks, 'remove' => 'unblockIp', 'empty' => __('Nothing is blocked.')])
        </x-filament::section>

        <x-filament::section id="safelist" :heading="__('Safelist')" :description="__('Never blocked or flagged')">
            @if ($canManage)
                <form wire:submit="addSafe" class="porro-health-form">
                    <x-filament::input.wrapper style="flex: 0 1 18rem">
                        <x-filament::input type="text" wire:model="safeIp" required :placeholder="__('IP or range')" :aria-label="__('IP or range')" />
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper style="flex: 1 1 10rem">
                        <x-filament::input type="text" wire:model="safeLabel" required :placeholder="__('Label, e.g. 1994 office')" :aria-label="__('Label')" />
                    </x-filament::input.wrapper>
                    <label class="porro-health-small porro-health-muted" style="display: inline-flex; align-items: center; gap: .35rem">
                        <x-filament::input.checkbox wire:model="confirmWide" /> {{ __('Wide range is OK') }}
                    </label>
                    <x-filament::button type="submit">{{ __('Add') }}</x-filament::button>
                </form>
            @endif
            @include('porro-filament::entries', ['entries' => $safe, 'remove' => 'unsafelistIp', 'empty' => __('Nothing is safelisted.')])
        </x-filament::section>

        <x-filament::section :heading="__('Always protected')" :description="__('Can’t be blocked')">
            <p class="porro-health-muted porro-health-small">{{ __('Infrastructure that has to reach this site. Blocks that overlap these are refused, and removed if a list changes.') }}</p>
            <ul class="porro-health-list porro-health-gap">
                @foreach ($protected as $row)
                    <li class="porro-health-item porro-health-row"><span>{{ $row['label'] }}</span><span class="porro-health-muted">{{ trans_choice(':count range|:count ranges', $row['count'], ['count' => $row['count']]) }}</span></li>
                @endforeach
            </ul>
        </x-filament::section>

        <p class="porro-health-small"><x-filament::link :href="$healthUrl">← {{ __('Site Health') }}</x-filament::link></p>
    </div>
</x-filament-panels::page>
