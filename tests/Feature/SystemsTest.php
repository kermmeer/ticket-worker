<?php

namespace Tests\Feature;

use App\Jobs\PrepareSystemFolder;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/tw-systems-'.uniqid();
        File::ensureDirectoryExists($this->root);
        config(['agent.systems_path' => $this->root, 'agent.systems_push_base' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_adding_a_system_leaves_the_folder_to_the_worker(): void
    {
        Queue::fake();

        $this->post('/systems', ['name' => 'billing', 'branch' => 'main'])->assertRedirect('/systems');

        $system = System::where('name', 'billing')->firstOrFail();
        $this->assertSame(System::PREPARING, $system->state);
        Queue::assertPushed(PrepareSystemFolder::class, fn ($job) => $job->system->is($system));
    }

    public function test_a_name_has_to_be_a_safe_folder_name(): void
    {
        Queue::fake();
        System::create(['name' => 'portal', 'branch' => 'main']);

        foreach (['../escape', 'Billing', 'with space', '-dash', 'portal'] as $name) {
            $this->post('/systems', ['name' => $name, 'branch' => 'main'])->assertSessionHasErrors('name');
        }
        foreach (['../main', 'main.lock', 'a..b', '-x'] as $branch) {
            $this->post('/systems', ['name' => 'billing', 'branch' => $branch])->assertSessionHasErrors('branch');
        }

        $this->assertSame(1, System::count());
    }

    public function test_the_prepared_folder_takes_a_push_and_shows_it(): void
    {
        $system = System::create(['name' => 'billing', 'branch' => 'main']);

        (new PrepareSystemFolder($system))->handle();

        $this->assertSame(System::READY, $system->fresh()->state);
        $this->assertNull($system->head(), 'Nothing has been pushed yet.');

        // What you would do from your own machine.
        $source = $this->root.'/source';
        File::ensureDirectoryExists($source);
        file_put_contents($source.'/README.md', "billing\n");
        foreach ([
            ['git', 'init', '--quiet', '--initial-branch=main'],
            ['git', 'add', 'README.md'],
            ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', 'First version'],
            ['git', 'push', '--quiet', $system->path(), 'main'],
        ] as $command) {
            Process::path($source)->run($command)->throw();
        }

        $this->assertFileExists($system->path().'/README.md', 'The push updated the checked-out files.');
        $this->assertSame('First version', $system->head()['subject']);
    }

    public function test_a_folder_that_cannot_be_made_fails_with_a_reason(): void
    {
        config(['agent.systems_path' => '/proc/no-such-place']);
        $system = System::create(['name' => 'billing', 'branch' => 'main']);

        (new PrepareSystemFolder($system))->handle();

        $this->assertSame(System::FAILED, $system->fresh()->state);
        $this->assertNotEmpty($system->fresh()->error);
    }

    public function test_the_page_shows_where_to_push(): void
    {
        config(['agent.systems_push_base' => 'kermmeer@minas:/srv/systems/']);
        System::create(['name' => 'billing', 'branch' => 'release', 'state' => System::READY]);

        $this->get('/systems')->assertInertia(fn (Assert $page) => $page
            ->component('Systems', true)
            ->where('systems.0.remote', 'kermmeer@minas:/srv/systems/billing')
            ->where('systems.0.branch', 'release')
            ->where('systems.0.head', null));
    }
}
