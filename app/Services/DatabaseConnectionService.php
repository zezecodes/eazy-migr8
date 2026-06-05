<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DatabaseConnectionService
{
    private array $config;
    private string $connectionName;

    public function connect(User $user): self
    {
        $this->config = $this->decryptConfig($user);
        $this->connectionName = "user_{$user->id}";

        Config::set("database.connections.{$this->connectionName}", [
            'driver' => $this->config['driver'],
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'database' => $this->config['database'],
            'username' => $this->config['username'],
            'password' => $this->config['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        DB::purge($this->connectionName);
        DB::reconnect($this->connectionName);

        return $this;
    }

    public function connectWithoutDatabase(User $user): self
    {
        $this->config = $this->decryptConfig($user);
        $this->connectionName = "user_{$user->id}";

        Config::set("database.connections.{$this->connectionName}", [
            'driver' => $this->config['driver'],
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'database' => null,
            'username' => $this->config['username'],
            'password' => $this->config['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        return $this;
    }

    public function setDatabase(): void
    {
        Config::set("database.connections.{$this->connectionName}.database", $this->config['database']);
        DB::purge($this->connectionName);
        DB::reconnect($this->connectionName);
    }

    public function createDatabaseIfNotExists(): void
    {
        $database = $this->config['database'];
        $driver = $this->config['driver'];

        if ($driver === 'pgsql') {
            $exists = DB::connection($this->connectionName)
                ->select("SELECT 1 FROM pg_database WHERE datname = ?", [$database]);
            if (empty($exists)) {
                $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $database);
                DB::connection($this->connectionName)->statement("CREATE DATABASE \"{$safeDb}\"");
            }
        } else {
            $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $database);
            DB::connection($this->connectionName)->statement("CREATE DATABASE IF NOT EXISTS `{$safeDb}`");
        }
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function getDatabaseName(): string
    {
        return $this->config['database'];
    }

    private function decryptConfig(User $user): array
    {
        if (empty($user->db_config)) {
            throw new \RuntimeException('No database configuration found. Please configure your database first.');
        }

        try {
            return json_decode(decrypt($user->db_config), true);
        } catch (DecryptException $e) {
            throw new \RuntimeException('Your database configuration is invalid or corrupted. Please update your database settings.');
        }
    }
}
