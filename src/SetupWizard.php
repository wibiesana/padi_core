<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Exception;

/**
 * Padi REST API - Setup Wizard
 * Core setup engine for interactive project configuration.
 */
class SetupWizard
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? (defined('PADI_ROOT') ? PADI_ROOT : getcwd());
    }

    public static function colorize(string $text, string $color): string
    {
        $colors = [
            'reset'  => "\033[0m",
            'red'    => "\033[31m",
            'green'  => "\033[32m",
            'yellow' => "\033[33m",
            'blue'   => "\033[34m",
            'cyan'   => "\033[36m",
            'bold'   => "\033[1m",
        ];

        if (!isset($colors[$color])) {
            return $text;
        }

        return $colors[$color] . $text . $colors['reset'];
    }

    private function info(string $message): void
    {
        echo self::colorize("ℹ ", 'blue') . $message . PHP_EOL;
    }

    private function success(string $message): void
    {
        echo self::colorize("✓ ", 'green') . $message . PHP_EOL;
    }

    private function error(string $message): void
    {
        echo self::colorize("✗ ", 'red') . $message . PHP_EOL;
    }

    private function warning(string $message): void
    {
        echo self::colorize("⚠ ", 'yellow') . $message . PHP_EOL;
    }

    private function ask(string $question, string $default = ''): string
    {
        $prompt = self::colorize($question, 'cyan');
        if ($default !== '') {
            $prompt .= self::colorize(" [$default]", 'yellow');
        }
        $prompt .= ": ";

        echo $prompt;
        $input = trim((string) fgets(STDIN));

        return $input === '' ? $default : $input;
    }

    private function choice(string $question, array $options, int $default = 1): int
    {
        // Use interactive arrow-key menu from Console class if available
        if (class_exists(Console::class)) {
            return Console::interactiveChoice($question, $options, $default);
        }

        // Fallback: number input
        echo PHP_EOL;
        echo self::colorize($question, 'cyan') . PHP_EOL;
        echo str_repeat('-', 60) . PHP_EOL;

        foreach ($options as $key => $label) {
            $marker = ($key == $default) ? '→' : '2.1.7';
            echo "  $marker $key. $label" . PHP_EOL;
        }
        echo str_repeat('-', 60) . PHP_EOL;

        $input = (int) $this->ask("Enter your choice", (string) $default);

        if (!isset($options[$input])) {
            $this->warning("Invalid choice. Using default: $default");
            return $default;
        }

        return $input;
    }

    private function banner(): void
    {
        $version = defined('Wibiesana\Padi\Core\Query::VERSION') ? Query::VERSION : '2.1.7';
        $versionStr = str_pad("Version {$version}", 64, ' ', STR_PAD_BOTH);

        $banner = <<<BANNER

╔════════════════════════════════════════════════════════════════╗
║             Padi REST API Framework - Setup Wizard             ║
║{$versionStr}║
║                    Powered by Padi Console                     ║
╚════════════════════════════════════════════════════════════════╝

BANNER;

        echo self::colorize($banner, 'blue') . PHP_EOL;
    }

    private function updateEnv(string $key, string $value): bool
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");

        $envFile = $this->projectRoot . '/.env';

        if (!file_exists($envFile)) {
            $this->error(".env file not found!");
            return false;
        }

        $content = file_get_contents($envFile);
        if ($content === false) {
            return false;
        }

        $pattern = "/^{$key}=.*/m";
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            $content .= PHP_EOL . $replacement;
        }

        $saved = file_put_contents($envFile, $content) !== false;
        if (class_exists(DatabaseManager::class)) {
            DatabaseManager::reset();
        }

        return $saved;
    }

    private function runCommand(string $command, string $description = ''): bool
    {
        if ($description) {
            $this->info($description);
        }

        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error("Command failed with code {$returnCode}: $command");
            $this->error("Error details:");
            foreach ($output as $line) {
                echo self::colorize("  ", 'red') . $line . PHP_EOL;
            }
            return false;
        }

        foreach ($output as $line) {
            echo "  " . $line . PHP_EOL;
        }

        return true;
    }

    public function run(): void
    {
        try {
            // Load autoload early if not yet loaded
            if (file_exists($this->projectRoot . '/vendor/autoload.php')) {
                require_once $this->projectRoot . '/vendor/autoload.php';
            }

            $this->banner();

            // Step 1: Check .env.example
            echo self::colorize("[1/7] Checking environment file...", 'yellow') . PHP_EOL;

            if (!file_exists($this->projectRoot . '/.env.example')) {
                $this->error(".env.example not found!");
                exit(1);
            }

            if (file_exists($this->projectRoot . '/.env')) {
                $overwrite = $this->ask("File .env already exists. Overwrite? (y/n)", 'n');
                if (strtolower($overwrite) === 'y') {
                    copy($this->projectRoot . '/.env.example', $this->projectRoot . '/.env');
                    $this->success(".env file updated from .env.example");
                } else {
                    $this->warning("Skipping .env creation");
                }
            } else {
                copy($this->projectRoot . '/.env.example', $this->projectRoot . '/.env');
                $this->success(".env file created from .env.example");
            }

            echo PHP_EOL;

            // Step 2: Choose Database Driver
            echo self::colorize("[2/7] Select Database Driver", 'yellow') . PHP_EOL;

            $dbChoice = $this->choice("Please select your database:", [
                1 => 'MySQL (Default)',
                2 => 'MariaDB',
                3 => 'PostgreSQL',
                4 => 'SQLite'
            ], 1);

            $drivers = [
                1 => ['driver' => 'mysql', 'port' => '3306'],
                2 => ['driver' => 'mysql', 'port' => '3306'],
                3 => ['driver' => 'pgsql', 'port' => '5432'],
                4 => ['driver' => 'sqlite', 'port' => '']
            ];

            $selectedDriver = $drivers[$dbChoice]['driver'];
            $defaultPort = $drivers[$dbChoice]['port'];

            $this->success("Selected: " . [1 => 'MySQL', 2 => 'MariaDB', 3 => 'PostgreSQL', 4 => 'SQLite'][$dbChoice]);

            echo PHP_EOL;

            // Step 3: Database Configuration
            echo self::colorize("[3/7] Database Configuration", 'yellow') . PHP_EOL;

            if ($selectedDriver === 'sqlite') {
                $dbPath = $this->ask("SQLite database path", "database/database.sqlite");

                // Create database directory and file
                $fullDbPath = $this->projectRoot . '/' . ltrim($dbPath, '/\\');
                $dbDir = dirname($fullDbPath);
                if (!is_dir($dbDir)) {
                    mkdir($dbDir, 0755, true);
                }
                if (!file_exists($fullDbPath)) {
                    touch($fullDbPath);
                }

                $this->success("SQLite will use: $dbPath");
                $this->updateEnv('DB_CONNECTION', $selectedDriver);
                $this->updateEnv('SQLITE_DATABASE', $dbPath);
            } else {
                $dbHost = $this->ask("Database Host", "localhost");
                $dbPort = $this->ask("Database Port", $defaultPort);
                $dbName = $this->ask("Database Name", "rest_api_db");
                $dbUser = $this->ask("Database Username", $selectedDriver === 'pgsql' ? 'postgres' : 'root');
                $dbPass = $this->ask("Database Password (press enter for empty)", "");

                $this->info("Configuration:");
                echo "  Host: $dbHost" . PHP_EOL;
                echo "  Port: $dbPort" . PHP_EOL;
                echo "  Database: $dbName" . PHP_EOL;
                echo "  Username: $dbUser" . PHP_EOL;

                $this->updateEnv('DB_CONNECTION', $selectedDriver);
                $this->updateEnv('DB_HOST', $dbHost);
                $this->updateEnv('DB_PORT', $dbPort);
                $this->updateEnv('DB_DATABASE', $dbName);
                $this->updateEnv('DB_USERNAME', $dbUser);
                $this->updateEnv('DB_PASSWORD', $dbPass);
            }

            $this->success(".env file updated");
            echo PHP_EOL;

            // Step 4: Test Database Connection
            echo self::colorize("[4/8] Testing Database Connection...", 'yellow') . PHP_EOL;

            try {
                if (!defined('PADI_ROOT')) {
                    define('PADI_ROOT', $this->projectRoot);
                }
                Env::load($this->projectRoot . '/.env', true);
                DatabaseManager::reset();

                $db = DatabaseManager::connection();
                $db->query('SELECT 1');
                $this->success("Database connection successful!");
            } catch (Exception $e) {
                $this->error("Database connection failed!");
                $this->error("Error: " . $e->getMessage());
                echo PHP_EOL;
                $this->warning("Common issues:");
                echo "  • Check database credentials in .env file" . PHP_EOL;
                echo "  • Ensure database server is running" . PHP_EOL;
                echo "  • Verify database exists (for MySQL/PostgreSQL)" . PHP_EOL;
                echo "  • Check if port is correct" . PHP_EOL;
                echo PHP_EOL;

                $continue = $this->ask("Continue anyway? (y/n)", 'n');
                if (strtolower($continue) !== 'y') {
                    $this->error("Setup aborted. Please fix database connection and run setup again.");
                    exit(1);
                }
            }
            echo PHP_EOL;

            // Step 5: Generate JWT Secret
            echo self::colorize("[5/8] Generating JWT Secret...", 'yellow') . PHP_EOL;

            $jwtSecret = bin2hex(random_bytes(32));
            $this->updateEnv('JWT_SECRET', $jwtSecret);

            $this->success("JWT Secret generated and saved");
            echo PHP_EOL;

            // Step 6: Run Migrations
            echo self::colorize("[6/8] Database Migrations", 'yellow') . PHP_EOL;

            $migrateChoice = $this->choice("Migration options:", [
                1 => 'Run migrations (users, password_resets)',
                2 => 'Skip migrations'
            ], 1);

            if ($migrateChoice == 1) {
                echo PHP_EOL;
                $this->info("Running migrations...");
                $migrationCmd = 'php ' . escapeshellarg($this->projectRoot . '/padi') . ' migrate';
                $migrationSuccess = $this->runCommand($migrationCmd);
                if ($migrationSuccess) {
                    $this->success("Migrations completed");
                } else {
                    $this->error("Migration failed!");
                    $this->warning("Troubleshooting:");
                    echo "  • Ensure database connection is working" . PHP_EOL;
                    echo "  • Check if migration files exist in database/migrations/" . PHP_EOL;
                    echo "  • Review error messages above" . PHP_EOL;
                    echo PHP_EOL;

                    $continue = $this->ask("Continue to next step? (y/n)", 'y');
                    if (strtolower($continue) !== 'y') {
                        $this->error("Setup aborted.");
                        exit(1);
                    }
                }
            } else {
                $this->warning("Migrations skipped");
            }

            echo PHP_EOL;

            // Step 7: Generate CRUD
            echo self::colorize("[7/8] CRUD Generation", 'yellow') . PHP_EOL;

            $generateChoice = $this->choice("Generate CRUD controllers and models?", [
                1 => 'Yes - Generate for all tables',
                2 => 'Yes - Select specific tables',
                3 => 'No - Skip generation'
            ], 3);

            if ($generateChoice == 1) {
                echo PHP_EOL;
                $realtimeChoice = $this->choice("Enable real-time SSE broadcasting (Mercure) for CRUD?", [
                    1 => 'No, standard CRUD only',
                    2 => 'Yes, generate with real-time ORM hooks'
                ], 1);

                $realtimeOpt = '';
                if ($realtimeChoice == 2) {
                    $realtimeOpt = ' --realtime';
                    $syncChoice = $this->choice("Should real-time broadcasts be processed asynchronously via Queue?", [
                        1 => 'Yes, use background Queue (asynchronous)',
                        2 => 'No, publish directly (synchronous)'
                    ], 1);
                    if ($syncChoice == 2) {
                        $realtimeOpt .= ' --realtime-sync=false';
                    } else {
                        $realtimeOpt .= ' --realtime-sync=true';
                    }
                }

                $this->info("Generating CRUD for all tables...");
                $crudCmd = 'php ' . escapeshellarg($this->projectRoot . '/padi') . ' generate:crud-all --write --overwrite' . $realtimeOpt;
                $crudSuccess = $this->runCommand($crudCmd);
                if ($crudSuccess) {
                    $this->success("CRUD generation completed");
                } else {
                    $this->error("CRUD generation failed!");
                    $this->warning("Troubleshooting:");
                    echo "  • Ensure database tables exist (run migrations first)" . PHP_EOL;
                    echo "  • Check if padi script exists in project root" . PHP_EOL;
                    echo "  • Review error messages above" . PHP_EOL;
                    echo PHP_EOL;
                }
            } elseif ($generateChoice == 2) {
                echo PHP_EOL;
                $this->info("Available tables:");
                $this->runCommand('php ' . escapeshellarg($this->projectRoot . '/padi') . ' -l');

                echo PHP_EOL;

                $tables = $this->ask("Enter table names (comma separated)", "");
                if ($tables) {
                    $realtimeChoice = $this->choice("Enable real-time SSE broadcasting (Mercure) for CRUD?", [
                        1 => 'No, standard CRUD only',
                        2 => 'Yes, generate with real-time ORM hooks'
                    ], 1);

                    $realtimeOpt = '';
                    if ($realtimeChoice == 2) {
                        $realtimeOpt = ' --realtime';
                        $syncChoice = $this->choice("Should real-time broadcasts be processed asynchronously via Queue?", [
                            1 => 'Yes, use background Queue (asynchronous)',
                            2 => 'No, publish directly (synchronous)'
                        ], 1);
                        if ($syncChoice == 2) {
                            $realtimeOpt .= ' --realtime-sync=false';
                        } else {
                            $realtimeOpt .= ' --realtime-sync=true';
                        }
                    }

                    $tableList = array_map('trim', explode(',', $tables));
                    $allSuccess = true;
                    foreach ($tableList as $table) {
                        $this->info("Generating CRUD for $table...");
                        $result = $this->runCommand('php ' . escapeshellarg($this->projectRoot . '/padi') . ' generate:crud ' . escapeshellarg($table) . ' --write' . $realtimeOpt);
                        if (!$result) {
                            $this->error("Failed to generate CRUD for table: $table");
                            $allSuccess = false;
                        }
                    }
                    if ($allSuccess) {
                        $this->success("CRUD generation completed");
                    } else {
                        $this->warning("Some CRUD generations failed. Check errors above.");
                    }
                }
            } else {
                $this->warning("CRUD generation skipped");
            }

            echo PHP_EOL;

            // Step 8: Summary
            echo self::colorize("╔════════════════════════════════════════════════════════════════╗", 'green') . PHP_EOL;
            echo self::colorize("║              Setup Completed Successfully! 🎉                 ║", 'green') . PHP_EOL;
            echo self::colorize("╚════════════════════════════════════════════════════════════════╝", 'green') . PHP_EOL;
            echo PHP_EOL;

            echo self::colorize("Next Steps:", 'blue') . PHP_EOL;
            echo "  1. Start the server:    " . self::colorize("php -S localhost:8085 -t public", 'yellow') . PHP_EOL;
            echo "  2. Visit:               " . self::colorize("http://localhost:8085", 'yellow') . PHP_EOL;
            echo "  3. API Documentation:   " . self::colorize("http://localhost:8085/docs", 'yellow') . PHP_EOL;
            echo PHP_EOL;

            echo self::colorize("Quick Commands:", 'blue') . PHP_EOL;
            echo "  - Start server:         " . self::colorize("php padi serve", 'yellow') . PHP_EOL;
            echo "  - Generate CRUD:        " . self::colorize("php padi generate:crud [table] --write", 'yellow') . PHP_EOL;
            echo "  - Run migrations:       " . self::colorize("php padi migrate", 'yellow') . PHP_EOL;
            echo "  - Rollback:            " . self::colorize("php padi migrate:rollback", 'yellow') . PHP_EOL;
            echo PHP_EOL;

            echo self::colorize("Happy coding! 🚀", 'green') . PHP_EOL;
            echo PHP_EOL;
        } catch (Exception $e) {
            $this->error("Setup failed: " . $e->getMessage());
            exit(1);
        }
    }
}
