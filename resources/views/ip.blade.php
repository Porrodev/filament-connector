<x-filament-panels::page>
    @include('porro-filament::styles')
    @php($tones = ['danger' => 'danger', 'warning' => 'warning', 'neutral' => 'gray'])

    <div class="porro-health">
        <div class="porro-health-stack">
            <p class="porro-health-small"><x-filament::link :href="$healthUrl">← {{ __('Site Health') }}</x-filament::link></p>
            <p class="porro-health-muted">
                {{ $details['country']['label'] ?? __('Country unknown') }}
                @if ($details['activity'] && $details['activity']['first']) · {{ __('first seen here :ago', ['ago' => $details['activity']['first']]) }} @endif
                @if ($details['blocked']) · <span style="color: rgb(220 38 38)">{{ __('blocked') }}</span> @endif
                @if ($details['safelisted']) · {{ __('safelisted') }} @endif
                @if ($details['protected'] ?? null) · {{ __('always protected: :list', ['list' => $details['protected']]) }} @endif
            </p>
            @if ($details['badges'])
                <div class="porro-health-chips">
                    @foreach ($details['badges'] as $badge)
                        <x-filament::badge :color="$tones[$badge['tone']] ?? 'gray'" size="sm">{{ $badge['label'] }}</x-filament::badge>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($details['crawler'])
            @php($tint = $details['crawler']['real'] ? '22 163 74' : '220 38 38')
            <div class="porro-health-small" style="border: 1px solid rgb({{ $tint }} / .45); background: rgb({{ $tint }} / .08); border-radius: .5rem; padding: .75rem 1rem">
                <strong style="color: rgb({{ $tint }})">{{ $details['crawler']['title'] }}.</strong> {{ $details['crawler']['body'] }}
            </div>
        @endif

        @if ($canManage && ! $details['blocked'] && ! $details['safelisted'] && ! ($details['protected'] ?? null))
            <div class="porro-health-actions" style="justify-content: flex-start; flex-wrap: wrap">
                <x-filament::button wire:click="mountAction('confirmBlock', {{ \Illuminate\Support\Js::from(['ip' => $details['ip'], 'days' => 0, 'reason' => $details['reason'], 'suggested' => false]) }})">{{ __('Block permanently') }}</x-filament::button>
                <x-filament::button color="gray" wire:click="mountAction('confirmBlock', {{ \Illuminate\Support\Js::from(['ip' => $details['ip'], 'days' => 7, 'reason' => $details['reason'], 'suggested' => true]) }})">{{ __('Block 7 days') }}</x-filament::button>
                @if ($details['range'])
                    <x-filament::button color="gray" wire:click="mountAction('confirmBlock', {{ \Illuminate\Support\Js::from(['ip' => $details['range'], 'days' => 7, 'reason' => $details['reason'], 'suggested' => true]) }})">{{ __('Block :range', ['range' => $details['range']]) }}</x-filament::button>
                @endif
                <x-filament::button color="gray" wire:click="mountAction('confirmSafelist', {{ \Illuminate\Support\Js::from(['ip' => $details['ip'], 'label' => __('From the IP details page')]) }})">{{ __('Safelist') }}</x-filament::button>
            </div>
        @endif

        <x-filament::section :heading="__('Network')">
            @forelse ($details['network'] as $label => $value)
                <div class="porro-health-item porro-health-entry">
                    <span class="porro-health-muted" style="width: 8rem; flex: none">{{ __($label) }}</span>
                    <span class="porro-health-body {{ in_array($label, ['Hostname', 'User agent'], true) ? 'porro-health-mono porro-health-path' : '' }}">{{ $value }}</span>
                </div>
            @empty
                <p>{{ __('Nothing more is known about this network. Connect Cloudflare for the network and its type.') }}</p>
            @endforelse
        </x-filament::section>

        <x-filament::section :heading="__('What it did here')" :description="__('Signals per hour · last 48 hours')">
            @if ($details['activity'])
                <div style="display: flex; align-items: flex-end; gap: 2px; height: 44px" aria-hidden="true">
                    @foreach ($details['activity']['hours'] as $n)
                        <span style="flex: 1; border-radius: 1px; height: {{ $n ? max(6, (int) round($n / $details['activity']['peak'] * 44)) : 2 }}px; background: {{ $n > 10 ? 'rgb(220 38 38)' : ($n > 0 ? 'rgb(234 128 51)' : 'rgb(127 127 127 / .2)') }}"></span>
                    @endforeach
                </div>
                <div class="porro-health-row porro-health-muted porro-health-small" style="display: flex; margin: .25rem 0 .75rem"><span>{{ __('48 h ago') }}</span><span>{{ __('now') }}</span></div>
                <table class="porro-health-table">
                    <tbody>
                        @foreach ($details['activity']['requests'] as $r)
                            <tr>
                                <td class="porro-health-mono porro-health-muted" style="width: 3.5rem">{{ $r['method'] }}</td>
                                <td class="porro-health-mono porro-health-path">{{ $r['path'] }}</td>
                                <td class="porro-health-mono" style="{{ $r['status'] >= 200 && $r['status'] < 300 ? 'color: rgb(220 38 38)' : '' }}">{{ $r['status'] ?: '—' }}</td>
                                <td class="porro-health-muted">{{ $r['time'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p>{{ __('Nothing flagged from this IP in the last 48 hours.') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('Reputation')" description="AbuseIPDB">
            @if ($details['reputation'])
                <p><strong style="{{ $details['reputation']['score'] >= 50 ? 'color: rgb(220 38 38)' : '' }}">{{ $details['reputation']['score'] }}%</strong> {{ __('abuse confidence') }} · {{ trans_choice(':count report|:count reports', $details['reputation']['reports'], ['count' => number_format($details['reputation']['reports'])]) }}@if ($details['reputation']['isp']) · {{ $details['reputation']['isp'] }}@endif</p>
            @elseif ($details['canCheckReputation'] && $canManage)
                <div class="porro-health-actions" style="justify-content: flex-start">
                    <x-filament::button size="sm" color="gray" wire:click="checkReputation">{{ __('Check on AbuseIPDB') }}</x-filament::button>
                    <span class="porro-health-muted porro-health-small">{{ __('Sends this IP to AbuseIPDB.') }}</span>
                </div>
            @else
                <p class="porro-health-muted">{{ __('Not checked. Set PORRO_ABUSEIPDB_KEY to check an IP’s reputation on request (it sends the IP to AbuseIPDB).') }}</p>
            @endif
        </x-filament::section>

        <p class="porro-health-muted porro-health-small">
            {{ __('Sources') }}: {{ implode(' · ', $details['sources']) }} · {{ $details['fetched'] }}
            @if ($details['attribution']) · <x-filament::link :href="$details['attribution']['url']" target="_blank">{{ $details['attribution']['text'] }}</x-filament::link>@endif
        </p>
    </div>
</x-filament-panels::page>
