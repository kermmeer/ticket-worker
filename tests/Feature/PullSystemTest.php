<?php

namespace Tests\Feature;

use App\Jobs\PullSystem;
use App\Models\System;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as Git;
use Tests\TestCase;

class PullSystemTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/tw-pull-'.uniqid();
        config(['agent.systems_path' => $this->root.'/systems']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function git(string $dir, array $args): void
    {
        (new Git(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args], $dir))->mustRun();
    }

    public function test_a_pull_moves_the_folder_to_the_branch_at_the_host(): void
    {
        // The "host": a repository with a newer commit.
        $host = $this->root.'/host';
        File::ensureDirectoryExists($host);
        $this->git($host, ['init', '--quiet', '--initial-branch=main']);
        File::put($host.'/Invoice.php', "<?php return 300;\n");
        $this->git($host, ['add', '.']);
        $this->git($host, ['commit', '--quiet', '-m', 'first']);

        $system = System::create(['name' => 'billing', 'branch' => 'main', 'state' => System::READY]);
        File::ensureDirectoryExists($system->path());
        $this->git($system->path(), ['init', '--quiet', '--initial-branch=main']);
        $this->git($system->path(), ['config', 'receive.denyCurrentBranch', 'updateInstead']);
        $this->git($system->path(), ['fetch', '--quiet', $host, 'main:main', '--update-head-ok']);
        $this->git($system->path(), ['reset', '--quiet', '--hard', 'HEAD']);

        File::put($host.'/Invoice.php', "<?php return 150;\n");
        $this->git($host, ['commit', '--quiet', '-am', 'second']);

        $system->update(['pull_url' => $host, 'pull_auth' => 'none']);
        (new PullSystem($system))->handle();

        $this->assertSame('done', $system->fresh()->pull_state, (string) $system->fresh()->pull_error);
        $this->assertSame("<?php return 150;\n", File::get($system->path().'/Invoice.php'));
    }

    public function test_a_token_never_shows_in_an_error(): void
    {
        $system = System::create(['name' => 'billing', 'branch' => 'main', 'state' => System::READY]);
        File::ensureDirectoryExists($system->path());
        $this->git($system->path(), ['init', '--quiet', '--initial-branch=main']);
        $system->update(['pull_url' => $this->root.'/nowhere', 'pull_auth' => 'token', 'pull_username' => 'deploy', 'pull_secret' => 'glpat-s3cret']);

        (new PullSystem($system))->handle();

        $this->assertSame('failed', $system->fresh()->pull_state);
        $this->assertStringNotContainsString('glpat-s3cret', (string) $system->fresh()->pull_error);
    }

    public function test_editors_set_it_and_a_new_host_drops_the_token(): void
    {
        Queue::fake();
        $system = System::create(['name' => 'billing', 'branch' => 'main', 'state' => System::READY]);

        $this->put("/systems/{$system->id}/pull", ['url' => 'https://gitlab.example.com/g/billing.git', 'auth' => 'token', 'username' => 'deploy', 'secret' => 'glpat-s3cret'])->assertSessionHasNoErrors();
        $this->assertSame('glpat-s3cret', $system->fresh()->pull_secret);
        $this->put("/systems/{$system->id}/pull", ['url' => 'https://evil.example.com/g/billing.git', 'auth' => 'token', 'username' => 'deploy'])->assertSessionHasNoErrors();
        $this->assertNull($system->fresh()->pull_secret);

        $this->put("/systems/{$system->id}/pull", ['url' => 'https://user:pass@gitlab.example.com/x.git', 'auth' => 'none'])->assertSessionHasErrors('url');

        auth()->user()->update(['role' => 'member']);
        $this->post("/systems/{$system->id}/pull/now")->assertForbidden();
    }
}
