@once
    {{-- Filament only ships the utility classes it uses itself, so this view brings its own few rules. --}}
    <style>
        .porro-health { display: flex; flex-direction: column; gap: 1rem; }
        .porro-health-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
        .porro-health-muted { color: rgb(107 114 128); }
        .dark .porro-health-muted { color: rgb(156 163 175); }
        .porro-health-small { font-size: .8125rem; }
        .porro-health-gap { margin-top: 1rem; }
        .porro-health-stack > * + * { margin-top: .75rem; }
        .porro-health-bullets { list-style: disc; padding-left: 1.25rem; }
        .porro-health-bullets li + li { margin-top: .25rem; }
        .porro-health-code { font-size: .8125rem; padding: .5rem .75rem; border-radius: .5rem; background: rgb(127 127 127 / .12); overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
        .porro-health-list > li + li { border-top: 1px solid rgb(127 127 127 / .2); }
        .porro-health-item { display: flex; flex-wrap: wrap; gap: .25rem .75rem; padding: .75rem 0; }
        .porro-health-item:first-child { padding-top: 0; }
        .porro-health-item:last-child { padding-bottom: 0; }
        .porro-health-badge { flex: none; padding-top: .125rem; }
        .porro-health-body { flex: 1 1 16rem; min-width: 0; font-size: .875rem; overflow-wrap: anywhere; }
        .porro-health-title { font-weight: 600; }
        .porro-health-title .porro-health-muted { font-weight: 400; }
        .porro-health-aligned .porro-health-badge { min-width: 8.5rem; }
    </style>
@endonce
