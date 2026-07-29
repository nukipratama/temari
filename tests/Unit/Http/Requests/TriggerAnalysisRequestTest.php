<?php

declare(strict_types=1);

use App\Http\Requests\TriggerAnalysisRequest;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Facades\Validator;

/**
 * The discriminator rule is resolved from the `{type}` route segment, so the
 * rules can only be built against a request that carries a route resolver.
 *
 * @return array<string, mixed>
 */
function triggerAnalysisRules(string $type): array
{
    $request = TriggerAnalysisRequest::create("/api/analyses/{$type}/1/trigger", 'POST');
    $request->setRouteResolver(fn () => new readonly class ($type) {
        public function __construct(private string $type)
        {
        }

        public function parameter(string $key): ?string
        {
            return ['type' => $this->type, 'subjectId' => '1'][$key] ?? null;
        }
    });

    return $request->rules();
}

function triggerAnalysisPasses(string $type, mixed $discriminator): bool
{
    $data = ['type' => $type, 'subjectId' => 1];
    if ($discriminator !== null) {
        $data['discriminator'] = $discriminator;
    }

    return Validator::make($data, triggerAnalysisRules($type))->passes();
}

it('authorizes the request (ownership is enforced in the controller)', function (): void {
    expect(new TriggerAnalysisRequest()->authorize())->toBeTrue();
});

it('folds the route segments into the validation payload', function (): void {
    $request = TriggerAnalysisRequest::create(
        '/api/analyses/briefing_mascot_voice/7/trigger?discriminator=2026-05-18',
        'POST',
    );
    $request->setRouteResolver(fn () => new class () {
        public function parameter(string $key): ?string
        {
            return ['type' => 'briefing_mascot_voice', 'subjectId' => '7'][$key] ?? null;
        }
    });

    expect($request->validationData())->toMatchArray([
        'type' => 'briefing_mascot_voice',
        'subjectId' => '7',
        'discriminator' => '2026-05-18',
    ]);
});

it('accepts every known analysis type against its enum rule', function (): void {
    foreach (AnalysisType::cases() as $case) {
        $validator = Validator::make(
            ['type' => $case->value, 'subjectId' => 1],
            ['type' => new TriggerAnalysisRequest()->rules()['type']],
        );
        expect($validator->passes())->toBeTrue();
    }
});

it('rejects an unknown type and a non-positive subjectId', function (): void {
    expect(triggerAnalysisPasses('nonsense', null))->toBeFalse()
        ->and(Validator::make(
            ['type' => 'briefing_mascot_voice', 'subjectId' => 0],
            triggerAnalysisRules('briefing_mascot_voice'),
        )->passes())->toBeFalse();
});

it('stays permissive on the discriminator when the type is unknown, so the type rule owns the failure', function (): void {
    $validator = Validator::make(
        ['type' => 'nonsense', 'subjectId' => 1, 'discriminator' => 'anything'],
        triggerAnalysisRules('nonsense'),
    );

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->toArray())->toHaveKey('type')
        ->and($validator->errors()->toArray())->not->toHaveKey('discriminator');
});

it('accepts the discriminator shape its own dispatch sites write', function (string $type, ?string $discriminator): void {
    expect(triggerAnalysisPasses($type, $discriminator))->toBeTrue();
})->with([
    'briefing mascot voice day' => ['briefing_mascot_voice', '2026-05-18'],
    'featured kartu card id' => ['briefing_featured_kartu_voice', '42'],
    'persona summary ISO week' => ['persona_summary', '2026-W31'],
    'monthly recap month' => ['monthly_recap', '2026-05'],
    'weekly recap keys off the snapshot id' => ['weekly_recap', null],
    'card flavor keys off the card id' => ['card_flavor', null],
    'aku profile voice keys off the user id' => ['aku_profile_voice', null],
]);

/**
 * The cooldown key embeds the discriminator, so a novel value would mint a
 * fresh row and a fresh billed generation. Every shape outside the closed set
 * must be refused before it reaches the controller.
 */
it('rejects a novel or malformed discriminator', function (string $type, string $discriminator): void {
    expect(triggerAnalysisPasses($type, $discriminator))->toBeFalse();
})->with([
    'random string on a daily type' => ['briefing_mascot_voice', 'kEy9fQ2z'],
    'over-long value on a daily type' => ['briefing_mascot_voice', str_repeat('x', 65)],
    'a month where a day belongs' => ['briefing_mascot_voice', '2026-05'],
    'a non-date on the mascot voice day' => ['briefing_mascot_voice', 'yesterday'],
    'a day where a month belongs' => ['monthly_recap', '2026-05-18'],
    'a day where an ISO week belongs' => ['persona_summary', '2026-05-18'],
    'a malformed ISO week' => ['persona_summary', '2026-W3'],
    'a non-numeric featured card' => ['briefing_featured_kartu_voice', 'abc'],
    'a zero featured card id' => ['briefing_featured_kartu_voice', '0'],
    'a zero-padded featured card id' => ['briefing_featured_kartu_voice', '007'],
]);

/**
 * These types resolve their subject from `subject_id` alone and their jobs
 * ignore the discriminator entirely, so any value at all is junk that would
 * only ever sidestep the cooldown.
 */
it('rejects any discriminator on the types whose job ignores it', function (string $type): void {
    expect(triggerAnalysisPasses($type, 'anything'))->toBeFalse()
        ->and(triggerAnalysisPasses($type, '2026-05-18'))->toBeFalse();
})->with([
    'post_run_speech',
    'run_insight_technical',
    'run_insight_splits',
    'run_insight_zones',
    'weekly_recap',
    'pr_context',
    'card_flavor',
    'aku_profile_voice',
]);

it('requires the discriminator on the types keyed by one', function (string $type): void {
    expect(triggerAnalysisPasses($type, null))->toBeFalse();
})->with([
    'briefing_mascot_voice',
    'persona_summary',
    'monthly_recap',
    'briefing_featured_kartu_voice',
]);

it('normalizes an empty discriminator to null', function (): void {
    $request = TriggerAnalysisRequest::create('/x', 'POST', ['discriminator' => '']);
    $request->setValidator(Validator::make(
        ['discriminator' => ''],
        ['discriminator' => ['nullable', 'string']],
    ));

    expect($request->discriminator())->toBeNull();
});

it('returns a non-empty discriminator verbatim', function (): void {
    $request = TriggerAnalysisRequest::create('/x', 'POST', ['discriminator' => '2026-05-18']);
    $request->setValidator(Validator::make(
        ['discriminator' => '2026-05-18'],
        ['discriminator' => ['nullable', 'string']],
    ));

    expect($request->discriminator())->toBe('2026-05-18');
});
