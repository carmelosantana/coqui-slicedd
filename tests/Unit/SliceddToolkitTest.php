<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitSlicedd\SliceddToolkit;

test('SliceddToolkit can be instantiated', function () {
    $tempDir = sys_get_temp_dir() . '/slicedd-test-' . uniqid();
    mkdir($tempDir, 0755, true);

    $toolkit = new SliceddToolkit(workspacePath: $tempDir);

    expect($toolkit)->toBeInstanceOf(SliceddToolkit::class);

    // Cleanup
    $files = glob($tempDir . '/slicedd/*') ?: [];
    foreach ($files as $file) {
        unlink($file);
    }
    if (is_dir($tempDir . '/slicedd')) {
        rmdir($tempDir . '/slicedd');
    }
    rmdir($tempDir);
});

test('SliceddToolkit tools returns expected tools', function () {
    $tempDir = sys_get_temp_dir() . '/slicedd-test-' . uniqid();
    mkdir($tempDir, 0755, true);

    $toolkit = new SliceddToolkit(workspacePath: $tempDir);
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(2);
    expect($tools[0]->name())->toBe('slicer');
    expect($tools[1]->name())->toBe('gcode');

    // Cleanup
    $files = glob($tempDir . '/slicedd/*') ?: [];
    foreach ($files as $file) {
        unlink($file);
    }
    if (is_dir($tempDir . '/slicedd')) {
        rmdir($tempDir . '/slicedd');
    }
    rmdir($tempDir);
});

test('SliceddToolkit guidelines returns non-empty string', function () {
    $tempDir = sys_get_temp_dir() . '/slicedd-test-' . uniqid();
    mkdir($tempDir, 0755, true);

    $toolkit = new SliceddToolkit(workspacePath: $tempDir);

    expect($toolkit->guidelines())->toContain('SLICEDD-TOOLKIT-GUIDELINES');
    expect($toolkit->guidelines())->toContain('slicer');
    expect($toolkit->guidelines())->toContain('gcode');

    // Cleanup
    $files = glob($tempDir . '/slicedd/*') ?: [];
    foreach ($files as $file) {
        unlink($file);
    }
    if (is_dir($tempDir . '/slicedd')) {
        rmdir($tempDir . '/slicedd');
    }
    rmdir($tempDir);
});

test('SlicerTool toFunctionSchema has correct structure', function () {
    $tempDir = sys_get_temp_dir() . '/slicedd-test-' . uniqid();
    mkdir($tempDir, 0755, true);

    $toolkit = new SliceddToolkit(workspacePath: $tempDir);
    $tools = $toolkit->tools();
    $schema = $tools[0]->toFunctionSchema();

    expect($schema)->toHaveKey('type');
    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('slicer');
    expect($schema['function']['parameters']['properties'])->toHaveKey('action');
    expect($schema['function']['parameters']['required'])->toBe(['action']);

    // Cleanup
    $files = glob($tempDir . '/slicedd/*') ?: [];
    foreach ($files as $file) {
        unlink($file);
    }
    if (is_dir($tempDir . '/slicedd')) {
        rmdir($tempDir . '/slicedd');
    }
    rmdir($tempDir);
});

test('GcodeTool toFunctionSchema has correct structure', function () {
    $tempDir = sys_get_temp_dir() . '/slicedd-test-' . uniqid();
    mkdir($tempDir, 0755, true);

    $toolkit = new SliceddToolkit(workspacePath: $tempDir);
    $tools = $toolkit->tools();
    $schema = $tools[1]->toFunctionSchema();

    expect($schema['function']['name'])->toBe('gcode');
    expect($schema['function']['parameters']['properties'])->toHaveKey('action');
    expect($schema['function']['parameters']['properties']['action']['enum'])->toContain('analyze');
    expect($schema['function']['parameters']['properties']['action']['enum'])->toContain('modify');

    // Cleanup
    $files = glob($tempDir . '/slicedd/*') ?: [];
    foreach ($files as $file) {
        unlink($file);
    }
    if (is_dir($tempDir . '/slicedd')) {
        rmdir($tempDir . '/slicedd');
    }
    rmdir($tempDir);
});
