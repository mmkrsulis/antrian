<?php
declare(strict_types=1);
namespace App\Core;

use PDO;

final class Database
{
    private static ?DatabaseConnection $connection = null;

    public static function connection(): DatabaseConnection
    {
        if (self::$connection) return self::$connection;
        $driver = strtolower((string) env('DB_CONNECTION', 'mysql'));
        $dsn = $driver === 'sqlite'
            ? 'sqlite:' . (string) env('DB_DATABASE')
            : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST'), env('DB_PORT', '3306'), env('DB_DATABASE'));
        $pdo = new DatabaseConnection($dsn, $driver === 'sqlite' ? null : (string) env('DB_USERNAME'), $driver === 'sqlite' ? null : (string) env('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$connection = $pdo;
        if ($driver === 'sqlite') {
            $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0, PDO::SQLITE_DETERMINISTIC);
            $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
            $pdo->exec('PRAGMA foreign_keys=ON');
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA synchronous=NORMAL');
            $pdo->exec('PRAGMA busy_timeout=10000');
            return $pdo;
        }
        $timezone=(string)env('APP_TIMEZONE','Asia/Jakarta');
        try {$saved=$pdo->query("SELECT `value` FROM settings WHERE `key`='app_timezone'")->fetchColumn();if($saved!==false)$timezone=(string)$saved;} catch (\Throwable) {}
        $offset=['Asia/Jakarta'=>'+07:00','Asia/Makassar'=>'+08:00','Asia/Jayapura'=>'+09:00','UTC'=>'+00:00'][$timezone]??'+07:00';
        $pdo->exec("SET time_zone='{$offset}'");
        return $pdo;
    }
}
