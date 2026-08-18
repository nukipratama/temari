#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Indonesian-language guard.
 *
 * The app was rewritten from Indonesian to English with no i18n layer, and the
 * rename kept being declared finished while pockets survived: enum case names,
 * a React hook, and docs quoting labels that had stopped rendering. This guard
 * exists so the next pocket fails a build instead of being found by hand.
 *
 * It greps a curated word list over the source tree and fails on any hit that
 * is not covered by an explicit ALLOWED entry. Two rules keep it from decaying
 * into a blanket suppression that passes while blind:
 *
 *   1. An ALLOWED entry that matches nothing is an error. Exceptions have to be
 *      deleted when the thing they excused is gone, so the file cannot silently
 *      grow into "allow everything".
 *   2. Scanning zero files is an error. A broken glob fails loudly rather than
 *      reporting a clean sweep over nothing.
 *
 * Standalone: no Laravel boot. Run from anywhere: `php scripts/check-indonesian.php`.
 */

$root = dirname(__DIR__);

/**
 * Indonesian words seen in this repo's own history, plus the everyday vocabulary
 * a reintroduction would most likely arrive in. Words that are also English
 * ("sore", "ada") are deliberately absent — a guard that cries wolf gets muted.
 *
 * @var list<string>
 */
const WORDS = [
    // badge and card vocabulary
    'kartu', 'kilat', 'jauh', 'keras', 'santai', 'pendaki', 'perdana',
    'hari', 'panas', 'pejuang', 'hujan', 'anak', 'pagi', 'malam', 'dingin',
    'tahan', 'diri', 'lawan', 'angin', 'lencana', 'pencapaian', 'musim',
    // navigation and page names
    'kalender', 'pengaturan', 'aktivitas', 'catatan', 'riwayat', 'koleksi',
    'profil', 'akun', 'aksesori', 'jejak', 'rekor', 'kondisi', 'beranda',
    // actions and controls
    'kirim', 'dikirim', 'hubungkan', 'putuskan', 'matikan', 'kabarin',
    'hapus', 'simpan', 'batal', 'tutup', 'pilih', 'ubah', 'tambah', 'lihat',
    'mulai', 'selesai', 'coba', 'ulang', 'keluar', 'masuk',
    // domain words
    'lari', 'latihan', 'sesi', 'zona', 'rute', 'minggu', 'bulan', 'cerita',
    // common connectives that give Indonesian prose away
    'kamu', 'aku', 'yang', 'dengan', 'untuk', 'banget', 'belum', 'sudah',
    'tidak', 'jangan', 'kalau', 'nggak', 'adalah', 'otomatis', 'opsional',
    'kata', 'tentang', 'semua', 'misteri', 'napas', 'kaki', 'cepat', 'lambat',
];

/**
 * Known-surviving Indonesian, each with the reason it is still here. Keyed by
 * word; each value is a list of path prefixes (relative to the repo root) the
 * word is tolerated under, plus the reason.
 *
 * Adding an entry is a deliberate act with a written justification. Removing
 * one is mandatory once the hit is gone — see rule 1 above.
 *
 * @var array<string, list<array{prefix: string, reason: string}>>
 */
const ALLOWED = [
    'kartu' => [
        ['prefix' => '', 'reason' => 'Outstanding pocket: Kartu components, ShareKartuData, the persisted AnalysisType value briefing_featured_kartu_voice, the featuredKartuVoice Inertia prop and the AZURE_OPENAI_BRIEFING_FEATURED_KARTU_VOICE_DEPLOYMENT env var. Needs a data migration and a prod env change, so it is its own slice.'],
    ],
    'aku' => [
        ['prefix' => '', 'reason' => 'Outstanding pocket: the persisted AnalysisType value aku_profile_voice and its narrator/job. Same data-migration shape as kartu.'],
    ],
    'rute' => [
        ['prefix' => '', 'reason' => "Outstanding pocket: the 'rute' share-card layout token, shared between RunCardImageRenderer and the client Layout union. Moves with the kartu slice."],
    ],
    'riwayat' => [
        ['prefix' => 'resources/js/hooks/useLastFilter.ts', 'reason' => "localStorage key temari:riwayat:last-filter, already written into real browsers; renaming it silently drops every user's saved filter."],
    ],
    'pengaturan' => [
        ['prefix' => 'routes/web.php', 'reason' => 'Legacy /pengaturan permanent redirect — a live URL that must keep resolving.'],
        ['prefix' => 'docs/features/settings.md', 'reason' => 'Describes that same legacy redirect.'],
        ['prefix' => 'tests/Feature/Http/Controllers/SettingsControllerTest.php', 'reason' => 'Covers that redirect.'],
        ['prefix' => 'docs/features/installed-app-shell.md', 'reason' => 'Quotes the breadcrumb "Aku · Pengaturan" as it read before it was corrected — true as written.'],
        ['prefix' => 'resources/js/pages/Settings/Index.test.tsx', 'reason' => 'Comment recording the page\'s past bare <h1>Pengaturan</h1> — true as written.'],
        ['prefix' => 'tests/Unit/Services/AI/MaintainerAlerterTest.php', 'reason' => 'Comment quoting retired copy; scheduled with the comment-quote sweep.'],
        ['prefix' => 'tests/Feature/Http/Controllers/HistoryControllerTest.php', 'reason' => 'Comment quoting retired copy; scheduled with the comment-quote sweep.'],
    ],
    'profil' => [
        ['prefix' => 'routes/web.php', 'reason' => 'Legacy /profil permanent redirect — a live URL.'],
        ['prefix' => 'docs/features/profile.md', 'reason' => 'Describes that same legacy redirect.'],
    ],
    'kalender' => [
        ['prefix' => 'routes/web.php', 'reason' => 'Legacy /kalender permanent redirect — a live URL.'],
        ['prefix' => 'tests/Feature/Http/Controllers/HistoryControllerTest.php', 'reason' => 'Test name still says "Kalender page"; harmless, folds into the comment-quote sweep.'],
        ['prefix' => 'tests/Unit/Services/AI/TemariPersonaTest.php', 'reason' => 'Manual-QA docblock listing old URLs; folds into the comment-quote sweep.'],
    ],
    'catatan' => [
        ['prefix' => 'routes/web.php', 'reason' => 'Legacy /catatan permanent redirect — a live URL.'],
        ['prefix' => 'tests/Feature/Runs/RunControllerTest.php', 'reason' => 'Covers that redirect.'],
    ],
    'akun' => [
        ['prefix' => 'routes/web.php', 'reason' => 'Legacy /akun permanent redirect — a live URL.'],
    ],
    'aksesori' => [
        ['prefix' => 'routes/web.php', 'reason' => 'Legacy /aksesori permanent redirect — a live URL.'],
    ],
    'rekor' => [
        ['prefix' => 'routes/web.php', 'reason' => 'Legacy /rekor permanent redirect — a live URL.'],
        ['prefix' => 'docs/features/records.md', 'reason' => 'Documents the /rekor legacy redirect route path, same as routes/web.php.'],
    ],
    'angin' => [
        ['prefix' => 'tests/Unit/Services/Run/Story/RunCardImageRendererTest.php', 'reason' => 'Regression test asserting the card no longer says "angin" — the word must stay for the assertion to mean anything.'],
    ],
];

/**
 * Indonesian narration still sitting in test fixtures — the strings that stand
 * in for LLM output, Strava activity names and special-move names. The narrators
 * emit English now, so this is stale test data and its own slice's work.
 *
 * Tolerated in test files only, and only for the *narration* vocabulary: an
 * identifier-shaped word (kalender, pengaturan, aksesori, a badge case name)
 * still fails inside a test, which is what would have caught useKalender and
 * the Badge enum. Deliberately not a blanket "skip tests/".
 *
 * @var list<string>
 */
const NARRATION_WORDS_IN_TESTS = [
    'banget', 'belum', 'sudah', 'tidak', 'nggak', 'yang', 'untuk', 'kamu',
    'kata', 'lari', 'minggu', 'bulan', 'hari', 'pagi', 'sesi', 'cerita',
    'santai', 'cepat', 'kaki', 'napas', 'masuk', 'keluar', 'mulai', 'lihat',
    'pilih', 'tutup', 'semua', 'misteri', 'otomatis', 'perdana', 'panas',
    'pejuang', 'zona', 'ulang', 'kirim', 'rekor', 'riwayat', 'aktivitas',
];

function isTestFile(string $relative): bool
{
    return str_starts_with($relative, 'tests/') || str_contains($relative, '.test.');
}

/**
 * Break identifiers into words so a word boundary exists where the reader sees
 * one. `\b` never fires inside `HariPanas`, `useKalender` or `briefing_featured_kartu_voice`,
 * which is how a plain grep guard passes over the exact pockets it was written
 * for — so split on camelCase humps and on _ - . / before matching.
 */
function segment(string $line): string
{
    return (string) preg_replace(
        ['/(?<=[a-z0-9])(?=[A-Z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/', '/[_\-.\/]+/'],
        ' ',
        $line,
    );
}

/**
 * Files and trees the guard does not read. `docs/decisions/` is immutable by
 * convention: an ADR records what was decided when it was decided, and its
 * quoted Indonesian labels are part of that record.
 *
 * @var list<string>
 */
const SKIPPED = [
    'docs/decisions/',
    'scripts/check-indonesian.php',
    // The rename migrations are the map from the old Indonesian slugs to the
    // English ones. They exist to name the old values, so every line is a hit.
    'database/migrations/2026_08_10_100000_rename_run_card_badges_to_english_slugs.php',
    'database/migrations/2026_08_10_100002_rename_user_unlock_keys_to_english.php',
];

/** @var list<string> */
const SCAN_DIRS = ['app', 'config', 'database', 'resources', 'routes', 'scripts', 'tests', 'docs'];

/** @var list<string> */
const SCAN_EXTENSIONS = ['php', 'ts', 'tsx', 'js', 'mjs', 'css', 'blade.php', 'md'];

/** @return list<string> repo-relative paths */
function collectFiles(string $root): array
{
    $files = [];

    foreach (SCAN_DIRS as $dir) {
        $base = $root.'/'.$dir;

        if (! is_dir($base)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            if (! endsWithAny($relative, SCAN_EXTENSIONS) || startsWithAny($relative, SKIPPED)) {
                continue;
            }

            $files[] = $relative;
        }
    }

    sort($files);

    return $files;
}

/** @param list<string> $suffixes */
function endsWithAny(string $haystack, array $suffixes): bool
{
    return array_any($suffixes, fn ($suffix) => str_ends_with($haystack, '.'.$suffix));
}

/** @param list<string> $prefixes */
function startsWithAny(string $haystack, array $prefixes): bool
{
    return array_any($prefixes, fn ($prefix) => str_starts_with($haystack, (string) $prefix));
}

$files = collectFiles($root);

if ($files === []) {
    fwrite(STDERR, "check-indonesian: scanned 0 files — the globs are broken, not the tree clean.\n");
    exit(1);
}

$pattern = '/\b('.implode('|', WORDS).')\b/i';

/** @var list<string> */
$violations = [];
/** Whether NARRATION_WORDS_IN_TESTS still excuses anything; false means delete it. */
$narrationInTests = false;
/** @var array<string, bool> word|prefix => used */
$allowanceUsed = [];

foreach (ALLOWED as $word => $entries) {
    foreach ($entries as $entry) {
        $allowanceUsed[$word.'|'.$entry['prefix']] = false;
    }
}

foreach ($files as $relative) {
    $contents = file_get_contents($root.'/'.$relative);

    if ($contents === false) {
        continue;
    }

    foreach (explode("\n", $contents) as $number => $line) {
        if (preg_match_all($pattern, segment($line), $matches) === 0) {
            continue;
        }

        foreach (array_unique(array_map(strtolower(...), $matches[1])) as $word) {
            if (isTestFile($relative) && in_array($word, NARRATION_WORDS_IN_TESTS, true)) {
                $narrationInTests = true;

                continue;
            }

            $allowed = false;

            foreach (ALLOWED[$word] ?? [] as $entry) {
                if ($entry['prefix'] === '' || str_starts_with($relative, $entry['prefix'])) {
                    $allowanceUsed[$word.'|'.$entry['prefix']] = true;
                    $allowed = true;
                }
            }

            if (! $allowed) {
                $violations[] = sprintf('%s:%d  "%s"  %s', $relative, $number + 1, $word, trim($line));
            }
        }
    }
}

/** @var list<string> */
$stale = [];

foreach ($allowanceUsed as $key => $used) {
    if (! $used) {
        [$word, $prefix] = explode('|', $key, 2);
        $stale[] = sprintf('%s under "%s"', $word, $prefix === '' ? '(anywhere)' : $prefix);
    }
}

if (! $narrationInTests) {
    $stale[] = 'the whole NARRATION_WORDS_IN_TESTS list — the test fixtures are clean now';
}

if ($violations === [] && $stale === []) {
    printf("Indonesian guard: %d files scanned, no unlisted Indonesian ✓\n", count($files));
    exit(0);
}

if ($violations !== []) {
    fwrite(STDERR, sprintf("\nUnlisted Indonesian (%d):\n\n", count($violations)));

    foreach ($violations as $violation) {
        fwrite(STDERR, '  '.$violation."\n");
    }

    fwrite(STDERR, "\nThe app is English-only with no i18n layer. Rename it, or add an ALLOWED\nentry in scripts/check-indonesian.php saying why it has to stay.\n");
}

if ($stale !== []) {
    fwrite(STDERR, sprintf("\nALLOWED entries that no longer match anything (%d):\n\n", count($stale)));

    foreach ($stale as $entry) {
        fwrite(STDERR, '  '.$entry."\n");
    }

    fwrite(STDERR, "\nDelete them. An exception nobody needs is how this guard goes blind.\n");
}

exit(1);
