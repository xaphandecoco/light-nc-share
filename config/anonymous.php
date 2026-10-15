<?php

// Limits for people who are not signed in (Light NC Share, D295).
// Every value can be changed with an environment variable; the defaults are the agreed limits.
return [
    // Largest single share, in bytes (2 GiB).
    'max_share_bytes' => (int) env('LNC_ANON_MAX_SHARE_BYTES', 2147483648),
    // Most files in one share.
    'max_files' => (int) env('LNC_ANON_MAX_FILES', 20),
    // Longest time a share may stay available, in days.
    'max_expiry_days' => (int) env('LNC_ANON_MAX_EXPIRY_DAYS', 7),
    // Most downloads of one share.
    'max_downloads' => (int) env('LNC_ANON_MAX_DOWNLOADS', 100),
    // Shares one client IP may create per hour and per day.
    'per_ip_hour' => (int) env('LNC_ANON_PER_IP_HOUR', 5),
    'per_ip_day' => (int) env('LNC_ANON_PER_IP_DAY', 20),
    // All shares from people who are not signed in, together, in bytes (20 GiB).
    'total_bytes' => (int) env('LNC_ANON_TOTAL_BYTES', 21474836480),
    // Largest single upload request the server accepts, in bytes (50 MB; the browser sends 20 MB).
    // The Caddyfile enforces this on /files/*; keep the two in step.
    'chunk_max_bytes' => (int) env('LNC_ANON_CHUNK_MAX_BYTES', 52428800),
    // How long an upload token stays valid, in minutes.
    'token_ttl_minutes' => (int) env('LNC_ANON_TOKEN_TTL_MINUTES', 180),
    // Upload tokens one client IP may ask for per hour.
    'tokens_per_ip_hour' => (int) env('LNC_ANON_TOKENS_PER_IP_HOUR', 30),
    // Unfinished uploads older than this many hours are deleted and stop counting.
    'stale_upload_hours' => (int) env('LNC_ANON_STALE_UPLOAD_HOURS', 24),

    // Peers allowed to tell us the visitor's address with CF-Connecting-IP: private
    // addresses only (the tunnel and the Docker network). Comma separated CIDR ranges.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'LNC_TRUSTED_PROXIES',
        '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8,::1/128,fc00::/7'
    ))))),
];
