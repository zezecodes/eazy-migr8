<?php

namespace App\Services;

class MigrationGeneratorService
{
    public function generateCreateTableContent(string $tableName, array $columns): string
    {
        $columnsCode = $this->generateColumnsCode($columns);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
{$columnsCode}
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;
    }

    public function generateAddColumnsContent(string $tableName, array $columns): string
    {
        $addCode = $this->generateColumnsCode($columns);
        $dropCode = $this->generateDropColumnsCode($columns);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
{$addCode}
        });
    }

    public function down(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
{$dropCode}
        });
    }
};
PHP;
    }

    public function generateDropColumnsContent(string $tableName, array $columnNames): string
    {
        $dropCode = '';
        foreach ($columnNames as $name) {
            $dropCode .= "            \$table->dropColumn('{$name}');\n";
        }

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
{$dropCode}
        });
    }

    public function down(): void
    {
        // Column recreation requires original definitions — run the corresponding add-columns migration instead.
        throw new \RuntimeException('This migration cannot be reversed automatically. Recreate the columns manually.');
    }
};
PHP;
    }

    public function buildFilename(string $prefix, string $tableName): string
    {
        $timestamp = date('Y_m_d_His');
        return "{$timestamp}_{$prefix}_{$tableName}_table.php";
    }

    private function generateColumnsCode(array $columns): string
    {
        $code = '';
        foreach ($columns as $column) {
            $code .= $this->generateColumnCode($column);
        }
        return $code;
    }

    private function generateColumnCode(array $column): string
    {
        $type = $column['type'];
        $name = $column['name'];
        $modifiers = $column['modifiers'] ?? [];
        $code = "            \$table->";

        if ($type === 'foreignId') {
            $code .= "foreignId('{$name}')";
            if (!empty($modifiers['constrained'])) {
                $table = is_string($modifiers['constrained']) ? $modifiers['constrained'] : null;
                $code .= $table ? "->constrained('{$table}')" : "->constrained()";
            }
            if (!empty($modifiers['onDelete'])) {
                $code .= "->onDelete('{$modifiers['onDelete']}')";
            }
            if (!empty($modifiers['onUpdate'])) {
                $code .= "->onUpdate('{$modifiers['onUpdate']}')";
            }
        } elseif ($type === 'enum' && isset($modifiers['values']) && is_array($modifiers['values'])) {
            $values = implode("', '", $modifiers['values']);
            $code .= "enum('{$name}', ['{$values}'])";
        } elseif ($type === 'string' && isset($modifiers['length'])) {
            $code .= "string('{$name}', {$modifiers['length']})";
        } else {
            $code .= "{$type}('{$name}')";
        }

        if (!empty($modifiers['nullable'])) {
            $code .= "->nullable()";
        }
        if (!empty($modifiers['unsigned']) && in_array($type, ['integer', 'bigInteger', 'float', 'double', 'decimal'])) {
            $code .= "->unsigned()";
        }
        if (array_key_exists('default', $modifiers)) {
            $default = var_export($modifiers['default'], true);
            $code .= "->default({$default})";
        }
        if (!empty($modifiers['comment'])) {
            $comment = addslashes($modifiers['comment']);
            $code .= "->comment('{$comment}')";
        }

        $code .= ";\n";

        if (!empty($modifiers['unique'])) {
            $code .= "            \$table->unique('{$name}');\n";
        }
        if (!empty($modifiers['index'])) {
            $code .= "            \$table->index('{$name}');\n";
        }
        if (!empty($modifiers['primary'])) {
            $code .= "            \$table->primary('{$name}');\n";
        }

        return $code;
    }

    private function generateDropColumnsCode(array $columns): string
    {
        $code = '';
        foreach ($columns as $column) {
            $name = is_string($column) ? $column : $column['name'];
            $code .= "            \$table->dropColumn('{$name}');\n";
        }
        return $code;
    }
}
