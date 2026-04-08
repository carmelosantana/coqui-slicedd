<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitSlicedd\Gcode\GcodeModifier;
use CarmeloSantana\CoquiToolkitSlicedd\Gcode\GcodeParser;

beforeEach(function () {
    $this->modifier = new GcodeModifier();
    $this->parser = new GcodeParser();
    $this->sampleFile = __DIR__ . '/../fixtures/sample.gcode';
    $this->tempDir = sys_get_temp_dir() . '/slicedd-test-' . uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    // Cleanup temp files
    if (is_dir($this->tempDir)) {
        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }
});

test('injectAfterLayer inserts commands at the correct layer', function () {
    $output = $this->tempDir . '/injected.gcode';

    $this->modifier->injectAfterLayer(
        $this->sampleFile,
        $output,
        1, // After layer 1
        ['M600 ; filament change', 'M117 Change filament'],
    );

    $content = file_get_contents($output);

    expect($content)->toContain('Injected after layer 1');
    expect($content)->toContain('M600 ; filament change');
    expect($content)->toContain('M117 Change filament');
    expect($content)->toContain('End injection');
});

test('injectAfterLayer throws on invalid layer number', function () {
    $output = $this->tempDir . '/injected.gcode';

    expect(fn() => $this->modifier->injectAfterLayer(
        $this->sampleFile,
        $output,
        999,
        ['M600'],
    ))->toThrow(\RuntimeException::class, 'Layer 999 not found');
});

test('appendBedClear adds bed clearing sequence', function () {
    $output = $this->tempDir . '/bedclear.gcode';

    $this->modifier->appendBedClear($this->sampleFile, $output);

    $content = file_get_contents($output);

    expect($content)->toContain('Bed clear sequence');
    expect($content)->toContain('G91 ; relative positioning');
    expect($content)->toContain('G1 Z5 F3000');
    expect($content)->toContain('M104 S0');
    expect($content)->toContain('M140 S0');
    expect($content)->toContain('M84');
});

test('prependStartGcode inserts commands before first G-code', function () {
    $output = $this->tempDir . '/prepended.gcode';

    $this->modifier->prependStartGcode(
        $this->sampleFile,
        $output,
        ['G92 E0 ; reset extruder', 'G1 F200 E3 ; prime nozzle'],
    );

    $content = file_get_contents($output);

    expect($content)->toContain('Custom start G-code');
    expect($content)->toContain('G92 E0');
    expect($content)->toContain('G1 F200 E3');

    // Original content should still be present
    expect($content)->toContain('M140 S60');
});

test('replaceTemperature modifies nozzle temperature', function () {
    $output = $this->tempDir . '/temped.gcode';

    $this->modifier->replaceTemperature($this->sampleFile, $output, nozzleTemp: 200);

    $content = file_get_contents($output);

    expect($content)->toContain('M104 S200');
    expect($content)->toContain('M109 S200');
    // Bed temp should remain unchanged
    expect($content)->toContain('M140 S60');
    expect($content)->toContain('M190 S60');
});

test('replaceTemperature modifies bed temperature', function () {
    $output = $this->tempDir . '/temped.gcode';

    $this->modifier->replaceTemperature($this->sampleFile, $output, bedTemp: 70);

    $content = file_get_contents($output);

    expect($content)->toContain('M140 S70');
    expect($content)->toContain('M190 S70');
    // Nozzle temp should remain unchanged
    expect($content)->toContain('M104 S215');
});

test('replaceTemperature throws when no temperature specified', function () {
    $output = $this->tempDir . '/temped.gcode';

    expect(fn() => $this->modifier->replaceTemperature($this->sampleFile, $output))
        ->toThrow(\RuntimeException::class, 'At least one temperature');
});

test('injectTimelapse adds moonraker triggers at layer changes', function () {
    $output = $this->tempDir . '/timelapse.gcode';

    $this->modifier->injectTimelapse($this->sampleFile, $output, 'moonraker');

    $content = file_get_contents($output);

    expect($content)->toContain('TIMELAPSE_TAKE_FRAME');
    expect($content)->toContain('Timelapse trigger (moonraker)');

    // Should appear at each layer change
    $count = substr_count($content, 'TIMELAPSE_TAKE_FRAME');
    expect($count)->toBe(3); // 3 layer changes in sample
});

test('insertFilamentChange adds M600 at specified layer', function () {
    $output = $this->tempDir . '/filchange.gcode';

    $this->modifier->insertFilamentChange($this->sampleFile, $output, 2, 'Swap to red');

    $content = file_get_contents($output);

    expect($content)->toContain('M117 Swap to red');
    expect($content)->toContain('M600 ; filament change');
    expect($content)->toContain('Injected after layer 2');
});

test('insertPause adds M0 at specified layer', function () {
    $output = $this->tempDir . '/paused.gcode';

    $this->modifier->insertPause($this->sampleFile, $output, 0, 'Check first layer');

    $content = file_get_contents($output);

    expect($content)->toContain('M117 Check first layer');
    expect($content)->toContain('M0 ; pause print');
});

test('modifier throws on non-existent input file', function () {
    $output = $this->tempDir . '/out.gcode';

    expect(fn() => $this->modifier->appendBedClear('/nonexistent/file.gcode', $output))
        ->toThrow(\RuntimeException::class, 'not found');
});
