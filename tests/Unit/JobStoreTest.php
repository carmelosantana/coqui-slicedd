<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitSlicedd\Storage\JobStore;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/slicedd-test-' . uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->store = new JobStore($this->tempDir . '/test.db');
});

afterEach(function () {
    unset($this->store);

    if (is_dir($this->tempDir)) {
        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }
});

test('recordJob creates a job and returns an ID', function () {
    $id = $this->store->recordJob(inputFile: '/tmp/model.stl');

    expect($id)->toBeString();
    expect(strlen($id))->toBe(24); // 12 bytes = 24 hex chars
});

test('getJob retrieves recorded job', function () {
    $id = $this->store->recordJob(
        inputFile: '/tmp/model.stl',
        profileMachine: 'ender3.json',
        profileProcess: 'standard.json',
        profileFilament: 'pla.json',
    );

    $job = $this->store->getJob($id);

    expect($job)->not->toBeNull();
    expect($job['input_file'])->toBe('/tmp/model.stl');
    expect($job['profile_machine'])->toBe('ender3.json');
    expect($job['status'])->toBe('slicing');
});

test('updateJobStatus updates status and completion fields', function () {
    $id = $this->store->recordJob(inputFile: '/tmp/model.stl');

    $this->store->updateJobStatus(
        id: $id,
        status: 'completed',
        outputFile: '/tmp/model.gcode',
        estimatedTimeS: 3600,
        filamentUsedG: 15.5,
        layerCount: 200,
        plateCount: 1,
    );

    $job = $this->store->getJob($id);

    expect($job['status'])->toBe('completed');
    expect($job['output_file'])->toBe('/tmp/model.gcode');
    expect((int) $job['estimated_time_s'])->toBe(3600);
    expect((float) $job['filament_used_g'])->toBe(15.5);
    expect((int) $job['layer_count'])->toBe(200);
    expect($job['completed_at'])->not->toBeNull();
});

test('updateJobStatus to failed sets completion timestamp', function () {
    $id = $this->store->recordJob(inputFile: '/tmp/model.stl');

    $this->store->updateJobStatus($id, 'failed');

    $job = $this->store->getJob($id);

    expect($job['status'])->toBe('failed');
    expect($job['completed_at'])->not->toBeNull();
});

test('listJobs returns jobs in reverse chronological order', function () {
    $id1 = $this->store->recordJob(inputFile: '/tmp/first.stl');
    usleep(10_000); // Ensure different timestamps
    $id2 = $this->store->recordJob(inputFile: '/tmp/second.stl');

    $jobs = $this->store->listJobs(limit: 10);

    expect($jobs)->toHaveCount(2);
    expect($jobs[0]['id'])->toBe($id2);
    expect($jobs[1]['id'])->toBe($id1);
});

test('listJobs respects limit', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->store->recordJob(inputFile: "/tmp/model{$i}.stl");
    }

    $jobs = $this->store->listJobs(limit: 3);

    expect($jobs)->toHaveCount(3);
});

test('getJob returns null for non-existent ID', function () {
    $job = $this->store->getJob('nonexistent');

    expect($job)->toBeNull();
});

test('aggregateStats returns correct totals', function () {
    $id1 = $this->store->recordJob(inputFile: '/tmp/a.stl');
    $this->store->updateJobStatus($id1, 'completed', filamentUsedG: 10.0, estimatedTimeS: 3600);

    $id2 = $this->store->recordJob(inputFile: '/tmp/b.stl');
    $this->store->updateJobStatus($id2, 'completed', filamentUsedG: 5.5, estimatedTimeS: 1800);

    $id3 = $this->store->recordJob(inputFile: '/tmp/c.stl');
    $this->store->updateJobStatus($id3, 'failed');

    $stats = $this->store->aggregateStats();

    expect($stats['total_jobs'])->toBe(3);
    expect($stats['completed_jobs'])->toBe(2);
    expect($stats['failed_jobs'])->toBe(1);
    expect($stats['total_filament_g'])->toBe(15.5);
    expect($stats['total_time_s'])->toBe(5400);
});

test('aggregateStats returns zeroes when empty', function () {
    $stats = $this->store->aggregateStats();

    expect($stats['total_jobs'])->toBe(0);
    expect($stats['completed_jobs'])->toBe(0);
    expect($stats['total_filament_g'])->toBe(0.0);
    expect($stats['total_time_s'])->toBe(0);
});

test('recordJob stores config overrides as JSON', function () {
    $id = $this->store->recordJob(
        inputFile: '/tmp/model.stl',
        configOverrides: ['layer_height' => '0.2', 'infill_density' => '30%'],
    );

    $job = $this->store->getJob($id);
    $overrides = json_decode($job['config_overrides'], true);

    expect($overrides)->toBe(['layer_height' => '0.2', 'infill_density' => '30%']);
});
