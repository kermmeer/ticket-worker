<?php

namespace Tests\Feature;

use App\Jobs\ReportHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ReportHealthTest extends TestCase
{
    public function test_the_scheduler_asks_both_queues_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => $event->description === ReportHealth::class);

        $this->assertCount(2, $events);
        $this->assertSame(['* * * * *'], $events->pluck('expression')->unique()->values()->all());
    }

    public function test_a_report_lands_on_the_queue_it_reports_for(): void
    {
        $this->assertSame('agents', (new ReportHealth('agents'))->queue);
        $this->assertSame('default', (new ReportHealth('default'))->queue);
    }

    public function test_the_agent_queue_reports_its_claude_and_its_folders(): void
    {
        $workspaces = sys_get_temp_dir().'/tw-health-'.uniqid();
        File::ensureDirectoryExists($workspaces);
        config(['agent.workspaces_path' => $workspaces, 'agent.systems_path' => $workspaces]);
        Process::fake(['*' => Process::result('2.1.282 (Claude Code)')]);

        (new ReportHealth('agents'))->handle();

        $report = Cache::get('health.agents');
        $this->assertSame('2.1.282 (Claude Code)', $report['claude']);
        $this->assertTrue($report['workspaces_writable']);
        $this->assertTrue($report['systems_readable']);

        File::deleteDirectory($workspaces);
    }

    public function test_a_missing_claude_is_reported_as_missing(): void
    {
        Process::fake(['*' => Process::result(exitCode: 127)]);

        (new ReportHealth('agents'))->handle();

        $this->assertNull(Cache::get('health.agents')['claude']);
    }
}
