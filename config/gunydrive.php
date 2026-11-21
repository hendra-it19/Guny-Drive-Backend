<?php

return [
    'public_path' => env('GUNYDRIVE_PUBLIC_PATH'),
    'admin_email' => env('GUNYDRIVE_ADMIN_EMAIL'),
    'admin_password' => env('GUNYDRIVE_ADMIN_PASSWORD'),
    'disk' => env('GUNYDRIVE_DISK', 'local'),
    'server_name' => env('GUNYDRIVE_SERVER_NAME', env('APP_NAME', 'GunyDrive')),
    'default_quota_bytes' => (int) env('GUNYDRIVE_DEFAULT_QUOTA_BYTES', 100 * 1024 * 1024 * 1024),
    'default_max_file_bytes' => (int) env('GUNYDRIVE_DEFAULT_MAX_FILE_BYTES', 2 * 1024 * 1024 * 1024),
    'physical_reserve_bytes' => (int) env('GUNYDRIVE_PHYSICAL_RESERVE_BYTES', 256 * 1024 * 1024),
    'trash_retention_days' => (int) env('GUNYDRIVE_TRASH_RETENTION_DAYS', 30),
    'upload_session_ttl_hours' => (int) env('GUNYDRIVE_UPLOAD_TTL_HOURS', 24),
    'vault_idle_timeout_seconds' => (int) env('GUNYDRIVE_VAULT_IDLE_TIMEOUT', 300),
    'vault_max_attempts' => (int) env('GUNYDRIVE_VAULT_MAX_ATTEMPTS', 5),
    'vault_lockout_seconds' => (int) env('GUNYDRIVE_VAULT_LOCKOUT_SECONDS', 300),
];
