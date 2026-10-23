<x-filament-widgets::widget>
    @include('porro-filament::styles')

    <x-filament::section :heading="__('Site health')">
        <div class="porro-health">
            <p class="porro-health-muted" role="status">@include('porro-filament::summary')</p>

            @if (! empty($top))
                @include('porro-filament::issues', ['issues' => $top, 'compact' => true])
            @endif

            <p class="porro-health-small">
                <x-filament::link :href="$pageUrl">{{ $more ? trans_choice('See :count more|See :count more', $more, ['count' => $more]) : __('Open Site health') }}</x-filament::link>
                @unless ($connected)
                    <span class="porro-health-muted"> · {{ __('Connect Porro for uptime, SSL and domain checks') }}</span>
                @endunless
            </p>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
