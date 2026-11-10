<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The API sits behind its nginx and the web app's Express proxy (and, when
    | it has a public domain, Nginx Proxy Manager), all on private Docker
    | networks. Only those may set X-Forwarded-For/Proto: the client IP is what
    | the rate limiters key on. With `*`, any client reaching the API could pick
    | its own IP and bypass them. Comma-separated IPs/CIDRs, or PRIVATE_SUBNETS.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', 'PRIVATE_SUBNETS'),

];
