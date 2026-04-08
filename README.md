# Slicedd

A Coqui toolkit for 3D printing with OrcaSlicer. Slice 3D models, analyze and post-process G-code, and track job history — all through conversational AI.

## Requirements

- PHP 8.4+
- [OrcaSlicer](https://github.com/SoftFever/OrcaSlicer/releases) installed and available in PATH (or configured via `ORCA_SLICER_PATH`)
- [Coqui](https://github.com/coquibot/coqui) with `carmelosantana/php-agents`

## Installation

```bash
composer require coquibot/coqui-toolkit-slicedd
```

Coqui auto-discovers the toolkit on startup. No manual registration needed.

## Tools

### `slicer` — OrcaSlicer CLI Wrapper

| Action | Description |
|--------|-------------|
| `check_deps` | Verify OrcaSlicer is installed and available |
| `slice` | Slice a 3D model (STL, 3MF, STEP, OBJ, AMF) with profiles and overrides |
| `info` | Get OrcaSlicer version and binary info |
| `export_settings` | Export slicer settings from a 3MF project |
| `export_3mf` | Export a model as a 3MF project file |
| `export_stl` | Export a model as STL |
| `list_profiles` | Discover available machine, process, and filament profiles |
| `job_history` | Query slice job history with statistics |

### `gcode` — G-code Analysis & Post-Processing

| Action | Description |
|--------|-------------|
| `analyze` | Full analysis: print stats, layer count, command counts, file size |
| `stats` | Extract metadata from G-code header/footer comments |
| `layers` | Find all layer change positions and Z heights |
| `commands` | Search for specific G-code commands (G28, M104, etc.) |
| `modify` | Post-process G-code (inject commands, change temps, timelapse, etc.) |
| `validate` | Check G-code structure for common issues |

#### Modifications

| Type | Description |
|------|-------------|
| `inject_after_layer` | Insert custom G-code at a specific layer |
| `bed_clear` | Append bed presentation/clearing sequence |
| `prepend_start` | Add custom start G-code before first command |
| `replace_temp` | Change nozzle and/or bed temperatures |
| `timelapse` | Inject timelapse triggers at every layer (Moonraker or custom) |
| `filament_change` | Insert M600 filament change at a layer |
| `pause` | Insert M0 pause at a layer |

## Configuration

### OrcaSlicer Binary

The toolkit searches for the OrcaSlicer binary in this order:

1. `ORCA_SLICER_PATH` environment variable / credential
2. `orca-slicer` in PATH
3. `OrcaSlicer` in PATH
4. `orca_slicer` in PATH

Set the credential through Coqui:

```
Set my OrcaSlicer path to /usr/local/bin/orca-slicer
```

### Profiles

OrcaSlicer profiles are discovered from the standard config directory:

- **Linux**: `~/.config/OrcaSlicer/user/<printer>/<type>/*.json`
- **macOS**: `~/Library/Application Support/OrcaSlicer/user/<printer>/<type>/*.json`

Profile types: `machine`, `process`, `filament`

You can also provide custom profile JSON files by path.

## Example Conversations

**Slice a model:**
> Slice my benchy.stl with the Ender 3 S1 Pro profile at 0.2mm layer height

**Analyze G-code:**
> Analyze the G-code file at /home/user/prints/benchy.gcode

**Post-process for timelapse:**
> Add Moonraker timelapse triggers to my G-code file

**Check print stats:**
> Show me my slice job history and total filament usage

**Modify temperatures:**
> Change the nozzle temperature to 210°C in my G-code file

## Architecture

```
src/
├── SliceddToolkit.php          # Toolkit entry point (auto-discovered)
├── Gcode/
│   ├── GcodeModifier.php       # G-code post-processing (immutable, file-based)
│   ├── GcodeParser.php         # G-code file reader and analyzer
│   └── GcodeStats.php          # Parsed statistics value object
├── Runtime/
│   ├── DependencyChecker.php   # Binary resolution and availability
│   ├── OrcaSlicerRunner.php    # CLI wrapper (proc_open, timeout, truncation)
│   └── SlicerResult.php        # Immutable CLI result value object
├── Storage/
│   └── JobStore.php            # SQLite job history (WAL mode)
└── Tool/
    ├── GcodeTool.php           # G-code ToolInterface implementation
    └── SlicerTool.php          # Slicer ToolInterface implementation
```

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test
# or
./vendor/bin/pest

# Static analysis
./vendor/bin/phpstan analyse
```

## Roadmap

### Phase 2 — Fleet Management
- Moonraker API client for Klipper-based printers
- Bambu MQTT client for P1S/X1 series
- Printer status monitoring and job queue
- Remote file upload and print start

### Phase 3 — Material & Monitoring
- Material/spool tracking (weight, cost, usage)
- Print time estimation improvements
- Build plate and nozzle wear tracking

## License

MIT
