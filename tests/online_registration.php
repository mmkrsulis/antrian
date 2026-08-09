<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/app.php';

use App\Core\Database;
use App\Services\OnlineRegistrationService;

function assertTrue(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function expectFailure(callable $call,string $contains): void {try{$call();throw new RuntimeException('Expected failure: '.$contains);}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'Expected failure'))throw $e;assertTrue(str_contains($e->getMessage(),$contains),'Unexpected error: '.$e->getMessage());}}

$db=Database::connection();$db->exec("INSERT INTO services(name,code,description,color,daily_limit,active) VALUES ('Test Service','TST','Automated test','#2563eb',2,1)");$serviceId=(int)$db->lastInsertId();$service=new OnlineRegistrationService();$today=date('Y-m-d');
assertTrue(count($service->services())===1,'Active service listing failed');
expectFailure(fn()=>$service->register([]),'Tanggal kunjungan');
expectFailure(fn()=>$service->register(['service_id'=>$serviceId,'visitor_name'=>'A','visit_date'=>$today,'consent'=>true]),'Nama pengunjung');
expectFailure(fn()=>$service->register(['service_id'=>$serviceId,'visitor_name'=>'Valid Name','phone'=>'bad','visit_date'=>$today,'consent'=>true]),'telepon');
expectFailure(fn()=>$service->register(['service_id'=>$serviceId,'visitor_name'=>'Valid Name','email'=>'bad','visit_date'=>$today,'consent'=>true]),'email');
expectFailure(fn()=>$service->register(['service_id'=>$serviceId,'visitor_name'=>'Valid Name','visit_date'=>$today]),'Persetujuan');
$one=$service->register(['service_id'=>$serviceId,'visitor_name'=>'Satu','phone'=>'0812345678','visit_date'=>$today,'consent'=>true],'automated_test');
assertTrue((bool)preg_match('/^RQ-[A-Z0-9]{8}$/',$one['registration_code']),'Registration code invalid');
assertTrue($one['status']==='registered','Initial status invalid');
assertTrue($service->find(strtolower($one['registration_code']))['visitor_name']==='Satu','Case-insensitive lookup failed');
$availability=$service->availability($serviceId,$today);assertTrue($availability['remaining']===1,'Capacity calculation failed');
$two=$service->register(['service_id'=>$serviceId,'visitor_name'=>'Dua','visit_date'=>$today,'consent'=>true]);
expectFailure(fn()=>$service->register(['service_id'=>$serviceId,'visitor_name'=>'Tiga','visit_date'=>$today,'consent'=>true]),'Kuota');
$checked=$service->checkIn($one['registration_code']);assertTrue($checked['status']==='checked_in','Check-in status invalid');assertTrue($checked['ticket_number']==='TST-001','Ticket sequence invalid');
$again=$service->checkIn($one['registration_code']);assertTrue($again['ticket_number']==='TST-001','Idempotent check-in failed');
expectFailure(fn()=>$service->checkIn('INVALID'),'Format kode');
$ticketCount=(int)$db->query('SELECT COUNT(*) FROM tickets')->fetchColumn();assertTrue($ticketCount===1,'Duplicate ticket created');
echo "Online registration service: 15 assertions passed.\n";
