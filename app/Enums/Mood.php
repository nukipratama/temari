<?php

declare(strict_types=1);

namespace App\Enums;

enum Mood: string
{
    case Blazing = 'blazing';
    case Easy = 'easy';
    case Wobbly = 'wobbly';
    case Gassed = 'gassed';
    case Overloaded = 'overloaded';
    case Chill = 'chill';
}
