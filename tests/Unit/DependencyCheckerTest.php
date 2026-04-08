<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitSlicedd\Runtime\DependencyChecker;

test('DependencyChecker check returns structured report', function () {
    $checker = new DependencyChecker();
    $report = $checker->check();

    expect($report)->toHaveKeys(['ready', 'slicer_binary', 'available', 'missing', 'summary']);
    expect($report['ready'])->toBeBool();
    expect($report['available'])->toBeArray();
    expect($report['missing'])->toBeArray();
    expect($report['summary'])->toBeString();
});

test('DependencyChecker resolveSlicerBinary returns string', function () {
    $checker = new DependencyChecker();
    $binary = $checker->resolveSlicerBinary();

    // May or may not find OrcaSlicer on the test system
    expect($binary)->toBeString();
});

test('DependencyChecker respects ORCA_SLICER_PATH env var', function () {
    $checker = new DependencyChecker();

    // Set a non-existent path — should not resolve
    $original = getenv('ORCA_SLICER_PATH');
    putenv('ORCA_SLICER_PATH=/nonexistent/binary');

    $binary = $checker->resolveSlicerBinary();

    // Restore
    if ($original !== false) {
        putenv("ORCA_SLICER_PATH={$original}");
    } else {
        putenv('ORCA_SLICER_PATH');
    }

    // The non-existent path is not executable, so it should not be returned
    expect($binary)->not->toBe('/nonexistent/binary');
});
