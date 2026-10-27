<x-filament::section :heading="__('Suspicious activity')" :description="__('Last :hours hours', ['hours' => $activity['hours']])">
    @unless ($activity['recording'])
        <p class="porro-health-muted porro-health-small" style="margin-bottom: 1rem">{{ __('Not recording on this copy of the site: activity is only recorded in production. Set PORRO_ACTIVITY=true to record here.') }}</p>
    @endunless

    <div class="porro-health-stats">
        @foreach ($activity['totals'] as $total)
            <div class="porro-health-stat">
                <div class="porro-health-stat-value">{{ number_format($total['value']) }}</div>
                <div class="porro-health-muted porro-health-small">{{ $total['label'] }}</div>
            </div>
        @endforeach
    </div>

    @if (empty($activity['flagged']))
        <p class="porro-health-gap">{{ __('Nothing suspicious in the last :hours hours.', ['hours' => $activity['hours']]) }}</p>
    @else
        <div class="porro-health-table-wrap porro-health-gap">
            <table class="porro-health-table">
                <thead>
                    <tr>
                        <th>{{ __('IP') }}</th>
                        <th>{{ __('Country') }}</th>
                        <th>{{ __('Signals') }}</th>
                        <th>{{ __('Last request') }}</th>
                        <th>{{ __('Last seen') }}</th>
                        @if ($canManage)<th></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($activity['flagged'] as $ip)
                        <tr wire:key="flagged-{{ $ip['ip'] }}">
                            <td class="porro-health-mono">
                                <x-filament::link :href="$ipUrl.'?ip='.urlencode($ip['ip'])">{{ $ip['ip'] }}</x-filament::link>
                                @foreach ($ip['badges'] as $badge)<x-filament::badge :color="['danger' => 'danger', 'warning' => 'warning'][$badge['tone']] ?? 'gray'" size="sm" style="display: inline-flex; margin-left: .25rem">{{ $badge['label'] }}</x-filament::badge>@endforeach
                            </td>
                            <td title="{{ $ip['country']['code'] ?? '' }}">{{ $ip['country']['label'] ?? '—' }}</td>
                            <td>
                                <div class="porro-health-chips">
                                    @foreach ($ip['signals'] as $signal)
                                        <x-filament::badge color="gray" size="sm">{{ $signal['label'] }} {{ $signal['count'] }}</x-filament::badge>
                                    @endforeach
                                </div>
                            </td>
                            <td class="porro-health-mono porro-health-path">{{ $ip['request'] }}</td>
                            <td class="porro-health-muted">{{ $ip['seen'] }}</td>
                            @if ($canManage)
                                <td>
                                    <div class="porro-health-actions">
                                        @if ($ip['blocked'])
                                            <x-filament::badge color="danger" size="sm">{{ __('Blocked') }}</x-filament::badge>
                                        @else
                                            <x-filament::button size="xs" color="gray" wire:confirm="{{ \Porro\Laravel\Activity\Confirm::block($ip['ip'], 7) }}" wire:click="blockIp({{ \Illuminate\Support\Js::from($ip['ip']) }}, 7, {{ \Illuminate\Support\Js::from($ip['reason']) }}, true)">{{ __('Block 7 days') }}</x-filament::button>
                                            <x-filament::button size="xs" color="gray" wire:confirm="{{ \Porro\Laravel\Activity\Confirm::block($ip['ip']) }}" wire:click="blockIp({{ \Illuminate\Support\Js::from($ip['ip']) }}, 0, {{ \Illuminate\Support\Js::from($ip['reason']) }})">{{ __('Block permanently') }}</x-filament::button>
                                            <x-filament::link size="sm" tag="button" wire:confirm="{{ \Porro\Laravel\Activity\Confirm::safelist($ip['ip']) }}" wire:click="safelistIp({{ \Illuminate\Support\Js::from($ip['ip']) }}, {{ \Illuminate\Support\Js::from(__('From the activity table')) }})">{{ __('Safelist') }}</x-filament::link>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="porro-health-small porro-health-gap">
        <p>
            <x-filament::link :href="$blockingUrl">{{ trans_choice(':count IP blocked|:count IPs blocked', $activity['blocked'], ['count' => $activity['blocked']]) }} · {{ trans_choice(':count safelisted|:count safelisted', $activity['safelisted'], ['count' => $activity['safelisted']]) }}</x-filament::link>
        </p>
        <p class="porro-health-muted">
            {{ __('IPs are kept on this site for 48 hours and never sent anywhere.') }}
            @if ($activity['countries'])
                <x-filament::link :href="$activity['countries']['url']" target="_blank">{{ $activity['countries']['attribution'] }}</x-filament::link>.
            @endif
        </p>
    </div>
</x-filament::section>
