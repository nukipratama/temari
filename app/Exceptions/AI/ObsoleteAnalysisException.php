<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * The subject this analysis row describes no longer exists, and nothing will
 * ever recreate it — so the row is obsolete rather than failed.
 *
 * {@see \App\Jobs\AI\AnalyzeRowJob} deletes it rather than leave a Failed block
 * behind a Try again button that cannot ever succeed.
 *
 * Deliberately does NOT extend UnavailableException: {@see \App\Jobs\AI\AnalyzeGroupJob}
 * catches that one to skip a member, which would swallow this meaning.
 */
class ObsoleteAnalysisException extends RuntimeException
{
}
