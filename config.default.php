<?php

// Default configuration – do not edit. To change a value, create config.php (git-ignored) that returns
// only the keys to override, e.g.:
//
//   <?php
//   return ['ipinfo_token' => '…', 'max_resources' => 100];
//
// Order: config.default.php < config.php < environment variables (SWA_ENABLED, SWA_DEBUG,
// SWA_ALLOWED_ORIGINS comma-separated, SWA_CACHE_DIR, SWA_IPINFO_TOKEN). Unknown keys are rejected.

return [
    // General
    'enabled' => true,             // false = maintenance mode (HTTP 503), also settable as SWA_ENABLED=false
    'debug' => false,              // true = append internal error details to error messages
    'timezone' => 'Europe/Berlin',

    // Access control – see "Abuse protection" in the README.
    'allowed_origins' => ['https://kreativ-anders.de', 'https://www.kreativ-anders.de'], // CORS only, grants nothing
    'client_ip_header' => null,    // WARNING: only behind a proxy that always overwrites it, e.g. 'HTTP_X_REAL_IP'

    // Rate limits. The same for everyone; cached results are free and count against nothing.
    'rate_limit_exempt_ips' => [], // no limits for these, e.g. ['203.0.113.7', '2001:db8::/32']
    'rate_limit_window' => 86400,        // seconds
    'rate_limit_per_ip' => 10,           // uncached analyses per IP per window (0 = unlimited)
    'rate_limit_requests_per_ip' => 200, // every request per IP, cached ones included (0 = unlimited)
    'rate_limit_per_target' => 50,       // uncached analyses of one target host, all callers together
    'rate_limit_global' => 1000,         // uncached analyses in total per window (0 = unlimited)
    'max_concurrent_analyses' => 6,      // keep below the PHP-FPM pool size

    // Caching
    'cache_dir' => null,               // null = <project>/var/cache
    'cache_ttl' => 604800,             // analysis results: 1 week
    'penalized_cache_ttl' => 259200,   // results that reached a gate (rated F): 3 days, time enough to fix the page and re-check
    'geo_cache_ttl' => 1209600,        // IP -> country lookups: 2 weeks

    // Gates: an analysis that reaches one stops measuring, lists it in meta.limits and is rated F.
    'max_execution_time' => 20,           // seconds for a complete analysis
    'max_resources' => 150,               // sub-resources measured per page
    'max_redirects' => 5,                 // per request (a page that redirects more often is an error, HTTP 502)
    'max_resource_bytes' => 10_000_000,   // per transfer (page or sub-resource), also caps a HEAD Content-Length
    'max_download_bytes' => 25_000_000,   // all full sub-resource downloads together (fallback for missing Content-Length; 0 = unlimited)

    // Crawling
    'connect_timeout' => 3,               // seconds per connection
    'request_timeout' => 8,               // seconds per request
    'concurrency' => 10,                  // parallel requests
    'max_html_bytes' => 2_000_000,        // HTML parsed for resources (the DOM needs up to ~60x this, outside memory_limit); the page weight still counts every byte
    'user_agent' => 'SustainableWebAnalyzer/1.0 (+https://kreativ-anders.de/web-analyse)',
    'verify_tls' => true,
    'head_requests' => true,              // read sizes from HEAD Content-Length, download only as fallback

    // External services
    'green_check_url' => 'https://api.thegreenwebfoundation.org/api/v3/greencheck/',
    'ipinfo_url' => 'https://ipinfo.io/',
    'ipinfo_token' => '',                 // works without a token, but with a low monthly limit
    'max_geo_lookups' => 25,              // uncached IP -> country lookups per analysis (0 = unlimited)
    'geo_lookups_per_day' => 1000,        // …and per day, to protect the ipinfo quota. Beyond it, country stays null
];
