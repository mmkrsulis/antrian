<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use Throwable;
use ZipArchive;

final class BackupService
{
    private const FORMAT = 'reka-queue-backup';
    private const VERSION = 1;
    private const MAX_ARCHIVE_BYTES = 500 * 1024 * 1024;
    private const MAX_FILES = 20000;
    private const EPHEMERAL_TABLES = ['remember_tokens','notification_devices','api_rate_limits','chat_presence'];

    public static function export(): string
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Ekstensi ZIP tidak tersedia.');
        $db=Database::connection();
        $tables=self::tables();
        $data=[];
        foreach($tables as $table)$data[$table]=$db->query('SELECT * FROM '.self::identifier($table))->fetchAll();
        $payload=[
            'format'=>self::FORMAT,
            'format_version'=>self::VERSION,
            'created_at'=>date(DATE_ATOM),
            'application'=>['version'=>app_version(),'commit'=>app_commit()],
            'tables'=>$data,
        ];
        $temporary=tempnam(sys_get_temp_dir(),'reka-backup-');
        if($temporary===false)throw new RuntimeException('Tidak dapat membuat file backup sementara.');
        $zip=new ZipArchive();
        if($zip->open($temporary,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){@unlink($temporary);throw new RuntimeException('Tidak dapat membuat arsip backup.');}
        try{
            $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $zip->addFromString('backup.json',$json);
            self::addDirectory($zip,dirname(__DIR__,2).'/public/uploads','uploads');
        }finally{$zip->close();}
        return $temporary;
    }

    public static function restore(string $archive): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Ekstensi ZIP tidak tersedia.');
        if(!is_file($archive)||filesize($archive)>self::MAX_ARCHIVE_BYTES)throw new RuntimeException('File backup tidak valid atau melebihi 500 MB.');
        $zip=new ZipArchive();
        if($zip->open($archive)!==true)throw new RuntimeException('Arsip ZIP tidak dapat dibuka.');
        $staging=sys_get_temp_dir().'/reka-restore-'.bin2hex(random_bytes(8));
        if(!mkdir($staging,0700,true)){$zip->close();throw new RuntimeException('Tidak dapat menyiapkan restore.');}
        try{
            if($zip->numFiles>self::MAX_FILES)throw new RuntimeException('Arsip berisi terlalu banyak file.');
            $json=$zip->getFromName('backup.json');
            if($json===false)throw new RuntimeException('backup.json tidak ditemukan.');
            $payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            if(($payload['format']??null)!==self::FORMAT||(int)($payload['format_version']??0)!==self::VERSION||!is_array($payload['tables']??null))throw new RuntimeException('Format backup tidak didukung.');
            $available=array_flip(self::tables());
            foreach($payload['tables'] as $table=>$rows){
                if(!isset($available[$table])||!is_array($rows))throw new RuntimeException('Backup memuat tabel yang tidak dikenal: '.$table);
            }
            self::stageUploads($zip,$staging);
            self::restoreTables($payload['tables']);
            self::installUploads($staging);
            return ['created_at'=>(string)($payload['created_at']??''),'tables'=>count($payload['tables'])];
        }finally{
            $zip->close();
            self::removeDirectory($staging);
        }
    }

    private static function tables(): array
    {
        $db=Database::connection();
        $rows=$db->isSqlite()
            ?$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll()
            :$db->query('SHOW TABLES')->fetchAll();
        $tables=array_values(array_map(static fn(array $row): string => (string)array_values($row)[0],$rows));
        return array_values(array_diff($tables,self::EPHEMERAL_TABLES));
    }

    private static function restoreTables(array $tables): void
    {
        $db=Database::connection();
        $sqlite=$db->isSqlite();
        $sqlite?$db->exec('PRAGMA foreign_keys=OFF'):$db->exec('SET FOREIGN_KEY_CHECKS=0');
        $db->beginTransaction();
        try{
            foreach(self::EPHEMERAL_TABLES as $table){try{$db->exec('DELETE FROM '.self::identifier($table));}catch(Throwable){} }
            foreach(array_reverse(array_keys($tables)) as $table)$db->exec('DELETE FROM '.self::identifier($table));
            foreach($tables as $table=>$rows){
                foreach($rows as $row){
                    if(!is_array($row)||$row===[])continue;
                    $columns=array_keys($row);
                    foreach($columns as $column)self::identifier((string)$column);
                    $sql='INSERT INTO '.self::identifier($table).' ('.implode(',',array_map([self::class,'identifier'],$columns)).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
                    $db->prepare($sql)->execute(array_values($row));
                }
            }
            $db->commit();
        }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
        finally{$sqlite?$db->exec('PRAGMA foreign_keys=ON'):$db->exec('SET FOREIGN_KEY_CHECKS=1');}
    }

    private static function addDirectory(ZipArchive $zip,string $directory,string $prefix): void
    {
        if(!is_dir($directory))return;
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){
            if(!$file->isFile()||$file->isLink())continue;
            $relative=str_replace('\\','/',substr($file->getPathname(),strlen($directory)+1));
            $archiveName=$prefix.'/'.$relative;
            $zip->addFile($file->getPathname(),$archiveName);
            if(in_array(strtolower($file->getExtension()),['mp4','webm','ogv','mp3','wav','jpg','jpeg','png','webp','zip'],true))$zip->setCompressionName($archiveName,ZipArchive::CM_STORE);
        }
    }

    private static function stageUploads(ZipArchive $zip,string $staging): void
    {
        $total=0;
        for($index=0;$index<$zip->numFiles;$index++){
            $stat=$zip->statIndex($index);$name=(string)($stat['name']??'');
            if(!str_starts_with($name,'uploads/')||str_ends_with($name,'/'))continue;
            $relative=substr($name,8);
            if($relative===''||str_contains($relative,"\0")||str_contains($relative,'\\')||preg_match('#(^|/)\.\.(/|$)#',$relative)||str_starts_with($relative,'/'))throw new RuntimeException('Path file backup tidak aman.');
            $size=(int)($stat['size']??0);$total+=$size;
            if($total>self::MAX_ARCHIVE_BYTES)throw new RuntimeException('Isi upload backup melebihi 500 MB.');
            $target=$staging.'/'.$relative;$parent=dirname($target);
            if(!is_dir($parent)&&!mkdir($parent,0700,true))throw new RuntimeException('Tidak dapat menyiapkan folder restore.');
            $stream=$zip->getStream($name);if($stream===false)throw new RuntimeException('Tidak dapat membaca file backup.');
            $output=fopen($target,'wb');if($output===false){fclose($stream);throw new RuntimeException('Tidak dapat menulis file restore.');}
            stream_copy_to_stream($stream,$output);fclose($stream);fclose($output);
        }
    }

    private static function installUploads(string $staging): void
    {
        $destination=dirname(__DIR__,2).'/public/uploads';
        if(!is_dir($destination)&&!mkdir($destination,0775,true))throw new RuntimeException('Folder uploads tidak dapat dibuat.');
        if(!is_dir($staging))return;
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staging,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach($iterator as $file){
            $relative=substr($file->getPathname(),strlen($staging)+1);$target=$destination.'/'.$relative;
            if($file->isDir()){if(!is_dir($target))mkdir($target,0775,true);}
            else{if(!is_dir(dirname($target)))mkdir(dirname($target),0775,true);if(!copy($file->getPathname(),$target))throw new RuntimeException('Gagal memulihkan upload: '.$relative);}
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if(!is_dir($directory))return;
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($directory);
    }

    private static function identifier(string $name): string
    {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$name))throw new RuntimeException('Identifier database tidak valid.');
        return '`'.$name.'`';
    }
}
