<?php

declare(strict_types=1);

namespace App\Services\AI\Anchor;

/**
 * The namespace a citation anchor lives in. An anchor is `<kind>:<value>` —
 * `split:4`, `zone:z3`, `metric:decoupling`.
 *
 * The grammar is defined once here and exported to TypeScript through
 * `artisan typescript:enums`, because both sides need it: the server decides
 * whether an anchor resolves against the subject's data, and the client decides
 * which element draws it. They used to hold separate regexes for the same
 * grammar with nothing keeping them honest.
 */
enum AnchorKind: string
{
    /** A whole kilometre of the run, 1-based. */
    case Split = 'split';

    /** One heart-rate zone, `z1` through `z5`. */
    case Zone = 'zone';

    /** A named derived reading, e.g. `decoupling`. */
    case Metric = 'metric';

    /** The value half of the grammar, without delimiters. */
    public function valuePattern(): string
    {
        return match ($this) {
            self::Split => '[1-9]\d*',
            self::Zone => 'z[1-5]',
            self::Metric => '[a-z_]+',
        };
    }

    /**
     * The value this anchor names, or null when it is not of this kind or is
     * malformed. Model output is untrusted, so a near-miss is a rejection.
     */
    public function valueIn(string $anchor): ?string
    {
        $matched = preg_match('/^'.$this->value.':('.$this->valuePattern().')$/', $anchor, $matches);

        return $matched === 1 ? $matches[1] : null;
    }

    /** @return array{0: self, 1: string}|null The kind and its value. */
    public static function parse(string $anchor): ?array
    {
        foreach (self::cases() as $kind) {
            $value = $kind->valueIn($anchor);
            if ($value !== null) {
                return [$kind, $value];
            }
        }

        return null;
    }
}
