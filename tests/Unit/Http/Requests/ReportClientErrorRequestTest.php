<?php

declare(strict_types=1);

use App\Http\Requests\ReportClientErrorRequest;
use Illuminate\Support\Facades\Validator;

function passesClientError(array $data): bool
{
    return Validator::make($data, new ReportClientErrorRequest()->rules())->passes();
}

it('authorizes the request', function (): void {
    expect(new ReportClientErrorRequest()->authorize())->toBeTrue();
});

it('accepts a message with the optional fields', function (): void {
    expect(passesClientError([
        'message' => 'Boom',
        'stack' => 'at foo (app.tsx:1)',
        'url' => 'https://temari.test/activities',
        'componentStack' => 'in RunPage',
    ]))->toBeTrue();
});

it('accepts a message alone', function (): void {
    expect(passesClientError(['message' => 'Boom']))->toBeTrue();
});

it('rejects a missing message', function (): void {
    expect(passesClientError([]))->toBeFalse();
});

it('rejects a message over the length limit', function (): void {
    expect(passesClientError(['message' => str_repeat('a', 1001)]))->toBeFalse();
});
