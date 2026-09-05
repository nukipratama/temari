#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * {@see} reference guard.
 *
 * Fails if a `{@see ...}` tag in PHP source names an App class, method, property,
 * constant or enum case that does not exist — the docblock equivalent of a broken
 * link, and invisible to PHPStan, which does not read {@see} bodies.
 *
 * Which symbol a tag names is a source-text question (`self` means the enclosing
 * class, a leading `\` is a FQCN, a bare name resolves through the file's `use`
 * imports and then its own namespace), so resolution is textual. Whether that
 * symbol exists is not: reflection is used so inherited and trait members count,
 * and so a private one is not mistaken for a missing one. References resolving
 * outside `App\` are skipped.
 *
 * Needs the autoloader: `php scripts/check-see-references.php` after composer install.
 */

$root = dirname(__DIR__);
$autoload = $root.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found — run composer install first.\n");
    exit(1);
}

require $autoload;

/** @var list<string> $broken */
$broken = [];

foreach (phpFiles($root, ['app', 'database']) as $file) {
    $source = file_get_contents($file) ?: '';

    if (preg_match_all('/\{@see\s+([^}]+)\}/', $source, $all, PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }

    [$selfClass, $namespace] = declaredClass($source);
    $imports = imports($source);

    foreach ($all[1] as [$raw, $offset]) {
        $problem = checkReference(trim($raw), $selfClass, $namespace, $imports);

        if ($problem !== null) {
            $lineNo = substr_count(substr($source, 0, $offset), "\n") + 1;
            $broken[] = substr($file, strlen($root) + 1).":{$lineNo} -> {$problem}";
        }
    }
}

if ($broken !== []) {
    fwrite(STDERR, "{@see} guard: these references name something that does not exist:\n");
    foreach ($broken as $entry) {
        fwrite(STDERR, "  {$entry}\n");
    }
    fwrite(STDERR, "\nFix the reference or drop it — a docblock must point at real code.\n");
    exit(1);
}

echo '{@see} guard: all App references resolve ✓'."\n";
exit(0);

/**
 * @param  list<string>  $dirs
 * @return list<string>
 */
function phpFiles(string $root, array $dirs): array
{
    $files = [];

    foreach ($dirs as $dir) {
        $path = $root.'/'.$dir;

        if (! is_dir($path)) {
            fwrite(STDERR, "{$dir}/ not found at {$path}\n");
            exit(1);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * @return array{0: string|null, 1: string}  the file's declared FQCN and namespace
 */
function declaredClass(string $source): array
{
    if (preg_match('/^namespace\s+([^;]+);/m', $source, $n) !== 1) {
        return [null, ''];
    }

    $namespace = trim($n[1]);
    $pattern = '/^(?:final\s+|readonly\s+|abstract\s+)*(?:class|interface|trait|enum)\s+(\w+)/m';

    if (preg_match($pattern, $source, $c) !== 1) {
        return [null, $namespace];
    }

    return [$namespace.'\\'.$c[1], $namespace];
}

/**
 * Top-level `use` statements only; a trait `use` inside a class body is indented.
 *
 * @return array<string, string>  short name => FQCN
 */
function imports(string $source): array
{
    preg_match_all('/^use\s+(?!function\s|const\s)([^;]+);/m', $source, $u);

    $imports = [];

    foreach ($u[1] as $import) {
        $import = trim($import);

        if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $import, $alias) === 1) {
            $imports[$alias[2]] = trim($alias[1]);

            continue;
        }

        $short = strrchr($import, '\\');
        $imports[$short === false ? $import : substr($short, 1)] = $import;
    }

    return $imports;
}

/**
 * @param  array<string, string>  $imports
 * @return string|null  a description of the problem, or null when the reference is fine
 */
function checkReference(string $ref, ?string $selfClass, string $namespace, array $imports): ?string
{
    if ($ref === '' || str_contains($ref, '/') || str_contains($ref, ' ')) {
        return null;
    }

    if (str_contains($ref, '::')) {
        [$class, $member] = array_map(trim(...), explode('::', $ref, 2));
    } else {
        [$class, $member] = bareReference($ref);
    }

    if ($class === null && $member === null) {
        return null;
    }

    $member = $member === null ? null : ltrim(rtrim($member, '()'), '$');

    if ($class === null || in_array($class, ['self', 'static', '$this'], true)) {
        $target = $selfClass;
    } elseif (str_starts_with($class, '\\')) {
        $target = ltrim($class, '\\');
    } elseif (isset($imports[$class])) {
        $target = $imports[$class];
    } else {
        $target = $namespace === '' ? $class : $namespace.'\\'.$class;
    }

    if ($target === null || ! str_starts_with($target, 'App\\')) {
        return null;
    }

    if (! class_exists($target) && ! interface_exists($target) && ! trait_exists($target) && ! enum_exists($target)) {
        return "{$ref} (no such class: {$target})";
    }

    if ($member === null || $member === '') {
        return null;
    }

    $reflection = new ReflectionClass($target);

    $declared = $reflection->hasMethod($member)
        || $reflection->hasProperty($member)
        || $reflection->hasConstant($member)
        || hasAnnotatedProperty($reflection, $member);

    return $declared ? null : "{$ref} (no such member on {$target})";
}

/**
 * An Eloquent column is a magic property, declared only as an `@property` line on
 * the class, so reflection alone would call it missing.
 *
 * @param  ReflectionClass<object>  $reflection
 */
function hasAnnotatedProperty(ReflectionClass $reflection, string $member): bool
{
    $pattern = '/@property(?:-read|-write)?\s+\S+\s+\$'.preg_quote($member, '/').'\b/';

    for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
        $doc = $class->getDocComment();

        if (is_string($doc) && preg_match($pattern, $doc) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Split a reference with no `::` into a class or a member of the enclosing class.
 * `Foo` is a class; `bar()`, `bar` and `$bar` are members; `config('x')` is a
 * function call and belongs to neither.
 *
 * @return array{0: string|null, 1: string|null}
 */
function bareReference(string $ref): array
{
    if (preg_match('/^[A-Z]\w*$/', $ref) === 1 || str_starts_with($ref, '\\')) {
        return [$ref, null];
    }

    if (preg_match('/^\$?[a-z_]\w*(\(\))?$/i', $ref) === 1) {
        return [null, $ref];
    }

    return [null, null];
}
