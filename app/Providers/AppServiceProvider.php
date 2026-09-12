<?php

namespace App\Providers;

use App\Support\PermissionRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Defines one Gate per permission in PermissionRegistry::all(), so
     * routes use `can:<permission>`. Each Gate simply delegates to
     * User::hasPermission(), which is where the actual role-default ->
     * user-override -> admin-always-allowed precedence lives — the Gate
     * layer itself carries no authorization logic of its own, so there is
     * exactly one place that logic can ever diverge.
     */
    public function boot(): void
    {
        foreach (PermissionRegistry::all() as $permission) {
            Gate::define($permission, fn ($user) => $user->hasPermission($permission));
        }

        // Single canonical place a stored (UTC) timestamp is converted for
        // an operator to look at — see config/app.php 'operational_timezone'
        // for why this is a display-only conversion, never applied to what
        // gets written to the database. Only call this on genuine
        // timestamp values (performed_at, created_at, marked_at, ...) —
        // never on a DATE-only value like DutySession::$date, which has no
        // time-of-day component to convert.
        Carbon::macro('toIst', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(config('app.operational_timezone'));
        });
    }
}
