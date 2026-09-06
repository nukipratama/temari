<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * The subject this analysis row describes no longer exists, and nothing will
 * ever recreate it — so the row is obsolete rather than failed.
 *
 * Distinct from {@see UnavailableException}, which means generation did not
 * succeed *this time*. A row that fails is worth retrying and worth surfacing;
 * an obsolete one is neither. It is deleted by {@see \App\Jobs\AI\AnalyzeRowJob},
 * because leaving it Failed shows the athlete a block that is "still
 * auto-retrying" behind a Try again button that cannot ever succeed.
 *
 * Deliberately does NOT extend UnavailableException: {@see \App\Jobs\AI\AnalyzeGroupJob}
 * catches that one to skip a member, which would swallow this meaning.
 */
class ObsoleteAnalysisException extends RuntimeException
{
}
