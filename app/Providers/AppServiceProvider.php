<?php

namespace App\Providers;

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
     * Defines one Gate per entry in config/permissions.php, so routes use
     * `can:<permission>` instead of hardcoded role names. Role behavior is
     * unchanged from before this refactor — only the enforcement mechanism
     * moved, to make future department-scoped permissions addable without
     * touching every route.
     */
    public function boot(): void
    {
        foreach (config('permissions', []) as $permission => $roles) {
            Gate::define($permission, fn ($user) => in_array($user->role, $roles, true));
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
