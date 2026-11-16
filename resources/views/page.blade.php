<x-filament-panels::page>
    @include('porro-filament::styles')

    <div class="porro-health">
        <div class="porro-health-bar">
            <p class="porro-health-muted" role="status">@include('porro-filament::summary')</p>
            <x-filament::button color="gray" size="sm" wire:click="checkAgain" wire:loading.attr="disabled" wire:target="checkAgain">
                {{ __('Check again') }}
            </x-filament::button>
        </div>

        @if ($disconnected)
            {{-- Once, straight after disconnecting: what happened, and what Porro knows. --}}
            @php($from = $disconnected['workspace'] ?? __('Porro'))
            <div class="porro-health-notice" role="status">
                @if ($disconnected['told'])
                    <p class="porro-health-notice-title">{{ __('Disconnected from :workspace', ['workspace' => $from]) }}</p>
                    <p class="porro-health-muted">{{ __('This site no longer sends reports, and its key has been deleted. :workspace still has the site in Porro; someone in that workspace can remove it there.', ['workspace' => $from]) }}</p>
                @else
                    <p class="porro-health-notice-title">{{ __('Disconnected on this site') }}</p>
                    <p class="porro-health-muted">{{ __('The key has been deleted and reports have stopped. Porro didn’t answer, so it hasn’t been told: :workspace will see this site as not reporting, not as disconnected.', ['workspace' => $from]) }}</p>
                @endif
            </div>
        @endif

        @includeWhen($connect['panel'], 'porro-filament::connect')

        @if ($failed)
            <x-filament::section>
                <p>{{ __('The checks couldn’t run just now. Try again in a minute; the error is in the Laravel log.') }}</p>
            </x-filament::section>
        @endif

        @if ($connected)
            <x-filament::section :heading="__('From Porro')" :description="__('Uptime, SSL, the domain and the server, checked from outside every few minutes.')">
                @if (! $porroOk)
                    <p>{{ $porro['error'] ?? __('Couldn’t reach Porro just now.') }} {{ __('Showing the checks run on this site instead.') }}</p>
                @elseif (empty($found))
                    <p>{{ __('Porro has nothing open for this site.') }}</p>
                @else
                    @include('porro-filament::issues', ['issues' => $found])
                @endif

                @if ($porroUrl)
                    <p class="porro-health-gap">
                        <x-filament::link :href="$porroUrl" target="_blank" icon="heroicon-m-arrow-top-right-on-square" icon-position="after">
                            {{ __('Open this site in Porro') }}
                        </x-filament::link>
                    </p>
                @endif

                @if ($connect['envKey'])
                    <p class="porro-health-small porro-health-muted porro-health-gap">{{ __('To disconnect, remove PORRO_KEY from the environment and deploy. The control panel can’t change it.') }}</p>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('Checked on this site')">
            @if (empty($local))
                <p>{{ __('Nothing needs attention.') }}</p>
            @else
                @include('porro-filament::issues', ['issues' => $local])
            @endif

            <div class="porro-health-small porro-health-muted porro-health-gap">
                @if (! empty($checked))
                    <p>{{ __('Checked') }}: {{ implode(' · ', $checked) }}.</p>
                @endif
                @foreach ($unavailable as $note)
                    <p>{{ $note }}.</p>
                @endforeach
                <p>{{ __('Only package names leave the site, to Packagist’s advisories API. Support dates come from endoflife.date.') }}</p>
            </div>
        </x-filament::section>

        @if ($activity)
            @include('porro-filament::activity')
        @endif

        @if ($connect['upsell'])
            <x-filament::section :heading="__('Watch this site from outside too')">
                <div class="porro-health-stack">
                    <p>{{ __('These checks run inside the site. Porro watches it from outside as well, alongside every other site you look after:') }}</p>
                    <ul class="porro-health-bullets">
                        <li>{{ __('Uptime from several regions, and alerts by email or Slack when it goes down') }}</li>
                        <li>{{ __('SSL certificate and domain expiry, well before they lapse') }}</li>
                        <li>{{ __('The server’s operating system and security updates') }}</li>
                        <li>{{ __('Advisories, updates and support dates across all your sites in one place') }}</li>
                    </ul>
                    <p>{{ __('Add the site in Porro, then connect it with its site key:') }}</p>
                    <pre class="porro-health-code"><code>{{ $commands['connect'] }}</code></pre>
                    <p>
                        <x-filament::link href="https://porro.dev/?utm_source=filament-connector&utm_medium=control-panel&utm_campaign=connectors" target="_blank">{{ __('Find out more at porro.dev') }}</x-filament::link>
                    </p>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
