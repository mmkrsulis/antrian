<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class QueueService
{
    public function issue(int $serviceId, ?int $subServiceId = null, bool $requireSubService = false): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $serviceStmt = $db->prepare('SELECT * FROM services WHERE id = ? AND active = 1 FOR UPDATE');
            $serviceStmt->execute([$serviceId]);
            $service = $serviceStmt->fetch();
            if (!$service) throw new RuntimeException('Layanan tidak tersedia.');
            $subService = null;
            if ($subServiceId !== null && $subServiceId > 0) {
                $subStmt = $db->prepare('SELECT id,name FROM sub_services WHERE id=? AND service_id=? AND active=1 FOR UPDATE');
                $subStmt->execute([$subServiceId, $serviceId]);
                $subService = $subStmt->fetch();
                if (!$subService) throw new RuntimeException('Sublayanan tidak tersedia untuk layanan ini.');
            } elseif ($requireSubService) {
                $requiredStmt = $db->prepare('SELECT COUNT(*) FROM sub_services WHERE service_id=? AND active=1');
                $requiredStmt->execute([$serviceId]);
                if ((int)$requiredStmt->fetchColumn() > 0) throw new RuntimeException('Pilih sublayanan terlebih dahulu.');
            }
            $date = date('Y-m-d');
            $db->prepare('INSERT IGNORE INTO daily_sequences(service_id, queue_date, last_number) VALUES (?, ?, 0)')->execute([$serviceId, $date]);
            $seqStmt = $db->prepare('SELECT last_number FROM daily_sequences WHERE service_id = ? AND queue_date = ? FOR UPDATE');
            $seqStmt->execute([$serviceId, $date]);
            $next = (int) $seqStmt->fetchColumn() + 1;
            if ($service['daily_limit'] && $next > (int) $service['daily_limit']) throw new RuntimeException('Kuota layanan hari ini telah habis.');
            $db->prepare('UPDATE daily_sequences SET last_number = ? WHERE service_id = ? AND queue_date = ?')->execute([$next, $serviceId, $date]);
            $number = strtoupper($service['code']) . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $publicId = $this->uuid();
            $db->prepare('INSERT INTO tickets(public_id, service_id, sub_service_id, sub_service_name, queue_date, sequence_number, ticket_number) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$publicId, $serviceId, $subService['id'] ?? null, $subService['name'] ?? null, $date, $next, $number]);
            $id = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO queue_events(ticket_id, event_type, payload) VALUES (?, 'issued', ?)")->execute([$id, json_encode(['ticket_number' => $number, 'sub_service_name' => $subService['name'] ?? null])]);
            $db->commit();
            Audit::log('ticket.issued', 'ticket', $id, ['ticket_number' => $number, 'sub_service_name' => $subService['name'] ?? null]);
            return $this->ticket($publicId);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function callNext(int $counterId, int $serviceId, int $userId): ?array
    {
        $db = Database::connection();
        $permission=$db->prepare("SELECT COUNT(*) FROM users u WHERE u.id=? AND u.active=1 AND (u.role IN ('super_admin','admin') OR EXISTS (SELECT 1 FROM user_services us WHERE us.user_id=u.id AND us.service_id=?))");
        $permission->execute([$userId,$serviceId]);
        if(!(int)$permission->fetchColumn()) throw new RuntimeException('You are not assigned to the selected service.');
        $allowed=$db->prepare('SELECT COUNT(*) FROM counters c JOIN counter_services cs ON cs.counter_id=c.id JOIN services s ON s.id=cs.service_id WHERE c.id=? AND s.id=? AND c.active=1 AND s.active=1');
        $allowed->execute([$counterId,$serviceId]);
        if(!(int)$allowed->fetchColumn()) throw new RuntimeException('This counter is not assigned to the selected service.');
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT id FROM tickets WHERE service_id = ? AND queue_date = CURDATE() AND status IN ('waiting','skipped') ORDER BY status='waiting' DESC, sequence_number ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
            $stmt->execute([$serviceId]);
            $id = $stmt->fetchColumn();
            if (!$id) { $db->commit(); return null; }
            $db->prepare("UPDATE tickets SET status='called', counter_id=?, called_at=NOW() WHERE id=?")->execute([$counterId, $id]);
            $db->prepare("INSERT INTO queue_events(ticket_id,event_type,counter_id,user_id) VALUES (?,'called',?,?)")->execute([$id,$counterId,$userId]);
            $db->commit();
            Audit::log('ticket.called', 'ticket', (int)$id, ['counter_id'=>$counterId]);
            return $this->ticketById((int)$id);
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public function transition(int $ticketId, string $action, int $counterId, int $userId, ?string $reason = null): array
    {
        $map = [
            'recall'=>['called','called_at'], 'serve'=>['serving','service_started_at'], 'complete'=>['completed','completed_at'],
            'skip'=>['skipped',null], 'cancel'=>['cancelled',null]
        ];
        if (!isset($map[$action])) throw new RuntimeException('Aksi tidak valid.');
        [$status,$timeColumn] = $map[$action];
        $sql = "UPDATE tickets SET status=?, counter_id=?" . ($timeColumn ? ", {$timeColumn}=NOW()" : '') . ($action === 'cancel' ? ', cancel_reason=?' : '') . ' WHERE id=? AND queue_date=CURDATE()';
        $params = [$status,$counterId];
        if ($action === 'cancel') $params[] = $reason ?: 'Dibatalkan operator';
        $params[] = $ticketId;
        $db = Database::connection();
        $stmt = $db->prepare($sql); $stmt->execute($params);
        if (!$stmt->rowCount()) {
            $exists=$db->prepare('SELECT COUNT(*) FROM tickets WHERE id=? AND queue_date=CURDATE()');
            $exists->execute([$ticketId]);
            if(!(int)$exists->fetchColumn()) throw new RuntimeException('Antrean tidak ditemukan.');
        }
        $db->prepare('INSERT INTO queue_events(ticket_id,event_type,counter_id,user_id,payload) VALUES (?,?,?,?,?)')
            ->execute([$ticketId,$action,$counterId,$userId,json_encode(['reason'=>$reason])]);
        Audit::log("ticket.{$action}", 'ticket', $ticketId);
        return $this->ticketById($ticketId);
    }

    public function ticket(string $publicId): array
    {
        $stmt = Database::connection()->prepare($this->selectSql() . ' WHERE t.public_id=?'); $stmt->execute([$publicId]);
        return $stmt->fetch() ?: throw new RuntimeException('Tiket tidak ditemukan.');
    }

    public function ticketById(int $id): array
    {
        $stmt = Database::connection()->prepare($this->selectSql() . ' WHERE t.id=?'); $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new RuntimeException('Tiket tidak ditemukan.');
    }

    private function selectSql(): string { return 'SELECT t.*,s.name service_name,s.code service_code,c.name counter_name FROM tickets t JOIN services s ON s.id=t.service_id LEFT JOIN counters c ON c.id=t.counter_id'; }
    private function uuid(): string { $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4)); }
}
