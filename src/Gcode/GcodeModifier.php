<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitSlicedd\Gcode;

use RuntimeException;

/**
 * Post-processing modifier for G-code files.
 *
 * All operations are file-based and immutable: they read from an input
 * file and write a modified copy to an output file. The original is
 * never mutated.
 */
final class GcodeModifier
{
    /**
     * Inject G-code commands after a specific layer change.
     *
     * Useful for inserting pause commands, filament changes, or
     * timelapse triggers at specific layer boundaries.
     *
     * @param list<string> $commands G-code commands to inject
     */
    public function injectAfterLayer(
        string $inputFile,
        string $outputFile,
        int $layerNumber,
        array $commands,
    ): void {
        $this->assertReadable($inputFile);

        $currentLayer = -1;
        $injected = false;
        $commentBlock = "; --- Injected after layer {$layerNumber} by Slicedd ---";

        $output = fopen($outputFile, 'w');
        if ($output === false) {
            throw new RuntimeException("Cannot create output file: {$outputFile}");
        }

        try {
            $parser = new GcodeParser();
            foreach ($parser->readLines($inputFile) as $line) {
                fwrite($output, $line . "\n");

                if (str_contains($line, ';LAYER_CHANGE') || str_contains($line, '; CHANGE_LAYER')) {
                    $currentLayer++;

                    if ($currentLayer === $layerNumber && !$injected) {
                        fwrite($output, $commentBlock . "\n");
                        foreach ($commands as $cmd) {
                            fwrite($output, $cmd . "\n");
                        }
                        fwrite($output, "; --- End injection ---\n");
                        $injected = true;
                    }
                }
            }
        } finally {
            fclose($output);
        }

        if (!$injected) {
            throw new RuntimeException(
                "Layer {$layerNumber} not found in G-code. File has layers 0-{$currentLayer}.",
            );
        }
    }

    /**
     * Append bed-clearing G-code at the end of the file.
     *
     * Adds commands to move the print head up, present the bed,
     * and disable heaters after the print completes.
     */
    public function appendBedClear(
        string $inputFile,
        string $outputFile,
        float $presentZ = 200.0,
        float $presentY = 220.0,
    ): void {
        $this->assertReadable($inputFile);

        $bedClear = [
            '; --- Bed clear sequence by Slicedd ---',
            'G91 ; relative positioning',
            'G1 Z5 F3000 ; lift nozzle 5mm',
            'G90 ; absolute positioning',
            sprintf('G1 Z%.1f F3000 ; move Z up to present height', $presentZ),
            sprintf('G1 Y%.1f F3000 ; push bed forward', $presentY),
            'M104 S0 ; turn off hotend',
            'M140 S0 ; turn off bed',
            'M107 ; turn off fan',
            'M84 ; disable steppers',
            '; --- End bed clear ---',
        ];

        $this->appendLines($inputFile, $outputFile, $bedClear);
    }

    /**
     * Prepend custom start G-code before the first non-comment line.
     *
     * @param list<string> $startCommands G-code commands to prepend
     */
    public function prependStartGcode(
        string $inputFile,
        string $outputFile,
        array $startCommands,
    ): void {
        $this->assertReadable($inputFile);

        $output = fopen($outputFile, 'w');
        if ($output === false) {
            throw new RuntimeException("Cannot create output file: {$outputFile}");
        }

        $inserted = false;

        try {
            $parser = new GcodeParser();
            foreach ($parser->readLines($inputFile) as $line) {
                // Insert before the first actual G-code command
                if (!$inserted && !str_starts_with(trim($line), ';') && trim($line) !== '') {
                    fwrite($output, "; --- Custom start G-code by Slicedd ---\n");
                    foreach ($startCommands as $cmd) {
                        fwrite($output, $cmd . "\n");
                    }
                    fwrite($output, "; --- End custom start ---\n");
                    $inserted = true;
                }

                fwrite($output, $line . "\n");
            }
        } finally {
            fclose($output);
        }
    }

    /**
     * Replace temperature values in the G-code.
     *
     * Modifies M104/M109 (hotend) and M140/M190 (bed) commands
     * to use new temperature values.
     */
    public function replaceTemperature(
        string $inputFile,
        string $outputFile,
        ?int $nozzleTemp = null,
        ?int $bedTemp = null,
    ): void {
        $this->assertReadable($inputFile);

        if ($nozzleTemp === null && $bedTemp === null) {
            throw new RuntimeException('At least one temperature must be specified.');
        }

        $output = fopen($outputFile, 'w');
        if ($output === false) {
            throw new RuntimeException("Cannot create output file: {$outputFile}");
        }

        try {
            $parser = new GcodeParser();
            foreach ($parser->readLines($inputFile) as $line) {
                $modified = $line;

                if ($nozzleTemp !== null) {
                    // M104 Sxxx (set hotend) / M109 Sxxx (wait for hotend)
                    $modified = preg_replace(
                        '/^(M10[49]\s+S)\d+/',
                        '${1}' . $nozzleTemp,
                        $modified,
                    ) ?? $modified;
                }

                if ($bedTemp !== null) {
                    // M140 Sxxx (set bed) / M190 Sxxx (wait for bed)
                    $modified = preg_replace(
                        '/^(M1[49]0\s+S)\d+/',
                        '${1}' . $bedTemp,
                        $modified,
                    ) ?? $modified;
                }

                fwrite($output, $modified . "\n");
            }
        } finally {
            fclose($output);
        }
    }

    /**
     * Inject timelapse trigger commands at every layer change.
     *
     * Supports multiple timelapse modes:
     * - `gcode`: Inject custom G-code (e.g. camera trigger)
     * - `moonraker`: Inject Moonraker timelapse macro call
     *
     * @param list<string>|null $customCommands Custom G-code for 'gcode' mode
     */
    public function injectTimelapse(
        string $inputFile,
        string $outputFile,
        string $mode = 'moonraker',
        ?array $customCommands = null,
    ): void {
        $this->assertReadable($inputFile);

        $triggerCommands = match ($mode) {
            'moonraker' => ['TIMELAPSE_TAKE_FRAME'],
            'gcode' => $customCommands ?? throw new RuntimeException(
                'Custom commands required for gcode timelapse mode.',
            ),
            default => throw new RuntimeException("Unknown timelapse mode: {$mode}"),
        };

        $output = fopen($outputFile, 'w');
        if ($output === false) {
            throw new RuntimeException("Cannot create output file: {$outputFile}");
        }

        try {
            $parser = new GcodeParser();
            foreach ($parser->readLines($inputFile) as $line) {
                fwrite($output, $line . "\n");

                if (str_contains($line, ';LAYER_CHANGE') || str_contains($line, '; CHANGE_LAYER')) {
                    fwrite($output, "; --- Timelapse trigger ({$mode}) ---\n");
                    foreach ($triggerCommands as $cmd) {
                        fwrite($output, $cmd . "\n");
                    }
                }
            }
        } finally {
            fclose($output);
        }
    }

    /**
     * Insert a filament change (M600) at a specific layer.
     *
     * Commonly used for multi-color prints on single-extruder printers.
     */
    public function insertFilamentChange(
        string $inputFile,
        string $outputFile,
        int $layerNumber,
        ?string $message = null,
    ): void {
        $commands = [];

        if ($message !== null) {
            $commands[] = "M117 {$message}";
        }

        $commands[] = 'M600 ; filament change';

        $this->injectAfterLayer($inputFile, $outputFile, $layerNumber, $commands);
    }

    /**
     * Add a pause at a specific layer.
     */
    public function insertPause(
        string $inputFile,
        string $outputFile,
        int $layerNumber,
        ?string $message = null,
    ): void {
        $commands = [];

        if ($message !== null) {
            $commands[] = "M117 {$message}";
        }

        $commands[] = 'M0 ; pause print';

        $this->injectAfterLayer($inputFile, $outputFile, $layerNumber, $commands);
    }

    /**
     * Append lines to the end of a G-code file.
     *
     * @param list<string> $lines Lines to append
     */
    private function appendLines(string $inputFile, string $outputFile, array $lines): void
    {
        // Copy the original file
        if (!copy($inputFile, $outputFile)) {
            throw new RuntimeException("Cannot copy {$inputFile} to {$outputFile}");
        }

        $handle = fopen($outputFile, 'a');
        if ($handle === false) {
            throw new RuntimeException("Cannot open output file for append: {$outputFile}");
        }

        try {
            fwrite($handle, "\n");
            foreach ($lines as $line) {
                fwrite($handle, $line . "\n");
            }
        } finally {
            fclose($handle);
        }
    }

    private function assertReadable(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("G-code file not found: {$filePath}");
        }
        if (!is_readable($filePath)) {
            throw new RuntimeException("G-code file not readable: {$filePath}");
        }
    }
}
