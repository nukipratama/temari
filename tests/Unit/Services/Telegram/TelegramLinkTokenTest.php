<?php

declare(strict_types=1);

use App\Services\Telegram\Exceptions\TelegramLinkTokenException;
use App\Services\Telegram\TelegramLinkToken;

it('round-trips a minted token back to the user id', function (): void {
    $token = new TelegramLinkToken();

    expect($token->userId($token->mint(42)))->toBe(42);
});

it('fits within Telegram\'s deep-link start payload limits', function (): void {
    // Telegram allows at most 64 chars and only [A-Za-z0-9_-] in the start param.
    $minted = (new TelegramLinkToken())->mint(2_000_000_000);

    expect(strlen($minted))->toBeLessThanOrEqual(64)
        ->and($minted)->toMatch('/^[A-Za-z0-9_-]+$/');
});

it('rejects a token that has already been consumed', function (): void {
    $token = new TelegramLinkToken();
    $minted = $token->mint(7);

    expect($token->userId($minted))->toBe(7);

    $token->consume($minted);

    try {
        $token->userId($minted);
        $this->fail('Expected TelegramLinkTokenException was not thrown.');
    } catch (TelegramLinkTokenException $e) {
        expect($e->expired)->toBeTrue();
    }
});

it('rejects a token whose signature was tampered with', function (): void {
    $token = new TelegramLinkToken();
    $minted = $token->mint(7);
    // Flip a leading char: unlike the trailing char (whose low bits are padding
    // and can decode identically), this reliably alters a signed body byte.
    $tampered = ($minted[0] === 'A' ? 'B' : 'A') . substr($minted, 1);

    expect(fn () => $token->userId($tampered))->toThrow(TelegramLinkTokenException::class);
});

it('throws an expired exception once the TTL has passed', function (): void {
    $token = new TelegramLinkToken();

    $minted = $this->travelTo(now()->subHours(2), fn (): string => $token->mint(7));

    try {
        $token->userId($minted);
        $this->fail('Expected TelegramLinkTokenException was not thrown.');
    } catch (TelegramLinkTokenException $e) {
        expect($e->expired)->toBeTrue();
    }
});

it('throws a non-expired exception for an undecryptable token', function (): void {
    $token = new TelegramLinkToken();

    try {
        $token->userId('not-a-real-token');
        $this->fail('Expected TelegramLinkTokenException was not thrown.');
    } catch (TelegramLinkTokenException $e) {
        expect($e->expired)->toBeFalse();
    }
});
