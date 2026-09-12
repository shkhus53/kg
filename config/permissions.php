<?php

/**
 * Centralized permission data — the single place the full permission list
 * and each role's DEFAULT set live. This is data only; the actual
 * effective-permission calculation (role default -> user override ->
 * admin-always-allowed) lives in App\Support\PermissionRegistry and
 * App\Models\User::hasPermission().
 *
 * Admin is deliberately absent from 'role_defaults' — an Admin always has
 * every permission unconditionally (see User::hasPermission()) and can
 * never be restricted by a user-level override, so listing "all
 * permissions" for admin here would be misleading busywork, not a real
 * default that could ever be overridden.
 */
return [
    'all' => [
        'view_dashboard',

        'view_sessions',
        'create_sessions',
        'activate_sessions',
        'close_sessions',
        'reopen_sessions',

        'view_live_attendance',
        'mark_attendance',
        'mark_extra_present',
        'correct_attendance',
        'view_attendance_history',

        'view_directory',
        'view_member_profile',

        'view_analytics',

        'view_planning',
        'manage_planning',

        'view_reports',
        'build_reports',

        'view_alerts',
        'view_management_summary',

        'view_audit_log',

        'manage_masters',
        'manage_users',

        'import_duty_list',
        'preview_import',
        'commit_import',
    ],

    'role_defaults' => [
        'operator' => [
            'view_dashboard',
            'view_sessions',
            'view_live_attendance',
            'mark_attendance',
            'mark_extra_present',
            'view_attendance_history',
            'view_directory',
            'view_member_profile',
        ],
        'viewer' => [
            'view_dashboard',
            'view_sessions',
            'view_live_attendance',
            'view_attendance_history',
            'view_directory',
            'view_member_profile',
            'view_analytics',
            'view_planning',
            'view_alerts',
            'view_management_summary',
        ],
    ],
];
