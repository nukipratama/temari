<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Azure's own `status` on a Responses API result. `incomplete` pairs with an
 * `incompleteDetails.reason` that says whether the cap or the content filter
 * stopped it.
 */
enum AzureResponseStatus: string
{
    case Completed = 'completed';
    case Incomplete = 'incomplete';
}
