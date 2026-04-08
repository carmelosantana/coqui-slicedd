<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitSlicedd\Runtime;

/**
 * CLI wrapper for OrcaSlicer commands.
 *
 * Resolves the OrcaSlicer binary, builds commands, and executes them via proc_open()
 * with non-blocking output reads, timeout support, and output truncation.
 */
final class OrcaSlicerRunner
{
    private const DEFAULT_TIMEOUT = 300;
    private const MAX_OUTPUT_BYTES = 65_536;

    private string $resolvedBinary = '';

    public function __construct(
        private readonly string $workspacePath,
        private readonly DependencyChecker $deps,
    ) {}

    /**
     * Slice a model file and produce G-code or 3MF output.
     *
     * @param array{
     *     profile_machine?: string,
     *     profile_process?: string,
     *     profile_filament?: string,
     *     output_dir?: string,
     *     plate?: int,
     *     config_overrides?: array<string, string>,
     *     arrange?: bool,
     *     orient?: bool,
     *     export_3mf?: string,
     *     enable_timelapse?: bool,
     *     debug?: int,
     * } $options
     */
    public function slice(string $inputFile, array $options = []): SlicerResult
    {
        $binary = $this->resolveBinary();
        if ($binary === '') {
            return new SlicerResult(127, '', 'OrcaSlicer binary not found. Set ORCA_SLICER_PATH or install orca-slicer.');
        }

        $args = [];

        // Profile loading
        $settingsFiles = [];
        if (isset($options['profile_machine']) && $options['profile_machine'] !== '') {
            $settingsFiles[] = $options['profile_machine'];
        }
        if (isset($options['profile_process']) && $options['profile_process'] !== '') {
            $settingsFiles[] = $options['profile_process'];
        }
        if ($settingsFiles !== []) {
            $args[] = '--load-settings';
            $args[] = implode(';', $settingsFiles);
        }

        if (isset($options['profile_filament']) && $options['profile_filament'] !== '') {
            $args[] = '--load-filaments';
            $args[] = $options['profile_filament'];
        }

        // Output directory
        $outputDir = $options['output_dir'] ?? $this->workspacePath . '/slicedd/output';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        $args[] = '--outputdir';
        $args[] = $outputDir;

        // Plate selection
        $plate = $options['plate'] ?? 0;
        $args[] = '--slice';
        $args[] = (string) $plate;

        // Export format
        if (isset($options['export_3mf']) && $options['export_3mf'] !== '') {
            $args[] = '--export-3mf';
            $args[] = $options['export_3mf'];
        }

        // Arrange and orient
        if ($options['arrange'] ?? false) {
            $args[] = '--arrange';
            $args[] = '1';
        }
        if ($options['orient'] ?? false) {
            $args[] = '--orient';
            $args[] = '1';
        }

        // Timelapse
        if ($options['enable_timelapse'] ?? false) {
            $args[] = '--enable-timelapse';
        }

        // Debug level
        $debug = $options['debug'] ?? 2;
        $args[] = '--debug';
        $args[] = (string) $debug;

        // Config overrides (inline key=value)
        if (isset($options['config_overrides']) && is_array($options['config_overrides'])) {
            foreach ($options['config_overrides'] as $key => $value) {
                $args[] = '--' . $key . '=' . $value;
            }
        }

        // Input file last
        $args[] = $inputFile;

        $result = $this->execute($binary, $args, self::DEFAULT_TIMEOUT);

        // Try to parse result.json from output directory
        $resultJson = $this->parseResultJson($outputDir);
        if ($resultJson !== null) {
            return new SlicerResult(
                $result->exitCode,
                $result->stdout,
                $result->stderr,
                $resultJson,
            );
        }

        return $result;
    }

    /**
     * Get model information using --info flag.
     */
    public function info(string $modelFile): SlicerResult
    {
        $binary = $this->resolveBinary();
        if ($binary === '') {
            return new SlicerResult(127, '', 'OrcaSlicer binary not found.');
        }

        return $this->execute($binary, ['--info', $modelFile], 30);
    }

    /**
     * Export slicer settings from a 3MF file to JSON.
     */
    public function exportSettings(string $inputFile, string $outputPath): SlicerResult
    {
        $binary = $this->resolveBinary();
        if ($binary === '') {
            return new SlicerResult(127, '', 'OrcaSlicer binary not found.');
        }

        return $this->execute($binary, ['--export-settings', $outputPath, $inputFile], 30);
    }

    /**
     * Export a model as 3MF.
     */
    public function export3mf(string $inputFile, string $outputPath): SlicerResult
    {
        $binary = $this->resolveBinary();
        if ($binary === '') {
            return new SlicerResult(127, '', 'OrcaSlicer binary not found.');
        }

        return $this->execute($binary, ['--export-3mf', $outputPath, $inputFile], 60);
    }

    /**
     * Export model(s) as STL.
     */
    public function exportStl(string $inputFile, string $outputPath): SlicerResult
    {
        $binary = $this->resolveBinary();
        if ($binary === '') {
            return new SlicerResult(127, '', 'OrcaSlicer binary not found.');
        }

        return $this->execute($binary, ['--export-stls', $outputPath, $inputFile], 60);
    }

    /**
     * Resolve the OrcaSlicer binary path.
     */
    public function resolveBinary(): string
    {
        if ($this->resolvedBinary !== '') {
            return $this->resolvedBinary;
        }

        $this->resolvedBinary = $this->deps->resolveSlicerBinary();

        return $this->resolvedBinary;
    }

    /**
     * Check if OrcaSlicer is available.
     */
    public function isAvailable(): bool
    {
        return $this->resolveBinary() !== '';
    }

    /**
     * Get the workspace path.
     */
    public function workspacePath(): string
    {
        return $this->workspacePath;
    }

    /**
     * @param list<string> $args
     */
    private function execute(string $binary, array $args, int $timeout): SlicerResult
    {
        $parts = [escapeshellarg($binary)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }
        $command = implode(' ', $parts);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->workspacePath,
        );

        if (!is_resource($process)) {
            return new SlicerResult(1, '', 'Failed to start process: ' . $command);
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $out = stream_get_contents($pipes[1]) ?: '';
            $err = stream_get_contents($pipes[2]) ?: '';

            $stdout .= $out;
            $stderr .= $err;

            if (!$status['running']) {
                break;
            }

            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                proc_terminate($process, 15); // SIGTERM
                usleep(100_000);
                proc_terminate($process, 9);  // SIGKILL
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new SlicerResult(
                    124,
                    $this->truncateOutput($stdout),
                    "Command timed out after {$timeout}s.\n" . $this->truncateOutput($stderr),
                );
            }

            usleep(10_000);
        }

        // Read any remaining output
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new SlicerResult(
            $exitCode,
            $this->truncateOutput(trim($stdout)),
            $this->truncateOutput(trim($stderr)),
        );
    }

    /**
     * Try to parse result.json from the output directory.
     *
     * @return array<string, mixed>|null
     */
    private function parseResultJson(string $outputDir): ?array
    {
        $resultPath = $outputDir . '/result.json';
        if (!is_file($resultPath)) {
            return null;
        }

        $content = file_get_contents($resultPath);
        if ($content === false) {
            return null;
        }

        $json = json_decode($content, true);

        return is_array($json) ? $json : null;
    }

    private function truncateOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_OUTPUT_BYTES)
            . "\n\n[Output truncated at " . self::MAX_OUTPUT_BYTES . ' bytes]';
    }
}
