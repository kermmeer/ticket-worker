<?php

namespace Tests\Feature;

use App\Agent\AutoScanSystems;
use App\Jobs\ScanSystem;
use App\Models\System;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as Git;
use Tests\TestCase;

class AutoScanTest extends TestCase
{
    private System $system;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['agent.systems_path' => sys_get_temp_dir().'/tw-autoscan-'.uniqid(), 'agent.claude.api_key' => 'sk-ant-test']);
        $this->system = System::create(['name' => 'boss', 'branch' => 'master', 'state' => System::READY, 'auto_scan' => true]);
        File::ensureDirectoryExists($this->system->path());
        $git = fn (array $args) => (new Git(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args], $this->system->path()))->mustRun()->getOutput();
        $git(['init', '--quiet', '--initial-branch=master']);
        File::put($this->system->path().'/a.php', "<?php\n");
        $git(['add', '.']);
        $git(['commit', '--quiet', '-m', 'one']);
        $first = trim($git(['rev-parse', 'HEAD']));
        File::put($this->system->path().'/b.php', "<?php\n");
        $git(['add', '.']);
        $git(['commit', '--quiet', '-m', 'two']);
        // A context written at the first commit, two hours ago: one commit behind now.
        $this->system->update(['context' => '# boss', 'context_commit' => $first, 'context_written_at' => now()->subHours(2)]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->system->path());
        parent::tearDown();
    }

    private function pushedMinutesAgo(int $minutes): void
    {
        touch($this->system->path().'/.git/logs/HEAD', now()->subMinutes($minutes)->getTimestamp());
    }

    public function test_a_push_past_the_context_rescans_once_it_has_settled(): void
    {
        $this->pushedMinutesAgo(2);
        (new AutoScanSystems)();
        Queue::assertNothingPushed();

        $this->pushedMinutesAgo(15);
        (new AutoScanSystems)();
        Queue::assertPushed(ScanSystem::class, 1);
        $this->assertSame('queued', $this->system->fresh()->scan_state);

        // Queued already: not twice.
        (new AutoScanSystems)();
        Queue::assertPushed(ScanSystem::class, 1);
    }

    public function test_not_more_than_once_an_hour_not_when_off_and_not_without_the_shared_sign_in(): void
    {
        $this->pushedMinutesAgo(15);
        $this->system->update(['context_written_at' => now()->subMinutes(20)]);
        (new AutoScanSystems)();
        Queue::assertNothingPushed();

        $this->system->update(['context_written_at' => now()->subHours(2), 'auto_scan' => false]);
        (new AutoScanSystems)();
        Queue::assertNothingPushed();

        $this->system->update(['auto_scan' => true]);
        config(['agent.claude.api_key' => null, 'agent.claude.oauth_token' => null]);
        (new AutoScanSystems)();
        Queue::assertNothingPushed();
    }
}
