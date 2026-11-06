<?php

use Illuminate\Support\Facades\File;

/**
 * Plugins can only do what the core lets columns do, so a check on one of our
 * own columns would quietly put a feature out of their reach.
 */
function concreteColumnClasses(): array
{
    return collect(File::files(__DIR__.'/../../src/Columns'))
        ->map(fn (SplFileInfo $file): string => $file->getBasename('.php'))
        ->reject(fn (string $class): bool => $class === 'Column')
        ->values()
        ->all();
}

it('has no instanceof check on a concrete column', function (): void {
    $pattern = '/instanceof\s+\\\\?(?:[\w\\\\]*\\\\)?('.implode('|', concreteColumnClasses()).')\b/';
    $found = [];

    foreach (File::allFiles(__DIR__.'/../../src') as $file) {
        if (preg_match_all($pattern, $file->getContents(), $matches) > 0) {
            $found[$file->getRelativePathname()] = $matches[0];
        }
    }

    expect($found)->toBe([]);
});

it('has no check on a built-in column type in the client', function (): void {
    $found = [];

    foreach (File::allFiles(__DIR__.'/../../resources/js') as $file) {
        if (preg_match_all("/\.type\s*[!=]==\s*'[a-z]+-column'/", $file->getContents(), $matches) > 0) {
            $found[$file->getRelativePathname()] = $matches[0];
        }
    }

    expect($found)->toBe([]);
});

it('declares what plugins build on as public API', function (string $directory): void {
    foreach (File::files(__DIR__.'/../../src/'.$directory) as $file) {
        expect($file->getContents())->toContain('@api');
    }
})->with(['Columns', 'Testing']);
