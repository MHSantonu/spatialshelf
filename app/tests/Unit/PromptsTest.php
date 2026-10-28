<?php

use App\Services\Prompts;

test('every schema file is valid JSON with an object root', function () {
    $files = glob(dirname(__DIR__, 3).'/prompts/schemas/*.json');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $decoded = Prompts::schema(pathinfo($file, PATHINFO_FILENAME));

        expect($decoded)->toBeArray('Schema must decode to an array: '.$file)
            ->and($decoded['type'] ?? null)->toBe('object', 'Schema root must be type object: '.$file);
    }
});
