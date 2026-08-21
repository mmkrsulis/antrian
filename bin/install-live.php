<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
use App\Core\Database;

$db=Database::connection();
foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) $db->exec(file_get_contents($file));
foreach (glob(dirname(__DIR__).'/database/seeds/*.sql') as $file) $db->exec(file_get_contents($file));
$password=(string)(getenv('LIVE_ADMIN_PASSWORD')?:'');
if($password!==''){
    $adminName=trim((string)(getenv('ADMIN_NAME')?:'Administrator'));
    $adminUsername=trim((string)(getenv('ADMIN_USERNAME')?:'admin'));
    if(!preg_match('/^[a-zA-Z0-9._-]{3,50}$/',$adminUsername))throw new RuntimeException('ADMIN_USERNAME is invalid.');
    $stmt=$db->prepare("INSERT INTO users(name,username,password_hash,role) VALUES (?,?,?,'super_admin') ON DUPLICATE KEY UPDATE name=VALUES(name),password_hash=VALUES(password_hash),role='super_admin',active=1");
    $stmt->execute([$adminName,$adminUsername,password_hash($password,PASSWORD_DEFAULT)]);
}elseif(!(int)$db->query("SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1")->fetchColumn()){
    throw new RuntimeException('LIVE_ADMIN_PASSWORD is required for the first installation.');
}
file_put_contents(dirname(__DIR__).'/storage/install.lock',date(DATE_ATOM));
echo "Live-test installation ready.\n";
