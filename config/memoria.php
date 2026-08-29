<?php

return [
    'localization' => [
        'supported_locales' => [
            'en' => 'English',
        ],
    ],

    'rich_text' => [
        'maximum_characters' => 125000,
    ],

    'entries' => [
        'autosave_version_interval_minutes' => (int) env('MEMORIA_AUTOSAVE_VERSION_INTERVAL', 15),
    ],

    'attachments' => [
        'maximum_kilobytes' => (int) env('MEMORIA_ATTACHMENT_MAX_KILOBYTES', 20480),
        'scanner' => [
            'driver' => env('MEMORIA_ATTACHMENT_SCANNER'),
            'binary' => env('MEMORIA_CLAMAV_BINARY', 'clamscan'),
            'timeout_seconds' => (int) env('MEMORIA_ATTACHMENT_SCAN_TIMEOUT', 60),
        ],
        'extensions' => [
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt', 'md',
            'mp3', 'm4a', 'wav', 'mp4', 'mov',
        ],
    ],

    'public_images' => [
        'maximum_kilobytes' => 20480,
        'maximum_pixels' => 40000000,
        'avatar_maximum_width' => 1024,
        'avatar_maximum_height' => 1024,
        'cover_maximum_width' => 2400,
        'cover_maximum_height' => 1600,
        'publication_maximum_source_pixels' => 24000000,
        'publication_maximum_width' => 2400,
        'publication_maximum_height' => 2400,
        'publication_maximum_kilobytes' => 8192,
        'publication_variant_widths' => [
            'thumbnail' => 480,
            'medium' => 960,
            'large' => 1600,
        ],
    ],

    'disks' => [
        'private' => env('MEMORIA_PRIVATE_DISK', 'local'),
        'public' => env('MEMORIA_PUBLIC_DISK', 'public'),
        'sanitized_media' => env('MEMORIA_SANITIZED_MEDIA_DISK', 'local'),
        'exports' => env('MEMORIA_EXPORT_DISK', 'local'),
    ],

    'shares' => [
        'token_bytes' => (int) env('MEMORIA_SHARE_TOKEN_BYTES', 32),
        'default_expiration_days' => (int) env('MEMORIA_SHARE_EXPIRATION_DAYS', 30),
        'maximum_expiration_days' => (int) env('MEMORIA_SHARE_MAX_EXPIRATION_DAYS', 365),
        'access_session_minutes' => (int) env('MEMORIA_SHARE_ACCESS_SESSION_MINUTES', 60),
    ],

    'exports' => [
        'expiration_hours' => (int) env('MEMORIA_EXPORT_EXPIRATION_HOURS', 72),
        'chunk_size' => (int) env('MEMORIA_EXPORT_CHUNK_SIZE', 100),
        'directory' => env('MEMORIA_EXPORT_DIRECTORY', 'exports'),
    ],

    'social' => [
        'driver' => env('MEMORIA_SOCIAL_DRIVER'),
        'connect_timeout_seconds' => (int) env('MEMORIA_SOCIAL_CONNECT_TIMEOUT', 3),
        'timeout_seconds' => (int) env('MEMORIA_SOCIAL_TIMEOUT', 15),
        'lock_seconds' => (int) env('MEMORIA_SOCIAL_LOCK_SECONDS', 60),
        'linkedin_version' => env('MEMORIA_LINKEDIN_VERSION', '202606'),
        'facebook_graph_version' => env('MEMORIA_FACEBOOK_GRAPH_VERSION', 'v25.0'),
        'providers' => [
            'x' => [
                'socialite_driver' => 'x',
                'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
            ],
            'linkedin' => [
                'socialite_driver' => 'linkedin-openid',
                'scopes' => ['openid', 'profile', 'email', 'w_member_social'],
            ],
            'facebook' => [
                'socialite_driver' => 'facebook',
                'scopes' => ['public_profile'],
            ],
            'mastodon' => [
                'socialite_driver' => null,
                'scopes' => ['read', 'write'],
            ],
        ],
    ],

    'scheduler' => [
        'batch_size' => (int) env('MEMORIA_SCHEDULER_BATCH_SIZE', 100),
    ],

    'sitemap' => [
        'maximum_urls' => (int) env('MEMORIA_SITEMAP_MAXIMUM_URLS', 5000),
        'cache_seconds' => (int) env('MEMORIA_SITEMAP_CACHE_SECONDS', 900),
        'lock_seconds' => (int) env('MEMORIA_SITEMAP_LOCK_SECONDS', 30),
    ],

    'security_headers' => [
        'content_security_policy' => env(
            'MEMORIA_CONTENT_SECURITY_POLICY',
            "default-src 'self'; base-uri 'self'; connect-src 'self'; font-src 'self' data:; form-action 'self'; frame-ancestors 'none'; img-src 'self' data:; object-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'",
        ),
        'panel_content_security_policy' => env(
            'MEMORIA_PANEL_CONTENT_SECURITY_POLICY',
            "default-src 'self'; base-uri 'self'; connect-src 'self'; font-src 'self' data:; form-action 'self'; frame-ancestors 'none'; img-src 'self' data: https:; object-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'",
        ),
        'permissions_policy' => env(
            'MEMORIA_PERMISSIONS_POLICY',
            'camera=(), microphone=(), geolocation=(), payment=()',
        ),
    ],
];
