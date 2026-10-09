<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off clean-up of what public demo visitors wrote into the shared demo
 * account before those writes were refused: run questions other than the
 * exchanges DemoRunSeeder seeds, the race name, and any Telegram link.
 *
 * The seeded questions, the shape of their fixture answers and the race name
 * are frozen here as they stood on 2026-10-08, so a later seeder change cannot
 * change what this deletes.
 *
 * down() is a documented no-op: the visitor text is not restored.
 */
return new class () extends Migration {
    private const string SEEDED_RACE_NAME = 'City 10K';

    /** @var array<string, string> */
    private const array SEEDED_ANSWER_PATTERNS = [
        'why did my heart rate drift up?' => '~^your HR climbed as the run went on, which is ordinary over .+ km at .+\. it only starts to mean something when the pace stays flat while the number keeps rising\.$~',
        'what does my decoupling say about my base?' => '~^your pace and HR stayed close to each other across .+ km\. that is what an honest aerobic base looks like, so keep spending time here\.$~',
        'how did I finish faster than I started?' => '~^you ran the back half quicker than the front over .+ km\. starting under control and finishing strong is the pattern worth repeating\.$~',
        'why did my cadence fall off?' => '~^your step rate sagged late on\. that usually tracks fatigue rather than form, and it fits a .+ km effort at .+\.$~',
        'which km cost me the most?' => '~^your slowest kilometre sat well off your .+ average\. one heavy split inside .+ km is terrain or traffic more often than fitness\.$~',
        'was this harder than it should have been?' => '~^most of this sat below threshold, so .+ reads as controlled rather than a day you overreached\.$~',
        'how much did the heat cost me?' => '~^the heat took a cut of your pace here\. holding .+ for .+ km in those conditions is worth more than the raw number suggests\.$~',
        'how much did the climbing slow me down?' => '~^the climbing is what shaped your .+ average\. put the same effort on a flat route and it reads quicker\.$~',
        'how does this one compare to my usual?' => '~^over .+ km at .+, nothing here is off\. it reads like the rest of your recent work, so treat it as a normal day rather than a signal\.$~',
    ];

    /** @contract-migration */
    public function up(): void
    {
        foreach (DB::table('users')->where('is_demo', true)->pluck('id') as $demoId) {
            $this->purgeVisitorQuestions((int) $demoId);

            DB::table('race_goals')->where('user_id', $demoId)->update(['name' => self::SEEDED_RACE_NAME]);
            DB::table('telegram_connections')->where('user_id', $demoId)->delete();
        }
    }

    public function down(): void
    {
    }

    private function purgeVisitorQuestions(int $demoId): void
    {
        $visitorIds = DB::table('run_questions')
            ->where('user_id', $demoId)
            ->select(['id', 'question', 'answer'])
            ->lazyById(1000)
            ->reject(fn (object $row): bool => self::isSeeded((string) $row->question, $row->answer))
            ->map(fn (object $row): int => (int) $row->id)
            ->values()
            ->all();

        foreach (array_chunk($visitorIds, 500) as $chunk) {
            DB::table('run_questions')->whereIn('id', $chunk)->delete();
        }
    }

    private static function isSeeded(string $question, mixed $answer): bool
    {
        $pattern = self::SEEDED_ANSWER_PATTERNS[$question] ?? null;

        return $pattern !== null && is_string($answer) && preg_match($pattern, $answer) === 1;
    }
};
