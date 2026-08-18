<?php

/*
|--------------------------------------------------------------------------
| Migrate Cloudflare Rules
|--------------------------------------------------------------------------
|
| Credentials and identifiers used by the `cloudflare:migrate` Artisan
| command. Rules are always read from the "source" and written to the
| "destination". Source and destination can live in two different
| Cloudflare accounts or be two zones of the same account.
|
| Never hard-code secrets here: keep them in your .env file (see
| .env.example in the package root) and let this file read them via env().
|
| Where to find each value:
|  - API token:  Cloudflare dashboard -> My Profile -> API Tokens -> Create Token
|               (see the README for the exact permissions required).
|  - Account ID: Cloudflare dashboard -> select the account -> the Account ID is
|               shown in the right sidebar of the account home / any zone
|               Overview page, and in the URL: dash.cloudflare.com/<account_id>
|  - Zone ID:    Cloudflare dashboard -> select the zone -> Overview -> "API"
|               box in the right sidebar.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Source (where the rules are read from)
    |--------------------------------------------------------------------------
    */
    'source' => [
        // API token with READ access to the source account/zone.
        'api_token' => env('CLOUDFLARE_MIGRATE_SOURCE_API_TOKEN'),

        // Account ID of the source account (required for account-level
        // resources: "ipaccessrulesaccount" and "customlists").
        'account_id' => env('CLOUDFLARE_MIGRATE_SOURCE_ACCOUNT_ID'),

        // Zone ID of the source zone (required for zone-level resources:
        // "waf", "ipaccessruleszone", "useragent", "ratelimit", "pagerules").
        'zone_id' => env('CLOUDFLARE_MIGRATE_SOURCE_ZONE_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Destination (where the rules are created)
    |--------------------------------------------------------------------------
    */
    'destination' => [
        // API token with EDIT access to the destination account/zone.
        'api_token' => env('CLOUDFLARE_MIGRATE_DESTINATION_API_TOKEN'),

        // Account ID of the destination account.
        'account_id' => env('CLOUDFLARE_MIGRATE_DESTINATION_ACCOUNT_ID'),

        // Zone ID of the destination zone.
        'zone_id' => env('CLOUDFLARE_MIGRATE_DESTINATION_ZONE_ID'),
    ],

];
