<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use Illuminate\Support\Str;
use JsonException;

final readonly class NarrationContent
{
    /**
     * @param  list<array{anchor: string, text: string, value: string|null, delta: string|null}>|null  $claims
     */
    public function __construct(
        public ?string $title,
        public string $body,
        public ?array $claims = null,
    ) {
    }

    public static function fromStored(?string $content): ?self
    {
        if ($content === null) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self(null, $content);
        }

        if (is_array($decoded) && is_string($decoded['body'] ?? null)) {
            $title = $decoded['title'] ?? null;

            return new self(
                is_string($title) && trim($title) !== '' ? $title : null,
                $decoded['body'],
                self::claimsFrom($decoded['claims'] ?? null),
            );
        }

        return new self(null, $content);
    }

    public function toStored(): string
    {
        $title = $this->title === null ? null : HeadlineCase::lower($this->title);
        $stored = ['title' => $title, 'body' => $this->body];
        if ($this->claims !== null) {
            $stored['claims'] = $this->claims;
        }

        return json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function plainText(): string
    {
        return $this->title === null ? $this->body : $this->title."\n\n".$this->body;
    }

    public function opener(int $words = 10): string
    {
        return $this->title ?? Str::words($this->body, $words, '');
    }

    /** @return list<array{anchor: string, text: string, value: string|null, delta: string|null}>|null */
    private static function claimsFrom(mixed $claims): ?array
    {
        if (! is_array($claims) || ! array_is_list($claims)) {
            return null;
        }

        $read = [];
        foreach ($claims as $claim) {
            if (! is_array($claim) || ! is_string($claim['anchor'] ?? null) || ! is_string($claim['text'] ?? null)) {
                continue;
            }

            $read[] = [
                'anchor' => $claim['anchor'],
                'text' => $claim['text'],
                'value' => is_string($claim['value'] ?? null) ? $claim['value'] : null,
                'delta' => is_string($claim['delta'] ?? null) ? $claim['delta'] : null,
            ];
        }

        return $read;
    }
}
