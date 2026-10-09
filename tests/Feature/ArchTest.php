<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('app namespace stays free of debug helpers', function (): void {
    $helpers = ['dd', 'dump', 'ray', 'var_dump'];
    $calls = [];

    foreach (json_decode(File::get(base_path('composer.json')), true)['autoload']['psr-4'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            $tokens = array_values(array_filter(
                PhpToken::tokenize($file->getContents()),
                fn (PhpToken $token): bool => ! $token->isIgnorable(),
            ));

            foreach ($tokens as $i => $token) {
                if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                    && in_array(strtolower(ltrim($token->text, '\\')), $helpers, true)
                    && ($tokens[$i + 1] ?? null)?->text === '('
                    && ! ($tokens[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST])) {
                    $calls[] = $directory.$file->getRelativePathname().':'.$token->line;
                }
            }
        }
    }

    expect($calls)->toBe([]);
})->group('structure');
