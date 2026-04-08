<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitSlicedd\Runtime\SlicerResult;

test('SlicerResult success returns true for exit code 0', function () {
    $result = new SlicerResult(0, 'output', '');

    expect($result->success())->toBeTrue();
    expect($result->output())->toBe('output');
    expect($result->error())->toBe('');
});

test('SlicerResult success returns false for non-zero exit code', function () {
    $result = new SlicerResult(1, '', 'some error');

    expect($result->success())->toBeFalse();
    expect($result->error())->toBe('some error');
});

test('SlicerResult error falls back to exit code message', function () {
    $result = new SlicerResult(42, '', '');

    expect($result->success())->toBeFalse();
    expect($result->error())->toBe('Command failed with exit code 42');
});

test('SlicerResult sliceStats parses result.json', function () {
    $resultJson = [
        'return_code' => 0,
        'plates' => [
            [
                'plate_index' => 0,
                'sliced_time' => 3600,
                'triangle_count' => 50000,
                'warning_message' => '',
            ],
        ],
        'prepare_time' => 5.2,
        'export_time' => 2.1,
    ];

    $result = new SlicerResult(0, '', '', $resultJson);
    $stats = $result->sliceStats();

    expect($stats)->not->toBeNull();
    expect($stats['plates'])->toHaveCount(1);
    expect($stats['plates'][0]['sliced_time'])->toBe(3600.0);
    expect($stats['plates'][0]['triangle_count'])->toBe(50000);
    expect($stats['prepare_time'])->toBe(5.2);
    expect($stats['export_time'])->toBe(2.1);
});

test('SlicerResult sliceStats returns null without resultJson', function () {
    $result = new SlicerResult(0, '', '');

    expect($result->sliceStats())->toBeNull();
});
