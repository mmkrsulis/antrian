<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class OnlineRegistrationService
{
    public function services(): array
    {
        return Database::connection()->query("SELECT id,name,description,color,daily_limit,avg_service_minutes FROM services WHERE active=1 ORDER BY name")->fetchAll();
    }

    public function availability(int $serviceId, string $date): array
    {
        $date=$this->validDate($date);
        if ($date < date('Y-m-d')) throw new RuntimeException('Tanggal kunjungan sudah berlalu.');
        $stmt=Database::connection()->prepare("SELECT s.id,s.name,s.daily_limit,COUNT(r.id) registered FROM services s LEFT JOIN online_registrations r ON r.service_id=s.id AND r.visit_date=? AND r.status IN ('registered','checked_in') WHERE s.id=? AND s.active=1 GROUP BY s.id");
        $stmt->execute([$date,$serviceId]);$row=$stmt->fetch();
        if(!$row) throw new RuntimeException('Layanan tidak tersedia.');
        $limit=$row['daily_limit']!==null?(int)$row['daily_limit']:null;$used=(int)$row['registered'];
        return ['date'=>$date,'service_id'=>(int)$row['id'],'service_name'=>$row['name'],'capacity'=>$limit,'registered'=>$used,'remaining'=>$limit===null?null:max(0,$limit-$used),'available'=>$limit===null||$used<$limit];
    }

    public function register(array $data, string $source='native_form'): array
    {
        $serviceId=(int)($data['service_id']??0);$name=trim((string)($data['visitor_name']??''));$phone=$this->nullable($data['phone']??null);$email=$this->nullable($data['email']??null);$identity=$this->nullable($data['identity_number']??null);$notes=$this->nullable($data['notes']??null);$date=$this->validDate((string)($data['visit_date']??''));
        if(mb_strlen($name)<2||mb_strlen($name)>120) throw new RuntimeException('Nama pengunjung harus berisi 2–120 karakter.');
        if($phone!==null&&!preg_match('/^[0-9+() .-]{6,30}$/',$phone)) throw new RuntimeException('Nomor telepon tidak valid.');
        if($email!==null&&!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Alamat email tidak valid.');
        if($identity!==null&&mb_strlen($identity)>80) throw new RuntimeException('Nomor identitas terlalu panjang.');
        if($notes!==null&&mb_strlen($notes)>2000) throw new RuntimeException('Catatan maksimal 2.000 karakter.');
        if(!filter_var($data['consent']??false,FILTER_VALIDATE_BOOL)) throw new RuntimeException('Persetujuan pemrosesan data wajib diberikan.');
        $availability=$this->availability($serviceId,$date);if(!$availability['available'])throw new RuntimeException('Kuota pendaftaran pada tanggal tersebut telah habis.');
        $db=Database::connection();$db->beginTransaction();
        try{
            $service=$db->prepare('SELECT id FROM services WHERE id=? AND active=1 FOR UPDATE');$service->execute([$serviceId]);if(!$service->fetchColumn())throw new RuntimeException('Layanan tidak tersedia.');
            $publicId=$this->uuid();$code=$this->code();
            $stmt=$db->prepare('INSERT INTO online_registrations(public_id,registration_code,service_id,visitor_name,phone,email,identity_number,notes,visit_date,source,consent_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
            $stmt->execute([$publicId,$code,$serviceId,$name,$phone,$email,$identity,$notes,$date,substr($source,0,50)]);$id=(int)$db->lastInsertId();$db->commit();
            Audit::log('registration.created','online_registration',$id,['code'=>$code,'source'=>$source]);return $this->find($code);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public function find(string $code): array
    {
        $stmt=Database::connection()->prepare("SELECT r.public_id,r.registration_code,r.visitor_name,r.phone,r.email,r.visit_date,r.status,r.checked_in_at,r.created_at,s.id service_id,s.name service_name,t.public_id ticket_public_id,t.ticket_number,t.status ticket_status FROM online_registrations r JOIN services s ON s.id=r.service_id LEFT JOIN tickets t ON t.id=r.ticket_id WHERE r.registration_code=?");$stmt->execute([$this->normaliseCode($code)]);return $stmt->fetch()?:throw new RuntimeException('Kode pendaftaran tidak ditemukan.');
    }

    public function checkIn(string $code): array
    {
        $code=$this->normaliseCode($code);$db=Database::connection();$db->beginTransaction();
        try{$stmt=$db->prepare('SELECT id,service_id,visit_date,status,ticket_id FROM online_registrations WHERE registration_code=? FOR UPDATE');$stmt->execute([$code]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Kode pendaftaran tidak ditemukan.');if($row['status']==='checked_in'&&$row['ticket_id']){$db->commit();return $this->find($code);}if($row['status']==='checking_in')throw new RuntimeException('Pendaftaran sedang diproses pada perangkat lain.');if($row['status']!=='registered')throw new RuntimeException('Pendaftaran tidak dapat digunakan untuk check-in.');if($row['visit_date']!==date('Y-m-d'))throw new RuntimeException('Check-in hanya dapat dilakukan pada tanggal kunjungan.');$db->prepare("UPDATE online_registrations SET status='checking_in' WHERE id=?")->execute([$row['id']]);$db->commit();
            try{$ticket=(new QueueService)->issue((int)$row['service_id']);}catch(Throwable $issueError){$db->prepare("UPDATE online_registrations SET status='registered' WHERE id=? AND status='checking_in' AND ticket_id IS NULL")->execute([$row['id']]);throw $issueError;}
            $db->beginTransaction();$update=$db->prepare("UPDATE online_registrations SET ticket_id=?,status='checked_in',checked_in_at=NOW() WHERE id=? AND status='checking_in' AND ticket_id IS NULL");$update->execute([$ticket['id'],$row['id']]);if(!$update->rowCount())throw new RuntimeException('Check-in tidak dapat diselesaikan.');$db->commit();Audit::log('registration.checked_in','online_registration',(int)$row['id'],['ticket'=>$ticket['ticket_number']]);return $this->find($code);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    private function validDate(string $value): string {$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new RuntimeException('Tanggal kunjungan tidak valid.');if($value<date('Y-m-d'))throw new RuntimeException('Tanggal kunjungan sudah berlalu.');if($value>(new DateTimeImmutable('+90 days'))->format('Y-m-d'))throw new RuntimeException('Pendaftaran maksimal 90 hari ke depan.');return $value;}
    private function nullable(mixed $value): ?string {$value=trim((string)$value);return $value===''?null:$value;}
    private function normaliseCode(string $code): string {$code=strtoupper(trim($code));if(!preg_match('/^RQ-[A-Z0-9]{8}$/',$code))throw new RuntimeException('Format kode pendaftaran tidak valid.');return $code;}
    private function code(): string {return 'RQ-'.strtoupper(substr(bin2hex(random_bytes(6)),0,8));}
    private function uuid(): string {$d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
}
