<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitSlicedd\Gcode;

/**
 * Extracted statistics from a G-code file's header comments.
 *
 * OrcaSlicer (and PrusaSlicer/BambuStudio) embed metadata as comments
 * at the start and end of the generated G-code. This value object
 * captures the most useful fields.
 */
final readonly class GcodeStats
{
    public function __construct(
        public ?float $estimatedTimeSeconds = null,
        public ?float $filamentUsedMm = null,
        public ?float $filamentUsedG = null,
        public ?float $filamentCost = null,
        public ?int $layerCount = null,
        public ?float $layerHeight = null,
        public ?float $firstLayerHeight = null,
        public ?int $nozzleTemp = null,
        public ?int $bedTemp = null,
        public ?float $nozzleDiameter = null,
        public ?string $filamentType = null,
        public ?string $printerModel = null,
        public ?string $slicerVersion = null,
        public ?float $infillDensity = null,
        public ?int $wallLoops = null,
        public ?float $totalLayerTime = null,
    ) {}

    /**
     * Parse G-code header comments into a GcodeStats instance.
     *
     * Handles comment formats from OrcaSlicer, PrusaSlicer, and BambuStudio:
     * - `; key = value`
     * - `; key: value`
     * - `; key : value`
     *
     * @param array<string, string> $headerComments Key-value pairs from parsed comments
     */
    public static function fromHeader(array $headerComments): self
    {
        // Normalize keys to lowercase with underscores
        $normalized = [];
        foreach ($headerComments as $key => $value) {
            $normalizedKey = strtolower(trim($key));
            $normalizedKey = str_replace([' ', '-'], '_', $normalizedKey);
            $normalized[$normalizedKey] = trim($value);
        }

        return new self(
            estimatedTimeSeconds: self::parseTime($normalized),
            filamentUsedMm: self::parseFloat($normalized, [
                'filament_used_[mm]', 'filament_used_mm', 'total_filament_used_[mm]',
            ]),
            filamentUsedG: self::parseFloat($normalized, [
                'filament_used_[g]', 'filament_used_g', 'total_filament_used_[g]',
                'filament_weight_[g]',
            ]),
            filamentCost: self::parseFloat($normalized, [
                'filament_cost', 'total_filament_cost',
            ]),
            layerCount: self::parseInt($normalized, [
                'total_layer_count', 'total_layers', 'layer_count',
            ]),
            layerHeight: self::parseFloat($normalized, [
                'layer_height', 'layer_height_[mm]',
            ]),
            firstLayerHeight: self::parseFloat($normalized, [
                'first_layer_height', 'initial_layer_height',
            ]),
            nozzleTemp: self::parseInt($normalized, [
                'nozzle_temperature', 'temperature', 'nozzle_temperature_initial_layer',
            ]),
            bedTemp: self::parseInt($normalized, [
                'bed_temperature', 'bed_temperature_initial_layer', 'first_layer_bed_temperature',
            ]),
            nozzleDiameter: self::parseFloat($normalized, [
                'nozzle_diameter',
            ]),
            filamentType: self::parseString($normalized, [
                'filament_type', 'filament_name',
            ]),
            printerModel: self::parseString($normalized, [
                'printer_model', 'printer_settings_id', 'machine_name',
            ]),
            slicerVersion: self::parseString($normalized, [
                'generated_by', 'slicer_version', 'slicer',
            ]),
            infillDensity: self::parseFloat($normalized, [
                'sparse_infill_density', 'fill_density', 'infill_density',
            ]),
            wallLoops: self::parseInt($normalized, [
                'wall_loops', 'perimeters', 'wall_count',
            ]),
        );
    }

    /**
     * Format estimated time as human-readable string.
     */
    public function formattedEstimatedTime(): string
    {
        if ($this->estimatedTimeSeconds === null) {
            return 'unknown';
        }

        $seconds = (int) $this->estimatedTimeSeconds;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m {$secs}s";
        }

        if ($minutes > 0) {
            return "{$minutes}m {$secs}s";
        }

        return "{$secs}s";
    }

    /**
     * Format filament usage as human-readable string.
     */
    public function formattedFilamentUsed(): string
    {
        $parts = [];
        if ($this->filamentUsedG !== null) {
            $parts[] = round($this->filamentUsedG, 1) . 'g';
        }
        if ($this->filamentUsedMm !== null) {
            $parts[] = round($this->filamentUsedMm, 1) . 'mm';
        }

        return $parts !== [] ? implode(' / ', $parts) : 'unknown';
    }

    /**
     * Convert stats to a markdown-friendly summary table.
     */
    public function toMarkdown(): string
    {
        $rows = [];

        if ($this->estimatedTimeSeconds !== null) {
            $rows[] = ['Estimated Time', $this->formattedEstimatedTime()];
        }
        if ($this->filamentUsedG !== null || $this->filamentUsedMm !== null) {
            $rows[] = ['Filament Used', $this->formattedFilamentUsed()];
        }
        if ($this->filamentCost !== null) {
            $rows[] = ['Filament Cost', '$' . number_format($this->filamentCost, 2)];
        }
        if ($this->layerCount !== null) {
            $rows[] = ['Layers', (string) $this->layerCount];
        }
        if ($this->layerHeight !== null) {
            $rows[] = ['Layer Height', $this->layerHeight . 'mm'];
        }
        if ($this->nozzleTemp !== null) {
            $rows[] = ['Nozzle Temp', $this->nozzleTemp . '°C'];
        }
        if ($this->bedTemp !== null) {
            $rows[] = ['Bed Temp', $this->bedTemp . '°C'];
        }
        if ($this->filamentType !== null) {
            $rows[] = ['Filament Type', $this->filamentType];
        }
        if ($this->printerModel !== null) {
            $rows[] = ['Printer', $this->printerModel];
        }
        if ($this->infillDensity !== null) {
            $rows[] = ['Infill', $this->infillDensity . '%'];
        }
        if ($this->wallLoops !== null) {
            $rows[] = ['Wall Loops', (string) $this->wallLoops];
        }
        if ($this->slicerVersion !== null) {
            $rows[] = ['Slicer', $this->slicerVersion];
        }

        if ($rows === []) {
            return 'No statistics available.';
        }

        $output = "| Property | Value |\n|----------|-------|\n";
        foreach ($rows as [$key, $value]) {
            $output .= "| **{$key}** | {$value} |\n";
        }

        return $output;
    }

    /**
     * Parse estimated time from various formats.
     *
     * OrcaSlicer: `; estimated printing time (normal mode) = 2h 15m 30s`
     * PrusaSlicer: `; estimated printing time = 2h 15m 30s`
     * Also handles raw seconds.
     *
     * @param array<string, string> $data
     */
    private static function parseTime(array $data): ?float
    {
        $keys = [
            'estimated_printing_time_(normal_mode)',
            'estimated_printing_time',
            'print_time',
            'estimated_time',
        ];

        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }

            $value = $data[$key];

            // Try "Xh Ym Zs" format
            $seconds = 0;
            $matched = false;

            if (preg_match('/(\d+)\s*h/', $value, $m)) {
                $seconds += (int) $m[1] * 3600;
                $matched = true;
            }
            if (preg_match('/(\d+)\s*m/', $value, $m)) {
                $seconds += (int) $m[1] * 60;
                $matched = true;
            }
            if (preg_match('/(\d+)\s*s/', $value, $m)) {
                $seconds += (int) $m[1];
                $matched = true;
            }

            if ($matched) {
                return (float) $seconds;
            }

            // Try raw numeric (seconds)
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $data
     * @param list<string> $keys
     */
    private static function parseFloat(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $data
     * @param list<string> $keys
     */
    private static function parseInt(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $data
     * @param list<string> $keys
     */
    private static function parseString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }
}
