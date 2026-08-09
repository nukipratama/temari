<?php

declare(strict_types=1);

use App\Services\AI\TemariPersona;

beforeEach(function (): void {
    $this->prompt = TemariPersona::systemPrompt();
});

/*
 * MANUAL VOICE SPOT-CHECK (run after meaningful persona edits):
 *  - Hit /dashboard logged in as a user with recent activity and read the
 *    Briefing Temari card. Voice should be first-person Temari ("I" /
 *    "you"), warm, casual global running-app register, no profanity/ALL CAPS.
 *  - Open a recent run at /aktivitas/{id} and read all 4 thread entries
 *    (run story, technical translation, split highlight, HR zone). Same
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
    expect($this->prompt)->toBeString()->not->toBe('');
});

it('introduces Temari in first person as the companion for every run', function (): void {
    expect($this->prompt)
        ->toContain("I'm Temari")
        ->toContain('runs alongside you');
});

it('locks the address forms — I for Temari, you for the user', function (): void {
    expect($this->prompt)
        ->toContain('Refer to myself as "I"')
        ->toContain('Refer to the user as "you"');
});

it('keeps the Daybreak mood vocabulary inline so narrators reuse it verbatim', function (): void {
    foreach (['nyala', 'enteng', 'oleng', 'lemes', 'mumet', 'adem'] as $mood) {
        expect($this->prompt)->toContain($mood);
    }
});

it('allows one bold emphasis but bans other markdown, em-dash, and clinical phrasing', function (): void {
    expect($this->prompt)
        ->toContain('**Bold**')
        ->toContain('NO other markdown')
        ->toContain('em dash')
        ->toContain('clinical third person');
});

it('draws a hard line at profanity and shouting while allowing casual contractions', function (): void {
    expect($this->prompt)
        ->toContain('Hard line, never cross it')
        ->toContain('no profanity or crude slang')
        ->toContain('ALL CAPS')
        ->toContain('"you\'re"')
        ->toContain('"gonna"');
});

it('makes encouragement soft and optional, not a forced beat', function (): void {
    expect($this->prompt)
        ->toContain('OPTIONAL')
        ->toContain("Don't force a positive note");
});

it('tells narrators to vary openers and never open with a continuity connector', function (): void {
    expect($this->prompt)
        ->toContain('Opening & variation')
        ->toContain('still riding that')
        ->toContain('Vary how you open');
});

it('forbids preachy / coach-mode phrasing like "you have to"', function (): void {
    expect($this->prompt)
        ->toContain('NEVER lecture or preach')
        ->toContain('"you have to"');
});

it('grounds Temari in Indonesian running context', function (): void {
    expect($this->prompt)
        ->toContain('Early-morning runs are common')
        ->toContain('31°C')
        ->toContain('Rain is scheduled');
});

it('forbids speaking internal field names, tidied or not', function (): void {
    // Prod output said "volume-ramp-nya turun banget" and "session intent-nya
    // memang easy": column names read aloud as if they were words.
    expect($this->prompt)
        ->toContain('Data field names')
        ->toContain('session_intent')
        ->toContain('your volume ramp');
});

it('pins numbers to Indonesian formatting so blocks stop disagreeing', function (): void {
    // The same day produced "24,7 detik" and "90.3% waktu": tool payloads carry
    // periods and were being copied straight through.
    expect($this->prompt)
        ->toContain('Decimals use a COMMA')
        ->toContain('90,3%')
        ->toContain("Don't mix the two styles");
});

it('asks for training-load jargon to be translated rather than dropped bare', function (): void {
    expect($this->prompt)
        ->toContain('Training-load jargon')
        ->toContain("what's normal for you");
});

it('keeps distinct terms to the nouns, so verbs stop leaking in', function (): void {
    // Prod output said "mayoritas waktunya memang stay di Z2". The allow-list
    // covers running terms; it never said the verbs around them stay plain.
    expect($this->prompt)
        ->toContain('the NOUN, not the verb')
        ->toContain('camping in Z2');
});

it('caps decimals at one place so tool precision stops leaking through', function (): void {
    // Prod monthly recap said "21,36 km": the comma rule landed, the rounding
    // one read as a suggestion ("cukup") and lost to the raw tool value.
    expect($this->prompt)
        ->toContain('MAX one digit after the comma')
        ->toContain('21,4 km');
});

it('forbids announcing missing data, not just inventing it', function (): void {
    // Validated against prod: on a run with no HR the model correctly refused to
    // invent one, then told the user so -- "Data HR zone-nya nggak kebaca, jadi
    // aku nggak mau ngarang" -- in three of four blocks. It obeyed the
    // don't-invent half and ignored the don't-announce half, so both are stated
    // as separate hard rules with the observed sentences as the anti-examples.
    expect($this->prompt)
        ->toContain('Two rules, both hard')
        ->toContain('NEVER announce it')
        ->toContain("Cadence isn't showing up")
        ->toContain("I don't want to guess");
});

it('forbids narrating its own reading process to the user', function (): void {
    expect($this->prompt)
        ->toContain("narrate your own process")
        ->toContain("talking to me");
});
