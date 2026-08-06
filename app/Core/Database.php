<?php
declare(strict_types=1);
namespace App\Core;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection) return self::$connection;
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST'), env('DB_PORT', '3306'), env('DB_DATABASE'));
        $pdo = new PDO($dsn, (string) env('DB_USERNAME'), (string) env('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$connection = $pdo;
        $timezone=(string)env('APP_TIMEZONE','Asia/Jakarta');
        try {$saved=$pdo->query("SELECT `value` FROM settings WHERE `key`='app_timezone'")->fetchColumn();if($saved!==false)$timezone=(string)$saved;} catch (\Throwable) {}
        $offset=['Asia/Jakarta'=>'+07:00','Asia/Makassar'=>'+08:00','Asia/Jayapura'=>'+09:00','UTC'=>'+00:00'][$timezone]??'+07:00';
        $pdo->exec("SET time_zone='{$offset}'");
        return $pdo;
    }
}
