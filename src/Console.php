<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

class Console
{
    private array $args;
    private string $baseDir;

    public function __construct(array $args)
    {
        $this->args = $args;
        $this->baseDir = defined('PADI_ROOT') ? PADI_ROOT : getcwd();
    }

    public function run(): void
    {
        $command = $this->args[1] ?? 'list';

        switch ($command) {
            case 'list':
            case 'help':
                $this->showHelp();
                break;
            case 'init':
            case 'setup':
                $this->init();
                break;
            case 'serve':
                $this->serve();
                break;
            case 'create:controller':
            case 'make:controller':
                $this->createController();
                break;
            case 'create:model':
            case 'make:model':
                $this->createModel();
                break;
            case 'create:migration':
            case 'make:migration':
                $this->createMigration();
                break;
            case 'migrate':
                $this->migrate();
                break;
            case 'migrate:rollback':
                $this->migrateRollback();
                break;
            case 'migrate:status':
                $this->migrateStatus();
                break;
            case 'generate:crud':
            case 'g':
                $this->generateCrud();
                break;
            case 'generate:crud-all':
            case 'ga':
                $this->generateCrudAll();
                break;
            default:
                echo "\e[31mUnknown command: {$command}\e[0m\n\n";
                $this->showHelp();
                break;
        }
    }

    private function showHelp(): void
    {
        echo "\e[32mPadi REST API Framework\e[0m version \e[33m2.0.0\e[0m\n\n";
        echo "\e[33mUsage:\e[0m\n";
        echo "  php padi <command> [options] [arguments]\n\n";
        echo "\e[33mAvailable commands:\e[0m\n";

        echo "  \e[32mserve\e[0m                      Start the PHP development server\n";
        echo "  \e[32minit\e[0m                       Initialize the application (Run Setup Wizard)\n";

        echo "\n \e[33mmake\e[0m\n";
        echo "  \e[32mmake:controller\e[0m <name>      Create a new controller\n";
        echo "  \e[32mmake:model\e[0m <table_name>   Create a new model from database table\n";
        echo "  \e[32mmake:migration\e[0m <name>       Create a new migration file\n";

        echo "\n \e[33mmigrate\e[0m\n";
        echo "  \e[32mmigrate\e[0m                     Run pending migrations\n";
        echo "  \e[32mmigrate:rollback\e[0m            Rollback last migration\n";
        echo "  \e[32mmigrate:status\e[0m              Show migration status\n";

        echo "\n \e[33mgenerate\e[0m\n";
        echo "  \e[32mgenerate:crud\e[0m <table_name>  Generate complete CRUD (Model, Controller, Resource, Routes)\n";
        echo "  \e[32mg\e[0m <table_name>              Alias for generate:crud\n";
        echo "  \e[32mgenerate:crud-all\e[0m           Generate complete CRUD for ALL tables in database\n";
        echo "  \e[32mga\e[0m                         Alias for generate:crud-all\n";
    }

    private function serve(): void
    {
        $port = $this->getOption('port', '8085');
        $host = $this->getOption('host', 'localhost');
        $publicDir = $this->baseDir . '/public';

        if (!is_dir($publicDir)) {
            echo "\e[31mError: Public directory not found at {$publicDir}\e[0m\n";
            return;
        }

        echo "\e[32mStarting Padi development server:\e[0m http://{$host}:{$port}\n";
        echo "Press Ctrl+C to stop.\n";

        passthru("php -S {$host}:{$port} -t \"{$publicDir}\"");
    }

    private function getOption(string $name, string $default = ''): string
    {
        return (string)($this->getOptions()[$name] ?? $default);
    }

    private function getOptions(): array
    {
        $options = [];
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0];
                $value = $parts[1] ?? true;

                if ($key === 'protected') {
                    if ($value === 'all') {
                        $options['protected'] = ['index', 'show', 'store', 'update', 'destroy'];
                    } elseif ($value === 'none') {
                        $options['protected'] = [];
                    }
                } elseif ($key === 'middleware') {
                    $options['middleware'] = explode(',', (string)$value);
                } else {
                    $options[$key] = $value;
                }
            }
        }
        return $options;
    }

    private function createController(): void
    {
        $name = $this->args[2] ?? null;
        if (!$name) {
            echo "\e[31mError: Controller name is required.\e[0m\n";
            echo "Usage: php padi make:controller <name>\n";
            return;
        }

        $generator = new Generator();
        $generator->generateController($name, $this->getOptions());
    }

    private function createModel(): void
    {
        $tableName = $this->args[2] ?? null;
        if (!$tableName) {
            echo "\e[31mError: Table name is required.\e[0m\n";
            echo "Usage: php padi make:model <table_name>\n";
            return;
        }

        $generator = new Generator();
        $generator->generateModel($tableName, $this->getOptions());
    }

    private function createMigration(): void
    {
        $name = $this->args[2] ?? null;
        if (!$name) {
            echo "\e[31mError: Migration name is required.\e[0m\n";
            echo "Usage: php padi make:migration <name>\n";
            return;
        }

        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_{$name}.php";
        $dir = $this->baseDir . '/database/migrations';
        $path = $dir . '/' . $fileName;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $template = <<<PHP
<?php

use Wibiesana\Padi\Core\DatabaseManager;

return new class {
    public function up(): void
    {
        \$db = DatabaseManager::connection();
        // \$db->exec("CREATE TABLE ...");
    }

    public function down(): void
    {
        \$db = DatabaseManager::connection();
        // \$db->exec("DROP TABLE ...");
    }
};
PHP;

        file_put_contents($path, $template);
        echo "\e[32m✓ Migration created:\e[0m {$path}\n";
    }

    private function migrate(): void
    {
        $migrator = new Migrator();
        $options = $this->getOptions();

        if (isset($options['tables']) && is_string($options['tables'])) {
            $tables = explode(',', $options['tables']);
            echo "Migrating specific tables: " . implode(', ', $tables) . "\n\n";
            $migrator->migrate($tables);
        } else {
            $migrator->migrate();
        }
    }

    private function migrateRollback(): void
    {
        $migrator = new Migrator();
        $steps = (int)($this->getOptions()['step'] ?? 1);
        $migrator->rollback($steps);
    }

    private function migrateStatus(): void
    {
        $migrator = new Migrator();
        $migrator->status();
    }

    private function generateCrud(): void
    {
        $tableName = $this->args[2] ?? null;
        if (!$tableName) {
            echo "Error: Table name is required.\n";
            return;
        }

        $generator = new Generator();
        $generator->generateCrud($tableName, $this->getOptions());
    }

    private function generateCrudAll(): void
    {
        echo "\e[33mGenerating CRUD for all tables...\e[0m\n";
        $generator = new Generator();

        $options = $this->getOptions();
        $options['write'] = $options['write'] ?? true; // Default to writing routes for bulk generation

        $generator->generateCrudAll($options);
        echo "\e[32m✓ Bulk CRUD generation completed!\e[0m\n";
    }

    private function init(): void
    {
        $initScript = $this->baseDir . '/scripts/init.php';
        if (file_exists($initScript)) {
            passthru("php \"{$initScript}\"");
        } else {
            echo "\e[31mError: Setup wizard not found at {$initScript}\e[0m\n";
        }
    }
}
