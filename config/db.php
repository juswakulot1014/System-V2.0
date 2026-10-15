<?php
// config/db.php — database bootstrap with strict mode and safe helpers.

if (defined('DB_BOOTSTRAPPED')) return;
define('DB_BOOTSTRAPPED', true);

// ────────────────────────────────────────────────
// Load .env from project root (simple parser, no library)
// Only sets a var if it isn't already set in the environment.
// ────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;

        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);

        // Strip surrounding quotes
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[0] === substr($v, -1)) {
            $v = substr($v, 1, -1);
        } else {
            // Strip inline `# comment` only when preceded by whitespace
            if (($pos = strpos($v, ' #')) !== false) {
                $v = rtrim(substr($v, 0, $pos));
            }
        }

        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k]    = $v;
            $_SERVER[$k] = $v;
        }
    }
}

// ────────────────────────────────────────────────
// Env helper — hard-fails if a required variable is missing
// ────────────────────────────────────────────────
function env_or_fail(string $key): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        error_log("Missing required env var: $key");
        http_response_code(500);
        exit('Server configuration error.');
    }
    return $v;
}

define('DB_HOST', env_or_fail('DB_HOST'));
define('DB_USER', env_or_fail('DB_USER'));
define('DB_PASS', env_or_fail('DB_PASS'));
define('DB_NAME', env_or_fail('DB_NAME'));

// ────────────────────────────────────────────────
// Connect — strict error mode
// ────────────────────────────────────────────────
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');

    // Enforce strict SQL mode — rejects silently-truncated inserts, invalid dates, etc.
    // This makes data corruption fail loudly instead of silently.
    $conn->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
} catch (mysqli_sql_exception $e) {
    error_log('DB connect failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database unavailable.');
}

// ────────────────────────────────────────────────
// Query timing wrapper — use to catch slow queries
// ────────────────────────────────────────────────
/**
 * Wrap a DB call to measure and log its duration.
 * Anything over 500 ms is logged as [db.slow].
 * Any exception is logged as [db.error] and re-thrown.
 */
function db_timed(callable $fn, string $label = 'query'): mixed
{
    $t0 = microtime(true);
    try {
        $result = $fn();
        $ms = round((microtime(true) - $t0) * 1000, 1);
        if ($ms > 500) {
            error_log(sprintf('[db.slow] %s took %s ms', $label, $ms));
        }
        return $result;
    } catch (\Throwable $e) {
        $ms = round((microtime(true) - $t0) * 1000, 1);
        error_log(sprintf('[db.error] %s failed after %s ms: %s', $label, $ms, $e->getMessage()));
        throw $e;
    }
}

// ────────────────────────────────────────────────
// Parameter binding helpers
// ────────────────────────────────────────────────
if (!function_exists('db_param_types')) {
    /**
     * Determine mysqli bind_param() type characters from PHP values.
     *   int    → i
     *   float  → d
     *   null   → s (mysqli has no "null" type; 's' with null value works)
     *   string → s
     */
    function db_param_types(array $values): string
    {
        $types = '';
        foreach ($values as $v) {
            $types .= match (true) {
                is_int($v)   => 'i',
                is_float($v) => 'd',
                is_null($v)  => 's',
                default      => 's',
            };
        }
        return $types;
    }
}

if (!function_exists('db_bind')) {
    /**
     * Bind an array of values to a prepared statement in one call.
     * Replaces the ad-hoc `bind_params()` functions scattered across the app.
     */
    function db_bind(mysqli_stmt $stmt, array $values): void
    {
        if (empty($values)) return;
        $stmt->bind_param(db_param_types($values), ...$values);
    }
}

// ────────────────────────────────────────────────
// Convenience: prepared SELECT / INSERT / UPDATE / DELETE
// ────────────────────────────────────────────────
if (!function_exists('db_select')) {
    /**
     * Run a prepared SELECT. Returns an array of rows.
     */
    function db_select(string $sql, array $params = []): array
    {
        global $conn;
        return db_timed(function () use ($conn, $sql, $params) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $conn->error);
            }
            if (!empty($params)) {
                db_bind($stmt, $params);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        }, 'select');
    }
}

if (!function_exists('db_select_one')) {
    /**
     * Run a prepared SELECT and return the first row, or null.
     */
    function db_select_one(string $sql, array $params = []): ?array
    {
        $rows = db_select($sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('db_execute')) {
    /**
     * Run a prepared INSERT / UPDATE / DELETE.
     * Returns [affected_rows, insert_id].
     */
    function db_execute(string $sql, array $params = []): array
    {
        global $conn;
        return db_timed(function () use ($conn, $sql, $params) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $conn->error);
            }
            if (!empty($params)) {
                db_bind($stmt, $params);
            }
            $stmt->execute();
            $result = [$stmt->affected_rows, $conn->insert_id];
            $stmt->close();
            return $result;
        }, 'execute');
    }
}

// ────────────────────────────────────────────────
// Legacy helpers — kept for backwards compatibility
// ────────────────────────────────────────────────
if (!function_exists('db_prepare')) {
    function db_prepare(string $query): mysqli_stmt|false
    {
        global $conn;
        return $conn->prepare($query);
    }
}

if (!function_exists('db_get_last_id')) {
    function db_get_last_id(): int
    {
        global $conn;
        return (int)$conn->insert_id;
    }
}