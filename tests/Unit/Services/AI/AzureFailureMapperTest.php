<?php

declare(strict_types=1);

use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Services\AI\AzureFailureMapper;
use GuzzleHttp\Psr7\Response as Psr7Response;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * @param  array<string, string>  $extra
 */
function azureError(int $status, string $message = 'boom', array $extra = [], array $headers = []): ErrorException
{
    return new ErrorException(
        ['message' => $message, 'type' => 'invalid_request_error'] + $extra,
        new Psr7Response($status, $headers),
    );
}

function transporterFailure(string $message = 'read timed out'): TransporterException
{
    return new TransporterException(new class ($message) extends RuntimeException implements ClientExceptionInterface {});
}

// ── content filter ────────────────────────────────────────────────────

it('maps an error carrying the content_filter code to a ContentFilterException', function (): void {
    $mapped = AzureFailureMapper::map(azureError(400, 'blocked', ['code' => 'content_filter']));

    expect($mapped)->toBeInstanceOf(ContentFilterException::class)
        ->and($mapped->getMessage())->toBe('Azure OpenAI call failed: blocked');
});

it('maps a content-filter prose message with no code to a ContentFilterException', function (): void {
    $mapped = AzureFailureMapper::map(azureError(400, 'The response was filtered due to Azure OpenAI content management policy.'));

    expect($mapped)->toBeInstanceOf(ContentFilterException::class);
});

it('detects the content_filter substring in the message as a fallback', function (): void {
    expect(AzureFailureMapper::map(azureError(400, 'rejected: CONTENT_FILTER triggered')))
        ->toBeInstanceOf(ContentFilterException::class);
});

it('never treats a non-ErrorException as a content filter', function (): void {
    expect(AzureFailureMapper::map(new RuntimeException('content management policy')))
        ->toBeInstanceOf(UnavailableException::class);
});

it('keeps the original throwable as the previous exception', function (): void {
    $original = azureError(400, 'blocked', ['code' => 'content_filter']);

    expect(AzureFailureMapper::map($original)->getPrevious())->toBe($original);
});

// ── transient vs terminal ─────────────────────────────────────────────

it('maps a RateLimitException to a transient failure carrying Retry-After', function (): void {
    $mapped = AzureFailureMapper::map(new RateLimitException(new Psr7Response(429, ['Retry-After' => '17'])));

    expect($mapped)->toBeInstanceOf(TransientUpstreamException::class)
        ->and($mapped->retryAfterSeconds)->toBe(17);
});

it('leaves Retry-After null when the header is absent', function (): void {
    expect(AzureFailureMapper::map(new RateLimitException(new Psr7Response(429)))->retryAfterSeconds)->toBeNull();
});

it('leaves Retry-After null when the header is an HTTP date rather than delta-seconds', function (): void {
    $mapped = AzureFailureMapper::map(new RateLimitException(new Psr7Response(429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'])));

    expect($mapped->retryAfterSeconds)->toBeNull();
});

it('maps a ServerException to a transient failure', function (): void {
    expect(AzureFailureMapper::map(new ServerException(new Psr7Response(503))))
        ->toBeInstanceOf(TransientUpstreamException::class);
});

it('maps an ErrorException carrying a 429 to a transient failure with its Retry-After', function (): void {
    $mapped = AzureFailureMapper::map(azureError(429, 'slow down', [], ['Retry-After' => '5']));

    expect($mapped)->toBeInstanceOf(TransientUpstreamException::class)
        ->and($mapped->retryAfterSeconds)->toBe(5);
});

it('maps an ErrorException carrying a 5xx to a transient failure', function (): void {
    expect(AzureFailureMapper::map(azureError(502)))->toBeInstanceOf(TransientUpstreamException::class);
});

it('maps a TransporterException to a transient failure with no delay', function (): void {
    $mapped = AzureFailureMapper::map(transporterFailure());

    expect($mapped)->toBeInstanceOf(TransientUpstreamException::class)
        ->and($mapped->retryAfterSeconds)->toBeNull();
});

it('keeps a permanent 4xx terminal', function (): void {
    expect(AzureFailureMapper::map(azureError(400, 'bad request')))->toBeInstanceOf(UnavailableException::class);
});

it('keeps an unrecognised throwable terminal', function (): void {
    expect(AzureFailureMapper::map(new RuntimeException('who knows')))->toBeInstanceOf(UnavailableException::class);
});

// ── config/auth failures ──────────────────────────────────────────────

it('counts a 401 as a config/auth failure', function (): void {
    expect(AzureFailureMapper::isConfigAuthFailure(azureError(401)))->toBeTrue();
});

it('counts a 403 as a config/auth failure', function (): void {
    expect(AzureFailureMapper::isConfigAuthFailure(azureError(403)))->toBeTrue();
});

it('counts a connection/DNS failure as a config/auth failure', function (): void {
    expect(AzureFailureMapper::isConfigAuthFailure(transporterFailure('could not resolve host')))->toBeTrue();
});

it('does not count a 429 or a 500 as a config/auth failure', function (): void {
    expect(AzureFailureMapper::isConfigAuthFailure(azureError(429)))->toBeFalse()
        ->and(AzureFailureMapper::isConfigAuthFailure(azureError(500)))->toBeFalse();
});

it('does not count an arbitrary throwable as a config/auth failure', function (): void {
    expect(AzureFailureMapper::isConfigAuthFailure(new RuntimeException('nope')))->toBeFalse();
});

// ── output-side content filter (200 + incomplete) ─────────────────────

it('flags a 200 response marked incomplete for content_filter', function (): void {
    expect(AzureFailureMapper::isOutputContentFiltered(fakeAzureResponse('', 'incomplete', 'content_filter')))->toBeTrue();
});

it('does not flag a length-truncated incomplete response as output-filtered', function (): void {
    expect(AzureFailureMapper::isOutputContentFiltered(fakeAzureResponse('{}', 'incomplete', 'max_output_tokens')))->toBeFalse();
});

it('does not flag a completed response as output-filtered', function (): void {
    expect(AzureFailureMapper::isOutputContentFiltered(fakeAzureResponse('{}')))->toBeFalse();
});
