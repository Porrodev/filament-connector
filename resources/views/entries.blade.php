@if (empty($entries))
    <p>{{ $empty }}</p>
@else
    <ul class="porro-health-list">
        @foreach ($entries as $entry)
            <li class="porro-health-item porro-health-entry" wire:key="{{ $remove }}-{{ $entry['cidr'] }}">
                <span class="porro-health-mono" style="min-width: 12rem">{{ $entry['cidr'] }}</span>
                <span class="porro-health-body">{{ $entry['note'] }}</span>
                <span class="porro-health-muted porro-health-small">{{ $entry['source'] }}@if ($entry['by']) · {{ $entry['by'] }}@endif</span>
                <span class="porro-health-muted porro-health-small" style="min-width: 6rem">{{ $entry['expires'] }}</span>
                @if ($canManage && ! $entry['fromConfig'])
                    <x-filament::link tag="button" color="danger" size="sm" wire:click="{{ $remove }}({{ \Illuminate\Support\Js::from($entry['cidr']) }})">{{ __('Remove') }}</x-filament::link>
                @endif
            </li>
        @endforeach
    </ul>
@endif
