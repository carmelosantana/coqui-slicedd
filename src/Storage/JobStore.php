<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitSlicedd\Storage;

use PDO;

/**
 * SQLite-backed slice job history tracking.
 *
 * Records every slice operation with input file, settings used, output path,
 * timestamps, and statistics (estimated time, filament usage, layer count).
 * Provides auditability and reproducibility for the slicing pipeline.
 */
final class JobStore
{
    private ?PDO $db = null;

    public function __construct(
        private readonly string $dbPath,
    ) {}

    /**
     * Record a new slice job (pending/slicing state). Returns auto-generated ID.
     *
     * @param array<string, string>|null $configOverrides
     */
    public function recordJob(
        string $inputFile,
        ?string $profileMachine = null,
        ?string $profileProcess = null,
        ?string $profileFilament = null,
        ?array $configOverrides = null,
    ): string {
        $id = bin2hex(random_bytes(12));
        $now = date('c');

        $stmt = $this->db()->prepare(<<<SQL
            INSERT INTO slice_jobs (
                id, input_file, output_file,
                profile_machine, profile_process, profile_filament,
                config_overrides, status,
                estimated_time_s, filament_used_g, layer_count, plate_count,
                warnings, created_at, completed_at
            ) VALUES (
                :id, :input_file, '',
                :profile_machine, :profile_process, :profile_filament,
                :config_overrides, 'slicing',
                NULL, NULL, NULL, NULL,
                '[]', :created_at, NULL
            )
        SQL);

        $stmt->execute([
            ':id' => $id,
            ':input_file' => $inputFile,
            ':profile_machine' => $profileMachine ?? '',
            ':profile_process' => $profileProcess ?? '',
            ':profile_filament' => $profileFilament ?? '',
            ':config_overrides' => json_encode($configOverrides ?? [], JSON_THROW_ON_ERROR),
            ':created_at' => $now,
        ]);

        return $id;
    }

    /**
     * Update job status and optionally set completion fields.
     *
     * @param list<string>|null $warnings
     */
    public function updateJobStatus(
        string $id,
        string $status,
        ?string $outputFile = null,
        ?int $estimatedTimeS = null,
        ?float $filamentUsedG = null,
        ?int $layerCount = null,
        ?int $plateCount = null,
        ?array $warnings = null,
    ): void {
        $sets = ['status = :status'];
        $params = [':id' => $id, ':status' => $status];

        if ($status === 'completed' || $status === 'failed') {
            $sets[] = 'completed_at = :completed_at';
            $params[':completed_at'] = date('c');
        }

        if ($outputFile !== null) {
            $sets[] = 'output_file = :output_file';
            $params[':output_file'] = $outputFile;
        }
        if ($estimatedTimeS !== null) {
            $sets[] = 'estimated_time_s = :estimated_time_s';
            $params[':estimated_time_s'] = $estimatedTimeS;
        }
        if ($filamentUsedG !== null) {
            $sets[] = 'filament_used_g = :filament_used_g';
            $params[':filament_used_g'] = $filamentUsedG;
        }
        if ($layerCount !== null) {
            $sets[] = 'layer_count = :layer_count';
            $params[':layer_count'] = $layerCount;
        }
        if ($plateCount !== null) {
            $sets[] = 'plate_count = :plate_count';
            $params[':plate_count'] = $plateCount;
        }
        if ($warnings !== null) {
            $sets[] = 'warnings = :warnings';
            $params[':warnings'] = json_encode($warnings, JSON_THROW_ON_ERROR);
        }

        $setClause = implode(', ', $sets);
        $stmt = $this->db()->prepare("UPDATE slice_jobs SET {$setClause} WHERE id = :id");
        $stmt->execute($params);
    }

    /**
     * Get a single job by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getJob(string $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM slice_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * List recent slice jobs.
     *
     * @return list<array<string, mixed>>
     */
    public function listJobs(int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM slice_jobs ORDER BY created_at DESC, rowid DESC LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get aggregate statistics across all jobs.
     *
     * @return array{total_jobs: int, completed_jobs: int, failed_jobs: int, total_filament_g: float, total_time_s: int, avg_time_s: float}
     */
    public function aggregateStats(): array
    {
        $stmt = $this->db()->query(<<<SQL
            SELECT
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN filament_used_g ELSE 0 END), 0) as total_filament_g,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN estimated_time_s ELSE 0 END), 0) as total_time_s,
                COALESCE(AVG(CASE WHEN status = 'completed' THEN estimated_time_s END), 0) as avg_time_s
            FROM slice_jobs
        SQL);

        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return $row !== false ? [
            'total_jobs' => (int) $row['total_jobs'],
            'completed_jobs' => (int) $row['completed_jobs'],
            'failed_jobs' => (int) $row['failed_jobs'],
            'total_filament_g' => (float) $row['total_filament_g'],
            'total_time_s' => (int) $row['total_time_s'],
            'avg_time_s' => (float) $row['avg_time_s'],
        ] : [
            'total_jobs' => 0,
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'total_filament_g' => 0.0,
            'total_time_s' => 0,
            'avg_time_s' => 0.0,
        ];
    }

    // ── Internal ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $dir = dirname($this->dbPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $db = new PDO("sqlite:{$this->dbPath}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');

        $this->db = $db;

        $this->createTables();

        return $db;
    }

    private function createTables(): void
    {
        $this->db()->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS slice_jobs (
                id TEXT PRIMARY KEY,
                input_file TEXT NOT NULL,
                output_file TEXT NOT NULL DEFAULT '',
                profile_machine TEXT NOT NULL DEFAULT '',
                profile_process TEXT NOT NULL DEFAULT '',
                profile_filament TEXT NOT NULL DEFAULT '',
                config_overrides TEXT NOT NULL DEFAULT '{}',
                status TEXT NOT NULL DEFAULT 'pending',
                estimated_time_s INTEGER,
                filament_used_g REAL,
                layer_count INTEGER,
                plate_count INTEGER,
                warnings TEXT NOT NULL DEFAULT '[]',
                created_at TEXT NOT NULL,
                completed_at TEXT
            )
        SQL);
    }
}
