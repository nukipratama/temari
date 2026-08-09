<?php

declare(strict_types=1);

namespace App\Services\AI\RuleBased;

use App\Enums\Badge;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Rule-based content per AnalysisType. Used by:
 * - DemoSeedCommand to backfill Analysis rows without spending LLM tokens
 * - BriefingComposer when Azure OpenAI is unconfigured (empty env)
 *
 * Output is deterministic and Temari-voiced. Where the subject's real data is
 * available it drives the copy (run-insight types via {@see RuleBasedRunInsights}
 * so a seeded demo shows the run's real numbers), falling back to seeded
 * variants only when the subject row is missing. Users with a configured Azure
 * can re-trigger via "Baca ulang" to get real LLM output.
 */
final readonly class RuleBasedNarrationFiller
{
    public function __construct(
        private RuleBasedRunInsights $runInsights,
    ) {
    }

    public function fillFor(Analysis $row): string
    {
        $seed = $this->seedFor($row);

        return match ($row->analysis_type) {
            AnalysisType::BriefingMascotVoice => $this->briefingMascotVoice($seed),
            AnalysisType::BriefingFeaturedKartuVoice => $this->briefingFeaturedKartuVoice($seed),
            AnalysisType::PostRunSpeech => $this->postRunSpeech($seed),
            AnalysisType::RunInsightTechnical => $this->runInsightTechnical($seed),
            AnalysisType::RunInsightSplits => $this->runInsightSplits($seed),
            AnalysisType::RunInsightZones => $this->runInsightZones($seed),
            AnalysisType::WeeklyRecap => $this->weeklyRecap($seed),
            AnalysisType::PrContext => $this->prContext($seed),
            AnalysisType::CardFlavor => $this->cardFlavor($seed),
            AnalysisType::AkuProfileVoice => $this->akuProfileVoice($seed),
            AnalysisType::MonthlyRecap => $this->monthlyRecap($seed),
        };
    }

    /**
     * Deterministic selection seed for a row. The discriminator (when present)
     * is folded in so discriminator-keyed types (monthly/weekly recap, daily
     * briefing) produce distinct content per discriminator instead of repeating
     * the same subject-only variant. A null discriminator leaves the seed equal
     * to subject_id, preserving determinism for non-discriminated types.
     */
    private function seedFor(Analysis $row): int
    {
        if ($row->discriminator === null) {
            return $row->subject_id;
        }

        return $row->subject_id + (int) crc32($row->discriminator);
    }


    private function briefingMascotVoice(int $seed): string
    {
        return $this->select([
            "Easy tempo, 35-45 minutes.\n\nYour rhythm's read steady these past few weeks and there hasn't been a quality session since last week, so today I think there's room for a tempo. 10-minute easy warmup, 15-20 minute tempo a bit faster than your average pace, then cooldown, cadence at 175+.\n\nWhat to watch: if HR climbs fast even at an easy pace, back off to a 15-25 minute run-walk or stop at the cooldown. If the weather's hot or you're still feeling wiped, resting isn't a loss either.",
            "Easy run, 30-40 minutes.\n\nYour last two sessions read heavy and recovery hasn't been long, that's why I'm putting easy on today. Hold around your normal pace, breathing should still allow talking, cadence at 170+ so your steps stay light.\n\nWhat to watch: if your legs feel heavy or HR climbs oddly early on, that's a sign recovery isn't done yet. Backing off to a brisk 20-minute walk is fine.",
            "Easy long run, 8-12 km.\n\nYour km's been climbing slightly and consistently this week, I think it'd feel good to close it out with one long session. Conversational pace, don't get tempted to chase a time, bring water if it's hot.\n\nWhat to watch: a long distance needs a steady pace. If km 5 already feels forced, cut it to 6-8 km. Better short and clean than long and messy.",
            "Rest today.\n\nYou've been going non-stop the past few days and I can see the load piling up, so I'm not suggesting a run today. Light mobility or a relaxed 20-minute walk is enough to make your legs feel light again.\n\nWhat to watch: if you're still feeling heavy tomorrow, add one more day. Rest is part of training, not skipping it.",
            "Easy run, 25-30 minutes.\n\nYou're just back after a few days off, so I want to start short to get the rhythm going again. Keep it slow, effort by feel, don't even look at the pace.\n\nWhat to watch: the biggest temptation after time off is to go straight back to fast. If your breathing gets heavy before minute 15, ease off or add some walking.",
            "Base run, 5-7 km.\n\nYou've been consistent this week and your condition reads safe, so today's about holding the rhythm that's already working. Pace around your average, one steady block with no need to shift gears.\n\nWhat to watch: a session like this is easy to accidentally turn into a tempo halfway through. If you feel like pushing, save it for next time.",
        ], $seed);
    }

    private function briefingFeaturedKartuVoice(int $seed): string
    {
        return $this->select([
            "This card holds a run worth remembering. Open it again whenever you need a push.",
            "One card stood out this week. Keep it as a reminder that you've got this.",
            'This card is proof of a session worth remembering. No harm in showing it off.',
            "This one's worth a second look. Sometimes it's easy to forget how far you've come.",
            'This card is a record of a session that was out of the ordinary. Save it for a day you need the reminder.',
            "One card stood out from the rest of the week. Show it off, so it sticks that you've got this.",
        ], $seed);
    }

    private function detailFor(int $activityId): ?ActivityDetail
    {
        return ActivityDetail::query()->where('activity_id', $activityId)->first();
    }

    private function postRunSpeech(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        if ($detail === null) {
            return 'Done. This is the kind of consistency I like to see.';
        }
        $km = DistanceFormatter::kmString($detail->distance) ?? '?';

        // Hashed (not the bare, sequential activityId), or a run of consecutive
        // activities — exactly what the Riwayat feed shows side by side — walks
        // the pool in lockstep and repeats the identical line every N-th run.
        $baseSeed = (int) crc32('post_run_speech_'.$activityId);

        $base = $this->select([
            "That's {$km} km done. Pace held up all the way to the end, nice.",
            "Finished {$km} km. Your rhythm was clean, I like it.",
            "{$km} km in the books. This kind of consistency is what builds progress.",
            "{$km} km done. Breathing stayed controlled, the run read easy.",
            "Got {$km} km today. No rush, but it got done, that's what matters.",
            "Another {$km} km in the log. Slow but regular, that's what makes the difference.",
            "{$km} km wrapped up. The effort read just right, not forced, not too easy either.",
            "{$km} km logged. Runs like this are what add up to progress.",
            "{$km} km wrapped today. Simple, but getting it done is what counts.",
            "{$km} km session done. Show up, run, go home, that's enough.",
            "{$km} km, wrapped up clean. Nothing to fix here.",
            "Today's log: {$km} km. It's the small ones like this that make the habit stick.",
        ], $baseSeed);

        return $base . $this->postRunCoda($detail, $activityId);
    }

    /**
     * One short data-driven coda for the post-run line, picked from whichever
     * real signal the run actually carries (negative split / heat / rain),
     * seeded so a given run always reads the same. Empty when nothing stands out.
     */
    private function postRunCoda(ActivityDetail $detail, int $seed): string
    {
        $codas = [];
        if (StreamSummary::fromArray($detail->stream_summary)->negativeSplit() === true) {
            $codas[] = ' Second half actually got faster, nice.';
        }
        if ($detail->weather_rain_detected === true) {
            $codas[] = ' Ran straight through the rain, respect.';
        } elseif ($detail->weather_temp_c !== null && $detail->weather_temp_c >= 31) {
            $codas[] = " And that was in {$detail->weather_temp_c} degrees, brutal heat.";
        }

        return $codas === [] ? '' : $codas[abs($seed) % count($codas)];
    }


    private function runInsightTechnical(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        if ($detail === null) {
            return "The technical detail isn't fully readable yet.";
        }

        return $this->runInsights->technical($detail);
    }

    private function runInsightSplits(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        if ($detail === null) {
            return "The splits aren't fully readable yet.";
        }

        return $this->runInsights->splits($detail);
    }

    private function runInsightZones(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        if ($detail === null) {
            return "The zone breakdown isn't fully readable yet.";
        }

        return $this->runInsights->zones($detail);
    }

    private function weeklyRecap(int $snapshotId): string
    {
        $snapshot = WeeklySnapshot::query()->find($snapshotId);
        if ($snapshot === null || $snapshot->runs === null || $snapshot->runs < 1) {
            return $this->select([
                "Your rhythm was pretty steady this week. Volume was reasonable, recovery got taken care of too.",
                "This week's volume was fine, not too much but not empty either. A healthy balance.",
                'Another week wrapped. Your distance and frequency made sense, just keep going steady.',
                'A consistent week, no drama. Sometimes this is exactly what\'s needed, a steady climb.',
                "This week was a bit quiet on running, that's fine. Sometimes the body just needs a pause.",
                'Not much movement this week. Ease back in slowly, no need to jump straight back to a lot.',
                "A week that leaned more toward rest. Recovery's part of training too.",
            ], $snapshotId);
        }

        $km = DecimalFormatter::decimal((float) $snapshot->distance_km);
        $runs = $snapshot->runs;
        $closer = match ($snapshot->form_status) {
            'fresh' => "Feeling fresh, there's room to build up gradually.",
            'optimal' => 'Right in the sweet spot, keep this rhythm going.',
            'fatigued' => 'Starting to feel the fatigue, work in some recovery next week.',
            'overreaching' => "Load's gotten high, don't forget to rest enough.",
            default => 'Keep it steady, nice and slow.',
        };

        return $this->select([
            "{$km} km across {$runs} runs this week. {$closer}",
            "This week added up to {$km} km from {$runs} sessions. {$closer}",
            "{$runs} runs, {$km} km total. {$closer}",
            "This week: {$km} km across {$runs} runs. {$closer}",
            "{$km} km logged, {$runs} sessions. {$closer}",
            "{$runs} sessions this week, {$km} km total. {$closer}",
        ], $snapshotId);
    }

    private function prContext(int $seed): string
    {
        return $this->select([
            "This PR is the result of consistency over the past few weeks, not luck.",
            "This isn't luck, it's hard work that added up slowly.",
            'New PR! Every second shaved off is proof of training that never stopped.',
            "A new record's unlocked. You've been paying the price slowly, this is the payoff.",
            "This record didn't come out of nowhere. It's the sum of sessions you put in quietly.",
            'New PR logged. The number\'s just the marker, the work was already done.',
            "The time shaved off looks small on screen, but it's huge in effort. Congrats.",
        ], $seed);
    }


    /**
     * Card flavor woven from the card's own context (rarity + special move +
     * distance + first badge), seeded by card id so each card reads differently
     * while staying deterministic. Mirrors the per-rarity pools of the real
     * {@see \App\Services\AI\Narrators\CardFlavorNarrator}, minus the LLM.
     */
    private function cardFlavor(int $cardId): string
    {
        $card = RunCard::query()->with('activity.detail')->find($cardId);
        if ($card === null) {
            return 'This card was born from a quiet but solid session.';
        }

        $move = $card->special_move;
        $distance = $card->activity->detail?->distance;
        $km = DistanceFormatter::kmString($distance !== null ? (float) $distance : null);

        $pool = self::FLAVOR_POOLS[$card->rarity->value];
        $templates = $km === null
            ? array_values(array_filter($pool, fn (string $t): bool => ! str_contains($t, '{km}')))
            : $pool;
        if ($templates === []) {
            $templates = $pool; // every pool keeps km-less variants; stay non-empty regardless
        }

        // Fold the badge set into the base pick (not just the appended clause) so
        // two commons that differ only by badge don't also land on the identical
        // base sentence. Badgeless cards fold a constant, so they still vary by id.
        $flavorSeed = $cardId + (int) crc32(implode(',', $card->badges ?? []));
        $base = strtr($this->select($templates, $flavorSeed), ['{move}' => $move, '{km}' => (string) $km]);
        $badgeClause = $this->badgeClause($card->badges, $cardId);

        return $badgeClause === null ? $base : $base . ' ' . $badgeClause;
    }

    /**
     * Per-rarity flavor templates. `{move}` and `{km}` are filled from the card;
     * km-less templates exist so a GPS-free run never renders an empty number.
     *
     * @var array<string, non-empty-list<string>>
     */
    private const array FLAVOR_POOLS = [
        'common' => [
            '"{move}" might be ordinary, but you still saw it through to the end.',
            'A calm {km} km run, logged because consistency is worth something.',
            'No drama in "{move}", just a clean rhythm.',
            'A quiet "{move}" session, but you finished it whole.',
            "An ordinary {km} km run, but a clean one, that's enough.",
        ],
        'uncommon' => [
            '"{move}" felt right, there\'s something about this session that sticks.',
            'A memorable {km} km run, not just a number.',
            'There\'s a moment in "{move}" worth remembering again.',
            '"{move}" holds one moment that sticks with you.',
            "A {km} km run that's more than just a log entry.",
        ],
        'rare' => [
            '"{move}" doesn\'t come around often, hang onto it.',
            "A rare {km} km run that doesn't happen every week.",
            'Something about "{move}" makes this session different from the usual.',
            '"{move}" doesn\'t show up every week, make sure it\'s logged.',
            "A {km} km run that's pretty rare for you.",
        ],
        'epic' => [
            '"{move}" is exceptional, the hard work reads clearly.',
            "A {km} km run worth showing off, this wasn't just any session.",
            '"{move}" is on another level, you\'re leveling up.',
            '"{move}" shows you\'re stepping up a level.',
            'A {km} km run that means business, worth showing off.',
        ],
        'legendary' => [
            '"{move}" is legendary, a session you\'ll be talking about for a while.',
            'A {km} km run that goes into your running history books.',
            '"{move}" is a once-in-a-while kind of progress, celebrate it.',
            '"{move}" is going to be a story you keep retelling.',
            'A {km} km run that goes into your running history.',
        ],
    ];

    /**
     * Short badge-driven coda, picked deterministically when the card carries a
     * badge. Returns null when there's nothing notable to add.
     *
     * @param  array<int, string>|null  $badges
     */
    private function badgeClause(?array $badges, int $seed): ?string
    {
        if ($badges === null || $badges === []) {
            return null;
        }

        $clauses = [
            Badge::NegativeSplit->value => 'Second half actually picked up even more.',
            Badge::HariPanas->value => 'And that was in the middle of a scorcher.',
            Badge::PejuangHujan->value => "Not even the rain could stop you.",
            Badge::AnakPagi->value => 'Headed out while the world was still quiet.',
            Badge::LongSlowDistance->value => 'Long distance, patience held.',
            Badge::TahanDiri->value => 'Pace held back cleanly from the start.',
            Badge::AnakMalam->value => 'The later it got, the more you kept going.',
            Badge::Pendaki->value => 'Big elevation, extra effort.',
            Badge::PertamaKali->value => "A first step you won't forget.",
            Badge::Rajin->value => 'Three days straight, seriously disciplined.',
            Badge::Kilat->value => 'Sub-5 pace per km, fast.',
            Badge::Jauh->value => 'Half marathon and up, serious distance.',
            Badge::Z2Master->value => 'Mostly in Z2, seriously patient.',
            Badge::AnakDingin->value => 'Dead of early morning, but the energy was already on.',
            Badge::Keras->value => 'HR stayed high start to finish.',
            Badge::Santai->value => 'Genuinely easy, HR kept low.',
            Badge::Berturut->value => 'A full week with no skips, impressive.',
            Badge::HariSpesial->value => 'Ran on a national holiday.',
            Badge::LawanAngin->value => "Strong wind didn't slow you down.",
        ];

        // Highlight one of the card's badges, chosen by seed so multi-badge
        // cards don't all lean on the same coda.
        $known = array_values(array_filter($badges, fn (string $b): bool => isset($clauses[$b])));
        if ($known === []) {
            return null;
        }

        return $clauses[$known[abs($seed) % count($known)]];
    }

    /**
     * @param  non-empty-list<string>  $pool
     */
    private function select(array $pool, int $seed): string
    {
        return $pool[abs($seed) % count($pool)];
    }

    private function akuProfileVoice(int $seed): string
    {
        return $this->select([
            "Your runs lean more **adem** than pushed, and it shows in how it adds up: slow, regular, never a big jump. The type who builds a base patiently. Keep the rhythm going, I'm tracking all of it here.",
            "Your mood spread leans **enteng** and the numbers back it up: you keep coming back without any drama, week after week. This kind of consistency is what makes progress last, so no need to rush the ramp-up.",
            "You're the type who picks routine over flash, and your weekly streak proves it. Base first, speed follows. Whenever you want to slip in a bigger session, you've already got the foundation for it.",
            "Lately **nyala** shows up more often than the quieter months before, and the records you've picked up follow that same direction. You're in a bold phase right now. Keep one easy run in the mix so it doesn't run away from you.",
            "Your running mood mixes **nyala** and **oleng**, and your longest distance reads like it was born on the bold days. You like to push, that's a good thing. One relaxed session in between will make the hard ones feel lighter.",
            "Your pattern reads disciplined: mostly **adem**, occasionally pushed, and your total km builds up from that, not from one heroic run. A healthy way to build. Just keep going steady.",
            "You're just getting started and the mix is still thin, but you've already come back again and again. To me, that's already a story. We'll build it slowly, so the persona reading actually has substance later.",
        ], $seed);
    }

    private function monthlyRecap(int $seed): string
    {
        return $this->select([
            "Your rhythm kept going this month. Not forcing it, not disappearing either. The kind of consistent I like to see.",
            'A full month of regular running. Volume made sense, effort stayed controlled too. A solid month.',
            'A month with no meaningful skips. You showed up, ran, went home. A healthy pattern.',
            "This month you chose consistency over intensity. And that's a good choice.",
            "This month you kept showing up even when you weren't always fast. Consistent presence is worth a lot.",
            "A clean month without much drama. Volume stayed on track, effort wasn't forced. Solid.",
            'This month leaned more patient than pushing hard. The right strategy for the long run.',
        ], $seed);
    }
}
