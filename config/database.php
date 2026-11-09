<?php
/**
 * DOCTOR CONNECT - Database Connection Handler (PDO)
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // If in web context and not in setup.php, display a friendly setup page or error
                $scriptName = basename($_SERVER['PHP_SELF'] ?? '');
                if ($scriptName !== 'setup.php') {
                    die("
                        <div style='font-family:sans-serif; max-width:600px; margin:50px auto; padding:24px; border:1px solid #e2e8f0; border-radius:12px; background:#fff;'>
                            <h2 style='color:#dc2626; margin-top:0;'>Database Connection Error</h2>
                            <p style='color:#475569;'>Could not connect to MySQL database <strong>" . htmlspecialchars(DB_NAME) . "</strong>.</p>
                            <p style='font-size:14px; color:#64748b; background:#f8fafc; padding:12px; border-radius:6px;'><code>" . htmlspecialchars($e->getMessage()) . "</code></p>
                            <hr style='border:0; border-top:1px solid #e2e8f0; margin:20px 0;'>
                            <p><strong>First time running DoctorConnect?</strong></p>
                            <a href='" . BASE_URL . "/setup.php' style='display:inline-block; padding:10px 18px; background:#0284c7; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;'>Run Database Installer & Seeder &rarr;</a>
                        </div>
                    ");
                }
                throw $e;
            }
        }
        return self::$instance;
    }

    /**
     * Test connection without die
     */
    public static function testConnection(): bool {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

function get_db(): PDO {
    return Database::getConnection();
}
