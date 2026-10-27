<x-filament-panels::page>
    @include('porro-filament::styles')

    <div class="porro-health">
        <p class="porro-health-small"><x-filament::link :href="$blockingUrl">← {{ __('Blocking') }}</x-filament::link></p>
        <p class="porro-health-muted">{{ trans_choice(':count entry|:count entries', $total, ['count' => number_format($total)]) }}@if ($list === 'safelist') · {{ __('Never blocked or flagged') }}@endif</p>

        @if ($failed)
            <x-filament::section>
                <p>{{ __('The blocklist tables aren’t there yet. Run php artisan migrate on this site.') }}</p>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="porro-health-stack">
                <form method="GET" action="{{ \Porro\Filament\Pages\ListPage::getUrl() }}" class="porro-health-form">
                    <input type="hidden" name="list" value="{{ $list }}">
                    @if ($filter !== 'all')<input type="hidden" name="filter" value="{{ $filter }}">@endif
                    <x-filament::input.wrapper style="flex: 1 1 18rem">
                        <x-filament::input type="text" name="q" :value="$search" :placeholder="__('Search: an IP, a range, a reason or who added it')" :aria-label="__('Search')" />
                    </x-filament::input.wrapper>
                    <x-filament::button type="submit" color="gray">{{ __('Search') }}</x-filament::button>
                    @if ($search !== '')<x-filament::link :href="$this->linkTo(['q' => null])">{{ __('Clear') }}</x-filament::link>@endif
                </form>
                <div class="porro-health-chips">
                    @foreach ($filters as $f)
                        @if ($f['count'] > 0 || $f['key'] === 'all' || $f['key'] === $filter)
                            <a href="{{ $this->linkTo(['filter' => $f['key'] === 'all' ? null : $f['key']]) }}" @if ($f['key'] === $filter) aria-current="true" @endif>
                                <x-filament::badge :color="$f['key'] === $filter ? 'primary' : 'gray'" size="sm">{{ $f['label'] }} {{ number_format($f['count']) }}</x-filament::badge>
                            </a>
                        @endif
                    @endforeach
                </div>
                @include('porro-filament::entries', ['empty' => $search !== '' || $filter !== 'all' ? __('Nothing matches.') : ($list === 'safelist' ? __('Nothing is safelisted.') : __('Nothing is blocked.'))])
                @if ($pages > 1)
                    <div class="porro-health-row">
                        @if ($currentPage > 1)<x-filament::button size="sm" color="gray" tag="a" :href="$this->linkTo(['page' => $currentPage - 1])">← {{ __('Newer') }}</x-filament::button>@else<span></span>@endif
                        <span class="porro-health-muted porro-health-small">{{ __('Page :page of :pages · :count shown', ['page' => $currentPage, 'pages' => $pages, 'count' => number_format($shown)]) }}</span>
                        @if ($currentPage < $pages)<x-filament::button size="sm" color="gray" tag="a" :href="$this->linkTo(['page' => $currentPage + 1])">{{ __('Older') }} →</x-filament::button>@else<span></span>@endif
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
