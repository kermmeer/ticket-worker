<?php

namespace App\Agent;

use InvalidArgumentException;

/**
 * What the agent may ask a database: one statement that only reads. The database account
 * should only be able to read anyway; this refuses the rest before it is sent, and the
 * query runs in a read-only transaction that is rolled back.
 */
class ReadOnlySql
{
    private const STARTS = ['select', 'with', 'show', 'describe', 'desc', 'explain'];

    /** Words that write, lock, run code or reach outside, wherever they appear. */
    private const REFUSED = [
        'insert', 'update', 'delete', 'replace', 'merge', 'upsert', 'drop', 'alter', 'create', 'truncate', 'rename',
        'grant', 'revoke', 'lock', 'unlock', 'call', 'do', 'handler', 'load', 'outfile', 'dumpfile', 'into', 'set',
        'copy', 'vacuum', 'analyze', 'cluster', 'reindex', 'comment', 'execute', 'prepare', 'deallocate', 'begin',
        'commit', 'rollback', 'savepoint', 'release', 'kill', 'shutdown', 'install', 'uninstall', 'sleep', 'benchmark',
        'pg_sleep', 'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir', 'lo_import', 'lo_export', 'dblink', 'load_file',
        'for update', 'for share',
    ];

    public static function check(string $sql): string
    {
        $sql = trim($sql);
        $sql = rtrim($sql, "; \t\n\r");
        if ($sql === '') {
            throw new InvalidArgumentException('Give a query.');
        }
        if (str_contains($sql, ';')) {
            throw new InvalidArgumentException('One statement at a time, without ";".');
        }
        if (preg_match('~(--|#|/\*)~', $sql)) {
            throw new InvalidArgumentException('No comments in the query.');
        }

        $first = strtolower(strtok(ltrim($sql, '('), " \t\n\r"));
        if (! in_array($first, self::STARTS, true)) {
            throw new InvalidArgumentException('Only SELECT, WITH, SHOW, DESCRIBE or EXPLAIN: this database is read-only for you.');
        }

        // Outside quoted strings only, so a WHERE note = 'update' is no problem.
        $bare = strtolower(preg_replace("~'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\"~s", "''", $sql));
        foreach (self::REFUSED as $word) {
            if (preg_match('~\b'.str_replace(' ', '\s+', preg_quote($word, '~')).'\b~', $bare)) {
                throw new InvalidArgumentException("\"{$word}\" is not allowed: only reading.");
            }
        }
        if ($first === 'explain' && preg_match('~\banalyze\b~', $bare)) {
            throw new InvalidArgumentException('EXPLAIN without ANALYZE: that runs the query.');
        }

        return $sql;
    }
}
