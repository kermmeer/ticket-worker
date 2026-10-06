<?php

namespace App\Http\Controllers;

use App\Jira\JiraClient;
use App\Jira\JiraException;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A ticket's attachment, fetched from Jira with the app's token: the browser has no Jira
 * session of its own to open Jira's link with. Only an attachment that belongs to the
 * ticket is served, so the route cannot fetch anything else from Jira.
 */
class AttachmentController extends Controller
{
    /** What opens in the browser instead of downloading. Never HTML or SVG: those run. */
    private const INLINE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];

    private const MAX_BYTES = 100 * 1024 * 1024;

    public function __invoke(Request $request, Ticket $ticket, string $attachment, JiraClient $jira): BinaryFileResponse
    {
        try {
            $issue = Cache::remember("ticket.issue.{$ticket->key}", 60, fn () => $jira->issue($ticket->key));
        } catch (JiraException $e) {
            abort(502, $e->getMessage());
        }

        $file = collect($issue['attachments'] ?? [])->firstWhere('id', $attachment);
        abort_if($file === null, 404, 'No such attachment on this ticket.');

        $path = tempnam(sys_get_temp_dir(), 'attachment-');
        abort_unless($jira->download($file['url'], $path, self::MAX_BYTES), 502, 'Jira did not hand over the file, or it is over 100 MB.');

        $mime = in_array($file['mime'], self::INLINE, true) ? $file['mime'] : 'application/octet-stream';
        $inline = $mime !== 'application/octet-stream' && ! $request->boolean('download');

        $response = $inline
            ? response()->file($path, ['Content-Type' => $mime])
            : response()->download($path, $file['filename'], ['Content-Type' => $mime]);

        if ($inline) {
            $response->setContentDisposition('inline', $file['filename'], 'attachment');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Whatever the file is, it gets no scripts and no access to this app.
        $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'");
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response->deleteFileAfterSend();
    }
}
