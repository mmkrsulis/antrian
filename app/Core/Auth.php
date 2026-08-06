<?php
declare(strict_types=1);
namespace App\Core;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) self::restoreRememberedUser();
        if (empty($_SESSION['user_id'])) return null;
        $stmt = Database::connection()->prepare('SELECT id, name, username, role, assigned_counter_id FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user=$stmt->fetch() ?: null;
        if(!$user) self::forgetDevice();
        return $user;
    }

    public static function attempt(string $username, string $password, bool $remember=false): bool
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        if($remember) self::rememberDevice((int)$user['id']);
        return true;
    }

    public static function logout(): void
    {
        self::forgetDevice();
        $_SESSION=[];
        if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-3600,$params['path'],$params['domain'],$params['secure'],$params['httponly']);}
        session_destroy();
    }

    private static function rememberDevice(int $userId): void
    {
        $selector=bin2hex(random_bytes(12));$validator=bin2hex(random_bytes(32));$expires=time()+30*86400;
        $stmt=Database::connection()->prepare('INSERT INTO remember_tokens(user_id,selector,validator_hash,expires_at,user_agent) VALUES (?,?,?,?,?)');
        $stmt->execute([$userId,$selector,hash('sha256',$validator),date('Y-m-d H:i:s',$expires),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)]);
        self::setRememberCookie($selector.':'.$validator,$expires);
    }

    private static function restoreRememberedUser(): void
    {
        $cookie=(string)($_COOKIE['queue_remember']??'');if(!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/',$cookie,$parts))return;
        $stmt=Database::connection()->prepare('SELECT rt.id,rt.user_id,rt.validator_hash,u.active FROM remember_tokens rt JOIN users u ON u.id=rt.user_id WHERE rt.selector=? AND rt.expires_at>NOW() LIMIT 1');$stmt->execute([$parts[1]]);$token=$stmt->fetch();
        if(!$token||!(bool)$token['active']||!hash_equals($token['validator_hash'],hash('sha256',$parts[2]))){self::forgetDevice();return;}
        session_regenerate_id(true);$_SESSION['user_id']=(int)$token['user_id'];
        $validator=bin2hex(random_bytes(32));$expires=time()+30*86400;Database::connection()->prepare('UPDATE remember_tokens SET validator_hash=?,expires_at=? WHERE id=?')->execute([hash('sha256',$validator),date('Y-m-d H:i:s',$expires),$token['id']]);self::setRememberCookie($parts[1].':'.$validator,$expires);
    }

    private static function forgetDevice(): void
    {
        $cookie=(string)($_COOKIE['queue_remember']??'');if(preg_match('/^([a-f0-9]{24}):/',$cookie,$parts))Database::connection()->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$parts[1]]);
        self::setRememberCookie('',time()-3600);unset($_COOKIE['queue_remember']);
    }

    private static function setRememberCookie(string $value,int $expires): void
    {
        setcookie('queue_remember',$value,['expires'=>$expires,'path'=>'/','secure'=>env('SESSION_SECURE')==='true','httponly'=>true,'samesite'=>'Lax']);
    }

    public static function require(array $roles = []): array
    {
        $user = self::user();
        if (!$user) { header('Location: /login'); exit; }
        if ($roles && !in_array($user['role'], $roles, true)) { http_response_code(403); exit('Akses ditolak'); }
        return $user;
    }
}
