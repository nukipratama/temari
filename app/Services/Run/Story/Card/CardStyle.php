<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Card;

/**
 * The three print styles the athlete picks between. No tier is a default
 * suggestion: the share popup opens on whichever the athlete last touched.
 */
enum CardStyle: string
{
    case Broadsheet = 'broadsheet';
    case Ticket = 'ticket';
    case TopoPlate = 'topo';

    /** The share URL also accepts the round's `a` / `b` / `c` shorthand. */
    public static function parse(?string $value): self
    {
        return match (mb_strtolower(trim((string) $value))) {
            'a', 'broadsheet' => self::Broadsheet,
            'b', 'ticket' => self::Ticket,
            'c', 'topo', 'topoplate' => self::TopoPlate,
            default => self::Broadsheet,
        };
    }
}
