<?php

namespace App\Http\Controllers;

use App\Jira\JiraClient;
use App\Jira\JiraException;
use App\Jobs\SyncSpace;
use App\Models\Space;
use App\Models\System;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting up a space (CONCEPT.md §4): pick it from Jira, say which of its tickets
 * count, pick its systems, and activate it.
 */
class SpaceController extends Controller
{
    private const NO_ORDER = 'not_regex:/\border\s+by\b/i';

    public function index(JiraClient $jira): Response
    {
        $spaces = Space::query()
            ->withCount(['tickets as open_count' => fn ($query) => $query->open()])
            ->orderBy('label')
            ->get();

        return Inertia::render('Spaces/Index', [
            'jiraConfigured' => $jira->configured(),
            'spaces' => $spaces->map(fn (Space $space) => $this->summary($space) + ['open_count' => $space->open_count]),
        ]);
    }

    public function create(JiraClient $jira): Response
    {
        $known = Space::query()->pluck('project_key')->all();

        try {
            $projects = collect($jira->projects())
                ->reject(fn (array $project) => in_array($project['key'], $known, true))
                ->map(fn (array $project) => $project + ['service' => Space::typeFromJira($project['type']) === Space::SERVICE])
                ->values()
                ->all();
            $error = null;
        } catch (JiraException $e) {
            $projects = [];
            $error = $e->getMessage();
        }

        return Inertia::render('Spaces/Create', ['projects' => $projects, 'error' => $error]);
    }

    public function store(Request $request, JiraClient $jira): RedirectResponse
    {
        $data = $request->validate([
            'project_key' => ['required', 'string', 'max:50', Rule::unique('spaces', 'project_key')],
        ]);

        try {
            $project = $jira->project($data['project_key']);
        } catch (JiraException $e) {
            return back()->withErrors(['project_key' => $e->getMessage()]);
        }

        $space = Space::create([
            'project_key' => $project['key'],
            'project_id' => $project['id'],
            'name' => $project['name'],
            'label' => Str::limit($project['key'], 24, ''),
            'colour' => Space::COLOURS[Space::query()->count() % count(Space::COLOURS)],
            'jira_type' => $project['type'],
            'type' => Space::typeFromJira($project['type']),
            'done_rule' => Space::DEFAULT_DONE_RULE,
        ]);

        return to_route('spaces.edit', $space);
    }

    public function edit(Space $space, JiraClient $jira): Response
    {
        return Inertia::render('Spaces/Edit', [
            'space' => $this->summary($space) + [
                'rules' => $space->rules,
                'done_rule' => $space->done_rule,
                'system_ids' => $space->systems()->pluck('systems.id'),
            ],
            'systems' => System::query()->orderBy('name')->get(['id', 'name']),
            'colours' => Space::COLOURS,
            // Both come from Jira; the page still works when it does not answer.
            'vocabulary' => $this->attempt(fn () => $jira->vocabulary($space->project_key)),
            'permissions' => $this->attempt(fn () => $jira->permissions($space->project_key, $this->permissionKeys($space))),
        ]);
    }

    public function update(Request $request, Space $space): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:24'],
            'colour' => ['required', Rule::in(Space::COLOURS)],
            'type' => ['required', Rule::in([Space::SERVICE, Space::PLAIN])],
            'rules' => ['nullable', 'string', 'max:4000', self::NO_ORDER],
            'done_rule' => ['required', 'string', 'max:2000', self::NO_ORDER],
            'system_ids' => ['array'],
            'system_ids.*' => ['integer', Rule::exists('systems', 'id')],
        ], [
            'rules.not_regex' => 'Leave out ORDER BY: the tool orders by last update itself.',
            'done_rule.not_regex' => 'Leave out ORDER BY: the tool orders by last update itself.',
        ]);

        $space->update(Arr::except($data, 'system_ids'));
        $space->systems()->sync($data['system_ids'] ?? []);

        return back()->with('success', 'Saved.');
    }

    /** What the rules being typed would find, before they are saved. */
    public function preview(Request $request, Space $space, JiraClient $jira): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['nullable', 'string', 'max:4000', self::NO_ORDER],
            'done_rule' => ['nullable', 'string', 'max:2000', self::NO_ORDER],
        ], [
            'rules.not_regex' => 'Leave out ORDER BY: the tool orders by last update itself.',
            'done_rule.not_regex' => 'Leave out ORDER BY: the tool orders by last update itself.',
        ]);

        $jql = Space::composeJql($space->project_key, $data['rules'] ?? null, $data['done_rule'] ?? null);

        try {
            $page = $jira->search($jql, ['summary', 'status'], max: 10);
        } catch (JiraException $e) {
            return response()->json(['jql' => $jql, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'jql' => $jql,
            'count' => $jira->count(Space::composeJql($space->project_key, $data['rules'] ?? null, $data['done_rule'] ?? null, ordered: false)),
            'more' => $page['next'] !== null,
            'tickets' => collect($page['issues'])->map(fn (array $issue) => [
                'key' => $issue['key'],
                'summary' => $issue['fields']['summary'] ?? '',
                'status' => $issue['fields']['status']['name'] ?? null,
            ])->all(),
        ]);
    }

    public function activate(Space $space): RedirectResponse
    {
        $space->update(['state' => Space::ACTIVE]);
        SyncSpace::dispatch($space);

        return back()->with('success', 'Active. The first sync is on its way.');
    }

    public function pause(Space $space): RedirectResponse
    {
        $space->update(['state' => Space::PAUSED]);

        return back()->with('success', 'Paused. It no longer syncs; its tickets stay as they were.');
    }

    public function sync(Space $space): RedirectResponse
    {
        abort_unless($space->state === Space::ACTIVE, 409, 'Only an active space syncs.');
        SyncSpace::dispatch($space);

        return back()->with('success', 'Syncing '.$space->label.'.');
    }

    public function destroy(Space $space): RedirectResponse
    {
        $space->delete();

        return to_route('spaces.index')->with('success', 'Removed '.$space->label.', with its tickets.');
    }

    private function summary(Space $space): array
    {
        return [
            'id' => $space->id,
            'project_key' => $space->project_key,
            'name' => $space->name,
            'label' => $space->label,
            'colour' => $space->colour,
            'jira_type' => $space->jira_type,
            'type' => $space->type,
            'state' => $space->state,
            'synced_at' => $space->synced_at?->toIso8601String(),
            'sync_error' => $space->sync_error,
        ];
    }

    /** A service space needs the account to be an agent there, or it cannot reply at all. */
    private function permissionKeys(Space $space): array
    {
        return $space->type === Space::SERVICE
            ? ['BROWSE_PROJECTS', 'ADD_COMMENTS', 'SERVICEDESK_AGENT']
            : ['BROWSE_PROJECTS', 'ADD_COMMENTS'];
    }

    /** @return array{data: mixed, error: ?string} */
    private function attempt(Closure $call): array
    {
        try {
            return ['data' => $call(), 'error' => null];
        } catch (JiraException $e) {
            return ['data' => null, 'error' => $e->getMessage()];
        }
    }
}
