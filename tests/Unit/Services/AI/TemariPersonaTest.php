<?php

declare(strict_types=1);

use App\Services\AI\TemariPersona;

function temariPrompt(): string
{
    return TemariPersona::systemPrompt();
}

/*
 * MANUAL VOICE SPOT-CHECK (run after meaningful persona edits):
 *  - Hit /dashboard logged in as a user with recent activity and read the
 *    Briefing Temari card. Voice should be first-person ("I" / "you"), dry,
 *    dry, and anchored on a number that moved. No corporate
 *    cheer, no exclamation point on an ordinary day, no profanity/ALL CAPS.
 *  - Open a recent run at /activities/{id} and read all 4 thread entries
 *    (run story, technical translation, split highlight, HR zone). Same
 *    voice across all four — they're produced by different narrators but
 *    should sound like the same character.
 *  - Open /history and read the weekly recap narrative + trend caption.
 *    The week-over-week comparison is the centrepiece, not a footnote.
 *  - Open /profile and read the PR context flavor lines.
 *  - Open a run's share card and read its card flavor.
 *
 *  What counts as drift now, beyond stiffness: praise handed out by default,
 *  a warm closer bolted onto a block whose numbers didn't earn one, or a
 *  coast that got named twice, or a capital where a headline should be
 *  lowercase (and the reverse for a multi-sentence body).
 *
 *  Voice drift = persona prompt needs tightening. Reasoning lives in the
 *  persona prompt body comments — keep it the single source of truth.
 */

it('exposes the full persona system message', function (): void {
    expect(temariPrompt())->toBeString()->and(temariPrompt())->not->toBe('');
});

it('persona prompt wording: who Temari is and how it addresses the user', function (string ...$needles): void {
    foreach ($needles as $needle) {
        expect(temariPrompt())->toContain($needle);
    }
})->with([
    'introduces Temari in first person as the partner who keeps score' => ["I'm temari", 'I keep score'],
    'pins the only opponent to the user themselves, never another runner' => ['# Keeping score', 'The only opponent is a past version of the user', 'no percentiles, no cross-user anything'],
    // The register shift is only safe if the hard exclusion list travels with
    // it: calling a fatigued or overreaching runner lazy is the failure mode
    // this whole section exists to prevent.
    'lets Temari name a coast, but never when the data explains it' => ['# Calling a coast', 'NEVER call it a coast when the data gives a real reason', 'the first run back after a break'],
    'keeps Temari a friend rather than software' => ['NEVER call myself an AI', 'never explain how I work'],
    'bans corporate cheer outright and rations exclamation points' => ['No corporate cheer', "you've got this", 'Exclamation points are effectively banned'],
    'locks the address forms — I for Temari, you for the user' => ['Refer to myself as "I"', 'Refer to the user as "you"'],
]);

it('keeps the Daybreak mood vocabulary inline so narrators reuse it verbatim', function (): void {
    foreach (['blazing', 'easy', 'wobbly', 'gassed', 'overloaded', 'chill'] as $mood) {
        expect(temariPrompt())->toContain($mood);
    }
});

it('persona prompt wording: voice rules, formats and guards', function (string ...$needles): void {
    foreach ($needles as $needle) {
        expect(temariPrompt())->toContain($needle);
    }
})->with([
    'allows one bold emphasis but bans other markdown, em-dash, and clinical phrasing' => ['**Bold**', 'NO other markdown', 'em dash', 'clinical third person'],
    'draws a hard line at profanity and shouting while allowing casual contractions' => ['Hard line, never cross it', 'no profanity or crude slang', 'ALL CAPS', '"you\'re"', '"don\'t"'],
    'makes encouragement soft and optional, not a forced beat' => ['OPTIONAL', "Don't force a positive note"],
    'tells narrators to vary openers and never open with a continuity connector' => ['Opening & variation', 'still riding that', 'Vary how you open'],
    'forbids preachy / coach-mode phrasing like "you have to"' => ['NEVER lecture or preach', '"you have to"'],
    'grounds Temari in Indonesian running context' => ['Early-morning runs are common', '31°C', 'Rain is scheduled'],
    // Prod: a run whose weather lookup timed out was narrated as "warm, humid
    // evening work"; the climate cues above stood in for the missing reading.
    'keeps climate cues from standing in for a missing weather reading' => ['background, never a reading', 'no weather_temp_c', 'warm, humid'],
    // Prod output said "volume ramp dropped a lot" and "session intent-nya
    // memang easy": column names read aloud as if they were words.
    'forbids speaking internal field names, tidied or not' => ['Data field names', 'session_intent', 'your volume ramp'],
    'pins numbers to period formatting so blocks stop disagreeing' => ['Decimals use a PERIOD', '90.3%'],
    'asks for training-load jargon to be translated rather than dropped bare' => ['Training-load jargon', "what's normal for you"],
    // Prod output said "mayoritas waktunya memang stay di Z2". The allow-list
    // covers running terms; it never said the verbs around them stay plain.
    'keeps distinct terms to the nouns, so verbs stop leaking in' => ['the NOUN, not the verb', 'camping in Z2'],
    'caps decimals at one place so tool precision stops leaking through' => ['MAX one digit after the decimal point', '21.4 km'],
    // Validated against prod: on a run with no HR the model correctly refused to
    // invent one, then told the user so -- announcing, in effect, "the HR zone
    // data would not read, so I am not going to make one up" -- in three of four
    // blocks. It obeyed the don't-invent half and ignored the don't-announce
    // half, so both are stated as separate hard rules with the observed
    // sentences as the anti-examples. (Observed pre-swap, hence originally in
    // Indonesian; the rules themselves are language-independent.)
    'forbids announcing missing data, not just inventing it' => ['Two rules, both hard', 'NEVER announce it', "Cadence isn't showing up", "I don't want to guess"],
    'forbids narrating its own reading process to the user' => ["narrate your own process", "talking to me"],
]);

it('persona prompt fixes the casing rule instead of leaving it a tendency', function (): void {
    expect(temariPrompt())
        ->toContain('Casing is a fixed rule')
        ->toContain('A headline or a one-sentence output')
        ->toContain('is lowercase')
        ->toContain('two or more sentences uses normal sentence case')
        ->toContain('one output never mixes the two styles')
        ->toContain('Real names and terms keep their capitals everywhere')
        ->toContain('The name temari stays lowercase even at the start of a sentence')
        ->toContain('The pronoun is "I" in sentence-case prose and "i" in a lowercase headline')
        ->toContain('NEVER capitalize a whole word for emphasis')
        ->and(temariPrompt())->not->toContain('Lowercase-leaning')
        ->and(temariPrompt())->not->toContain('It\'s a tendency, not a rule')
        ->and(temariPrompt())->not->toContain('break it whenever a capital reads better');
});

it('persona prompt caps slang contractions at one per output and bans them from headlines', function (): void {
    expect(temariPrompt())
        ->toContain('"gonna", "kinda", "gotta") are allowed at most once per output and never in a headline')
        ->and(temariPrompt())->not->toContain('"it\'s", "don\'t", "gonna", "kinda", "gotta". Use them');
});

it('persona prompt examples follow the casing rule', function (): void {
    expect(temariPrompt())
        ->toContain('"Three easy runs, three weeks straight. Your legs could do this')
        ->toContain('"Two runs this week, four last week. The scoreboard noticed."')
        ->toContain('Right: "You\'re wiped. Take today off."')
        ->toContain('"Longest you\'ve ever gone. By 1.8 km, so it wasn\'t close."')
        ->toContain('"Easy pace, short distance. A light start to the day."')
        ->and(temariPrompt())->not->toContain('"three easy runs, three weeks straight.')
        ->and(temariPrompt())->not->toContain('"you\'re wiped. take today off."');
});

it('mandates English output regardless of tool results, prior narration, or Indonesian context cues', function (): void {
    // An explicit language mandate sits near the top of the prompt, before any
    // other guidance (including the cultural context section, which could
    // otherwise read as license to answer in Indonesian).
    expect(temariPrompt())
        ->toContain('# Language')
        ->toContain('Always write in English')
        ->toContain('prev_narrative')
        ->toContain('content to riff off, not a language to continue in');

    $languageAt = mb_strpos(temariPrompt(), '# Language');
    $cultureAt = mb_strpos(temariPrompt(), '# Cultural awareness');

    expect($languageAt)->toBeInt()
        ->and($cultureAt)->toBeInt();
    assert($languageAt !== false && $cultureAt !== false);
    expect($languageAt)->toBeLessThan($cultureAt);
});
