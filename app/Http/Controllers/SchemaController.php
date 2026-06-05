<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddColumnsRequest;
use App\Http\Requests\GenerateMigrationRequest;
use App\Http\Requests\RemoveColumnRequest;
use App\Http\Requests\RollbackRequest;
use App\Http\Requests\RunMigrationRequest;
use App\Http\Requests\StoreDbConfigRequest;
use App\Services\DatabaseConnectionService;
use App\Services\MigrationGeneratorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class SchemaController extends Controller
{
    public function __construct(
        private DatabaseConnectionService $db,
        private MigrationGeneratorService $migrator,
    ) {}

    public function storeConfig(StoreDbConfigRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = Auth::user();
        $user->db_config = encrypt(json_encode($validated));
        $user->save();

        return response()->json([
            'message' => 'Database configuration saved.',
            'driver' => $validated['driver'],
            'host' => $validated['host'],
            'port' => $validated['port'],
            'database' => $validated['database'],
        ]);
    }

    public function generateMigration(GenerateMigrationRequest $request): JsonResponse
    {
        $content = $this->migrator->generateCreateTableContent($request->table, $request->columns);
        $filename = $this->migrator->buildFilename("create", $request->table);

        return response()->json([
            'message' => 'Migration preview generated.',
            'table' => $request->table,
            'columns' => $request->columns,
            'filename' => $filename,
            'migration_content' => $content,
        ]);
    }

    public function runMigration(RunMigrationRequest $request): JsonResponse
    {
        try {
            $this->db->connectWithoutDatabase(Auth::user());
            $this->db->createDatabaseIfNotExists();
            $this->db->setDatabase();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unable to connect to database.'], 500);
        }

        $connectionName = $this->db->getConnectionName();

        if (Schema::connection($connectionName)->hasTable($request->table)) {
            return response()->json(['error' => 'Table already exists.'], 409);
        }

        Schema::connection($connectionName)->create($request->table, function (Blueprint $table) use ($request) {
            $table->id();
            foreach ($request->columns as $column) {
                $this->applyColumnDefinition($table, $column);
            }
            $table->timestamps();
        });

        $content = $this->migrator->generateCreateTableContent($request->table, $request->columns);
        $filename = $this->migrator->buildFilename("create", $request->table);

        return response()->json([
            'message' => 'Table created successfully.',
            'filename' => $filename,
            'migration_content' => $content,
        ]);
    }

    public function addColumnsToTable(AddColumnsRequest $request): JsonResponse
    {
        try {
            $this->db->connect(Auth::user());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $connectionName = $this->db->getConnectionName();

        if (!Schema::connection($connectionName)->hasTable($request->table)) {
            return response()->json(['error' => 'Table does not exist.'], 404);
        }

        $columnNames = array_column($request->columns, 'name');
        $existingColumns = Schema::connection($connectionName)->getColumnListing($request->table);
        $duplicates = array_intersect($columnNames, $existingColumns);

        if (!empty($duplicates)) {
            return response()->json([
                'error' => 'The following columns already exist: ' . implode(', ', $duplicates),
            ], 409);
        }

        Schema::connection($connectionName)->table($request->table, function (Blueprint $table) use ($request) {
            foreach ($request->columns as $column) {
                $this->applyColumnDefinition($table, $column);
            }
        });

        $content = $this->migrator->generateAddColumnsContent($request->table, $request->columns);
        $filename = $this->migrator->buildFilename("add_columns_to", $request->table);

        return response()->json([
            'message' => 'Columns added successfully.',
            'filename' => $filename,
            'migration_content' => $content,
        ]);
    }

    public function removeColumnFromTable(RemoveColumnRequest $request): JsonResponse
    {
        try {
            $this->db->connect(Auth::user());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $connectionName = $this->db->getConnectionName();

        if (!Schema::connection($connectionName)->hasTable($request->table)) {
            return response()->json(['error' => 'Table does not exist.'], 404);
        }

        if (!Schema::connection($connectionName)->hasColumn($request->table, $request->column)) {
            return response()->json(['error' => 'Column does not exist.'], 404);
        }

        Schema::connection($connectionName)->table($request->table, function (Blueprint $table) use ($request) {
            $table->dropColumn($request->column);
        });

        $content = $this->migrator->generateDropColumnsContent($request->table, [$request->column]);
        $filename = $this->migrator->buildFilename("drop_{$request->column}_from", $request->table);

        return response()->json([
            'message' => 'Column removed successfully.',
            'filename' => $filename,
            'migration_content' => $content,
        ]);
    }

    public function dropTable(RollbackRequest $request): JsonResponse
    {
        try {
            $this->db->connect(Auth::user());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $connectionName = $this->db->getConnectionName();

        if (!Schema::connection($connectionName)->hasTable($request->table)) {
            return response()->json(['error' => 'Table does not exist.'], 404);
        }

        Schema::connection($connectionName)->drop($request->table);

        return response()->json(['message' => 'Table dropped successfully.']);
    }

    private function applyColumnDefinition(Blueprint $table, array $column): void
    {
        $type = $column['type'];
        $name = $column['name'];
        $modifiers = $column['modifiers'] ?? [];

        if ($type === 'foreignId') {
            $col = $table->foreignId($name);
            if (!empty($modifiers['constrained'])) {
                $col = is_string($modifiers['constrained'])
                    ? $col->constrained($modifiers['constrained'])
                    : $col->constrained();
            }
            if (!empty($modifiers['onDelete'])) {
                $col->onDelete($modifiers['onDelete']);
            }
            if (!empty($modifiers['onUpdate'])) {
                $col->onUpdate($modifiers['onUpdate']);
            }
            return;
        }

        if ($type === 'enum' && isset($modifiers['values']) && is_array($modifiers['values'])) {
            $col = $table->enum($name, $modifiers['values']);
        } elseif ($type === 'string' && isset($modifiers['length'])) {
            $col = $table->string($name, $modifiers['length']);
        } else {
            $col = $table->$type($name);
        }

        if (!empty($modifiers['nullable'])) {
            $col->nullable();
        }
        if (!empty($modifiers['unsigned']) && method_exists($col, 'unsigned')) {
            $col->unsigned();
        }
        if (array_key_exists('default', $modifiers)) {
            $col->default($modifiers['default']);
        }
        if (!empty($modifiers['comment'])) {
            $col->comment($modifiers['comment']);
        }
        if (!empty($modifiers['unique'])) {
            $table->unique($name);
        }
        if (!empty($modifiers['index'])) {
            $table->index($name);
        }
        if (!empty($modifiers['primary'])) {
            $table->primary($name);
        }
    }
}
