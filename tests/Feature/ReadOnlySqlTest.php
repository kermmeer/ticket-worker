<?php

namespace Tests\Feature;

use App\Agent\ApiToolServer;
use App\Agent\ReadOnlySql;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ReadOnlySqlTest extends TestCase
{
    public function test_reading_passes(): void
    {
        foreach ([
            'SELECT id, recurring_price FROM service WHERE id = 337800',
            "select * from invoice_line where note = 'update the price' limit 5;",
            'WITH t AS (SELECT 1) SELECT * FROM t',
            'SHOW TABLES',
            'DESCRIBE invoice',
            'EXPLAIN SELECT * FROM invoice',
        ] as $sql) {
            $this->assertNotSame('', ReadOnlySql::check($sql), $sql);
        }
    }

    public function test_anything_else_is_refused(): void
    {
        foreach ([
            'UPDATE service SET recurring_price = 0',
            'DELETE FROM invoice',
            'SELECT 1; DROP TABLE invoice',
            'SELECT * INTO OUTFILE "/tmp/x" FROM invoice',
            'SELECT * FROM invoice FOR UPDATE',
            'SELECT sleep(100)',
            'SELECT pg_read_file(\'/etc/passwd\')',
            'SELECT 1 -- comment',
            'WITH d AS (DELETE FROM invoice RETURNING *) SELECT * FROM d',
            'EXPLAIN ANALYZE SELECT * FROM invoice',
            'SET GLOBAL read_only = 0',
            '',
        ] as $sql) {
            try {
                ReadOnlySql::check($sql);
                $this->fail("Not refused: {$sql}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_tool_offers_the_databases_and_refuses_writing_with_a_note_in_the_log(): void
    {
        $system = System::create(['name' => 'boss', 'branch' => 'master', 'state' => System::READY]);
        $system->databases()->create(['name' => 'crm', 'driver' => 'mysql', 'host' => 'db.example.com', 'database' => 'crm', 'username' => 'reader', 'password' => 'pa55word-secret']);
        $this->assertStringNotContainsString('pa55word-secret', (string) DB::table('db_connections')->value('password'));
        $ticket = Ticket::create(['space_id' => SpacesTest::space()->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->assertContains('mcp__ticket-worker__run_query', ApiToolServer::toolNamesFor([$system]));
        $server = ApiToolServer::forQuickScan($ticket, collect([$system]));
        $tools = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'];
        $this->assertSame(['list_databases', 'run_query', 'list_apis', 'call_api'], array_column($tools, 'name'));
        $this->assertStringNotContainsString('pa55word', json_encode($tools));

        $refused = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'run_query', 'arguments' => ['database' => 'boss/crm', 'sql' => 'DELETE FROM service']]]);
        $this->assertTrue($refused['result']['isError']);
        $this->assertSame(['sql boss/crm: refused: DELETE FROM service'], $ticket->fresh()->quick_scan_steps);
    }
}
