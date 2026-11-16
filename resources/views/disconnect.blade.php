{{-- Inside the "Disconnect from Porro?" confirmation: what stops, what carries on, and what Porro keeps. --}}
@include('porro-filament::styles')

<div class="porro-health-disconnect">
    <dl>
        <div>
            <dt>{{ __('Stops') }}</dt>
            <dd class="porro-health-muted">{{ __('Hourly reports of what is installed here, and signing in to this site from Porro.') }}</dd>
        </div>
        <div>
            <dt>{{ __('Carries on') }}</dt>
            <dd class="porro-health-muted">{{ __('The checks on this page. They run on this site and need no account.') }}</dd>
        </div>
        <div>
            <dt>{{ __('In Porro') }}</dt>
            <dd class="porro-health-muted">{{ __(':workspace keeps the site and its history. Uptime, SSL and domain checks go on from outside until someone removes the site there.', ['workspace' => $workspace]) }}</dd>
        </div>
    </dl>
    <p class="porro-health-small porro-health-muted">{{ __('You can connect again at any time.') }}</p>
</div>
