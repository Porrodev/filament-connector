{{-- Connect to Porro: the live site not connected, a development copy, or connected from here. --}}
<x-filament::section :heading="$connect['state'] === 'connected' ? __('Connected to Porro') : __('Not connected to Porro')">
    <div class="porro-health-connect">
        <div class="porro-health-connect-text">
            @if ($connect['state'] === 'connected')
                <p class="porro-health-connect-title">{{ $connect['workspace'] ? __('Connected to :workspace', ['workspace' => $connect['workspace']]) : __('Connected to Porro') }}</p>
                <p>{{ $connect['approvedAgo'] ? __('Approved :ago.', ['ago' => $connect['approvedAgo']]) : '' }} {{ __('The first report has been sent, and Porro now checks this site every hour.') }}</p>
                <p class="porro-health-small porro-health-muted">{{ __('The key was made on this site and is kept encrypted in its database.') }}</p>
            @elseif ($connect['state'] === 'development')
                <p class="porro-health-connect-title">{{ __('This is a development copy') }}</p>
                <p>{{ __('Its environment is “:environment”, so it does not connect and sends no reports. Deploy, then connect from the live site’s control panel. The checks below still run here.', ['environment' => $connect['environment']]) }}</p>
                <p class="porro-health-small porro-health-muted">{{ __('Is this the live site? Its APP_ENV should say production. You can also set a site key.') }}</p>
            @else
                <p class="porro-health-connect-title">{{ __('Have this site checked every hour') }}</p>
                <p>{{ __('The checks below ran on this site just now. Connect it to Porro for hourly advisory checks, uptime from outside, and an alert the moment something changes.') }}</p>
                <p class="porro-health-small porro-health-muted">{{ __('Opens :host to sign in and approve. No key to copy.', ['host' => $connect['porroHost']]) }}</p>
            @endif
        </div>
        @if ($connect['state'] === 'connected')
            <x-filament::button tag="a" :href="$connect['openUrl']" target="_blank" color="gray">
                {{ __('Open in Porro') }}
            </x-filament::button>
        @elseif ($connect['state'] === 'ready')
            {{-- The branded button: black, or near-white on a dark panel so it still stands out. The mark keeps its own colours on its black tile. --}}
            <button type="button" class="porro-health-connect-button" wire:click="connectToPorro" wire:loading.attr="disabled" wire:target="connectToPorro" style="flex-shrink: 0; display: inline-flex; align-items: center; gap: 10px; padding: 9px 18px 9px 12px; border: 0; border-radius: 8px; font-size: 15px; font-weight: 500; line-height: normal; white-space: nowrap; cursor: pointer">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 5.647px; background: #000" aria-hidden="true">
                    <svg width="14.1176" height="14.1176" viewBox="0 0 14.1176 14.1176" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: block">
                        <path d="M7.05882 1.05882L13.0588 7.05882L7.05882 13.0588L1.05882 7.05882L7.05882 1.05882Z" stroke-width="1.12941" stroke="#F2F2F0"/>
                        <circle cx="7.05882" cy="7.05882" r="1.76471" fill="#C9F26A"/>
                    </svg>
                </span>
                {{ __('Connect to Porro') }}
            </button>
        @endif
    </div>
</x-filament::section>
