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
    'enabled' => true,             // false = maintenance mode (HTTP 503)
    'debug' => false,              // true = append internal error details to error messages
    'timezone' => 'Europe/Berlin',

    // Access control
    'allowed_origins' => ['https://kreativ-anders.de', 'https://www.kreativ-anders.de'],
    'require_origin' => true,      // reject requests that are neither from an allowed origin nor same-origin
    'client_ip_header' => null,    // e.g. 'HTTP_X_REAL_IP' – only behind a trusted reverse proxy/CDN that sets it, otherwise it can be spoofed
    'rate_limit_window' => 600,    // seconds
    'rate_limit_per_ip' => 10,     // uncached analyses per IP per window (0 = unlimited)
    'rate_limit_global' => 300,    // uncached analyses in total per window (0 = unlimited)

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
];
