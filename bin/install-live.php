<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
use App\Core\Database;

$db=Database::connection();
foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) $db->exec(file_get_contents($file));
foreach (glob(dirname(__DIR__).'/database/seeds/*.sql') as $file) $db->exec(file_get_contents($file));
$password=getenv('LIVE_ADMIN_PASSWORD') ?: throw new RuntimeException('LIVE_ADMIN_PASSWORD is required.');
$stmt=$db->prepare("INSERT INTO users(name,username,password_hash,role) VALUES ('Live Test Administrator','admin',?,'super_admin') ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)");
$stmt->execute([password_hash($password,PASSWORD_DEFAULT)]);
file_put_contents(dirname(__DIR__).'/storage/install.lock',date(DATE_ATOM));
echo "Live-test installation ready.\n";
