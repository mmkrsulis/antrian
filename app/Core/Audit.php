<?php
declare(strict_types=1);
namespace App\Core;

final class Audit
{
    public static function log(string $action, string $entity, ?int $entityId = null, array $details = []): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$_SESSION['user_id'] ?? null, $action, $entity, $entityId, json_encode($details), $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}

