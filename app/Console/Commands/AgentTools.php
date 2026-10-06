<?php

namespace App\Console\Commands;

use App\Agent\ApiToolServer;
use App\Agent\Workspace;
use App\Models\AgentTurn;
use Illuminate\Console\Command;

/**
 * Started by Claude Code, not by you: the MCP server for one turn's API calls.
 * Reads JSON-RPC from stdin and answers on stdout until Claude Code closes it.
 */
class AgentTools extends Command
{
    protected $signature = 'agent:tools {turn}';

    protected $description = 'The API tools of an agent turn, as an MCP server on stdio (started by Claude Code)';

    protected $hidden = true;

    public function handle(Workspace $workspace): int
    {
        // stdout is the protocol: a PHP notice printed there would break it.
        ini_set('display_errors', 'stderr');

        $turn = AgentTurn::with('session.ticket.space')->findOrFail($this->argument('turn'));
        $server = ApiToolServer::forTurn($turn, $workspace->systems($turn->session->ticket));

        while (($line = fgets(STDIN)) !== false) {
            $message = json_decode(trim($line), true);
            if (! is_array($message)) {
                continue;
            }
            $answer = $server->handle($message);
            if ($answer !== null) {
                fwrite(STDOUT, json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                fflush(STDOUT);
            }
        }

        return self::SUCCESS;
    }
}
