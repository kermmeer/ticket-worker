<?php

/*
| How Ticket Worker runs its Claude agents: CONCEPT.md §10.
|
| The folders sit next to the repository, in the environment's shared/ directory,
| never inside it: ticket data must not end up in a checkout, and a clone of a
| system must survive a rebuild of the containers.
*/

$shared = dirname(base_path()).'/shared';

return [

    'claude' => [
        // The Claude Code CLI. It lives in the agent container, not in php-fpm's.
        'bin' => env('CLAUDE_BIN', 'claude'),
        'model' => env('CLAUDE_MODEL', 'claude-opus-5'),
        'effort' => env('CLAUDE_EFFORT', 'high'),
        // How the CLI signs in, passed to its environment when a turn runs, never shown:
        // an API key (Commercial Terms, billed per use), or a subscription token from
        // `claude setup-token` (uses the subscription's limits; CONCEPT.md §13). The key
        // wins when both are set.
        'api_key' => env('ANTHROPIC_API_KEY'),
        'oauth_token' => env('CLAUDE_CODE_OAUTH_TOKEN'),
        // The CLI keeps its sessions here, so this must outlive the container.
        'config_dir' => env('CLAUDE_CONFIG_DIR', $shared.'/agent/claude'),
    ],

    'max_parallel' => (int) env('AGENT_MAX_PARALLEL', 2),

    'turn_budget_usd' => (float) env('AGENT_TURN_BUDGET_USD', 3),

    // One folder per ticket, where its agent works: ticket.md and attachments/.
    'workspaces_path' => env('AGENT_WORKSPACES_PATH', $shared.'/agent/tickets'),

    // One folder per system: a checkout you push into, since nothing here can reach
    // your Git server (CONCEPT.md §4).
    'systems_path' => env('SYSTEMS_PATH', $shared.'/systems'),

    // Where that folder is as seen from your own machine, to show the push command:
    // e.g. kermmeer@192.168.3.89:/data/apps/ticket-worker-dev/shared/systems
    'systems_push_base' => env('SYSTEMS_PUSH_BASE'),

    'sync_every_minutes' => (int) env('SYNC_EVERY_MINUTES', 10),

];
