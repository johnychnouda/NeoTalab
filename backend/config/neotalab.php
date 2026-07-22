<?php

// Platform-level defaults for NeoTalab. Per-tenant overrides live on the merchants table.
return [
    // Free-trial length (days) applied when a new merchant is provisioned.
    'trial_days' => (int) env('NEOTALAB_TRIAL_DAYS', 7),
];
