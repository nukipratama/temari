<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * The one place this repo decides what a cardiac-decoupling figure means.
 *
 * The same question — "was that a lot?" — used to be answered in four files
 * with five different numbers: the plan called 5% high, the rule-based
 * insights agreed at 5% and called 2% tight, Temari's mood picked 12%, and the
 * run-insight prompt told the model ">10%". A run at 11% was simultaneously
 * high, normal and not worth mentioning depending on who was asking.
 *
 * The ladder is fitted against real runs rather than assumed. On the corpus
 * this athlete's ordinary runs — easy days and quality days alike — land
 * between 6 and 13%, so a line drawn at 5% fires on a routine Tuesday and
 * tells the plan the week was run too hard. {@see self::HIGH} therefore sits
 * above the top of that band, where a figure genuinely says something the
 * athlete did not intend.
 */
final class DecouplingBands
{
    /** Under this the HR/pace ratio barely moved: the aerobic base held. */
    public const float TIGHT = 2.0;

    /** A hard session that still finished with HR under control, rather than as a grind. */
    public const float CONTROLLED = 5.0;

    /** Past the top of the ordinary band: HR drifted well past pace, whatever the day asked for. */
    public const float HIGH = 12.0;

    /** Far enough past {@see self::HIGH} that the day is not a rounding error on the week. */
    public const float EGREGIOUS = 15.0;
}
