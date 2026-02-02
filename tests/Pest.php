<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(Tests\TestCase::class)->in('Feature', 'Unit');
uses(Illuminate\Foundation\Testing\DatabaseTransactions::class)->in('Feature/Contracts');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toHaveJsonStructureRecursive', function (array $structure) {
    foreach ($structure as $key => $value) {
        if (is_array($value)) {
            expect($this->value)->toHaveKey($key);
            expect($this->value[$key])->toHaveJsonStructureRecursive($value);
        } else {
            expect($this->value)->toHaveKey($value);
        }
    }
    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function exportFixture(string $filename, array $data): void
{
    if (!env('EXPORT_FIXTURES', false)) {
        return;
    }

    $path = base_path("tests/fixtures/{$filename}");
    $dir = dirname($path);

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
