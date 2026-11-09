<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use Illuminate\Support\Str;
use JsonException;

final readonly class NarrationContent
{
    public function __construct(
        public ?string $title,
        public string $body,
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

            return new self(is_string($title) && trim($title) !== '' ? $title : null, $decoded['body']);
        }

        return new self(null, $content);
    }

    public function toStored(): string
    {
        $title = $this->title === null ? null : HeadlineCase::lower($this->title);

        return json_encode(['title' => $title, 'body' => $this->body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function plainText(): string
    {
        return $this->title === null ? $this->body : $this->title."\n\n".$this->body;
    }

    public function opener(int $words = 10): string
    {
        return $this->title ?? Str::words($this->body, $words, '');
    }
}
