<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Onboarding force-show
    |--------------------------------------------------------------------------
    |
    | When true, the first-run tooltip on the dashboard appears on every
    | mount regardless of run count or any client-side dismissal flag.
    | Used in prod for QA / demos. Default false — normal "first run only"
    | behaviour driven by localStorage on the FE.
    */
    'force_show' => (bool) env('ONBOARDING_FORCE_SHOW', false),
];
