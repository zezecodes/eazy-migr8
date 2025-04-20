<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SchemaController extends Controller
{
    public function storeConfig(Request $request)
    {
        $rules = [
            'driver' => 'required|in:mysql,pgsql,sqlite',
            'host' => 'required|string',
            'port' => 'required|numeric',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'sometimes|string',
        ];

        $validator = Validator::make($request->all(), $rules);
        
        if ($validator->fails()) {
            return response(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        
        // Store the validated data, not the rules
        $validatedData = $validator->validated();
        $user->db_config = encrypt(json_encode($validatedData));
        $user->save();

        return response([
            'message' => 'DB config saved.',
            'config' => $validatedData
        ]);
    }

    // Generate migration preview (optional)
    public function generateMigration(Request $request)
    {
        $rules = [
            'table' => 'required|string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'columns' => 'required|array',
            'columns.*.name' => 'required|string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'columns.*.type' => 'required|string|in:string,text,integer,boolean,date,datetime,float,double',
            'columns.*.modifiers' => 'nullable|array',
            'columns.*.modifiers.nullable' => 'boolean',
            'columns.*.modifiers.unique' => 'boolean',
            'columns.*.modifiers.default' => 'nullable',
        ];

        $validator = Validator::make($request->all(), $rules);
        
        if ($validator->fails()) {
            return response(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        return response([
            'message' => 'Migration preview generated.',
            'table' => $request->table,
            'columns' => $request->columns
        ]);
    }

    // Safely run migration to create table
    public function runMigration(Request $request)
    {
        $rules = [
            'table' => 'required|string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'columns' => 'required|array',
            'columns.*.name' => 'required|string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'columns.*.type' => 'required|string|in:string,text,integer,boolean,date,datetime,float,double,foreignId,enum',            'columns.*.modifiers' => 'nullable|array',
            'columns.*.modifiers' => 'sometimes|array',
        ];

        $validator = Validator::make($request->all(), $rules);
        
        if ($validator->fails()) {
            return response(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $config = json_decode(decrypt($user->db_config), true);
        $connectionName = "user_" . $user->id;
        $database = $config['database'];

        Config::set("database.connections.$connectionName", [
            'driver' => $config['driver'],
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => null,
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        try {
            DB::connection($connectionName)->statement("CREATE DATABASE IF NOT EXISTS `$database`");
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unable to create database: ' . $e->getMessage()], 500);
        }

        Config::set("database.connections.$connectionName.database", $database);

        DB::purge($connectionName);
        DB::reconnect($connectionName);

        $protectedTables = ['users', 'migrations', 'password_resets'];
        if (in_array($request->table, $protectedTables)) {
            return response(['error' => 'Table name is protected.'], 403);
        }

        if (Schema::connection($connectionName)->hasTable($request->table)) {
            return response(['error' => 'Table already exists.'], 409);
        }


        Schema::connection($connectionName)->create($request->table, function (Blueprint $table) use ($request) {
            $table->id();
            foreach ($request->columns as $column) {
                $this->applyColumnDefinition($table, $column);
            }
            $table->timestamps();
        });

        return response(['message' => 'Table created successfully.']);
    }

    // Rollback (drop) the created table
    public function rollbackMigration(Request $request)
    {
        $rules = [
            'table' => 'required|string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
        ];

        $validator = Validator::make($request->all(), $rules);
        
        if ($validator->fails()) {
            return response(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $config = json_decode(decrypt($user->db_config), true);
        $connectionName = "user_" . $user->id;

        Config::set("database.connections.$connectionName", [
            'driver' => $config['driver'],
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if (!Schema::connection($connectionName)->hasTable($request->table)) {
            return response(['error' => 'Table does not exist.'], 404);
        }

        Schema::connection($connectionName)->drop($request->table);

        return response(['message' => 'Table rolled back (dropped) successfully.']);
    }

    private function applyColumnDefinition(Blueprint $table, array $column)
    {
        $type = $column['type'];
        $name = $column['name'];
        $modifiers = $column['modifiers'] ?? [];

        // Handle foreignId
        if ($type === 'foreignId') {
            $columnObj = $table->foreignId($name);

            if (!empty($modifiers['constrained'])) {
                if (is_string($modifiers['constrained'])) {
                    $columnObj->constrained($modifiers['constrained']);
                } else {
                    $columnObj->constrained();
                }
            }

            if (!empty($modifiers['onDelete'])) {
                $columnObj->onDelete($modifiers['onDelete']);
            }

            if (!empty($modifiers['onUpdate'])) {
                $columnObj->onUpdate($modifiers['onUpdate']);
            }

        }

        // Handle enum
        if ($type === 'enum' && isset($modifiers['values']) && is_array($modifiers['values'])) {
            $columnObj = $table->enum($name, $modifiers['values']);
        }
        // Handle string with length
        elseif ($type === 'string' && isset($modifiers['length'])) {
            $columnObj = $table->string($name, $modifiers['length']);
        } else {
            $columnObj = $table->$type($name);
        }

        // Common modifiers
        if (!empty($modifiers['nullable'])) {
            $columnObj->nullable();
        }

        if (!empty($modifiers['unsigned']) && method_exists($columnObj, 'unsigned')) {
            $columnObj->unsigned();
        }

        if (array_key_exists('default', $modifiers)) {
            $columnObj->default($modifiers['default']);
        }

        if (!empty($modifiers['comment'])) {
            $columnObj->comment($modifiers['comment']);
        }

        // Indexes
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
