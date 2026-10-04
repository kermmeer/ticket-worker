<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * tools/push-systems.sh, run for real against throwaway repositories: a bare one
 * playing GitLab, your clone of it, and a checkout playing the server's folder.
 */
class PushSystemsScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/tw-push-'.uniqid();
        File::ensureDirectoryExists($this->root.'/clones');

        $this->git($this->root, 'init', '--quiet', '--bare', '--initial-branch=main', 'gitlab.git');
        $this->git($this->root, 'init', '--quiet', '--initial-branch=main', 'server');
        $this->git($this->root.'/server', 'config', 'receive.denyCurrentBranch', 'updateInstead');

        // Someone pushes to GitLab.
        $this->commitToGitlab('README.md', "billing\n", 'First version');

        // Your clone, with the one-time setup.
        $this->git($this->root.'/clones', 'clone', '--quiet', $this->root.'/gitlab.git', 'billing');
        $this->git($this->root.'/clones/billing', 'remote', 'add', 'ticket-worker', $this->root.'/server');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_one_run_brings_the_server_up_to_date(): void
    {
        $this->commitToGitlab('fix.txt', "fixed\n", 'Fix the rounding');

        $result = $this->script($this->root.'/clones');

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertStringContainsString('billing: main at', $result->output());
        $this->assertFileExists($this->root.'/server/fix.txt', 'The push updated the checked-out files.');
    }

    public function test_fetch_and_push_can_run_apart_for_when_the_vpn_is_in_the_way(): void
    {
        $this->commitToGitlab('later.txt', "later\n", 'A later change');

        $this->assertSame(0, $this->script('fetch', $this->root.'/clones')->exitCode());
        $this->assertFileDoesNotExist($this->root.'/server/later.txt');

        $this->assertSame(0, $this->script('push', $this->root.'/clones')->exitCode());
        $this->assertFileExists($this->root.'/server/later.txt');
    }

    public function test_the_server_follows_a_rewritten_branch(): void
    {
        $this->script($this->root.'/clones');

        // GitLab's main is rewritten: a new root commit replaces the history.
        $work = $this->root.'/rewrite';
        $this->git($this->root, 'init', '--quiet', '--initial-branch=main', 'rewrite');
        file_put_contents($work.'/NEW.md', "new history\n");
        $this->git($work, 'add', 'NEW.md');
        $this->git($work, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', 'Start over');
        $this->git($work, 'push', '--quiet', '--force', $this->root.'/gitlab.git', 'main');

        $result = $this->script($this->root.'/clones');

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertFileExists($this->root.'/server/NEW.md');
        $this->assertFileDoesNotExist($this->root.'/server/README.md');
    }

    public function test_the_remote_may_be_spelled_ticketworker_with_its_own_branch_setting(): void
    {
        $clone = $this->root.'/clones/billing';
        $this->git($clone, 'remote', 'rename', 'ticket-worker', 'ticketworker');
        $this->git($clone, 'config', 'ticketworker.branch', 'main');

        $result = $this->script($this->root.'/clones');

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertFileExists($this->root.'/server/README.md');
    }

    public function test_a_folder_without_set_up_clones_is_an_error_not_a_silent_success(): void
    {
        File::ensureDirectoryExists($this->root.'/empty');

        $result = $this->script($this->root.'/empty');

        $this->assertSame(1, $result->exitCode());
        $this->assertStringContainsString('No clone with a ticket-worker remote', $result->errorOutput());
    }

    private function script(string ...$arguments)
    {
        return Process::run(['bash', base_path('tools/push-systems.sh'), ...$arguments]);
    }

    private function commitToGitlab(string $file, string $content, string $message): void
    {
        $work = $this->root.'/author';
        if (! is_dir($work)) {
            $this->git($this->root, 'clone', '--quiet', $this->root.'/gitlab.git', 'author');
        }
        file_put_contents($work.'/'.$file, $content);
        $this->git($work, 'add', $file);
        $this->git($work, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', $message);
        $this->git($work, 'push', '--quiet', 'origin', 'HEAD:main');
    }

    private function git(string $path, string ...$arguments): void
    {
        Process::path($path)->run(['git', ...$arguments])->throw();
    }
}
