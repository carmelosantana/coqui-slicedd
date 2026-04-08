<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitSlicedd\Runtime;

/**
 * Checks for required and optional system dependencies for 3D printing.
 *
 * Reports structured status with availability, version info,
 * and install instructions for missing tools.
 */
final class DependencyChecker
{
    /** @var array<string, array{description: string, required: bool, install: string, alternatives: list<string>}> */
    private const array DEPENDENCIES = [
        'orca-slicer' => [
            'description' => 'OrcaSlicer CLI for model slicing',
            'required' => true,
            'install' => 'Download from https://github.com/SoftFever/OrcaSlicer/releases — or install via package manager',
            'alternatives' => ['OrcaSlicer', 'orca_slicer'],
        ],
    ];

    /**
     * Check all dependencies and return a structured report.
     *
     * @return array{
     *     ready: bool,
     *     slicer_binary: string,
     *     available: list<array{name: string, path: string, version: string, description: string}>,
     *     missing: list<array{name: string, required: bool, description: string, install: string}>,
     *     summary: string,
     * }
     */
    public function check(): array
    {
        $available = [];
        $missing = [];
        $slicerBinary = '';

        foreach (self::DEPENDENCIES as $binary => $info) {
            $resolved = $this->resolveWithAlternatives($binary, $info['alternatives']);

            if ($resolved !== null) {
                $available[] = [
                    'name' => $resolved['name'],
                    'path' => $resolved['path'],
                    'version' => $this->getVersion($resolved['path']),
                    'description' => $info['description'],
                ];

                if ($binary === 'orca-slicer') { // @phpstan-ignore identical.alwaysTrue
                    $slicerBinary = $resolved['path'];
                }
            } else {
                $missing[] = [
                    'name' => $binary,
                    'required' => $info['required'],
                    'description' => $info['description'],
                    'install' => $info['install'],
                ];
            }
        }

        // Also check configured path via env
        if ($slicerBinary === '') {
            $envPath = $this->getEnvSlicerPath();
            if ($envPath !== '' && is_executable($envPath)) {
                $slicerBinary = $envPath;
                $available[] = [
                    'name' => 'orca-slicer (ORCA_SLICER_PATH)',
                    'path' => $envPath,
                    'version' => $this->getVersion($envPath),
                    'description' => 'OrcaSlicer via ORCA_SLICER_PATH environment variable',
                ];
                // Remove from missing if it was added
                $missing = array_values(array_filter(
                    $missing,
                    static fn(array $dep): bool => $dep['name'] !== 'orca-slicer', // @phpstan-ignore notIdentical.alwaysFalse
                ));
            }
        }

        $requiredMissing = array_filter($missing, static fn(array $dep): bool => $dep['required']);
        $ready = $requiredMissing === [];

        $summaryParts = [];
        if ($requiredMissing !== []) {
            $names = implode(', ', array_column($requiredMissing, 'name'));
            $summaryParts[] = "Missing required: {$names}";
        }
        if ($summaryParts === []) {
            $summaryParts[] = 'All required dependencies are available.';
            if ($slicerBinary !== '') {
                $summaryParts[] = "OrcaSlicer binary: {$slicerBinary}";
            }
        }

        $optionalMissing = array_filter($missing, static fn(array $dep): bool => !$dep['required']); // @phpstan-ignore booleanNot.alwaysFalse
        if ($optionalMissing !== []) {
            $names = implode(', ', array_column($optionalMissing, 'name'));
            $summaryParts[] = "Optional missing: {$names}";
        }

        return [
            'ready' => $ready,
            'slicer_binary' => $slicerBinary,
            'available' => $available,
            'missing' => $missing,
            'summary' => implode("\n", $summaryParts),
        ];
    }

    /**
     * Resolve the OrcaSlicer binary path, trying env var, then PATH alternatives.
     */
    public function resolveSlicerBinary(): string
    {
        // 1. Check ORCA_SLICER_PATH env var
        $envPath = $this->getEnvSlicerPath();
        if ($envPath !== '' && is_executable($envPath)) {
            return $envPath;
        }

        // 2. Try known binary names
        $names = ['orca-slicer', 'OrcaSlicer', 'orca_slicer'];
        foreach ($names as $name) {
            $path = $this->which($name);
            if ($path !== null) {
                return $path;
            }
        }

        return '';
    }

    private function getEnvSlicerPath(): string
    {
        $env = getenv('ORCA_SLICER_PATH');

        return ($env !== false && $env !== '') ? $env : '';
    }

    /**
     * @param list<string> $alternatives
     * @return array{name: string, path: string}|null
     */
    private function resolveWithAlternatives(string $primary, array $alternatives): ?array
    {
        $path = $this->which($primary);
        if ($path !== null) {
            return ['name' => $primary, 'path' => $path];
        }

        foreach ($alternatives as $alt) {
            $path = $this->which($alt);
            if ($path !== null) {
                return ['name' => $alt, 'path' => $path];
            }
        }

        return null;
    }

    private function which(string $binary): ?string
    {
        $output = [];
        $exitCode = 0;
        exec('which ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exitCode);

        return ($exitCode === 0 && isset($output[0])) ? trim($output[0]) : null;
    }

    private function getVersion(string $path): string
    {
        $output = [];
        $exitCode = 0;
        exec(escapeshellarg($path) . ' --version 2>&1', $output, $exitCode);

        if ($exitCode === 0 && isset($output[0])) {
            $version = trim($output[0]);
            if (preg_match('/(\d+\.\d+[\.\d]*)/', $version, $matches)) {
                return $matches[1];
            }

            return $version;
        }

        return 'unknown';
    }
}
