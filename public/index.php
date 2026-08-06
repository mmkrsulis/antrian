<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Database;
use App\Core\View;
use App\Services\QueueService;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$json = static function(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
$redirect = static function(string $to): never { header("Location: {$to}"); exit; };
$input = static function(): array { $raw=json_decode(file_get_contents('php://input'),true); return is_array($raw)?$raw:$_POST; };
$csrf = static function(array $data) use ($json): void { if (!hash_equals(csrf_token(), (string)($data['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) $json(['error'=>'Token keamanan tidak valid.'],419); };

try {
    if ($path === '/health') { try { Database::connection()->query('SELECT 1'); $json(['status'=>'ok']); } catch (Throwable) { $json(['status'=>'starting'],503); } }
    if ($path === '/downloads/reka-queue-windows-startup.zip' && in_array($method, ['GET','HEAD'], true)) {
        $file = dirname(__DIR__).'/deployment/reka-queue-windows-startup.zip';
        if (!is_file($file)) { http_response_code(404); exit('Download tidak ditemukan.'); }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="reka-queue-windows-startup.zip"');
        header('Content-Length: '.filesize($file));
        header('Cache-Control: no-store');
        if ($method === 'GET') readfile($file);
        exit;
    }
    if ($path === '/install') {
        if (is_file(dirname(__DIR__).'/storage/install.lock')) $redirect('/login');
        $requirements=['PHP >= 8.1'=>version_compare(PHP_VERSION,'8.1','>='),'PDO MySQL'=>extension_loaded('pdo_mysql'),'Storage writable'=>is_writable(dirname(__DIR__).'/storage')];
        if ($method==='POST') {
            $csrf($_POST); $db=Database::connection();
            foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) $db->exec(file_get_contents($file));
            foreach (glob(dirname(__DIR__).'/database/seeds/*.sql') as $file) $db->exec(file_get_contents($file));
            $username=trim($_POST['username']??'admin'); $password=(string)($_POST['password']??'');
            if (strlen($password)<10) throw new RuntimeException('Password minimal 10 karakter.');
            $stmt=$db->prepare("INSERT INTO users(name,username,password_hash,role) VALUES (?,?,?,'super_admin') ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)");
            $stmt->execute([trim($_POST['name']??'Administrator'),$username,password_hash($password,PASSWORD_DEFAULT)]);
            file_put_contents(dirname(__DIR__).'/storage/install.lock',date(DATE_ATOM)); $redirect('/login?installed=1');
        }
        View::render('install',compact('requirements')); exit;
    }
    if (!is_file(dirname(__DIR__).'/storage/install.lock')) $redirect('/install');

    if ($path === '/login') {
        if ($method==='POST') { $csrf($_POST); if(Auth::attempt(trim($_POST['username']??''),(string)($_POST['password']??''),isset($_POST['remember']))) $redirect('/dashboard'); $error='Username atau password salah.'; }
        View::render('login',compact('error')); exit;
    }
    if ($path === '/logout' && $method==='POST') { $csrf($_POST); Auth::logout(); $redirect('/login'); }
    if ($path === '/') $redirect('/kiosk');

    if ($path === '/kiosk') {
        $services=Database::connection()->query("SELECT s.*,COUNT(t.id) waiting FROM services s LEFT JOIN tickets t ON t.service_id=s.id AND t.queue_date=CURDATE() AND t.status IN ('waiting','called') WHERE s.active=1 GROUP BY s.id ORDER BY s.id")->fetchAll();
        View::render('kiosk',compact('services'),false); exit;
    }
    if ($path === '/api/tickets' && $method==='POST') { $data=$input(); $csrf($data); $ticket=(new QueueService)->issue((int)($data['service_id']??0)); $json(['data'=>$ticket],201); }
    if (preg_match('#^/ticket/([a-f0-9-]+)$#',$path,$m)) { $ticket=(new QueueService)->ticket($m[1]); View::render('ticket',compact('ticket'),false); exit; }
    if ($path === '/display') {
        $key=(string)($_GET['key']??''); if (!hash_equals((string)env('DISPLAY_ACCESS_KEY'),$key)) { http_response_code(403); exit('Kunci display tidak valid.'); }
        View::render('display',['displayKey'=>$key],false); exit;
    }
    if ($path === '/api/display/events') {
        $key=(string)($_GET['key']??''); if (!hash_equals((string)env('DISPLAY_ACCESS_KEY'),$key)) $json(['error'=>'Unauthorized'],401);
        $after=max(0,(int)($_GET['after']??0));
        $stmt=Database::connection()->prepare("SELECT e.id,e.event_type,e.created_at,t.ticket_number,c.name counter_name FROM queue_events e JOIN tickets t ON t.id=e.ticket_id LEFT JOIN counters c ON c.id=e.counter_id WHERE e.id>? AND e.event_type IN ('called','recall') ORDER BY e.id ASC LIMIT 50"); $stmt->execute([$after]);
        $recent=Database::connection()->query("SELECT t.ticket_number,c.name counter_name,t.called_at FROM tickets t JOIN counters c ON c.id=t.counter_id WHERE t.queue_date=CURDATE() AND t.called_at IS NOT NULL ORDER BY t.called_at DESC LIMIT 8")->fetchAll();
        $summary=Database::connection()->query("SELECT s.id,s.name,s.code,s.color,COUNT(t.id) total,COALESCE(SUM(t.status IN ('waiting','skipped')),0) waiting,(SELECT t2.ticket_number FROM tickets t2 WHERE t2.service_id=s.id AND t2.queue_date=CURDATE() AND t2.called_at IS NOT NULL ORDER BY t2.called_at DESC,t2.id DESC LIMIT 1) current_number,(SELECT c2.name FROM tickets t3 JOIN counters c2 ON c2.id=t3.counter_id WHERE t3.service_id=s.id AND t3.queue_date=CURDATE() AND t3.called_at IS NOT NULL ORDER BY t3.called_at DESC,t3.id DESC LIMIT 1) counter_name FROM services s LEFT JOIN tickets t ON t.service_id=s.id AND t.queue_date=CURDATE() WHERE s.active=1 GROUP BY s.id ORDER BY s.id")->fetchAll();
        $settingRows=Database::connection()->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('display_media_type','display_media_url','display_media_muted','header_mode','header_image_url','header_title','header_subtitle','footer_text')")->fetchAll();
        $screenSettings=array_column($settingRows,'value','key');
        $playlistFiles=[];foreach(glob(dirname(__DIR__).'/public/uploads/media/playlist/*.{mp4,webm,ogv}',GLOB_BRACE)?:[] as $file)$playlistFiles[]='/uploads/media/playlist/'.rawurlencode(basename($file));sort($playlistFiles);
        $json(['events'=>$stmt->fetchAll(),'recent'=>$recent,'summary'=>$summary,'media'=>['type'=>$screenSettings['display_media_type']??'none','url'=>$screenSettings['display_media_url']??'','muted'=>($screenSettings['display_media_muted']??'1')==='1','playlist'=>$playlistFiles],'header'=>['mode'=>$screenSettings['header_mode']??'text','image_url'=>$screenSettings['header_image_url']??'','title'=>$screenSettings['header_title']??app_name(),'subtitle'=>$screenSettings['header_subtitle']??'Sistem Antrean Digital'],'footer_text'=>$screenSettings['footer_text']??'Mohon menunggu nomor antrean Anda dipanggil']);
    }
    if ($path === '/api/display/speech' && $method === 'GET') {
        $key=(string)($_GET['key']??'');if(!hash_equals((string)env('DISPLAY_ACCESS_KEY'),$key)){http_response_code(401);exit;}
        $ticket=strtoupper(trim((string)($_GET['ticket']??'')));$counterName=trim((string)($_GET['counter']??'Loket pelayanan'));
        if(!preg_match('/^[A-Z0-9 -]{1,20}$/',$ticket)||$counterName===''||mb_strlen($counterName)>80){http_response_code(422);exit;}
        $spokenTicket=implode(', ',array_map(static fn(string $char): string => ['0'=>'nol','1'=>'satu','2'=>'dua','3'=>'tiga','4'=>'empat','5'=>'lima','6'=>'enam','7'=>'tujuh','8'=>'delapan','9'=>'sembilan','A'=>'a','B'=>'be','C'=>'ce','D'=>'de','E'=>'e','F'=>'ef','G'=>'ge','H'=>'ha','I'=>'i','J'=>'je','K'=>'ka','L'=>'el','M'=>'em','N'=>'en','O'=>'o','P'=>'pe','Q'=>'ki','R'=>'er','S'=>'es','T'=>'te','U'=>'u','V'=>'ve','W'=>'we','X'=>'eks','Y'=>'ye','Z'=>'zet'][$char]??$char,str_split(str_replace(['-',' '],'',$ticket))));
        $text='Nomor antrean, '.$spokenTicket.', silakan menuju '.$counterName.'.';$cacheDir=dirname(__DIR__).'/public/uploads/speech';$cacheFile=$cacheDir.'/'.hash('sha256',$text).'.wav';
        if(!is_file($cacheFile)){$temporary=$cacheFile.'.tmp-'.bin2hex(random_bytes(4));$command='espeak-ng -v id -s 132 -p 48 -a 170 -w '.escapeshellarg($temporary).' '.escapeshellarg($text);exec($command,$output,$exitCode);if($exitCode!==0||!is_file($temporary)||filesize($temporary)<1000){@unlink($temporary);http_response_code(503);exit;}rename($temporary,$cacheFile);}
        header('Content-Type: audio/wav');header('Content-Length: '.filesize($cacheFile));header('Cache-Control: public, max-age=31536000, immutable');readfile($cacheFile);exit;
    }

    if ($path === '/dashboard') {
        $user=Auth::require(); $db=Database::connection(); $allowedServices=[];
        if($user['role']==='operator'){
            $statsStmt=$db->prepare("SELECT COUNT(*) total,COALESCE(SUM(t.status='waiting'),0) waiting,COALESCE(SUM(t.status='serving'),0) serving,COALESCE(SUM(t.status='completed'),0) completed FROM tickets t JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE t.queue_date=CURDATE()");
            $statsStmt->execute([$user['id']]);$stats=$statsStmt->fetch();
            $servicesStmt=$db->prepare('SELECT s.name FROM services s JOIN user_services us ON us.service_id=s.id WHERE us.user_id=? AND s.active=1 ORDER BY s.name');
            $servicesStmt->execute([$user['id']]);$allowedServices=$servicesStmt->fetchAll(PDO::FETCH_COLUMN);
        }else{$stats=$db->query("SELECT COUNT(*) total,COALESCE(SUM(status='waiting'),0) waiting,COALESCE(SUM(status='serving'),0) serving,COALESCE(SUM(status='completed'),0) completed FROM tickets WHERE queue_date=CURDATE()")->fetch();}
        View::render('dashboard',compact('user','stats','allowedServices')); exit;
    }
    if ($path === '/operator') {
        $user=Auth::require(['super_admin','admin','operator']);
        $db=Database::connection();
        if($user['role']==='operator'){
            $counterStmt=$db->prepare('SELECT c.*,GROUP_CONCAT(cs.service_id ORDER BY cs.service_id) service_ids FROM counters c LEFT JOIN counter_services cs ON cs.counter_id=c.id WHERE c.active=1 AND (? IS NULL OR c.id=?) GROUP BY c.id ORDER BY c.name');$counterStmt->execute([$user['assigned_counter_id'],$user['assigned_counter_id']]);$counters=$counterStmt->fetchAll();
            $serviceStmt=$db->prepare('SELECT s.* FROM services s JOIN user_services us ON us.service_id=s.id WHERE us.user_id=? AND s.active=1 ORDER BY s.name');$serviceStmt->execute([$user['id']]);$services=$serviceStmt->fetchAll();
        }else{$counters=$db->query('SELECT c.*,GROUP_CONCAT(cs.service_id ORDER BY cs.service_id) service_ids FROM counters c LEFT JOIN counter_services cs ON cs.counter_id=c.id WHERE c.active=1 GROUP BY c.id ORDER BY c.name')->fetchAll();$services=$db->query('SELECT * FROM services WHERE active=1 ORDER BY name')->fetchAll();}
        if($user['role']==='operator'){$currentStmt=$db->prepare("SELECT t.*,s.name service_name,c.name counter_name FROM tickets t JOIN services s ON s.id=t.service_id JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? LEFT JOIN counters c ON c.id=t.counter_id WHERE t.queue_date=CURDATE() AND t.status IN ('called','serving') AND (? IS NULL OR t.counter_id=?) ORDER BY t.updated_at DESC LIMIT 20");$currentStmt->execute([$user['id'],$user['assigned_counter_id'],$user['assigned_counter_id']]);$current=$currentStmt->fetchAll();}
        else{$current=$db->query("SELECT t.*,s.name service_name,c.name counter_name FROM tickets t JOIN services s ON s.id=t.service_id LEFT JOIN counters c ON c.id=t.counter_id WHERE t.queue_date=CURDATE() AND t.status IN ('called','serving') ORDER BY t.updated_at DESC LIMIT 20")->fetchAll();}
        if($user['role']==='operator'){$waitingStmt=$db->prepare("SELECT t.service_id,COUNT(*) waiting FROM tickets t JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') GROUP BY t.service_id");$waitingStmt->execute([$user['id']]);}
        else{$waitingStmt=$db->query("SELECT service_id,COUNT(*) waiting FROM tickets WHERE queue_date=CURDATE() AND status IN ('waiting','skipped') GROUP BY service_id");}
        $waitingCounts=array_column($waitingStmt->fetchAll(),'waiting','service_id');
        $notificationStmt=$db->prepare('SELECT enabled,sound_type,sound_url,volume FROM user_notification_settings WHERE user_id=?');$notificationStmt->execute([$user['id']]);$notificationSettings=$notificationStmt->fetch()?:['enabled'=>1,'sound_type'=>'chime','sound_url'=>'','volume'=>'0.80'];$notificationCursor=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM tickets')->fetchColumn();
        View::render('operator',compact('user','counters','services','current','waitingCounts','notificationSettings','notificationCursor'),false); exit;
    }
    if ($path === '/api/operator/next' && $method==='POST') {
        $user=Auth::require(['super_admin','admin','operator']); $data=$input(); $csrf($data);
        $ticket=(new QueueService)->callNext((int)$data['counter_id'],(int)$data['service_id'],(int)$user['id']);
        $json(['data'=>$ticket]);
    }
    if ($path === '/api/operator/notifications') {
        $user=Auth::require(['super_admin','admin','operator']);$after=max(0,(int)($_GET['after']??0));$db=Database::connection();$cursor=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM tickets')->fetchColumn();
        if($user['role']==='operator'){$stmt=$db->prepare("SELECT t.id,t.service_id,t.ticket_number,t.created_at,s.name service_name FROM tickets t JOIN services s ON s.id=t.service_id JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE t.id>? AND t.queue_date=CURDATE() AND t.status='waiting' ORDER BY t.id LIMIT 20");$stmt->execute([$user['id'],$after]);$countsStmt=$db->prepare("SELECT t.service_id,COUNT(*) waiting FROM tickets t JOIN user_services us ON us.service_id=t.service_id AND us.user_id=? WHERE t.queue_date=CURDATE() AND t.status IN ('waiting','skipped') GROUP BY t.service_id");$countsStmt->execute([$user['id']]);}
        else{$stmt=$db->prepare("SELECT t.id,t.service_id,t.ticket_number,t.created_at,s.name service_name FROM tickets t JOIN services s ON s.id=t.service_id WHERE t.id>? AND t.queue_date=CURDATE() AND t.status='waiting' ORDER BY t.id LIMIT 20");$stmt->execute([$after]);$countsStmt=$db->query("SELECT service_id,COUNT(*) waiting FROM tickets WHERE queue_date=CURDATE() AND status IN ('waiting','skipped') GROUP BY service_id");}
        $json(['cursor'=>$cursor,'tickets'=>$stmt->fetchAll(),'waiting_counts'=>array_column($countsStmt->fetchAll(),'waiting','service_id')]);
    }
    if ($path === '/api/operator/notification-settings' && $method==='POST') {
        $user=Auth::require(['super_admin','admin','operator']);$csrf($_POST);$enabled=isset($_POST['enabled'])?1:0;$soundType=(string)($_POST['sound_type']??'chime');$volume=max(0,min(1,(float)($_POST['volume']??0.8)));if(!in_array($soundType,['chime','bell','beep','custom'],true))throw new RuntimeException('Notification sound is invalid.');$db=Database::connection();$stmt=$db->prepare('SELECT sound_url FROM user_notification_settings WHERE user_id=?');$stmt->execute([$user['id']]);$soundUrl=(string)($stmt->fetchColumn()?:'');
        if(isset($_FILES['sound_file'])&&($_FILES['sound_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){if($_FILES['sound_file']['error']!==UPLOAD_ERR_OK||$_FILES['sound_file']['size']>10*1024*1024)throw new RuntimeException('Sound upload failed or exceeds 10 MB.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['sound_file']['tmp_name']);$formats=['audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/ogg'=>'ogg'];if(!isset($formats[$mime]))throw new RuntimeException('Custom sound must be MP3, WAV, or OGG.');$dir=dirname(__DIR__).'/public/uploads/notifications';if(!is_dir($dir))mkdir($dir,0775,true);foreach(glob($dir.'/user-'.$user['id'].'.*')?:[] as $old)if(is_file($old))unlink($old);$target=$dir.'/user-'.$user['id'].'.'.$formats[$mime];if(!move_uploaded_file($_FILES['sound_file']['tmp_name'],$target))throw new RuntimeException('Unable to store custom sound.');$soundUrl='/uploads/notifications/'.basename($target);$soundType='custom';}
        $save=$db->prepare('INSERT INTO user_notification_settings(user_id,enabled,sound_type,sound_url,volume) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),sound_type=VALUES(sound_type),sound_url=VALUES(sound_url),volume=VALUES(volume)');$save->execute([$user['id'],$enabled,$soundType,$soundUrl,$volume]);$json(['data'=>['enabled'=>(bool)$enabled,'sound_type'=>$soundType,'sound_url'=>$soundUrl,'volume'=>$volume]]);
    }
    if ($path === '/api/admin/display-settings' && $method==='POST') {
        Auth::require(['super_admin','admin']); $csrf($_POST);
        if(!array_key_exists('media_type',$_POST)) throw new RuntimeException('Media source is required; existing settings were not changed.');
        $mediaType=(string)$_POST['media_type']; $headerMode=(string)($_POST['header_mode']??'text');
        if(!in_array($mediaType,['none','local','playlist','youtube','obs'],true)) throw new RuntimeException('Media type is invalid.');
        if(!in_array($headerMode,['text','image'],true)) throw new RuntimeException('Header mode is invalid.');
        $mediaUrl=trim((string)($_POST['media_url']??'')); $headerImageUrl=trim((string)($_POST['header_image_url']??''));
        $storeUpload=static function(string $field,string $folder,array $mimes,int $maxBytes): ?string {
            if(!isset($_FILES[$field])||($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
            if($_FILES[$field]['error']!==UPLOAD_ERR_OK) throw new RuntimeException("Upload {$field} failed.");
            if($_FILES[$field]['size']>$maxBytes) throw new RuntimeException("Uploaded {$field} is too large.");
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
            if(!isset($mimes[$mime])) throw new RuntimeException("Uploaded {$field} format is not supported.");
            $dir=dirname(__DIR__)."/public/uploads/{$folder}"; if(!is_dir($dir)) mkdir($dir,0775,true);
            foreach(glob($dir.'/current.*')?:[] as $old) if(is_file($old)) unlink($old);
            $target=$dir.'/current.'.$mimes[$mime];
            if(!move_uploaded_file($_FILES[$field]['tmp_name'],$target)) throw new RuntimeException("Unable to store {$field}.");
            return "/uploads/{$folder}/".basename($target);
        };
        $videoUpload=$storeUpload('media_file','media',['video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv'],512*1024*1024); if($videoUpload)$mediaUrl=$videoUpload;
        $playlistDir=dirname(__DIR__).'/public/uploads/media/playlist';if(!is_dir($playlistDir))mkdir($playlistDir,0775,true);if(isset($_POST['clear_playlist']))foreach(glob($playlistDir.'/*.{mp4,webm,ogv}',GLOB_BRACE)?:[] as $old)if(is_file($old))unlink($old);
        if(isset($_FILES['playlist_files']['name'])&&is_array($_FILES['playlist_files']['name'])){foreach($_FILES['playlist_files']['name'] as $i=>$name){$error=$_FILES['playlist_files']['error'][$i]??UPLOAD_ERR_NO_FILE;if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK)throw new RuntimeException('A playlist video failed to upload.');if(($_FILES['playlist_files']['size'][$i]??0)>512*1024*1024)throw new RuntimeException('A playlist video exceeds 512 MB.');$tmp=$_FILES['playlist_files']['tmp_name'][$i];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$formats=['video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv'];if(!isset($formats[$mime]))throw new RuntimeException('Playlist supports MP4, WebM, and OGG only.');$safe=preg_replace('/[^a-zA-Z0-9_-]+/','-',pathinfo((string)$name,PATHINFO_FILENAME));$target=$playlistDir.'/'.trim($safe,'-').'-'.substr(hash_file('sha256',$tmp),0,8).'.'.$formats[$mime];if(!move_uploaded_file($tmp,$target))throw new RuntimeException('Unable to store a playlist video.');}}
        if(in_array($mediaType,['youtube','obs'],true)&&$mediaUrl!==''&&!filter_var($mediaUrl,FILTER_VALIDATE_URL)) throw new RuntimeException('A valid media URL is required.');
        if($mediaType==='local'&&$mediaUrl!==''&&!str_starts_with($mediaUrl,'/')&&!filter_var($mediaUrl,FILTER_VALIDATE_URL)) throw new RuntimeException('Local media path is invalid.');
        if(in_array($mediaType,['youtube','obs','local'],true)&&$mediaUrl==='') throw new RuntimeException('The selected media source requires a URL or uploaded file; existing settings were not changed.');
        if($mediaType==='playlist'&&!(glob($playlistDir.'/*.{mp4,webm,ogv}',GLOB_BRACE)?:[])) throw new RuntimeException('Upload at least one playlist video; existing settings were not changed.');
        if($headerImageUrl!==''&&!str_starts_with($headerImageUrl,'/')&&!filter_var($headerImageUrl,FILTER_VALIDATE_URL)) throw new RuntimeException('Header image path is invalid.');
        $values=['display_media_type'=>$mediaType,'display_media_url'=>$mediaUrl,'display_media_muted'=>isset($_POST['media_muted'])?'1':'0'];
        $db=Database::connection();$previousStmt=$db->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('display_media_type','display_media_url','display_media_muted')");$previous=array_column($previousStmt->fetchAll(),'value','key');
        $stmt=$db->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)'); foreach($values as $key=>$value)$stmt->execute([$key,$value]);
        Audit::log('settings.display_media_updated','settings',null,['previous'=>$previous,'current'=>$values]);
        $json(['data'=>$values]);
    }
    if (preg_match('#^/api/operator/tickets/(\d+)/(recall|serve|complete|skip|cancel)$#',$path,$m) && $method==='POST') {
        $user=Auth::require(['super_admin','admin','operator']); $data=$input(); $csrf($data);
        $ticket=(new QueueService)->transition((int)$m[1],$m[2],(int)$data['counter_id'],(int)$user['id'],$data['reason']??null); $json(['data'=>$ticket]);
    }
    if ($path === '/admin/services') {
        Auth::require(['super_admin','admin']);
        if ($method==='POST') { $csrf($_POST); $stmt=Database::connection()->prepare('INSERT INTO services(name,code,description,color,avg_service_minutes) VALUES (?,?,?,?,?)'); $stmt->execute([trim($_POST['name']),strtoupper(trim($_POST['code'])),trim($_POST['description']),$_POST['color'],max(1,(int)$_POST['minutes'])]); $redirect('/admin/services'); }
        $services=Database::connection()->query('SELECT * FROM services ORDER BY id')->fetchAll(); View::render('services',compact('services')); exit;
    }
    if ($path === '/admin/counters') {
        Auth::require(['super_admin','admin']);$db=Database::connection();
        if($method==='POST'){$csrf($_POST);$id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$code=strtoupper(trim((string)($_POST['code']??'')));$active=isset($_POST['active'])?1:0;$serviceIds=array_values(array_unique(array_filter(array_map('intval',$_POST['service_ids']??[]))));if($name===''||$code==='')throw new RuntimeException('Counter name and code are required.');if(!$serviceIds)throw new RuntimeException('Assign at least one service.');$db->beginTransaction();try{if($id){$stmt=$db->prepare('UPDATE counters SET name=?,code=?,active=? WHERE id=?');$stmt->execute([$name,$code,$active,$id]);}else{$stmt=$db->prepare('INSERT INTO counters(name,code,active) VALUES (?,?,?)');$stmt->execute([$name,$code,$active]);$id=(int)$db->lastInsertId();}$db->prepare('DELETE FROM counter_services WHERE counter_id=?')->execute([$id]);$link=$db->prepare('INSERT INTO counter_services(counter_id,service_id) VALUES (?,?)');foreach($serviceIds as $serviceId)$link->execute([$id,$serviceId]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}$redirect('/admin/counters?saved=1');}
        $counters=$db->query('SELECT c.*,GROUP_CONCAT(cs.service_id ORDER BY cs.service_id) service_ids,GROUP_CONCAT(s.name ORDER BY s.id SEPARATOR ", ") service_names FROM counters c LEFT JOIN counter_services cs ON cs.counter_id=c.id LEFT JOIN services s ON s.id=cs.service_id GROUP BY c.id ORDER BY c.id')->fetchAll();$services=$db->query('SELECT * FROM services WHERE active=1 ORDER BY id')->fetchAll();View::render('counters',compact('counters','services'));exit;
    }
    if ($path === '/admin/users') {
        Auth::require(['super_admin','admin']);$db=Database::connection();
        if($method==='POST'){$csrf($_POST);$id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');$role=(string)($_POST['role']??'operator');$counterId=(int)($_POST['assigned_counter_id']??0)?:null;$active=isset($_POST['active'])?1:0;$serviceIds=array_values(array_unique(array_filter(array_map('intval',$_POST['service_ids']??[]))));if($name===''||$username==='')throw new RuntimeException('Name and username are required.');if(!in_array($role,['admin','operator'],true))throw new RuntimeException('Role is invalid.');if($role==='operator'&&!$serviceIds)throw new RuntimeException('Assign at least one service to an operator.');if(!$id&&strlen($password)<10)throw new RuntimeException('New user password must contain at least 10 characters.');$db->beginTransaction();try{if($id){if($password!==''){if(strlen($password)<10)throw new RuntimeException('Password must contain at least 10 characters.');$stmt=$db->prepare('UPDATE users SET name=?,username=?,password_hash=?,role=?,assigned_counter_id=?,active=? WHERE id=? AND role<>\'super_admin\'');$stmt->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT),$role,$counterId,$active,$id]);}else{$stmt=$db->prepare('UPDATE users SET name=?,username=?,role=?,assigned_counter_id=?,active=? WHERE id=? AND role<>\'super_admin\'');$stmt->execute([$name,$username,$role,$counterId,$active,$id]);}}else{$stmt=$db->prepare('INSERT INTO users(name,username,password_hash,role,assigned_counter_id,active) VALUES (?,?,?,?,?,?)');$stmt->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT),$role,$counterId,$active]);$id=(int)$db->lastInsertId();}$db->prepare('DELETE FROM user_services WHERE user_id=?')->execute([$id]);if($role==='operator'){$link=$db->prepare('INSERT INTO user_services(user_id,service_id) VALUES (?,?)');foreach($serviceIds as $serviceId)$link->execute([$id,$serviceId]);}$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}$redirect('/admin/users?saved=1');}
        $users=$db->query("SELECT u.id,u.name,u.username,u.role,u.assigned_counter_id,u.active,c.name counter_name,GROUP_CONCAT(us.service_id ORDER BY us.service_id) service_ids,GROUP_CONCAT(s.name ORDER BY s.id SEPARATOR ', ') service_names FROM users u LEFT JOIN counters c ON c.id=u.assigned_counter_id LEFT JOIN user_services us ON us.user_id=u.id LEFT JOIN services s ON s.id=us.service_id WHERE u.role<>'super_admin' GROUP BY u.id ORDER BY u.id")->fetchAll();$services=$db->query('SELECT * FROM services WHERE active=1 ORDER BY id')->fetchAll();$counters=$db->query('SELECT * FROM counters WHERE active=1 ORDER BY name')->fetchAll();View::render('users',compact('users','services','counters'));exit;
    }
    if ($path === '/api/admin/header-height' && $method==='POST') {
        Auth::require(['super_admin','admin']);$data=$input();$csrf($data);$mode=(string)($data['mode']??'fixed');$height=max(60,min(300,(int)($data['height']??100)));if(!in_array($mode,['auto','fixed'],true))throw new RuntimeException('Header height mode is invalid.');$stmt=Database::connection()->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');$stmt->execute(['header_height_mode',$mode]);$stmt->execute(['header_height_px',(string)$height]);$json(['data'=>['mode'=>$mode,'height'=>$height]]);
    }
    if ($path === '/admin/settings') {
        Auth::require(['super_admin','admin']);$db=Database::connection();
        if($method==='POST'){$csrf($_POST);$values=['app_name'=>trim((string)($_POST['app_name']??'')),'header_mode'=>(string)($_POST['header_mode']??'text'),'header_title'=>trim((string)($_POST['header_title']??'')),'header_subtitle'=>trim((string)($_POST['header_subtitle']??'')),'header_image_url'=>trim((string)($_POST['header_image_url']??'')),'primary_color'=>(string)($_POST['primary_color']??'#075f91'),'secondary_color'=>(string)($_POST['secondary_color']??'#1478c8'),'accent_color'=>(string)($_POST['accent_color']??'#ffd94f'),'footer_text'=>trim((string)($_POST['footer_text']??''))];if($values['app_name']===''||$values['footer_text']==='')throw new RuntimeException('Application name and footer text are required.');if(!in_array($values['header_mode'],['text','image'],true))throw new RuntimeException('Header mode is invalid.');if($values['header_image_url']!==''&&!str_starts_with($values['header_image_url'],'/')&&!filter_var($values['header_image_url'],FILTER_VALIDATE_URL))throw new RuntimeException('Header image URL is invalid.');foreach(['primary_color','secondary_color','accent_color'] as $color)if(!preg_match('/^#[0-9a-fA-F]{6}$/',$values[$color]))throw new RuntimeException('Theme color is invalid.');if(isset($_FILES['header_image'])&&($_FILES['header_image']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){if($_FILES['header_image']['error']!==UPLOAD_ERR_OK||$_FILES['header_image']['size']>10*1024*1024)throw new RuntimeException('Header image upload failed or exceeds 10 MB.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['header_image']['tmp_name']);$formats=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($formats[$mime]))throw new RuntimeException('Header image must be JPG, PNG, or WebP.');$dir=dirname(__DIR__).'/public/uploads/header';if(!is_dir($dir))mkdir($dir,0775,true);foreach(glob($dir.'/header-*.*')?:[] as $old)if(is_file($old))unlink($old);$name='header-'.substr(hash_file('sha256',$_FILES['header_image']['tmp_name']),0,12).'.'.$formats[$mime];$target=$dir.'/'.$name;if(!move_uploaded_file($_FILES['header_image']['tmp_name'],$target))throw new RuntimeException('Unable to store header image.');$values['header_image_url']='/uploads/header/'.$name;}$stmt=$db->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');foreach($values as $key=>$value)$stmt->execute([$key,$value]);$redirect('/admin/settings?saved=1');}
        $rows=$db->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('app_name','header_mode','header_title','header_subtitle','header_image_url','header_height_mode','header_height_px','primary_color','secondary_color','accent_color','footer_text','display_media_type','display_media_url','display_media_muted')")->fetchAll();$branding=array_column($rows,'value','key');$playlistFiles=[];foreach(glob(dirname(__DIR__).'/public/uploads/media/playlist/*.{mp4,webm,ogv}',GLOB_BRACE)?:[] as $file)$playlistFiles[]=basename($file);sort($playlistFiles);View::render('settings',compact('branding','playlistFiles'));exit;
    }
    if ($path === '/reports') {
        Auth::require(['super_admin','admin']);
        $rows=Database::connection()->query("SELECT s.name service_name,COUNT(*) total,SUM(t.status='completed') completed,ROUND(AVG(TIMESTAMPDIFF(MINUTE,t.called_at,t.completed_at)),1) avg_minutes FROM tickets t JOIN services s ON s.id=t.service_id WHERE t.queue_date=CURDATE() GROUP BY s.id ORDER BY s.name")->fetchAll();
        if (($_GET['format']??'')==='csv') { header('Content-Type:text/csv'); header('Content-Disposition:attachment; filename="laporan-antrean.csv"'); $o=fopen('php://output','w'); fputcsv($o,['Layanan','Total','Selesai','Rata-rata Menit']); foreach($rows as $r) fputcsv($o,$r); exit; }
        View::render('reports',compact('rows')); exit;
    }
    http_response_code(404); View::render('404');
} catch (Throwable $e) {
    error_log($e->__toString());
    if (str_starts_with($path,'/api/')) $json(['error'=>$e->getMessage()],422);
    http_response_code(500); View::render('error',['message'=>env('APP_DEBUG')==='true'?$e->getMessage():'Terjadi kesalahan. Silakan coba kembali.']);
}
