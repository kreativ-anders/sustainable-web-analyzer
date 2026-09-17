<?php

// Copy to config.php (git-ignored) and adjust. Every key is optional; see src/Config.php for all defaults.
// Selected keys can also be set via environment variables: SWA_ENABLED, SWA_DEBUG,
// SWA_ALLOWED_ORIGINS (comma-separated), SWA_CACHE_DIR, SWA_IPINFO_TOKEN.

return [
    'enabled' => true,
    'debug' => false,

    'allowed_origins' => ['https://kreativ-anders.de', 'https://www.kreativ-anders.de'],

    // ipinfo.io works without a token, but with a low monthly limit.
    'ipinfo_token' => '',

    // Only when a reverse proxy/CDN sits in front of PHP and sets this header (otherwise it can be spoofed):
    // 'client_ip_header' => 'HTTP_X_REAL_IP',

    // 'rate_limit_per_ip' => 10,
    // 'rate_limit_global' => 300,
    // 'cache_ttl' => 604800,
];
