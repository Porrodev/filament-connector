<x-filament-panels::page>
    @include('porro-filament::styles')

    @php
        $state = fn (bool $ok) => $ok ? '✓ '.__('Done') : __('To do');
    @endphp

    <div class="porro-health">
        <p class="porro-health-small"><x-filament::link :href="$blockingUrl">← {{ __('Blocking') }}</x-filament::link></p>
        <p class="porro-health-muted">{{ __('For sites behind Cloudflare: blocked IPs are stopped at Cloudflare’s edge, before they reach your server. Three steps, once for the site.') }}</p>

        @if ($cloudflare['configured'] && ! $cloudflare['production'])
            <x-filament::section>
                <p>{{ __('This copy isn’t production, so it won’t sync the blocklist. You can still connect here to check the token.') }}</p>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('1 · Create a Cloudflare API token')" :description="$state($steps['token'])">
            <div class="porro-health-stack">
                <p>{{ __('In Cloudflare, open') }} <x-filament::link :href="$tokensUrl" target="_blank" rel="noopener">{{ __('My Profile → API Tokens') }}</x-filament::link>{{ __(', then Create Token → Create Custom Token. It needs two permission policies: one for the site’s domain, one for your account.') }}</p>
                @foreach ($policies as $i => $policy)
                    <p class="porro-health-small"><strong>{{ __('Policy :n', ['n' => $i + 1]) }}</strong> · {{ __('scope it to') }} {{ $i === 0 ? __('Specified Domains → :host', ['host' => $host ?: __('this site’s domain')]) : __($policy['scope']) }}@if ($i > 0) {{ __('(Add policy, then pick Entire Account)') }}@endif</p>
                    <table class="porro-health-table">
                        <thead><tr><th>{{ __('Search for') }}</th><th>{{ __('Tick') }}</th><th>{{ __('Access') }}</th></tr></thead>
                        <tbody>
                            @foreach ($policy['permissions'] as $p)
                                <tr><td class="porro-health-mono">{{ $p['search'] }}</td><td>{{ $p['tick'] }}@if ($p['group'])<span class="porro-health-muted porro-health-small"> · {{ __('under :group', ['group' => $p['group']]) }}</span>@endif</td><td>{{ $p['access'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endforeach
                <p class="porro-health-muted porro-health-small">{{ __('Create the token and copy it: Cloudflare only shows it once.') }}</p>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('2 · Add it to the site’s environment')" :description="$state($steps['token'])">
            <div class="porro-health-stack">
                <p>{{ __('Paste the token after the = in .env, or on Forge, the site’s Environment tab. It stays in the environment, never the database.') }}</p>
                <div class="porro-health-copyable"><pre class="porro-health-code">{{ $envLine }}your-token-here</pre><x-filament::button size="sm" color="gray" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText($el.dataset.copy); copied = true; setTimeout(() => copied = false, 1500)" data-copy="{{ $envLine }}"><span x-text="copied ? '✓' : {{ \Illuminate\Support\Js::from(__('Copy')) }}">{{ __('Copy') }}</span></x-filament::button></div>
                <p class="porro-health-muted porro-health-small">{{ $steps['token'] ? __('Token found.') : __('This page ticks this off once the site can see the token. If you cache config, run php artisan config:cache after saving it.') }}</p>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('3 · Connect')" :description="$state($steps['connected'])">
            <div class="porro-health-stack">
                <div class="porro-health-callout">
                    <strong>{{ __('One list for the whole Cloudflare account.') }}</strong>
                    {{ __('Cloudflare keeps IP lists per account, not per domain, so porro_blocklist is shared by every site you connect on this account. An IP blocked on one of them is blocked on all of them. Each site only adds and removes its own entries, and you’ll find the list under Manage account → Configurations → Lists.') }}
                    {{ __('Sites that mustn’t share blocks, such as different clients, need their own Cloudflare accounts.') }}
                </div>
                @if ($cloudflare['connected'])
                    <p>{{ __('Connected to :zone. Blocks go to the porro_blocklist list, with one rule blocking it next to your existing rules.', ['zone' => $cloudflare['zone']]) }}</p>
                    <p class="porro-health-small {{ $cloudflare['error'] ? '' : 'porro-health-muted' }}" @if ($cloudflare['error']) style="color: rgb(220 38 38)" @endif>
                        @if ($cloudflare['error']) {{ $cloudflare['error'] }}
                        @elseif ($cloudflare['synced']) {{ __('Last synced :ago. It syncs after every change and on the scheduled task.', ['ago' => $cloudflare['synced']]) }}
                        @else {{ __('Waiting for the first sync.') }}
                        @endif
                    </p>
                @else
                    <p>{{ __('Finds the zone for :host, creates the porro_blocklist list if the account doesn’t have it yet, and adds one rule blocking it, next to your existing rules, never replacing them.', ['host' => $host ?: __('this site')]) }}</p>
                    @if ($canManage)
                        <div><x-filament::button wire:click="connect" :disabled="! $steps['token']">{{ __('Connect to Cloudflare') }}</x-filament::button></div>
                    @endif
                    <p class="porro-health-muted porro-health-small">{{ __('Or on the server:') }} <code>{{ $command }}</code></p>
                    @if ($cloudflare['error'])
                        <p class="porro-health-small" style="color: rgb(220 38 38)">{{ $cloudflare['error'] }}</p>
                    @endif
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
