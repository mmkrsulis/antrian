<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Small PDO compatibility layer. MariaDB receives SQL unchanged; SQLite gets
 * only the syntax adjustments required by the shared application queries.
 */
final class DatabaseConnection extends PDO
{
    private bool $sqlite;
    private bool $sqliteInTransaction = false;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        $this->sqlite = str_starts_with($dsn, 'sqlite:');
        parent::__construct($dsn, $username, $password, $options);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->sql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = $this->sql($query);
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->sql($statement));
    }

    public function beginTransaction(): bool
    {
        if (!$this->sqlite) return parent::beginTransaction();
        $ok = parent::exec('BEGIN IMMEDIATE') !== false;
        if ($ok) $this->sqliteInTransaction = true;
        return $ok;
    }

    public function commit(): bool
    {
        if (!$this->sqlite) return parent::commit();
        $ok = parent::exec('COMMIT') !== false;
        $this->sqliteInTransaction = false;
        return $ok;
    }

    public function rollBack(): bool
    {
        if (!$this->sqlite) return parent::rollBack();
        $ok = parent::exec('ROLLBACK') !== false;
        $this->sqliteInTransaction = false;
        return $ok;
    }

    public function inTransaction(): bool
    {
        if (!$this->sqlite) return parent::inTransaction();
        return $this->sqliteInTransaction;
    }

    public function isSqlite(): bool { return $this->sqlite; }

    private function sql(string $sql): string
    {
        if (!$this->sqlite) return $sql;
        $sql = preg_replace('/\s+FOR\s+UPDATE(?:\s+SKIP\s+LOCKED)?/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql) ?? $sql;
        $sql = preg_replace_callback(
            '/TIMESTAMPDIFF\(\s*MINUTE\s*,\s*([^,]+),\s*([^\)]+)\)/i',
            static fn(array $m): string => "((julianday({$m[2]}) - julianday({$m[1]})) * 1440.0)",
            $sql
        ) ?? $sql;
        $sql = preg_replace('/GROUP_CONCAT\(([^()\s]+)\s+ORDER\s+BY\s+[^)]*?\s+SEPARATOR\s+([\'\"][^\'\"]*[\'\"])\)/i', 'GROUP_CONCAT($1, $2)', $sql) ?? $sql;
        $sql = preg_replace('/GROUP_CONCAT\(([^()\s]+)\s+ORDER\s+BY\s+[^)]*\)/i', 'GROUP_CONCAT($1)', $sql) ?? $sql;
        if (preg_match('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+/i', $sql)) {
            [$insert, $update] = preg_split('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+/i', $sql, 2);
            $update = preg_replace('/\bVALUES\((`?[A-Za-z_][A-Za-z0-9_]*`?)\)/i', 'excluded.$1', $update) ?? $update;
            $sql = $insert . ' ON CONFLICT DO UPDATE SET ' . $update;
        }
        return $sql;
    }
}
