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

    /**
     * Check if we are running in CLI SAPI.
     */
    private static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /**
     * Check if a function is available (not disabled and exists).
     */
    private static function functionAvailable(string $func): bool
    {
        if (!function_exists($func)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array($func, $disabled, true);
    }

    /**
     * Check if STDIN is available for interactive input.
     */
    private static function stdinAvailable(): bool
    {
        return defined('STDIN') && is_resource(STDIN);
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
            case 'serve:frankenphp':
            case 'serve:standard':
            case 'frankenphp':
                $this->serveFrankenphp(false);
                break;
            case 'serve:worker':
            case 'frankenphp:worker':
                $this->serveFrankenphp(true);
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
            case 'queue:work':
            case 'queue':
                $this->queueWork();
                break;
            default:
                echo "\e[31mUnknown command: {$command}\e[0m\n\n";
                $this->showHelp();
                break;
        }
    }

    private function showHelp(): void
    {
        echo "\e[32mPadi REST API Framework\e[0m version \e[33m2.1.7\e[0m\n\n";
        echo "\e[33mUsage:\e[0m\n";
        echo "  php padi <command> [options] [arguments]\n\n";
        echo "\e[33mAvailable commands:\e[0m\n";

        echo "  \e[32mserve\e[0m                      Start PHP dev server (--frankenphp or --worker options supported)\n";
        echo "  \e[32mserve:frankenphp\e[0m            Start FrankenPHP server in standard mode\n";
        echo "  \e[32mserve:worker\e[0m                Start FrankenPHP server in worker mode\n";
        echo "  \e[32mfrankenphp\e[0m                  Alias for serve:frankenphp\n";
        echo "  \e[32mfrankenphp:worker\e[0m           Alias for serve:worker\n";
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

        echo "\n \e[33mqueue\e[0m\n";
        echo "  \e[32mqueue:work\e[0m [queue]        Start processing jobs on the queue (--once, --stop-when-empty)\n";
        echo "  \e[32mqueue\e[0m [queue]             Alias for queue:work\n";
    }

    private function serve(): void
    {
        if ($this->hasOption('worker') || $this->getOption('mode') === 'worker') {
            $this->serveFrankenphp(true);
            return;
        }

        if ($this->hasOption('frankenphp') || $this->getOption('driver') === 'frankenphp') {
            $this->serveFrankenphp(false);
            return;
        }

        if (!self::functionAvailable('passthru')) {
            echo "\e[31mError: 'passthru' function is disabled. Cannot start dev server.\e[0m\n";
            return;
        }

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

    private function serveFrankenphp(bool $worker = false): void
    {
        if (!self::functionAvailable('passthru')) {
            echo "\e[31mError: 'passthru' function is disabled. Cannot start FrankenPHP server.\e[0m\n";
            return;
        }

        $port = $this->getOption('port', '8085');
        $host = $this->getOption('host', 'localhost');
        $numWorkers = $this->getOption('workers', '');
        $configFile = $this->getOption('config', '');
        $publicDir = $this->baseDir . '/public';

        if (!is_dir($publicDir)) {
            echo "\e[31mError: Public directory not found at {$publicDir}\e[0m\n";
            return;
        }

        $binary = $this->findFrankenphpBinary();
        if (!$binary) {
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $exe = $isWindows ? 'frankenphp.exe' : 'frankenphp';
            echo "\e[31mError: FrankenPHP binary ('{$exe}') was not found in project root or system PATH.\e[0m\n";
            echo "\e[33mTo install FrankenPHP:\e[0m\n";
            echo "  1. Download the binary from \e[36mhttps://frankenphp.dev\e[0m\n";
            echo "  2. Place \e[33m{$exe}\e[0m in your project root directory or system PATH.\n";
            return;
        }

        $modeLabel = $worker ? 'Worker Mode' : 'Standard Mode';
        echo "\e[32mStarting FrankenPHP server ({$modeLabel}):\e[0m http://{$host}:{$port}\n";
        echo "Press Ctrl+C to stop.\n\n";

        if (!empty($configFile)) {
            $cmd = "{$binary} run --config \"{$configFile}\"";
        } elseif ($worker) {
            $entryScript = "public/index.php";
            $workerOpt = "--worker \"{$entryScript}\"";
            if (!empty($numWorkers)) {
                $workerOpt .= " --nb-workers {$numWorkers}";
            }
            $cmd = "{$binary} php-server -r \"public\" {$workerOpt} -l \"{$host}:{$port}\"";
        } else {
            $cmd = "{$binary} php-server -r \"public\" -l \"{$host}:{$port}\"";
        }

        passthru($cmd);
    }

    private function findFrankenphpBinary(): ?string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $exe = $isWindows ? 'frankenphp.exe' : 'frankenphp';

        // 1. Check in base directory
        $localBinary = $this->baseDir . DIRECTORY_SEPARATOR . $exe;
        if (file_exists($localBinary)) {
            return escapeshellarg($localBinary);
        }

        // 2. Check system PATH using shell commands
        $whereCmd = $isWindows ? "where {$exe} 2>NUL" : "which {$exe} 2>/dev/null";
        $path = trim((string) @shell_exec($whereCmd));
        if (!empty($path)) {
            $lines = explode("\n", str_replace("\r", "", $path));
            $firstPath = trim($lines[0] ?? '');
            if ($firstPath !== '') {
                return escapeshellarg($firstPath);
            }
        }

        // 3. Check if 'frankenphp' works directly via version command
        $testCmd = $isWindows ? "{$exe} version 2>NUL" : "{$exe} version 2>/dev/null";
        $testOutput = @shell_exec($testCmd);
        if ($testOutput !== null && $testOutput !== false) {
            return $exe;
        }

        return null;
    }

    private function hasOption(string $name): bool
    {
        return isset($this->getOptions()[$name]);
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

        $options = $this->getOptions();
        
        // Interactive prompt for realtime if not explicitly passed via cli option (e.g. --realtime)
        if (!isset($options['realtime'])) {
            $choice = self::interactiveChoice(
                "Do you want to enable real-time SSE broadcasting (Mercure) for this CRUD?",
                [
                    'yes' => 'Yes, generate with real-time ORM hooks',
                    'no' => 'No, standard CRUD only'
                ],
                'no'
            );
            $options['realtime'] = ($choice === 'yes');
        } else {
            // Normalize CLI option --realtime (e.g. --realtime=true, --realtime=1, or just --realtime)
            $options['realtime'] = filter_var($options['realtime'], FILTER_VALIDATE_BOOLEAN) || $options['realtime'] === '';
        }

        if ($options['realtime']) {
            if (!isset($options['realtime-sync'])) {
                $syncChoice = self::interactiveChoice(
                    "Should real-time broadcasts be processed asynchronously via Queue (recommended)?",
                    [
                        'no' => 'Yes, use background Queue (asynchronous)',
                        'yes' => 'No, publish directly (synchronous)'
                    ],
                    'no'
                );
                $options['realtime_sync'] = ($syncChoice === 'yes');
            } else {
                $options['realtime_sync'] = filter_var($options['realtime-sync'], FILTER_VALIDATE_BOOLEAN) || $options['realtime-sync'] === '';
            }
        }

        $generator = new Generator();
        $generator->generateCrud($tableName, $options);
    }

    private function generateCrudAll(): void
    {
        $options = $this->getOptions();
        $options['write'] = $options['write'] ?? true; // Default to writing routes for bulk generation

        // Interactive prompt for realtime if not explicitly passed
        if (!isset($options['realtime'])) {
            $choice = self::interactiveChoice(
                "Do you want to enable real-time SSE broadcasting (Mercure) for ALL generated tables?",
                [
                    'yes' => 'Yes, generate with real-time ORM hooks',
                    'no' => 'No, standard CRUD only'
                ],
                'no'
            );
            $options['realtime'] = ($choice === 'yes');
        } else {
            $options['realtime'] = filter_var($options['realtime'], FILTER_VALIDATE_BOOLEAN) || $options['realtime'] === '';
        }

        if ($options['realtime']) {
            if (!isset($options['realtime-sync'])) {
                $syncChoice = self::interactiveChoice(
                    "Should real-time broadcasts be processed asynchronously via Queue (recommended)?",
                    [
                        'no' => 'Yes, use background Queue (asynchronous)',
                        'yes' => 'No, publish directly (synchronous)'
                    ],
                    'no'
                );
                $options['realtime_sync'] = ($syncChoice === 'yes');
            } else {
                $options['realtime_sync'] = filter_var($options['realtime-sync'], FILTER_VALIDATE_BOOLEAN) || $options['realtime-sync'] === '';
            }
        }

        echo "\e[33mGenerating CRUD for all tables...\e[0m\n";
        $generator = new Generator();
        $generator->generateCrudAll($options);
        echo "\e[32m✓ Bulk CRUD generation completed!\e[0m\n";
    }

    private function init(): void
    {
        $wizard = new SetupWizard($this->baseDir);
        $wizard->run();
    }

    private function queueWork(): void
    {
        $queue = 'default';
        $once = false;
        $stopWhenEmpty = false;

        foreach (array_slice($this->args, 2) as $arg) {
            if ($arg === '--once') {
                $once = true;
            } elseif ($arg === '--stop-when-empty') {
                $stopWhenEmpty = true;
            } elseif (!str_starts_with($arg, '--')) {
                $queue = $arg;
            }
        }

        Queue::work($queue, $once, $stopWhenEmpty);
    }

    // =========================================================================
    // Interactive Menu (Arrow Key Navigation)
    // =========================================================================

    /**
     * Display an interactive menu with arrow-key navigation.
     *
     * @param string $title   The menu title/question
     * @param array  $options Associative array [key => label]
     * @param int|string $default Default selected key
     * @return int|string The selected key
     */
    public static function interactiveChoice(string $title, array $options, int|string $default = 1): int|string
    {
        // Non-CLI or non-interactive environments: always use fallback
        if (!self::isCli() || !self::stdinAvailable()) {
            return self::fallbackChoice($title, $options, $default);
        }

        // Enable VT100 ANSI escape sequences on Windows
        self::enableVT100();

        $keys = array_keys($options);
        $selectedIndex = array_search($default, $keys, false);
        if ($selectedIndex === false) {
            $selectedIndex = 0;
        }
        $count = count($keys);

        // Try raw mode; if unsupported fall back to number input
        if (!self::enableRawMode()) {
            return self::fallbackChoice($title, $options, $default);
        }

        // Hide cursor
        echo "\e[?25l";

        // Print title
        echo "\n\e[36m{$title}\e[0m\n";
        echo str_repeat('─', 60) . "\n";

        // Render initial options
        self::renderOptions($options, $keys, $selectedIndex);

        echo str_repeat('─', 60) . "\n";
        echo "\e[90m  ↑/↓ Navigate  •  Enter Select  •  q Quit\e[0m";

        while (true) {
            $key = self::readKey();

            if ($key === 'UP') {
                $selectedIndex = ($selectedIndex - 1 + $count) % $count;
            } elseif ($key === 'DOWN') {
                $selectedIndex = ($selectedIndex + 1) % $count;
            } elseif ($key === 'ENTER') {
                break;
            } elseif ($key === 'q' || $key === 'Q' || $key === 'ESC') {
                self::disableRawMode();
                echo "\e[?25h\n";
                echo "\e[31m  Cancelled.\e[0m\n";
                // Return default instead of exit() — safe for FrankenPHP worker mode
                return $default;
            } elseif (is_numeric($key) && isset($options[(int)$key])) {
                $selectedIndex = array_search((int)$key, $keys, false);
                break;
            } else {
                continue;
            }

            // Move cursor up to first option line:
            // Current position = end of hint line (no \n)
            // Lines above: separator(1) + options(count) = count + 1
            $linesToMoveUp = $count + 1;
            echo "\e[{$linesToMoveUp}A\r";

            self::renderOptions($options, $keys, $selectedIndex);

            echo "\e[2K" . str_repeat('─', 60) . "\n";
            echo "\e[2K\e[90m  ↑/↓ Navigate  •  Enter Select  •  q Quit\e[0m";
        }

        // Restore terminal
        self::disableRawMode();
        echo "\e[?25h\n\n";

        $selectedKey = $keys[$selectedIndex];
        echo "\e[32m  ✓ Selected:\e[0m {$options[$selectedKey]}\n";

        return $selectedKey;
    }

    /**
     * Render the option list with the selected item highlighted.
     * Each line is cleared first with \e[2K to prevent residual characters.
     */
    private static function renderOptions(array $options, array $keys, int $selectedIndex): void
    {
        foreach ($keys as $i => $key) {
            $label = $options[$key];
            // Clear entire line before writing to prevent leftover chars
            echo "\e[2K";
            if ($i === $selectedIndex) {
                echo "  \e[46m\e[1;37m → {$key}. {$label} \e[0m\n";
            } else {
                echo "    \e[90m{$key}.\e[0m {$label}\n";
            }
        }
    }

    /**
     * Fallback: classic number-input when raw mode is unavailable.
     */
    private static function fallbackChoice(string $title, array $options, int|string $default): int|string
    {
        echo "\n\e[36m{$title}\e[0m\n";
        echo str_repeat('─', 60) . "\n";

        foreach ($options as $key => $label) {
            $marker = ($key == $default) ? '→' : ' ';
            echo "  {$marker} {$key}. {$label}\n";
        }

        echo str_repeat('─', 60) . "\n";
        echo "\e[36mEnter your choice\e[0m";
        if ($default !== '') {
            echo " \e[33m[{$default}]\e[0m";
        }
        echo ": ";

        if (!self::stdinAvailable()) {
            echo "\n\e[33m  ⚠ STDIN not available. Using default: {$default}\e[0m\n";
            return $default;
        }

        $input = trim((string) fgets(STDIN));
        $input = $input === '' ? $default : $input;

        if (is_numeric($input)) {
            $input = (int) $input;
        }

        if (!isset($options[$input])) {
            echo "\e[33m  ⚠ Invalid choice. Using default: {$default}\e[0m\n";
            return $default;
        }

        return $input;
    }

    // =========================================================================
    // Terminal Helpers (Cross-Platform)
    // =========================================================================

    private static ?string $sttyState = null;

    /** @var \FFI|null|false Cached FFI instance. null=not tried, false=unavailable */
    private static \FFI|null|false $ffi = null;

    private static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Enable VT100/ANSI escape sequence processing on Windows.
     * Without this, escape codes like \e[2K and \e[nA are printed literally.
     */
    private static function enableVT100(): void
    {
        if (self::isWindows() && function_exists('sapi_windows_vt100_support')) {
            @sapi_windows_vt100_support(STDOUT, true);
            @sapi_windows_vt100_support(STDERR, true);
        }
    }

    /**
     * Enable raw (non-canonical, no-echo) terminal mode.
     * On Windows: no-op (FFI _getch / PowerShell handle raw input natively).
     * On Unix/Mac: sets stty to raw mode.
     */
    private static function enableRawMode(): bool
    {
        if (self::isWindows()) {
            // Windows: FFI _getch() and PowerShell handle raw input natively
            return self::functionAvailable('shell_exec') || extension_loaded('ffi');
        }

        // Unix/Mac: requires shell_exec + stty
        if (!self::functionAvailable('shell_exec')) {
            return false;
        }

        $saved = shell_exec('stty -g 2>/dev/null');
        if ($saved === null) {
            return false;
        }
        self::$sttyState = trim($saved);
        shell_exec('stty -icanon -echo 2>/dev/null');
        return true;
    }

    /**
     * Restore the original terminal mode.
     */
    private static function disableRawMode(): void
    {
        if (self::isWindows()) {
            return;
        }

        if (self::$sttyState !== null && self::functionAvailable('shell_exec')) {
            shell_exec('stty ' . self::$sttyState . ' 2>/dev/null');
            self::$sttyState = null;
        }
    }

    /**
     * Read a single keypress and return a normalized key name.
     * Returns: 'UP', 'DOWN', 'ENTER', 'ESC', or the character itself.
     */
    private static function readKey(): string
    {
        if (self::isWindows()) {
            return self::readKeyWindows();
        }
        return self::readKeyUnix();
    }

    // ---- Windows Key Reading ----

    /**
     * Windows: read a single keypress using FFI _getch() or PowerShell fallback.
     *
     * Strategy 1: PHP FFI with msvcrt.dll _getch() — instant, zero process spawn.
     * Strategy 2: PowerShell [Console]::ReadKey() via shell_exec — ~200ms per key.
     */
    private static function readKeyWindows(): string
    {
        // Try FFI _getch() — instant native console read
        if (self::$ffi === null) {
            if (!extension_loaded('ffi')) {
                self::$ffi = false;
            } else {
                try {
                    self::$ffi = \FFI::cdef("int _getch(void);", "msvcrt.dll");
                } catch (\Throwable) {
                    self::$ffi = false;
                }
            }
        }

        if (self::$ffi instanceof \FFI) {
            try {
                return self::readKeyFFI();
            } catch (\Throwable) {
                // If FFI call fails, disable it and fall back
                self::$ffi = false;
            }
        }

        // Fallback: PowerShell per-keypress
        return self::readKeyPowerShell();
    }

    /**
     * Read key via FFI calling msvcrt.dll _getch().
     * Arrow keys send two bytes: 0/224 prefix + scan code.
     */
    private static function readKeyFFI(): string
    {
        /** @var \FFI $ffi */
        $ffi = self::$ffi;

        /** @var callable $getch */
        $getch = [$ffi, '_getch'];
        $ch = (int) $getch();

        // Enter
        if ($ch === 13) {
            return 'ENTER';
        }
        // ESC
        if ($ch === 27) {
            return 'ESC';
        }

        // Extended key (arrow keys, function keys):
        // First byte is 0x00 or 0xE0 (224), second byte is the scan code
        if ($ch === 0 || $ch === 224) {
            $scanCode = (int) $getch();
            return match ($scanCode) {
                72 => 'UP',
                80 => 'DOWN',
                75 => 'LEFT',
                77 => 'RIGHT',
                default => '',
            };
        }

        return chr($ch);
    }

    /**
     * Read key via PowerShell shell_exec (fallback when FFI is unavailable).
     */
    private static function readKeyPowerShell(): string
    {
        if (!self::functionAvailable('shell_exec')) {
            // Cannot read keys without shell_exec — return ENTER to avoid infinite loop
            return 'ENTER';
        }

        $ps = 'powershell -NoProfile -Command "$k=[Console]::ReadKey($true); Write-Output $k.Key"';
        $result = trim((string) shell_exec($ps));

        return match ($result) {
            'UpArrow'    => 'UP',
            'DownArrow'  => 'DOWN',
            'Enter'      => 'ENTER',
            'Escape'     => 'ESC',
            'Spacebar'   => 'ENTER',
            default      => $result,
        };
    }

    // ---- Unix/Mac Key Reading (stty raw mode) ----

    /**
     * Unix/Mac: read raw bytes from STDIN to detect arrow-key escape sequences.
     */
    private static function readKeyUnix(): string
    {
        $c = fread(STDIN, 1);

        if ($c === "\n" || $c === "\r") {
            return 'ENTER';
        }

        // ESC sequence (arrow keys send: ESC [ A/B/C/D)
        if ($c === "\e") {
            $seq1 = fread(STDIN, 1);
            if ($seq1 === '[') {
                $seq2 = fread(STDIN, 1);
                return match ($seq2) {
                    'A' => 'UP',
                    'B' => 'DOWN',
                    'C' => 'RIGHT',
                    'D' => 'LEFT',
                    default => 'ESC',
                };
            }
            return 'ESC';
        }

        return $c;
    }
}
