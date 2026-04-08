<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitSlicedd\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitSlicedd\Runtime\DependencyChecker;
use CarmeloSantana\CoquiToolkitSlicedd\Runtime\OrcaSlicerRunner;
use CarmeloSantana\CoquiToolkitSlicedd\Storage\JobStore;

/**
 * OrcaSlicer tool — slice models, manage profiles, query job history.
 */
final class SlicerTool implements ToolInterface
{
    public function __construct(
        private readonly OrcaSlicerRunner $runner,
        private readonly JobStore $jobs,
        private readonly DependencyChecker $deps,
    ) {}

    public function name(): string
    {
        return 'slicer';
    }

    public function description(): string
    {
        return <<<'DESC'
            OrcaSlicer 3D model slicer — slice STL/3MF/STEP files, manage profiles, query job history.

            Actions:
            - check_deps: Verify OrcaSlicer binary is installed and available
            - slice: Slice a 3D model with specified profile and options
            - info: Get OrcaSlicer version and configuration info
            - export_settings: Export current slicer settings
            - export_3mf: Export a sliced model as 3MF project
            - export_stl: Export a model as STL
            - list_profiles: List available OrcaSlicer profiles for machine/process/filament
            - job_history: Query slice job history and statistics
            DESC;
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');

        return match ($action) {
            'check_deps' => $this->checkDeps(),
            'slice' => $this->slice($input),
            'info' => $this->info(),
            'export_settings' => $this->exportSettings($input),
            'export_3mf' => $this->export3mf($input),
            'export_stl' => $this->exportStl($input),
            'list_profiles' => $this->listProfiles($input),
            'job_history' => $this->jobHistory($input),
            default => ToolResult::error("Unknown slicer action: '{$action}'"),
        };
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The slicer action to perform.',
                            'enum' => [
                                'check_deps', 'slice', 'info', 'export_settings',
                                'export_3mf', 'export_stl', 'list_profiles', 'job_history',
                            ],
                        ],
                        'input_file' => [
                            'type' => 'string',
                            'description' => 'Path to 3D model file (STL, 3MF, STEP, OBJ, AMF). Required for slice, export_3mf, export_stl.',
                        ],
                        'output_dir' => [
                            'type' => 'string',
                            'description' => 'Output directory for sliced G-code or exports. Defaults to same directory as input file.',
                        ],
                        'profile_machine' => [
                            'type' => 'string',
                            'description' => 'Path to machine profile JSON. Use list_profiles to discover available profiles.',
                        ],
                        'profile_process' => [
                            'type' => 'string',
                            'description' => 'Path to process profile JSON (print settings like layer height, speed, infill).',
                        ],
                        'profile_filament' => [
                            'type' => 'string',
                            'description' => 'Path to filament profile JSON (temperatures, retraction, cooling).',
                        ],
                        'plate' => [
                            'type' => 'integer',
                            'description' => 'Plate number to slice (0-based). Default: 0 (first plate).',
                        ],
                        'config_overrides' => [
                            'type' => 'object',
                            'description' => 'Inline config overrides as key-value pairs. These override profile values. Example: {"layer_height": "0.2", "sparse_infill_density": "20%"}.',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                        'arrange' => [
                            'type' => 'boolean',
                            'description' => 'Auto-arrange objects on the build plate before slicing.',
                        ],
                        'orient' => [
                            'type' => 'boolean',
                            'description' => 'Auto-orient objects for optimal print orientation before slicing.',
                        ],
                        'profile_type' => [
                            'type' => 'string',
                            'description' => 'Profile type to list. Used with list_profiles.',
                            'enum' => ['machine', 'process', 'filament', 'all'],
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max number of results to return. Used with job_history. Default: 20.',
                        ],
                        'status_filter' => [
                            'type' => 'string',
                            'description' => 'Filter job history by status. Used with job_history.',
                            'enum' => ['completed', 'failed', 'slicing'],
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }

    private function checkDeps(): ToolResult
    {
        $report = $this->deps->check();

        if ($report['ready']) {
            return ToolResult::success(
                "OrcaSlicer is ready.\n\n"
                . "Binary: {$report['slicer_binary']}\n"
                . "Summary: {$report['summary']}",
            );
        }

        $missingNames = array_column($report['missing'], 'name');
        $missing = implode(', ', $missingNames);

        return ToolResult::error(
            "OrcaSlicer is not available.\n\n"
            . "Missing: {$missing}\n"
            . "Summary: {$report['summary']}\n\n"
            . 'Install OrcaSlicer from https://github.com/SoftFever/OrcaSlicer/releases '
            . 'or set the ORCA_SLICER_PATH credential to the binary path.',
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function slice(array $input): ToolResult
    {
        $inputFile = trim((string) ($input['input_file'] ?? ''));
        if ($inputFile === '') {
            return ToolResult::error('The "input_file" parameter is required for slicing.');
        }

        if (!file_exists($inputFile)) {
            return ToolResult::error("Input file not found: {$inputFile}");
        }

        $options = $this->buildSliceOptions($input);

        // Record job start
        $profileMachine = trim((string) ($input['profile_machine'] ?? ''));
        $profileProcess = trim((string) ($input['profile_process'] ?? ''));
        $profileFilament = trim((string) ($input['profile_filament'] ?? ''));
        /** @var array<string, string> $configOverrides */
        $configOverrides = is_array($input['config_overrides'] ?? null) ? $input['config_overrides'] : [];

        $jobId = $this->jobs->recordJob(
            inputFile: $inputFile,
            profileMachine: $profileMachine !== '' ? $profileMachine : null,
            profileProcess: $profileProcess !== '' ? $profileProcess : null,
            profileFilament: $profileFilament !== '' ? $profileFilament : null,
            configOverrides: $configOverrides !== [] ? $configOverrides : null,
        );

        $result = $this->runner->slice($inputFile, $options);

        if (!$result->success()) {
            $this->jobs->updateJobStatus($jobId, 'failed');

            return ToolResult::error(
                "Slicing failed (exit code {$result->exitCode}).\n\n"
                . "Error: {$result->error()}\n"
                . "Output: {$result->output()}",
            );
        }

        // Extract stats from result
        $stats = $result->sliceStats();
        $outputFile = null;
        $estimatedTime = null;
        $plateCount = null;
        $warnings = null;

        if ($stats !== null) {
            // sliceStats returns per-plate data; aggregate from plates
            $totalTime = 0.0;
            foreach ($stats['plates'] as $plate) {
                $totalTime += $plate['sliced_time'];
            }
            $estimatedTime = $totalTime > 0 ? $totalTime : null;
            $plateCount = count($stats['plates']);

            if (!empty($stats['warnings'])) {
                $warnings = $stats['warnings'];
            }
        }

        // Try to find the output G-code file
        $outputDir = $options['output_dir'] ?? dirname($inputFile);
        $baseName = pathinfo(basename($inputFile), PATHINFO_FILENAME);
        $candidateGcode = $outputDir . '/' . $baseName . '.gcode';
        if (file_exists($candidateGcode)) {
            $outputFile = $candidateGcode;
        }

        $this->jobs->updateJobStatus(
            id: $jobId,
            status: 'completed',
            outputFile: $outputFile,
            estimatedTimeS: $estimatedTime !== null ? (int) $estimatedTime : null,
            plateCount: $plateCount,
            warnings: $warnings,
        );

        // Build response
        $response = "Slicing completed successfully.\n\n";
        $response .= "**Input**: {$inputFile}\n";
        if ($outputFile !== null) {
            $response .= "**Output**: {$outputFile}\n";
        }
        $response .= "**Job ID**: {$jobId}\n";

        if ($stats !== null) {
            $response .= "\n### Slice Statistics\n";
            if ($estimatedTime !== null) {
                $response .= "- Estimated time: {$this->formatTime((int) $estimatedTime)}\n";
            }
            if ($plateCount > 1) {
                $response .= "- Plates: {$plateCount}\n";
            }
        }

        if ($warnings !== null) {
            $response .= "\n### Warnings\n";
            foreach ($warnings as $w) {
                $response .= "- {$w}\n";
            }
        }

        return ToolResult::success($response);
    }

    private function info(): ToolResult
    {
        // OrcaSlicer --info requires a model file, but we use --version for basic info
        $report = $this->deps->check();

        if (!$report['ready']) {
            return ToolResult::error('OrcaSlicer is not installed.');
        }

        $output = "### OrcaSlicer Info\n\n";
        $output .= "Binary: {$report['slicer_binary']}\n";

        foreach ($report['available'] as $dep) {
            $output .= "- {$dep['name']}: {$dep['path']} (v{$dep['version']})\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function exportSettings(array $input): ToolResult
    {
        $inputFile = trim((string) ($input['input_file'] ?? ''));
        if ($inputFile === '') {
            return ToolResult::error('The "input_file" parameter is required for export_settings.');
        }

        $outputDir = trim((string) ($input['output_dir'] ?? ''));
        if ($outputDir === '') {
            $outputDir = dirname($inputFile);
        }

        $result = $this->runner->exportSettings($inputFile, $outputDir);

        if (!$result->success()) {
            return ToolResult::error("Failed to export settings: {$result->error()}");
        }

        return ToolResult::success("Settings exported to: {$outputDir}\n\n{$result->output()}");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function export3mf(array $input): ToolResult
    {
        $inputFile = trim((string) ($input['input_file'] ?? ''));
        if ($inputFile === '') {
            return ToolResult::error('The "input_file" parameter is required for export_3mf.');
        }

        $outputDir = trim((string) ($input['output_dir'] ?? ''));
        $outputPath = $outputDir !== '' ? $outputDir : dirname($inputFile);

        $result = $this->runner->export3mf($inputFile, $outputPath);

        if (!$result->success()) {
            return ToolResult::error("3MF export failed: {$result->error()}");
        }

        return ToolResult::success("3MF exported successfully.\n\n{$result->output()}");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function exportStl(array $input): ToolResult
    {
        $inputFile = trim((string) ($input['input_file'] ?? ''));
        if ($inputFile === '') {
            return ToolResult::error('The "input_file" parameter is required for export_stl.');
        }

        $outputDir = trim((string) ($input['output_dir'] ?? ''));
        $outputPath = $outputDir !== '' ? $outputDir : dirname($inputFile);

        $result = $this->runner->exportStl($inputFile, $outputPath);

        if (!$result->success()) {
            return ToolResult::error("STL export failed: {$result->error()}");
        }

        return ToolResult::success("STL exported successfully.\n\n{$result->output()}");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function listProfiles(array $input): ToolResult
    {
        $type = trim((string) ($input['profile_type'] ?? 'all'));

        $configDir = $this->resolveConfigDir();
        if ($configDir === null) {
            return ToolResult::error(
                'Could not find OrcaSlicer configuration directory. '
                . 'Expected at ~/.config/OrcaSlicer/',
            );
        }

        $profiles = [];

        $scanTypes = $type === 'all'
            ? ['machine', 'process', 'filament']
            : [$type];

        foreach ($scanTypes as $profileType) {
            $found = $this->scanProfiles($configDir, $profileType);
            if ($found !== []) {
                $profiles[$profileType] = $found;
            }
        }

        if ($profiles === []) {
            return ToolResult::success(
                "No profiles found in {$configDir}.\n\n"
                . 'Create profiles in OrcaSlicer GUI first, or provide profile JSON files directly.',
            );
        }

        $output = "### OrcaSlicer Profiles\n\n";
        $output .= "Config directory: `{$configDir}`\n\n";

        foreach ($profiles as $profileType => $items) {
            $output .= "#### " . ucfirst($profileType) . " Profiles\n\n";
            foreach ($items as $item) {
                $output .= "- `{$item['name']}` — `{$item['path']}`\n";
            }
            $output .= "\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function jobHistory(array $input): ToolResult
    {
        $limit = (int) ($input['limit'] ?? 20);
        $statusFilter = trim((string) ($input['status_filter'] ?? ''));

        $jobs = $this->jobs->listJobs($limit);

        if ($statusFilter !== '') {
            $jobs = array_filter(
                $jobs,
                fn(array $job): bool => ($job['status'] ?? '') === $statusFilter,
            );
        }

        if ($jobs === []) {
            return ToolResult::success('No slice jobs found in history.');
        }

        $output = "### Slice Job History\n\n";
        $output .= "| # | Input | Status | Time | Filament | Layers | Date |\n";
        $output .= "|---|-------|--------|------|----------|--------|------|\n";

        $i = 1;
        foreach ($jobs as $job) {
            $input_name = basename($job['input_file'] ?? 'unknown');
            $status = $job['status'] ?? '—';
            $time = isset($job['estimated_time_s']) ? $this->formatTime((int) $job['estimated_time_s']) : '—';
            $filament = isset($job['filament_used_g']) ? round((float) $job['filament_used_g'], 1) . 'g' : '—';
            $layers = $job['layer_count'] ?? '—';
            $date = isset($job['created_at']) ? substr($job['created_at'], 0, 16) : '—';

            $output .= "| {$i} | {$input_name} | {$status} | {$time} | {$filament} | {$layers} | {$date} |\n";
            $i++;
        }

        // Add aggregate stats
        $stats = $this->jobs->aggregateStats();
        if ($stats['total_jobs'] > 0) {
            $output .= "\n### Aggregate Statistics\n\n";
            $output .= "- Total jobs: {$stats['total_jobs']}\n";
            $output .= "- Completed: {$stats['completed_jobs']}\n";
            $output .= "- Failed: {$stats['failed_jobs']}\n";
            if ($stats['total_filament_g'] > 0) {
                $totalFilament = round($stats['total_filament_g'], 1);
                $output .= "- Total filament: {$totalFilament}g\n";
            }
            if ($stats['total_time_s'] > 0) {
                $output .= "- Total print time: {$this->formatTime($stats['total_time_s'])}\n";
            }
        }

        return ToolResult::success($output);
    }

    /**
     * Build options array for OrcaSlicerRunner::slice().
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildSliceOptions(array $input): array
    {
        $options = [];

        $machine = trim((string) ($input['profile_machine'] ?? ''));
        $process = trim((string) ($input['profile_process'] ?? ''));
        $filament = trim((string) ($input['profile_filament'] ?? ''));

        if ($machine !== '') {
            $options['profile_machine'] = $machine;
        }
        if ($process !== '') {
            $options['profile_process'] = $process;
        }
        if ($filament !== '') {
            $options['profile_filament'] = $filament;
        }

        $outputDir = trim((string) ($input['output_dir'] ?? ''));
        if ($outputDir !== '') {
            $options['output_dir'] = $outputDir;
        }

        if (isset($input['plate'])) {
            $options['plate'] = (int) $input['plate'];
        }

        if (is_array($input['config_overrides'] ?? null)) {
            $options['config_overrides'] = $input['config_overrides'];
        }

        if (!empty($input['arrange'])) {
            $options['arrange'] = true;
        }

        if (!empty($input['orient'])) {
            $options['orient'] = true;
        }

        return $options;
    }

    private function resolveConfigDir(): ?string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        if ($home === '') {
            return null;
        }

        $candidates = [
            $home . '/.config/OrcaSlicer',
            $home . '/.config/orcaslicer',
            $home . '/Library/Application Support/OrcaSlicer',  // macOS
            $home . '/AppData/Roaming/OrcaSlicer',              // Windows
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Scan for profile JSON files.
     *
     * @return list<array{name: string, path: string}>
     */
    private function scanProfiles(string $configDir, string $type): array
    {
        $profiles = [];

        // OrcaSlicer stores profiles under user/<printer>/<type>/
        $userDir = $configDir . '/user';
        if (!is_dir($userDir)) {
            return [];
        }

        $printerDirs = glob($userDir . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($printerDirs as $printerDir) {
            $typeDir = $printerDir . '/' . $type;
            if (!is_dir($typeDir)) {
                continue;
            }

            $files = glob($typeDir . '/*.json') ?: [];
            foreach ($files as $file) {
                $profiles[] = [
                    'name' => pathinfo($file, PATHINFO_FILENAME),
                    'path' => $file,
                ];
            }
        }

        // Sort alphabetically
        usort($profiles, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $profiles;
    }

    private function formatTime(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        if ($minutes > 0) {
            return "{$minutes}m";
        }

        return "{$seconds}s";
    }
}
