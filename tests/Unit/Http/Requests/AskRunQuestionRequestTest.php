<?php

declare(strict_types=1);

use App\Http\Requests\AskRunQuestionRequest;
use App\Models\AI\RunQuestion;
use Illuminate\Support\Facades\Validator;

function askRules(): array
{
    return new AskRunQuestionRequest()->rules();
}

it('requires a question of at least a few characters', function (mixed $question): void {
    expect(Validator::make(['question' => $question], askRules())->fails())->toBeTrue();
})->with([
    'missing' => [null],
    'blank' => [''],
    'too short' => ['hi'],
    'not a string' => [['why']],
    'longer than the column' => [str_repeat('a', RunQuestion::MAX_QUESTION_LENGTH + 1)],
]);

it('accepts a question that fits the column', function (): void {
    $question = str_repeat('a', RunQuestion::MAX_QUESTION_LENGTH);

    expect(Validator::make(['question' => $question], askRules())->fails())->toBeFalse();
});

it('trims the question it hands the controller', function (): void {
    $request = AskRunQuestionRequest::create('/', 'POST', ['question' => '  why did my HR drift?  ']);
    $request->setContainer(app())->validateResolved();

    expect($request->question())->toBe('why did my HR drift?');
});

it('authorizes everyone, leaving ownership to the controller', function (): void {
    expect(new AskRunQuestionRequest()->authorize())->toBeTrue();
});
