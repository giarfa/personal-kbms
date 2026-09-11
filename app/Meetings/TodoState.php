<?php

namespace App\Meetings;

/**
 * What the title says (US-013), before the clock is consulted.
 *
 * "Overdue" is deliberately not a case here: it is not a state the operator
 * types, it is a derivation from this state plus `ends_at` plus now. Keeping it
 * out means a summary can never claim to be overdue on its own.
 */
enum TodoState: string
{
    case Open = 'open';
    case Done = 'done';
}
