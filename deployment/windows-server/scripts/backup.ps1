param([string]$InstallDir='C:\RekaQueue')
$ErrorActionPreference='Stop'
$dataDir=Join-Path $env:ProgramData 'Reka Queue'
$database=Join-Path $dataDir 'database\reka-queue.sqlite'
if(!(Test-Path $database)){exit 1}
$backupDir=Join-Path $dataDir 'backups';New-Item -ItemType Directory -Force $backupDir|Out-Null
$stamp=Get-Date -Format 'yyyyMMdd-HHmmss';$target=Join-Path $backupDir "reka-queue-$stamp.sqlite"
$php=Join-Path $InstallDir 'runtime\php\php.exe'
$script=Join-Path $InstallDir 'tools\sqlite-backup.php'
if(!(Test-Path $script)){
    $code='<?php $source=new SQLite3($argv[1],SQLITE3_OPEN_READONLY);$target=new SQLite3($argv[2]);if(!$source->backup($target)){fwrite(STDERR,"Backup failed\n");exit(1);}$target->close();$source->close();'
    [IO.File]::WriteAllText($script,$code,[Text.UTF8Encoding]::new($false))
}
& $php $script $database $target
if($LASTEXITCODE -ne 0){throw 'SQLite backup failed.'}
Get-ChildItem $backupDir -Filter '*.sqlite'|Sort-Object LastWriteTime -Descending|Select-Object -Skip 30|Remove-Item -Force
