<?php

// Merged into the Laravel connector's "porro" config, so it sits alongside
// the key and URL and a published config/porro.php can set it too.
return [

    // Porro members can sign in to this site's Filament panel from the site
    // page in Porro, once "Sign in to client sites" is on for the workspace.
    // Set PORRO_SIGN_IN=false to turn it off here, whatever Porro says.
    // Left unset, it's on for the live site (APP_ENV=production) and off
    // on staging and local copies; PORRO_SIGN_IN=true turns it on there.
    'sign_in' => env('PORRO_SIGN_IN'),

    // The id of the panel members are signed in to. Unset: the default
    // panel. Its auth guard finds the user and holds the session.
    'sign_in_panel' => env('PORRO_SIGN_IN_PANEL'),

];
