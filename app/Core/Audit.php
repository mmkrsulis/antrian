<?php
declare(strict_types=1);
namespace App\Core;

use PDOException;

final class Audit
{
    public static function log(string $action, string $entity, ?int $entityId = null, array $details = []): void
    {
        $params=[$_SESSION['user_id'] ?? null, $action, $entity, $entityId, json_encode($details), $_SERVER['REMOTE_ADDR'] ?? null];
        for($attempt=0;$attempt<5;$attempt++) {
            try {
                $stmt = Database::connection()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute($params);
                return;
            } catch(PDOException $e) {
                $busy=str_contains(strtolower($e->getMessage()),'locked')||str_contains(strtolower($e->getMessage()),'busy');
                if(!$busy) throw $e;
                usleep(100000*($attempt+1));
            }
        }
        error_log("Audit log deferred after database contention: {$action} {$entity}");
    }
}
