<?php

declare(strict_types=1);

use App\Http\Requests\TriggerAnalysisRequest;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Facades\Validator;

it('authorizes the request (ownership is enforced in the controller)', function (): void {
    expect(new TriggerAnalysisRequest()->authorize())->toBeTrue();
});

it('folds the route segments into the validation payload', function (): void {
    $request = TriggerAnalysisRequest::create(
        '/api/analyses/briefing_headline/7/trigger?discriminator=2026-05-18',
        'POST',
    );
    $request->setRouteResolver(fn () => new class () {
        public function parameter(string $key): ?string
        {
            return ['type' => 'briefing_headline', 'subjectId' => '7'][$key] ?? null;
        }
    });

    expect($request->validationData())->toMatchArray([
        'type' => 'briefing_headline',
        'subjectId' => '7',
        'discriminator' => '2026-05-18',
    ]);
});

it('accepts every known analysis type against its enum rule', function (): void {
    foreach (AnalysisType::cases() as $case) {
        $validator = Validator::make(
            ['type' => $case->value, 'subjectId' => 1],
            new TriggerAnalysisRequest()->rules(),
        );
        expect($validator->passes())->toBeTrue();
    }
});

it('rejects an unknown type, a non-positive subjectId and an over-long discriminator', function (): void {
    $rules = new TriggerAnalysisRequest()->rules();

    expect(Validator::make(['type' => 'nonsense', 'subjectId' => 1], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['type' => 'briefing_headline', 'subjectId' => 0], $rules)->passes())->toBeFalse()
        ->and(Validator::make([
            'type' => 'briefing_headline',
            'subjectId' => 1,
            'discriminator' => str_repeat('x', 65),
        ], $rules)->passes())->toBeFalse();
});

it('allows a null / absent discriminator', function (): void {
    $validator = Validator::make(
        ['type' => 'briefing_headline', 'subjectId' => 1],
        new TriggerAnalysisRequest()->rules(),
    );

    expect($validator->passes())->toBeTrue();
});

it('normalizes an empty discriminator to null', function (): void {
    $request = TriggerAnalysisRequest::create('/x', 'POST', ['discriminator' => '']);
    $request->setValidator(Validator::make(
        ['discriminator' => ''],
        ['discriminator' => ['nullable', 'string', 'max:64']],
    ));

    expect($request->discriminator())->toBeNull();
});

it('returns a non-empty discriminator verbatim', function (): void {
    $request = TriggerAnalysisRequest::create('/x', 'POST', ['discriminator' => 'abc']);
    $request->setValidator(Validator::make(
        ['discriminator' => 'abc'],
        ['discriminator' => ['nullable', 'string', 'max:64']],
    ));

    expect($request->discriminator())->toBe('abc');
});
