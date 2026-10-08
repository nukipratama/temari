<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * The three states of load balance (long-term load minus short-term load) shown to the athlete.
 */
enum LoadBalance: string
{
    case Fresh = 'fresh';
    case Steady = 'steady';
    case Heavy = 'heavy';
}
