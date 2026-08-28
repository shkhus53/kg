<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown inside the close-session transaction to force a rollback when
 * Pending assignments remain — session must stay Active, not end up
 * Closed or stuck in an ambiguous state.
 */
class SessionHasPendingAssignmentsException extends Exception
{
    public function __construct(public readonly int $pendingCount)
    {
        parent::__construct("Cannot close: {$pendingCount} pending assignment(s) remain.");
    }
}
