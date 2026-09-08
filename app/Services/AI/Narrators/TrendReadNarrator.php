<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanAdherenceTool;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

/**
 * "Temari's read" on the Trends tab: one narrated take on the user's
 * training for a given range (30d/90d/12mo). Refreshed on a schedule (see
 * routes/console.php's three ai:trend-read entries), never generated live
 * per page view — same never-bill-on-page-load rule every other narrator
 * in the app already follows.
 */
class TrendReadNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: read the user's training over the given range. Output TWO
        fields: title (one short sentence, the headline) and description
        (2-4 sentences, the supporting read). Together they read as one
        continuous idea split across two sizes, not two disconnected
        thoughts: the title is the takeaway, the description is why.

        DATA: none of the numbers are handed to you up front. Call
        get_trend_range_totals before narrating, the comparison shape
        below only makes sense once you have it.

        COMPARISON SHAPE depends on range, and this is the whole point of
        the reading:
        - 30d: compare `current` (the last 30 days) against `comparison`
          (the 30 days before that), read it as "the month before".
        - 90d: same shape, `comparison` reads as "the quarter before".
        - 12mo: `current` is the second half of the last 12 months,
          `comparison` is the FIRST half of that SAME window, read it as
          "the first half of the year" vs "the second half". NEVER read
          12mo as "this year vs last year": you were not given a prior
          year, only this window's own two halves.

        ONE READING, AND THE SENTENCES DEEPEN IT. The description has room
        for 2-4 sentences and that room is NOT there to fit more metrics
        into. Every sentence after the first explains the same reading:
        what it means, what it does not mean, what would change it, what
        the athlete should take from it. The moment a second metric
        family appears you are writing a table.

        NUMBER LIMIT: max 3 numbers across title + description combined.
        A comparison is TWO of them (the before and the after), so in
        practice you get one comparison and at most one more figure. If
        you are about to write a fourth number, you have started a second
        reading: cut it and say more about the first instead.

        COMMIT FIRST. Output a `reading` field naming the ONE thing below
        your description is about, before you write a word of it. Every
        number you then use has to belong to that reading. If you find
        yourself wanting a figure from a different family, the answer is
        not to add it, it is that you may have picked the wrong reading:
        go back and change `reading`, then write to the new one.

        The five, and `reading` takes exactly one of these values:
        - `load`: current vs comparison distance_km, runs, or
          trimp_total (trimp_total is null on an unscored stretch, that
          means "no reading", not zero, don't narrate it as a rest period).
        - `fitness`: ctl_start vs ctl_end.
        - `vdot`: vdot_start vs vdot_end, skip this angle entirely
          if either is null, that means not enough history yet, don't
          guess a direction from missing data.
        - `shape`: avg_monotony above 2 means the load was unusually
          uniform (a known injury-risk pattern), worth naming once if it
          stands out, not a default thing to mention. Call it "monotony"
          in your words; `avg_monotony` is the field name and field names
          never reach the reader.
        - `adherence`: get_plan_adherence's counts. A stretch where
          the missed count climbed explains a volume drop better than the
          volume drop does, and a stretch they held session by session
          while fitness slid is the more interesting reading of the two.
          Its counts cover the WHOLE range you were asked to read, which
          on 12mo means both halves together, NOT the `current` half. So
          never pair an adherence count with a current-vs-comparison
          figure as though the two describe the same stretch of time.
          Skip it when prescribed is 0.
        Don't stack two or more of these into one answer.

        Title: one short sentence, the headline. A number is not required
        if the plain read is clear on its own ("training load eased back
        this quarter" is a complete title).
        Description: 2-4 sentences explaining the title, all of them about
        the one thing you picked.

        Match posture to the direction of the number that drives the
        reading, never softening what it actually says: a real drop in
        load is a real drop, a flat stretch is flat, a climb is a climb.
        Nothing here is a verdict on the user, it's a reading of the range.

        ANTI-PATTERN:
        - Stacking metrics ("32km across 5 runs, TRIMP 410, CTL 45 up from
          38 monotony 1.8"), that's a table, not a story.
        - A number with no context ("your monotony was 2.3").
        - Manufacturing a positive spin on a real drop in training load.
          Down is down, say it and stop.
        - Exclamation points, emoji, "great month!", "keep it up!".
        - Comparing 12mo against a prior year you were not given, you
          only have this window's own first half vs second half.
        PROMPT;

    /** @var array<string, array<string, mixed>> */
    private const array READING_PROPERTY_SCHEMA = [
        'reading' => [
            'type' => 'string',
            'enum' => ['load', 'fitness', 'vdot', 'shape', 'adherence'],
        ],
    ];

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    /**
     * @param  string  $range  One of AnalysisType::TREND_READ_RANGES.
     */
    public function generate(User $user, string $range): string
    {
        $decoded = $this->caller->call(
            kind: 'trend_read',
            systemPrompt: self::SYSTEM_PROMPT,
            context: ['range' => $range],
            schemaName: 'TemariTrendRead',
            requiredKeys: ['reading', 'title', 'description'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $user->id,
                maxTokens: 1200,
                toolbox: new AgentToolbox([
                    new TrendRangeTool($user, $range, $this->trainingLoad),
                    new PlanAdherenceTool(
                        $user,
                        Carbon::today(),
                        Carbon::today()->subDays((TrendRangeTool::RANGE_DAYS[$range] ?? TrendRangeTool::RANGE_DAYS['30d']) - 1),
                    ),
                ]),
                maxSteps: 6,
            ),
            propertySchema: self::READING_PROPERTY_SCHEMA,
        );

        // `reading` is a commitment device, not content: naming the one family
        // before writing is what stops four of them turning up in the prose.
        // Three separate no-stacking rules in the prompt did not, measured at
        // 4-5 families and 9-10 numbers against a stated ceiling of 3.
        return trim((string) $decoded['title']."\n\n".(string) $decoded['description']);
    }
}
