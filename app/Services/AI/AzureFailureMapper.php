<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Responses\Responses\CreateResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The Azure failure taxonomy every request is read through: which throwables are
 * transient, which are terminal, which are content-filter rejections, and which
 * count as a config/auth misconfiguration.
 */
final class AzureFailureMapper
{
    /**
     * Classify an Azure OpenAI throwable. Rate-limit (429), server error (5xx),
     * and connection/timeout failures are transient and should let the queue
     * retry; everything else is terminal and fails the row.
     */
    public static function map(Throwable $e): Throwable
    {
        $message = 'Azure OpenAI call failed: '.$e->getMessage();

        // A content-filter rejection is an input-driven terminal 400: retrying
        // the same prompt just re-trips the filter. Surface the distinct type so
        // the caller can strip continuity context and retry, and the job can
        // degrade to rule-based content instead of dead-lettering.
        if (self::isContentFilter($e)) {
            return new ContentFilterException($message, previous: $e);
        }

        $response = self::transientResponse($e);

        if ($response === false) {
            return new UnavailableException($message, previous: $e);
        }

        return new TransientUpstreamException(
            $message,
            $response !== null ? self::retryAfterSeconds($response) : null,
            $e,
        );
    }

    /**
     * Whether $e is an Azure *config/auth* failure: a permanent 401/403 (wrong
     * API key / deployment access) or a connection/DNS/timeout failure (wrong
     * base URL/host). These feed the config circuit breaker; a single one is
     * still transient, the breaker's consecutive-failure streak is what
     * distinguishes a persistent misconfig from a one-off blip.
     */
    public static function isConfigAuthFailure(Throwable $e): bool
    {
        if ($e instanceof ErrorException && in_array($e->getStatusCode(), [401, 403], true)) {
            return true;
        }

        return $e instanceof TransporterException;
    }

    /**
     * Whether Azure filtered the *output*: a 200 response marked incomplete with
     * an explicit content_filter reason, the output-side twin of the thrown
     * input-side content_filter 400.
     */
    public static function isOutputContentFiltered(CreateResponse $response): bool
    {
        return $response->status === AzureResponseStatus::Incomplete->value
            && $response->incompleteDetails?->reason === 'content_filter';
    }

    /**
     * Whether $e is an Azure content-filter rejection. Detected primarily by the
     * error code (`content_filter`), with a defensive substring fallback on the
     * message for the prose forms Azure sometimes returns without the code.
     */
    private static function isContentFilter(Throwable $e): bool
    {
        if (! $e instanceof ErrorException) {
            return false;
        }

        if ($e->getErrorCode() === 'content_filter') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'content management policy')
            || str_contains($message, 'content_filter');
    }

    /**
     * Resolve whether $e is a transient upstream failure, returning its HTTP
     * response (for `Retry-After`), `null` when transient but response-less
     * (connection/timeout), or `false` when the failure is terminal.
     */
    private static function transientResponse(Throwable $e): ResponseInterface|null|false
    {
        if ($e instanceof RateLimitException || $e instanceof ServerException) {
            return $e->response;
        }

        if ($e instanceof ErrorException && ($e->getStatusCode() === 429 || $e->getStatusCode() >= 500)) {
            return $e->response;
        }

        // TransporterException = connection refused / DNS / read timeout: transient
        // but response-less. Anything else is a terminal (permanent) failure.
        return $e instanceof TransporterException ? null : false;
    }

    /**
     * Read Azure's `Retry-After` header (delta-seconds form) if present.
     */
    private static function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));
        if ($header === '' || ! ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }
}
