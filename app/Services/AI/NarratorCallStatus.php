<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * The outcome field of the `narrator.ai.call` log event.
 */
enum NarratorCallStatus: string
{
    case Ok = 'ok';
    case Fail = 'fail';
}
