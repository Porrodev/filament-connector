<ul class="porro-health-list {{ ($compact ?? false) ? 'porro-health-compact' : 'porro-health-aligned' }}">
    @foreach ($issues as $issue)
        <li class="porro-health-item">
            <div class="porro-health-badge">
                <x-filament::badge :color="$issue['style']['color']" size="sm">{{ __($issue['style']['label']) }}</x-filament::badge>
            </div>
            <div class="porro-health-body">
                <p class="porro-health-title">
                    @if ($issue['url'])
                        <x-filament::link :href="$issue['url']" target="_blank">{{ $issue['title'] }}</x-filament::link>
                    @else
                        {{ $issue['title'] }}
                    @endif
                    @if ($issue['scope'] === 'server')
                        <span class="porro-health-muted">· {{ __('server') }}</span>
                    @endif
                    @if ($issue['acknowledged'])
                        <span class="porro-health-muted">· {{ __('acknowledged') }}</span>
                    @endif
                </p>
                @if ($issue['detail'])
                    <p class="porro-health-muted">{{ $issue['detail'] }}</p>
                @endif
                @if ($issue['fix'])
                    <p><code class="porro-health-small">{{ $issue['fix'] }}</code></p>
                @endif
            </div>
        </li>
    @endforeach
</ul>
