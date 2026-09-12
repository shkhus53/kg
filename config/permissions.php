<?php

/**
 * Permission -> role map. This is the single place role-based access is
 * defined; routes/controllers check `Gate::allows($permission)` (or the
 * `can:` middleware) rather than hardcoding role names, so introducing a
 * new role or a future department-scoped permission never requires
 * touching every route. Admin is intentionally listed everywhere — Admin
 * stays unrestricted.
 */
return [
    'manage_sessions' => ['admin', 'operator'],
    'reopen_sessions' => ['admin'],
    'view_audit_log' => ['admin'],
    // Deliberately its own permission, not reused from manage_sessions: this
    // gates "may take attendance" specifically, so a future role that can
    // mark attendance without managing session lifecycle doesn't require
    // touching every route again.
    'mark_attendance' => ['admin', 'operator'],
];
