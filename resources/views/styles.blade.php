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
        .porro-health-compact .porro-health-badge { flex-basis: 100%; }
        .porro-health-stats { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); }
        .porro-health-stat { border-radius: .5rem; background: rgb(127 127 127 / .08); padding: .75rem 1rem; }
        .porro-health-stat-value { font-size: 1.5rem; font-weight: 600; line-height: 1.2; }
        .porro-health-table-wrap { overflow-x: auto; }
        .porro-health-table { width: 100%; font-size: .875rem; border-collapse: collapse; }
        .porro-health-table th { text-align: left; font-size: .75rem; font-weight: 500; text-transform: uppercase; letter-spacing: .02em; color: rgb(107 114 128); padding: 0 1rem .5rem 0; }
        .porro-health-table td { padding: .6rem 1rem .6rem 0; border-top: 1px solid rgb(127 127 127 / .2); vertical-align: middle; }
        .porro-health-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125rem; white-space: nowrap; }
        .porro-health-path { white-space: normal; overflow-wrap: anywhere; }
        .porro-health-chips { display: flex; flex-wrap: wrap; gap: .25rem; }
        .porro-health-actions { display: flex; align-items: center; justify-content: flex-end; gap: .5rem; white-space: nowrap; }
        .porro-health-form { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-bottom: 1rem; }
        .porro-health-entry { align-items: center; font-size: .875rem; }
        .porro-health-row { justify-content: space-between; font-size: .875rem; }
        .porro-health-copyable { display: flex; gap: .5rem; align-items: flex-start; }
        .porro-health-copyable pre { flex: 1; min-width: 0; margin: 0; }
        .porro-health-example span { display: block; min-height: 1.5em; }
        .porro-health-example .porro-health-add { background: rgb(191 232 74 / .3); margin: 0 -.75rem; padding: 0 .75rem; font-weight: 600; }
        .porro-health-example em { font-style: normal; font-weight: 400; opacity: .6; }
        .porro-health-protected { width: 100%; }
        .porro-health-protected summary { cursor: pointer; list-style: none; display: flex; justify-content: space-between; gap: 1rem; }
        .porro-health-protected summary::-webkit-details-marker { display: none; }
        .porro-health-protected summary > span:first-child::before { content: '› '; opacity: .6; }
        .porro-health-protected[open] summary > span:first-child::before { content: '⌄ '; }
        .porro-health-ranges { display: grid; grid-template-columns: repeat(auto-fill, minmax(24ch, 1fr)); gap: 2px 16px; max-height: 240px; overflow-y: auto; font-family: ui-monospace, monospace; font-size: .75rem; padding: .5rem .75rem; border-radius: .5rem; background: rgb(127 127 127 / .12); }
    </style>
@endonce
