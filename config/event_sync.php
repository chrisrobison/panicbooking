<?php

return [
    'timezone' => 'America/Los_Angeles',
    'source_priorities' => [
        'foopee' => 40,
        'gamh' => 88,
        'warfield' => 90,
        'regency' => 88,
        'fillmore' => 90,
        'ticketmaster' => 85,
        'default' => 60,
    ],
    'scoring' => [
        'prestige_multiplier' => 35.0,
        'capacity_log_multiplier' => 6.0,
        'upcoming_30_multiplier' => 2.5,
        'upcoming_60_multiplier' => 1.2,
        'consistency_multiplier' => 15.0,
        'core_venue_bonus' => 12.0,
        'official_sync_bonus' => 10.0,
        'tier_thresholds' => [
            'Tier 1' => 85.0,
            'Tier 2' => 65.0,
            'Tier 3' => 45.0,
        ],
    ],
    'dark_nights' => [
        'default_days' => 60,
        'max_days' => 120,
        'lookback_days' => 180,
    ],
];
