<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitSlicedd;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitSlicedd\Gcode\GcodeModifier;
use CarmeloSantana\CoquiToolkitSlicedd\Gcode\GcodeParser;
use CarmeloSantana\CoquiToolkitSlicedd\Runtime\DependencyChecker;
use CarmeloSantana\CoquiToolkitSlicedd\Runtime\OrcaSlicerRunner;
use CarmeloSantana\CoquiToolkitSlicedd\Storage\JobStore;
use CarmeloSantana\CoquiToolkitSlicedd\Tool\GcodeTool;
use CarmeloSantana\CoquiToolkitSlicedd\Tool\SlicerTool;

/**
 * Slicedd — 3D printing toolkit for Coqui.
 *
 * Wraps OrcaSlicer CLI for slicing 3D models and provides G-code
 * analysis and post-processing tools. Tracks slice job history
 * in a SQLite database.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 */
final class SliceddToolkit implements ToolkitInterface
{
    private readonly OrcaSlicerRunner $runner;
    private readonly JobStore $jobs;
    private readonly DependencyChecker $deps;
    private readonly GcodeParser $parser;
    private readonly GcodeModifier $modifier;

    public function __construct(
        string $workspacePath,
        ?OrcaSlicerRunner $runner = null,
        ?JobStore $jobs = null,
        ?DependencyChecker $deps = null,
        ?GcodeParser $parser = null,
        ?GcodeModifier $modifier = null,
    ) {
        $this->deps = $deps ?? new DependencyChecker();
        $this->runner = $runner ?? new OrcaSlicerRunner(
            workspacePath: $workspacePath,
            deps: $this->deps,
        );
        $this->jobs = $jobs ?? new JobStore($workspacePath . '/slicedd/jobs.db');
        $this->parser = $parser ?? new GcodeParser();
        $this->modifier = $modifier ?? new GcodeModifier();
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if ($workspacePath === false || $workspacePath === '') {
            $workspacePath = getcwd() . '/.workspace';
        }

        return new self(workspacePath: $workspacePath);
    }

    public function tools(): array
    {
        return [
            new SlicerTool($this->runner, $this->jobs, $this->deps),
            new GcodeTool($this->parser, $this->modifier),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <SLICEDD-TOOLKIT-GUIDELINES>
            ## 3D Printing — OrcaSlicer & G-code Management

            You have 2 tools for 3D model slicing and G-code post-processing.

            ### Tool Overview

            | Tool | Purpose | Key Actions |
            |------|---------|-------------|
            | `slicer` | Slice 3D models with OrcaSlicer | check_deps, slice, info, export_settings, export_3mf, export_stl, list_profiles, job_history |
            | `gcode` | Analyze and modify G-code files | analyze, stats, layers, commands, modify, validate |

            ### Workflow: Slice a 3D Model

            1. **Check dependencies**: `slicer` action `check_deps` — verifies OrcaSlicer is installed
            2. **List profiles**: `slicer` action `list_profiles` — discover available machine, process, and filament profiles
            3. **Slice**: `slicer` action `slice` with `input_file`, `profile_machine`, `profile_process`, `profile_filament`
            4. **Analyze output**: `gcode` action `analyze` on the generated G-code file
            5. **Validate**: `gcode` action `validate` to check for common issues

            ### Slicing Parameters

            The `slice` action accepts:
            - `input_file` (required): Path to STL, 3MF, STEP, OBJ, or AMF file
            - `profile_machine`: Machine profile JSON (printer definition)
            - `profile_process`: Process profile JSON (print settings — layer height, speed, infill)
            - `profile_filament`: Filament profile JSON (temps, retraction, cooling)
            - `plate`: Plate number (0-based, default: 0)
            - `config_overrides`: Inline key-value overrides (e.g. `{"layer_height": "0.2"}`)
            - `arrange`: Auto-arrange objects on build plate
            - `orient`: Auto-orient for optimal printing

            ### G-code Post-Processing

            The `gcode` tool's `modify` action supports:
            - `inject_after_layer`: Add custom G-code at a specific layer
            - `bed_clear`: Append bed presentation/clearing sequence
            - `prepend_start`: Add custom start G-code
            - `replace_temp`: Change nozzle and/or bed temperatures
            - `timelapse`: Inject timelapse triggers (Moonraker or custom G-code) at every layer
            - `filament_change`: Insert M600 filament change at a layer
            - `pause`: Insert M0 pause at a layer

            **Important**: Modifications create a new file — the original is never changed.

            ### Profile Discovery

            OrcaSlicer profiles are stored at:
            - Linux: `~/.config/OrcaSlicer/user/<printer>/<type>/*.json`
            - macOS: `~/Library/Application Support/OrcaSlicer/user/<printer>/<type>/*.json`

            Types: `machine`, `process`, `filament`

            Users can also provide custom profile JSON files directly as paths.

            ### Config Overrides

            Any OrcaSlicer config key can be overridden inline:
            ```json
            {
                "layer_height": "0.2",
                "sparse_infill_density": "20%",
                "wall_loops": "3",
                "support_type": "normal(auto)"
            }
            ```

            ### Job History

            Every slice is recorded. Use `job_history` to query past slices, filter by status, and see aggregate stats (total filament used, total print time, success/failure rates).

            ### Supported File Formats

            - **Input**: STL, 3MF, STEP, OBJ, AMF
            - **Output**: G-code (.gcode), 3MF project (.3mf), STL (.stl)

            ### Tips

            - Always `check_deps` first if unsure whether OrcaSlicer is installed
            - Use `analyze` on G-code to get print time estimates and filament usage
            - Use `validate` to catch common issues before sending to a printer
            - Use `modify` with `timelapse` mode `moonraker` for Klipper-based printers
            - The `config_overrides` parameter is powerful — it overrides any profile setting
            </SLICEDD-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
