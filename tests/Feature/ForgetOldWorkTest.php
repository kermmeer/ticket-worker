<?php

namespace Tests\Feature;

use App\Agent\ForgetOldWork;
use App\Models\AgentSession;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ForgetOldWorkTest extends TestCase
{
    public function test_a_workspace_and_its_transcripts_go_30_days_after_the_work_ended(): void
    {
        $root = sys_get_temp_dir().'/tw-forget-'.uniqid();
        config(['agent.workspaces_path' => $root.'/tickets', 'agent.claude.config_dir' => $root.'/claude']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $make = function (string $key, ?string $state, ?\DateTimeInterface $closed) use ($space, $root) {
            $ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => $key, 'key' => $key, 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
            if ($state !== null) {
                AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => (string) \Illuminate\Support\Str::uuid(), 'model' => 'm', 'state' => $state, 'closed_at' => $closed]);
            }
            $dir = $root.'/tickets/'.strtolower($key);
            File::ensureDirectoryExists($dir.'/attachments');
            File::ensureDirectoryExists($root.'/claude/projects/'.preg_replace('/[^A-Za-z0-9]/', '-', $dir));

            return $dir;
        };

        $old = $make('SUP-1', 'closed', now()->subDays(31));
        $recent = $make('SUP-2', 'closed', now()->subDays(5));
        $open = $make('SUP-3', 'open', null);

        $gone = (new ForgetOldWork)();

        $this->assertSame(['sup-1'], $gone);
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryDoesNotExist($root.'/claude/projects/'.preg_replace('/[^A-Za-z0-9]/', '-', $old));
        $this->assertDirectoryExists($recent);
        $this->assertDirectoryExists($open);
        File::deleteDirectory($root);
    }
}
