<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Card;

/**
 * Which composition the run earns. Resolved in {@see CardFacts::from()} and
 * read by all three styles, so "form follows the run" means the same thing on
 * a broadsheet, a ticket and a survey plate.
 */
enum RunForm: string
{
    case Easy = 'easy';
    case Long = 'long';
    case Race = 'race';
    case Pr = 'pr';
    case NoGps = 'nogps';
}
