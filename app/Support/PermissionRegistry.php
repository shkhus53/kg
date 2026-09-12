<?php

namespace App\Support;

/**
 * Thin logic layer over config/permissions.php — the data stays
 * centralized in config, this class is what code actually calls so the
 * config shape never leaks into controllers/views/Gate definitions.
 *
 * Grouping/labels exist only for the permission-management UI (Edit User
 * -> Permissions) — they have no effect on enforcement.
 */
class PermissionRegistry
{
    /**
     * @return array<int,string>
     */
    public static function all(): array
    {
        return config('permissions.all', []);
    }

    /**
     * Operator/Viewer only — Admin's "default" is unconditional full access,
     * handled directly in User::hasPermission(), never listed here.
     *
     * @return array<int,string>
     */
    public static function defaultsForRole(string $role): array
    {
        return config("permissions.role_defaults.{$role}", []);
    }

    /**
     * @return array<string,array<int,string>> group label => permission keys, in display order
     */
    public static function groups(): array
    {
        return [
            'Dashboard' => ['view_dashboard'],
            'Sessions' => ['view_sessions', 'create_sessions', 'activate_sessions', 'close_sessions', 'reopen_sessions'],
            'Attendance' => ['view_live_attendance', 'mark_attendance', 'mark_extra_present', 'correct_attendance', 'view_attendance_history'],
            'Directory' => ['view_directory', 'view_member_profile'],
            'Analytics' => ['view_analytics'],
            'Planning' => ['view_planning', 'manage_planning'],
            'Reports' => ['view_reports', 'build_reports'],
            'Operational Intelligence' => ['view_alerts', 'view_management_summary'],
            'Duty List Import' => ['import_duty_list', 'preview_import', 'commit_import'],
            'Administration' => ['view_audit_log', 'manage_masters', 'manage_users'],
        ];
    }

    public static function label(string $permission): string
    {
        return self::LABELS[$permission] ?? ucfirst(str_replace('_', ' ', $permission));
    }

    private const LABELS = [
        'view_dashboard' => 'Dashboard',
        'view_sessions' => 'View Sessions',
        'create_sessions' => 'Create Session',
        'activate_sessions' => 'Activate Session',
        'close_sessions' => 'Close Session',
        'reopen_sessions' => 'Reopen Closed Session',
        'view_live_attendance' => 'View Live Attendance',
        'mark_attendance' => 'Mark Present / Absent',
        'mark_extra_present' => 'Add Extra Present',
        'correct_attendance' => 'Attendance Correction',
        'view_attendance_history' => 'View Attendance History',
        'view_directory' => 'View Khidmatguzar Directory',
        'view_member_profile' => 'View Khidmatguzar Profile',
        'view_analytics' => 'View Analytics',
        'view_planning' => 'View Planning',
        'manage_planning' => 'Manage Planning (Create/Edit)',
        'view_reports' => 'Reports',
        'build_reports' => 'Report Builder',
        'view_alerts' => 'View Operational Alerts',
        'view_management_summary' => 'View Management Summary',
        'view_audit_log' => 'Audit Log',
        'manage_masters' => 'Master Data',
        'manage_users' => 'User Management',
        'import_duty_list' => 'Import Duty List',
        'preview_import' => 'Preview Import',
        'commit_import' => 'Commit Import',
    ];
}
