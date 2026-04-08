<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitSlicedd\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitSlicedd\Gcode\GcodeModifier;
use CarmeloSantana\CoquiToolkitSlicedd\Gcode\GcodeParser;

/**
 * G-code analysis and post-processing tool.
 */
final class GcodeTool implements ToolInterface
{
    public function __construct(
        private readonly GcodeParser $parser,
        private readonly GcodeModifier $modifier,
    ) {}

    public function name(): string
    {
        return 'gcode';
    }

    public function description(): string
    {
        return <<<'DESC'
            G-code analyzer and post-processor — inspect, analyze, and modify G-code files.

            Actions:
            - analyze: Full analysis of a G-code file (stats, layer count, command counts, file size)
            - stats: Extract metadata/statistics from G-code header comments
            - layers: Find all layer change positions and Z heights
            - commands: Search for specific G-code commands by type
            - modify: Post-process G-code (inject commands, change temps, add timelapse, bed clear)
            - validate: Basic validation of G-code file structure
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
            'analyze' => $this->analyze($input),
            'stats' => $this->stats($input),
            'layers' => $this->layers($input),
            'commands' => $this->commands($input),
            'modify' => $this->modify($input),
            'validate' => $this->validate($input),
            default => ToolResult::error("Unknown gcode action: '{$action}'"),
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
                            'description' => 'The G-code action to perform.',
                            'enum' => ['analyze', 'stats', 'layers', 'commands', 'modify', 'validate'],
                        ],
                        'file' => [
                            'type' => 'string',
                            'description' => 'Path to the G-code file. Required for all actions.',
                        ],
                        'output_file' => [
                            'type' => 'string',
                            'description' => 'Output file path for modify action. If not specified, a modified copy is created alongside the original.',
                        ],
                        'modification' => [
                            'type' => 'string',
                            'description' => 'Type of modification. Used with "modify" action.',
                            'enum' => [
                                'inject_after_layer', 'bed_clear', 'prepend_start',
                                'replace_temp', 'timelapse', 'filament_change', 'pause',
                            ],
                        ],
                        'layer_number' => [
                            'type' => 'integer',
                            'description' => 'Layer number (0-based). Used with inject_after_layer, filament_change, pause.',
                        ],
                        'inject_commands' => [
                            'type' => 'array',
                            'description' => 'G-code commands to inject. Used with inject_after_layer, prepend_start.',
                            'items' => ['type' => 'string'],
                        ],
                        'nozzle_temp' => [
                            'type' => 'integer',
                            'description' => 'New nozzle temperature in °C. Used with replace_temp.',
                        ],
                        'bed_temp' => [
                            'type' => 'integer',
                            'description' => 'New bed temperature in °C. Used with replace_temp.',
                        ],
                        'timelapse_mode' => [
                            'type' => 'string',
                            'description' => 'Timelapse mode. Used with timelapse modification.',
                            'enum' => ['moonraker', 'gcode'],
                        ],
                        'search_commands' => [
                            'type' => 'array',
                            'description' => 'Command prefixes to search for (e.g. ["G28", "M104"]). Used with "commands" action.',
                            'items' => ['type' => 'string'],
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'Display message for filament_change or pause modification.',
                        ],
                        'present_z' => [
                            'type' => 'number',
                            'description' => 'Z height to move to when presenting bed. Used with bed_clear. Default: 200.',
                        ],
                        'present_y' => [
                            'type' => 'number',
                            'description' => 'Y position to push bed to when presenting. Used with bed_clear. Default: 220.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function analyze(array $input): ToolResult
    {
        $file = $this->requireFile($input);
        if ($file === null) {
            return ToolResult::error('The "file" parameter is required.');
        }

        try {
            $analysis = $this->parser->analyze($file);
        } catch (\RuntimeException $e) {
            return ToolResult::error("Analysis failed: {$e->getMessage()}");
        }

        $stats = $analysis['stats'];
        $counts = $analysis['counts'];
        $fileSize = $this->formatBytes($analysis['file_size']);

        $output = "### G-code Analysis: " . basename($file) . "\n\n";
        $output .= "**File size**: {$fileSize}\n";
        $output .= "**Layers**: {$analysis['layer_count']}\n\n";

        $output .= "#### Print Statistics\n\n";
        $output .= $stats->toMarkdown() . "\n";

        $output .= "#### Command Summary\n\n";
        $output .= "| Metric | Count |\n";
        $output .= "|--------|-------|\n";
        $output .= "| Total lines | {$counts['total_lines']} |\n";
        $output .= "| G-code commands | {$counts['command_lines']} |\n";
        $output .= "| Move commands | {$counts['move_commands']} |\n";
        $output .= "| Extrusion commands | {$counts['extrusion_commands']} |\n";
        $output .= "| Temperature commands | {$counts['temp_commands']} |\n";
        $output .= "| Comments | {$counts['comment_lines']} |\n";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stats(array $input): ToolResult
    {
        $file = $this->requireFile($input);
        if ($file === null) {
            return ToolResult::error('The "file" parameter is required.');
        }

        try {
            $stats = $this->parser->parse($file);
        } catch (\RuntimeException $e) {
            return ToolResult::error("Failed to parse stats: {$e->getMessage()}");
        }

        $output = "### G-code Statistics: " . basename($file) . "\n\n";
        $output .= $stats->toMarkdown();

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function layers(array $input): ToolResult
    {
        $file = $this->requireFile($input);
        if ($file === null) {
            return ToolResult::error('The "file" parameter is required.');
        }

        try {
            $layers = $this->parser->findLayerChanges($file);
        } catch (\RuntimeException $e) {
            return ToolResult::error("Failed to find layers: {$e->getMessage()}");
        }

        if ($layers === []) {
            return ToolResult::success("No layer changes found in {$file}.");
        }

        $output = "### Layer Changes: " . basename($file) . "\n\n";
        $output .= "Total layers: " . count($layers) . "\n\n";

        // Show first/last 10 layers for brevity
        $showAll = count($layers) <= 25;
        $display = $showAll ? $layers : [
            ...array_slice($layers, 0, 10),
            ...array_slice($layers, -10),
        ];

        $output .= "| Layer | Line | Z Height |\n";
        $output .= "|-------|------|----------|\n";

        $prevIdx = -1;
        foreach ($display as $idx => $layer) {
            if (!$showAll && $prevIdx !== -1 && $idx - $prevIdx > 1) {
                $output .= "| ... | ... | ... |\n";
            }
            $z = $layer['z'] !== null ? sprintf('%.2fmm', $layer['z']) : '—';
            $output .= "| {$idx} | {$layer['line']} | {$z} |\n";
            $prevIdx = $idx;
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function commands(array $input): ToolResult
    {
        $file = $this->requireFile($input);
        if ($file === null) {
            return ToolResult::error('The "file" parameter is required.');
        }

        /** @var list<string> $searchCommands */
        $searchCommands = is_array($input['search_commands'] ?? null) ? $input['search_commands'] : [];

        if ($searchCommands === []) {
            // Default to common interesting commands
            $searchCommands = ['G28', 'G29', 'M104', 'M109', 'M140', 'M190', 'M600', 'M0'];
        }

        try {
            $results = $this->parser->findCommands($file, $searchCommands);
        } catch (\RuntimeException $e) {
            return ToolResult::error("Command search failed: {$e->getMessage()}");
        }

        if ($results === []) {
            $cmds = implode(', ', $searchCommands);
            return ToolResult::success("No matching commands found for: {$cmds}");
        }

        $output = "### Command Search: " . basename($file) . "\n\n";
        $output .= "Found " . count($results) . " matches.\n\n";

        // Group by command
        $grouped = [];
        foreach ($results as $r) {
            $grouped[$r['command']][] = $r;
        }

        foreach ($grouped as $cmd => $matches) {
            $output .= "#### {$cmd} (" . count($matches) . " occurrences)\n\n";
            $showCount = min(count($matches), 10);
            for ($i = 0; $i < $showCount; $i++) {
                $output .= "- Line {$matches[$i]['line']}: `{$matches[$i]['full']}`\n";
            }
            if (count($matches) > 10) {
                $remaining = count($matches) - 10;
                $output .= "- ... and {$remaining} more\n";
            }
            $output .= "\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function modify(array $input): ToolResult
    {
        $file = $this->requireFile($input);
        if ($file === null) {
            return ToolResult::error('The "file" parameter is required.');
        }

        $modification = trim((string) ($input['modification'] ?? ''));
        if ($modification === '') {
            return ToolResult::error('The "modification" parameter is required.');
        }

        $outputFile = trim((string) ($input['output_file'] ?? ''));
        if ($outputFile === '') {
            // Generate output filename
            $dir = dirname($file);
            $base = pathinfo(basename($file), PATHINFO_FILENAME);
            $outputFile = "{$dir}/{$base}_modified.gcode";
        }

        try {
            match ($modification) {
                'inject_after_layer' => $this->modifyInjectAfterLayer($file, $outputFile, $input),
                'bed_clear' => $this->modifyBedClear($file, $outputFile, $input),
                'prepend_start' => $this->modifyPrependStart($file, $outputFile, $input),
                'replace_temp' => $this->modifyReplaceTemp($file, $outputFile, $input),
                'timelapse' => $this->modifyTimelapse($file, $outputFile, $input),
                'filament_change' => $this->modifyFilamentChange($file, $outputFile, $input),
                'pause' => $this->modifyPause($file, $outputFile, $input),
                default => throw new \RuntimeException("Unknown modification type: '{$modification}'"),
            };
        } catch (\RuntimeException $e) {
            return ToolResult::error("Modification failed: {$e->getMessage()}");
        }

        return ToolResult::success(
            "G-code modified successfully.\n\n"
            . "**Original**: {$file}\n"
            . "**Modified**: {$outputFile}\n"
            . "**Modification**: {$modification}",
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function validate(array $input): ToolResult
    {
        $file = $this->requireFile($input);
        if ($file === null) {
            return ToolResult::error('The "file" parameter is required.');
        }

        $issues = [];
        $info = [];

        try {
            $counts = $this->parser->countCommands($file);
            $stats = $this->parser->parse($file);
        } catch (\RuntimeException $e) {
            return ToolResult::error("Validation failed: {$e->getMessage()}");
        }

        // Basic structural checks
        if ($counts['command_lines'] === 0) {
            $issues[] = 'No G-code commands found — file may be empty or corrupted.';
        }

        if ($counts['move_commands'] === 0) {
            $issues[] = 'No movement commands (G0/G1) found — file may not contain valid print data.';
        }

        if ($counts['extrusion_commands'] === 0) {
            $issues[] = 'No extrusion commands found — file may not produce any printed material.';
        }

        if ($counts['temp_commands'] === 0) {
            $issues[] = 'No temperature commands found — printer may not heat up.';
        }

        // Check for home command
        $homeCommands = $this->parser->findCommands($file, ['G28']);
        if ($homeCommands === []) {
            $issues[] = 'No G28 (home) command found — print may start without homing.';
        }

        // Metadata checks
        if ($stats->estimatedTimeSeconds !== null) {
            $info[] = "Estimated time: {$stats->formattedEstimatedTime()}";
        }
        if ($stats->filamentType !== null) {
            $info[] = "Filament: {$stats->filamentType}";
        }
        if ($stats->nozzleTemp !== null) {
            $info[] = "Nozzle temp: {$stats->nozzleTemp}°C";
            if ($stats->nozzleTemp > 300) {
                $issues[] = "Nozzle temperature ({$stats->nozzleTemp}°C) is unusually high — verify this is correct.";
            }
        }
        if ($stats->bedTemp !== null) {
            $info[] = "Bed temp: {$stats->bedTemp}°C";
            if ($stats->bedTemp > 120) {
                $issues[] = "Bed temperature ({$stats->bedTemp}°C) is unusually high — verify this is correct.";
            }
        }

        $output = "### G-code Validation: " . basename($file) . "\n\n";
        $output .= "**Lines**: {$counts['total_lines']}\n";
        $output .= "**Commands**: {$counts['command_lines']}\n\n";

        if ($info !== []) {
            $output .= "#### Info\n\n";
            foreach ($info as $i) {
                $output .= "- {$i}\n";
            }
            $output .= "\n";
        }

        if ($issues === []) {
            $output .= "**Result**: No issues found. G-code appears valid.\n";
        } else {
            $output .= "#### Issues Found (" . count($issues) . ")\n\n";
            foreach ($issues as $issue) {
                $output .= "- ⚠ {$issue}\n";
            }
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requireFile(array $input): ?string
    {
        $file = trim((string) ($input['file'] ?? ''));
        if ($file === '') {
            return null;
        }

        if (!file_exists($file)) {
            return null;
        }

        return $file;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function modifyInjectAfterLayer(string $file, string $outputFile, array $input): void
    {
        $layerNumber = (int) ($input['layer_number'] ?? throw new \RuntimeException(
            'The "layer_number" parameter is required for inject_after_layer.',
        ));
        /** @var list<string> $commands */
        $commands = is_array($input['inject_commands'] ?? null)
            ? $input['inject_commands']
            : throw new \RuntimeException('The "inject_commands" parameter is required for inject_after_layer.');

        $this->modifier->injectAfterLayer($file, $outputFile, $layerNumber, $commands);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function modifyBedClear(string $file, string $outputFile, array $input): void
    {
        $presentZ = (float) ($input['present_z'] ?? 200.0);
        $presentY = (float) ($input['present_y'] ?? 220.0);

        $this->modifier->appendBedClear($file, $outputFile, $presentZ, $presentY);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function modifyPrependStart(string $file, string $outputFile, array $input): void
    {
        /** @var list<string> $commands */
        $commands = is_array($input['inject_commands'] ?? null)
            ? $input['inject_commands']
            : throw new \RuntimeException('The "inject_commands" parameter is required for prepend_start.');

        $this->modifier->prependStartGcode($file, $outputFile, $commands);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function modifyReplaceTemp(string $file, string $outputFile, array $input): void
    {
        $nozzleTemp = isset($input['nozzle_temp']) ? (int) $input['nozzle_temp'] : null;
        $bedTemp = isset($input['bed_temp']) ? (int) $input['bed_temp'] : null;

        $this->modifier->replaceTemperature($file, $outputFile, $nozzleTemp, $bedTemp);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function modifyTimelapse(string $file, string $outputFile, array $input): void
    {
        $mode = trim((string) ($input['timelapse_mode'] ?? 'moonraker'));
        /** @var list<string>|null $customCommands */
        $customCommands = is_array($input['inject_commands'] ?? null) ? $input['inject_commands'] : null;

        $this->modifier->injectTimelapse($file, $outputFile, $mode, $customCommands);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function modifyFilamentChange(string $file, string $outputFile, array $input): void
    {
        $layerNumber = (int) ($input['layer_number'] ?? throw new \RuntimeException(
            'The "layer_number" parameter is required for filament_change.',
        ));
        $message = trim((string) ($input['message'] ?? ''));

        $this->modifier->insertFilamentChange(
            $file,
            $outputFile,
            $layerNumber,
            $message !== '' ? $message : null,
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function modifyPause(string $file, string $outputFile, array $input): void
    {
        $layerNumber = (int) ($input['layer_number'] ?? throw new \RuntimeException(
            'The "layer_number" parameter is required for pause.',
        ));
        $message = trim((string) ($input['message'] ?? ''));

        $this->modifier->insertPause(
            $file,
            $outputFile,
            $layerNumber,
            $message !== '' ? $message : null,
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
