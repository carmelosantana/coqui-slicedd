<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitSlicedd\Runtime;

/**
 * Immutable result from an OrcaSlicer CLI command execution.
 */
final readonly class SlicerResult
{
    /**
     * @param array<string, mixed>|null $resultJson Parsed result.json from OrcaSlicer, if available
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?array $resultJson = null,
    ) {}

    public function success(): bool
    {
        return $this->exitCode === 0;
    }

    public function output(): string
    {
        return $this->stdout;
    }

    public function error(): string
    {
        if ($this->stderr !== '') {
            return $this->stderr;
        }

        if (!$this->success()) {
            return "Command failed with exit code {$this->exitCode}";
        }

        return '';
    }

    /**
     * Extract per-plate slice statistics from result.json.
     *
     * OrcaSlicer writes a result.json after slicing with fields like:
     * return_code, plate_index, sliced_time, triangle_count, warning_message.
     *
     * @return array{
     *     return_code: int,
     *     plates: list<array{plate_index: int, sliced_time: float, triangle_count: int}>,
     *     prepare_time: float,
     *     export_time: float,
     *     warnings: list<string>,
     * }|null
     */
    public function sliceStats(): ?array
    {
        if ($this->resultJson === null) {
            return null;
        }

        $json = $this->resultJson;
        $plates = [];

        // result.json may contain per-plate data
        if (isset($json['plate_index'])) {
            $plates[] = [
                'plate_index' => (int) $json['plate_index'],
                'sliced_time' => (float) ($json['sliced_time'] ?? 0),
                'triangle_count' => (int) ($json['triangle_count'] ?? 0),
            ];
        }

        // Or it may be an array of plates
        if (isset($json['plates']) && is_array($json['plates'])) {
            foreach ($json['plates'] as $plate) {
                $plates[] = [
                    'plate_index' => (int) ($plate['plate_index'] ?? 0),
                    'sliced_time' => (float) ($plate['sliced_time'] ?? 0),
                    'triangle_count' => (int) ($plate['triangle_count'] ?? 0),
                ];
            }
        }

        $warnings = [];
        if (isset($json['warning_message']) && $json['warning_message'] !== '') {
            $warnings[] = (string) $json['warning_message'];
        }

        return [
            'return_code' => (int) ($json['return_code'] ?? $this->exitCode),
            'plates' => $plates,
            'prepare_time' => (float) ($json['prepare_time'] ?? 0),
            'export_time' => (float) ($json['export_time'] ?? 0),
            'warnings' => $warnings,
        ];
    }
}
