<?php

declare(strict_types=1);

/**
 * config/database.php
 *
 * Creates the PHP connection to MySQL using PDO and credentials supplied through environment variables.
 */

// 1. Simple environment variable loader for .env file
if (!function_exists('loadEnv')) {
    function loadEnv(string $filePath): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignore comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $name  = trim($name);
                $value = trim($value);

                // Strip surrounding single or double quotes
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                // Register variable if not already populated
                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv("{$name}={$value}");
                    $_ENV[$name]    = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}

// Automatically load .env from the project root if it exists
loadEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

// 2. Helper function to read environment variables with fallback
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}

// 3. Database connection class (Singleton pattern)
class Database
{
    private static ?PDO $connection = null;

    /**
     * Get or create the PDO database connection.
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $host     = (string) env('DB_HOST', '127.0.0.1');
            $port     = (string) env('DB_PORT', '3307');
            $database = (string) env('DB_DATABASE', env('DB_NAME', 'milesbh'));
            $username = (string) env('DB_USERNAME', env('DB_USER', 'root'));
            $password = (string) env('DB_PASSWORD', env('DB_PASS', ''));
            $charset  = (string) env('DB_CHARSET', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$connection = new PDO($dsn, $username, $password, $options);
            } catch (PDOException $e) {
                // If database does not exist yet (Error 1049), attempt auto-creation in development
                if ($e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
                    try {
                        $serverDsn = "mysql:host={$host};port={$port};charset={$charset}";
                        $serverPdo = new PDO($serverDsn, $username, $password, $options);
                        $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci;");
                        self::$connection = new PDO($dsn, $username, $password, $options);
                        return self::$connection;
                    } catch (PDOException $createEx) {
                        error_log("Failed to auto-create database '{$database}': " . $createEx->getMessage());
                    }
                }

                error_log("Database connection failure: " . $e->getMessage());
                throw new PDOException("Database connection failed: " . $e->getMessage(), (int) $e->getCode());
            }
        }

        return self::$connection;
    }

    /**
     * Close the database connection.
     */
    public static function closeConnection(): void
    {
        self::$connection = null;
    }
}

// 4. Procedural helper function
if (!function_exists('getDBConnection')) {
    function getDBConnection(): PDO
    {
        return Database::getConnection();
    }
}

// Determine if this file is being accessed directly (e.g., opened in a browser or run in CLI)
$isDirectAccess = (
    (isset($_SERVER['SCRIPT_FILENAME']) && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) ||
    (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) === basename(__FILE__)) ||
    (isset($argv[0]) && basename($argv[0]) === basename(__FILE__))
);

try {
    $pdo = Database::getConnection();

    // Output success message when opened directly in a web browser or terminal
    if ($isDirectAccess) {
        $dbName = htmlspecialchars((string) env('DB_DATABASE', 'milesbh'));
        $dbHost = htmlspecialchars((string) env('DB_HOST', '127.0.0.1'));
        $dbPort = htmlspecialchars((string) env('DB_PORT', '3307'));

        echo "Database connected successfully!<br>";
        echo "Connected to database: <strong>{$dbName}</strong> on <strong>{$dbHost}:{$dbPort}</strong>";
    }

    return $pdo;
} catch (PDOException $e) {
    if ($isDirectAccess) {
        echo "Database connection failed: " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "<em>Please ensure MySQL is running in your XAMPP Control Panel.</em>";
        exit;
    }

    throw $e;
}

