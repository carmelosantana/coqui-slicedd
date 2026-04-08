<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitSlicedd\Gcode\GcodeStats;

test('GcodeStats fromHeader parses OrcaSlicer metadata', function () {
    $headers = [
        'layer_height' => '0.2',
        'first_layer_height' => '0.28',
        'nozzle_temperature' => '215',
        'bed_temperature' => '60',
        'filament_type' => 'PLA',
        'printer_model' => 'Ender-3 S1 Pro',
        'total_layer_count' => '150',
        'filament_used [mm]' => '4523.45',
        'filament_used [g]' => '13.52',
        'filament_cost' => '0.27',
        'estimated printing time (normal mode)' => '1h 23m 45s',
        'generated_by' => 'OrcaSlicer 2.2.0',
        'sparse_infill_density' => '20',
        'wall_loops' => '3',
    ];

    $stats = GcodeStats::fromHeader($headers);

    expect($stats->layerHeight)->toBe(0.2);
    expect($stats->firstLayerHeight)->toBe(0.28);
    expect($stats->nozzleTemp)->toBe(215);
    expect($stats->bedTemp)->toBe(60);
    expect($stats->filamentType)->toBe('PLA');
    expect($stats->printerModel)->toBe('Ender-3 S1 Pro');
    expect($stats->layerCount)->toBe(150);
    expect($stats->filamentUsedMm)->toBe(4523.45);
    expect($stats->filamentUsedG)->toBe(13.52);
    expect($stats->filamentCost)->toBe(0.27);
    expect($stats->slicerVersion)->toBe('OrcaSlicer 2.2.0');
    expect($stats->infillDensity)->toBe(20.0);
    expect($stats->wallLoops)->toBe(3);
});

test('GcodeStats parses time formats correctly', function () {
    $stats = GcodeStats::fromHeader([
        'estimated printing time (normal mode)' => '2h 30m 15s',
    ]);

    // 2*3600 + 30*60 + 15 = 9015
    expect($stats->estimatedTimeSeconds)->toBe(9015.0);
});

test('GcodeStats parses time with only minutes', function () {
    $stats = GcodeStats::fromHeader([
        'estimated printing time (normal mode)' => '45m 10s',
    ]);

    expect($stats->estimatedTimeSeconds)->toBe(2710.0);
});

test('GcodeStats formattedEstimatedTime returns human readable string', function () {
    $stats = new GcodeStats(estimatedTimeSeconds: 5025.0);

    expect($stats->formattedEstimatedTime())->toBe('1h 23m 45s');
});

test('GcodeStats formattedEstimatedTime returns unknown when null', function () {
    $stats = new GcodeStats();

    expect($stats->formattedEstimatedTime())->toBe('unknown');
});

test('GcodeStats formattedFilamentUsed includes both units', function () {
    $stats = new GcodeStats(filamentUsedMm: 4523.45, filamentUsedG: 13.52);

    expect($stats->formattedFilamentUsed())->toBe('13.5g / 4523.5mm');
});

test('GcodeStats toMarkdown generates valid table', function () {
    $stats = new GcodeStats(
        estimatedTimeSeconds: 3600.0,
        filamentUsedG: 15.0,
        layerCount: 200,
        nozzleTemp: 215,
        bedTemp: 60,
        filamentType: 'PLA',
        printerModel: 'Ender-3 S1 Pro',
    );

    $md = $stats->toMarkdown();

    expect($md)->toContain('| **Estimated Time** | 1h 0m 0s |');
    expect($md)->toContain('| **Filament Type** | PLA |');
    expect($md)->toContain('| **Printer** | Ender-3 S1 Pro |');
    expect($md)->toContain('| **Layers** | 200 |');
});

test('GcodeStats toMarkdown returns message when empty', function () {
    $stats = new GcodeStats();

    expect($stats->toMarkdown())->toBe('No statistics available.');
});

test('GcodeStats fromHeader handles empty input', function () {
    $stats = GcodeStats::fromHeader([]);

    expect($stats->estimatedTimeSeconds)->toBeNull();
    expect($stats->layerCount)->toBeNull();
    expect($stats->filamentType)->toBeNull();
});
