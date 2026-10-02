<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;

/**
 * The repository's own Markdown, readable in the app (and so on a phone).
 */
class DocsController extends Controller
{
    /** Served by slug, and only these: a slug never becomes a path. */
    private const DOCS = [
        'concept' => 'CONCEPT.md',
    ];

    public function __invoke(string $doc): Response
    {
        abort_unless(isset(self::DOCS[$doc]), 404);

        $markdown = file_get_contents(base_path(self::DOCS[$doc]));

        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            // Ids as GitHub makes them, so the documents' own #links work here too.
            'heading_permalink' => [
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
                'html_class' => 'anchor',
                'insert' => 'after',
                'symbol' => '#',
                'title' => 'Link to this section',
            ],
        ], [new HeadingPermalinkExtension]);

        return Inertia::render('Docs', [
            'title' => Str::match('/^# (.+)$/m', $markdown) ?: $doc,
            'html' => $html,
        ]);
    }
}
