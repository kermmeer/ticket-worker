<?php

namespace Tests\Feature;

use App\Agent\Checks;
use App\Models\System;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process as Git;
use Tests\TestCase;

class SystemEditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/tw-sysedit-'.uniqid();
        config(['agent.systems_path' => $this->root.'/systems', 'agent.workspaces_path' => $this->root.'/agent/tickets', 'agent.systems_push_base' => 'me@10.0.0.5:/srv/tw/data/systems']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** As a push leaves it: the folder set up for the system's branch, a commit arrived on $branch. */
    private function repo(System $system, string $branch): void
    {
        File::ensureDirectoryExists($system->path());
        $git = fn (array $args) => (new Git(['git', ...$args], $system->path()))->mustRun();
        $git(['init', '--quiet', "--initial-branch={$system->branch}"]);
        $git(['checkout', '--quiet', '-b', $branch]);
        File::put($system->path().'/run.py', "print('hi')\n");
        $git(['add', '.']);
        $git(['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'start']);
        if ($branch !== $system->branch) {
            $git(['symbolic-ref', 'HEAD', "refs/heads/{$system->branch}"]);
            File::delete($system->path().'/run.py');
            $git(['read-tree', '--empty']);
        }
    }

    public function test_a_push_of_another_branch_than_the_one_followed_is_named_and_can_be_followed(): void
    {
        $system = System::create(['name' => 'telepo-boss', 'branch' => 'master', 'state' => System::READY]);
        $this->repo($system, 'main');

        $this->get('/systems')->assertInertia(fn (Assert $page) => $page->where('systems.0.head', null)->where('systems.0.branches_here', ['main']));

        $this->put("/systems/{$system->id}", ['name' => 'telepo-boss', 'branch' => 'main'])->assertSessionHasNoErrors();
        $this->get('/systems')->assertInertia(fn (Assert $page) => $page->where('systems.0.head.subject', 'start'));
        $this->assertFileExists($system->path().'/run.py', 'Its files are checked out, for the agents to read.');
    }

    public function test_renaming_moves_the_folder_its_checks_and_the_redirects_to_it(): void
    {
        $system = System::create(['name' => 'telepo', 'branch' => 'main', 'state' => System::READY]);
        $this->repo($system, 'main');
        File::ensureDirectoryExists(Checks::root().'/checks/telepo');
        $boss = System::create(['name' => 'boss', 'branch' => 'master', 'state' => System::READY, 'checks_redirects' => "git@internal:x/telepo.git = telepo\n"]);

        $this->put("/systems/{$system->id}", ['name' => 'telepo-boss', 'branch' => 'main'])
            ->assertSessionHas('success', 'Renamed. In your clone: git remote set-url ticket-worker me@10.0.0.5:/srv/tw/data/systems/telepo-boss');

        $this->assertDirectoryExists($this->root.'/systems/telepo-boss/.git');
        $this->assertDirectoryDoesNotExist($this->root.'/systems/telepo');
        $this->assertDirectoryExists(Checks::root().'/checks/telepo-boss');
        $this->assertSame('git@internal:x/telepo.git = telepo-boss', trim($boss->fresh()->checks_redirects));

        $this->put("/systems/{$boss->id}", ['name' => 'telepo-boss', 'branch' => 'master'])->assertSessionHasErrors('name');
    }
}
