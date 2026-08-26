<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Public branding
    |--------------------------------------------------------------------------
    |
    | These values back the public site title, uploaded logos / favicon, and
    | the "Powered by ..." footer rendered inside the visitor widget on free
    | plans. The admin branding page persists overrides in app_settings; these
    | config defaults keep local and first-boot installs renderable.
    |
    */

    'site_title' => env('APP_NAME', 'Pitchbar'),

    'header_logo_path' => env('BRANDING_HEADER_LOGO_PATH'),

    'footer_logo_path' => env('BRANDING_FOOTER_LOGO_PATH'),

    'dashboard_logo_path' => env('BRANDING_DASHBOARD_LOGO_PATH'),

    'favicon_path' => env('BRANDING_FAVICON_PATH'),

    'url' => env('PITCHBAR_BRAND_URL', 'https://pitchbar.thecodestudio.xyz'),

    'label' => env('PITCHBAR_BRAND_LABEL', 'Powered by Pitchbar'),
];
