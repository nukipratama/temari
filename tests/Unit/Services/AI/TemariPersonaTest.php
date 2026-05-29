<?php

declare(strict_types=1);

use App\Services\AI\TemariPersona;

/*
 * MANUAL VOICE SPOT-CHECK (run after meaningful persona edits):
 *  - Hit /dashboard logged in as a user with recent activity and read the
 *    Briefing Temari card. Voice should be first-person Temari ("aku" /
 *    "kamu"), hangat, santai khas obrolan Jakarta, garis merah di lo/gue.
 *  - Open a recent run at /aktivitas/{id} and read all 4 thread entries
 *    (Cerita lari ini, Terjemahan teknis, Split highlight, HR zone). Same
 *    voice across all four — they're produced by different narrators but
 *    should sound like the same character.
 *  - Open /aktivitas + /kalender and read the weekly recap narrative + trend caption.
 *  - Open /rekor and read the PR context flavor lines.
 *  - Open /kartu and read the card flavor on the spotlight card.
 *
 *  Voice drift = persona prompt needs tightening. Reasoning lives in the
 *  persona prompt body comments — keep it the single source of truth.
 */

it('exposes the full persona system message', function (): void {
    $prompt = TemariPersona::systemPrompt();

    expect($prompt)->toBeString()->not->toBe('');
});

it('introduces Temari in first person and as a teman lari', function (): void {
    $prompt = TemariPersona::systemPrompt();

    expect($prompt)
        ->toContain('Aku adalah Temari')
        ->toContain('teman lari');
});

it('locks the address forms — aku for Temari, kamu for the user', function (): void {
    $prompt = TemariPersona::systemPrompt();

    expect($prompt)
        ->toContain('Sebut diriku "aku"')
        ->toContain('Sebut pengguna "kamu"');
});

it('keeps the Daybreak mood vocabulary inline so narrators reuse it verbatim', function (): void {
    $prompt = TemariPersona::systemPrompt();

    foreach (['nyala', 'enteng', 'oleng', 'lemes', 'mumet', 'adem'] as $mood) {
        expect($prompt)->toContain($mood);
    }
});

it('allows one bold emphasis but bans other markdown, em-dash, and clinical phrasing', function (): void {
    $prompt = TemariPersona::systemPrompt();

    expect($prompt)
        ->toContain('**bold**')
        ->toContain('JANGAN markdown lain')
        ->toContain('em dash')
        ->toContain('orang ketiga');
});

it('draws the slang bright line at lo/gue while allowing casual Jakarta vocab', function (): void {
    $prompt = TemariPersona::systemPrompt();

    expect($prompt)
        ->toContain('Garis merah')
        ->toContain('"lo"')
        ->toContain('"gue"')
        ->toContain('"udah"')
        ->toContain('"gak"');
});

it('forbids preachy / coach-mode phrasing like "kamu harus"', function (): void {
    $prompt = TemariPersona::systemPrompt();

    expect($prompt)
        ->toContain('JANGAN menggurui')
        ->toContain('"kamu harus"');
});

it('grounds Temari in Indonesian running context', function (): void {
    $prompt = TemariPersona::systemPrompt();

    expect($prompt)
        ->toContain('Lari subuh')
        ->toContain('31°C')
        ->toContain('hujan');
});
