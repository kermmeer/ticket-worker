<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Own work: an issue or an improvement you start yourself, before there is a ticket for it.
 * It is a ticket here, marked local, with the chat, Refine and Build of any other; when it is
 * ready, the agent drafts the Jira ticket and the work moves onto it (TicketController::link).
 */
class OwnWorkController extends Controller
{
    public const TYPES = ['Story', 'Improvement', 'Bug', 'Task'];

    /** Files to go along: screenshots mostly. */
    private const MAX_FILES = 12;

    private const MAX_KB = 10240;

    public function create(): Response
    {
        return Inertia::render('Work/New', [
            'spaces' => Space::query()->visibleTo(auth()->user())->where('state', Space::ACTIVE)->orderBy('label')
                ->get(['id', 'label', 'colour', 'project_key']),
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $spaces = Space::query()->visibleTo(auth()->user())->where('state', Space::ACTIVE)->pluck('id');
        $data = $request->validate([
            'space_id' => ['required', 'integer', Rule::in($spaces)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'type' => ['required', Rule::in(self::TYPES)],
        ]);
        $space = Space::findOrFail($data['space_id']);

        $ticket = Ticket::create([
            'space_id' => $space->id,
            'site' => $space->jira_site_id ?? 0,
            'jira_id' => 'local-'.Str::uuid(),
            'key' => 'WIP',
            'summary' => $data['title'],
            'issue_type' => $data['type'],
            'status' => 'Own work',
            'reporter' => auth()->user()?->name,
            'local' => true,
            'local_description' => $data['description'] ?? null,
            'local_user_id' => auth()->id(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        // Its own key until it has Jira's: WIP-12, and so feature/WIP-12 for its branch.
        $ticket->update(['key' => 'WIP-'.$ticket->id]);
        AuditEvent::record('work.create', $ticket->key, $data['title']);

        return to_route('tickets.show', $ticket)->with('success', 'Started. Ask, refine or build it like any ticket; make it a Jira ticket when it is ready.');
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->isLocal(), 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'type' => ['required', Rule::in(self::TYPES)],
        ]);
        $ticket->update(['summary' => $data['title'], 'local_description' => $data['description'] ?? null, 'issue_type' => $data['type']]);

        return back()->with('success', 'Saved.');
    }

    /** Screenshots and other files, for the agent to see and to go to Jira with the ticket. */
    public function upload(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->isLocal(), 404);
        $request->validate([
            'files' => ['required', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.self::MAX_KB, 'mimes:png,jpg,jpeg,gif,webp,pdf,txt,log,csv,json'],
        ]);
        if (count($ticket->localFiles()) + count($request->file('files')) > self::MAX_FILES) {
            return back()->withErrors(['files' => 'At most '.self::MAX_FILES.' files.']);
        }

        File::ensureDirectoryExists($ticket->filesPath());
        foreach ($request->file('files') as $file) {
            $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
            $name = "{$base}.{$extension}";
            for ($i = 2; is_file($ticket->filesPath().'/'.$name); $i++) {
                $name = "{$base}-{$i}.{$extension}";
            }
            $file->move($ticket->filesPath(), $name);
        }

        return back();
    }

    public function file(Ticket $ticket, string $name): BinaryFileResponse
    {
        $path = $this->path($ticket, $name);

        return response()->file($path, ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'"]);
    }

    public function destroyFile(Ticket $ticket, string $name): RedirectResponse
    {
        abort_unless($ticket->isLocal(), 404);
        File::delete($this->path($ticket, $name));

        return back();
    }

    /** One of its files, by name: a plain name, never a path. */
    private function path(Ticket $ticket, string $name): string
    {
        abort_unless(preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $name) && ! str_contains($name, '..'), 404);
        $path = $ticket->filesPath().'/'.$name;
        abort_unless(is_file($path), 404);

        return $path;
    }
}
