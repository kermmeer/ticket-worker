<?php

namespace App\Notify;

use App\Models\Space;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A space's Slack or Teams channel (docs/TEAM.md, item 4): one line of text to its incoming
 * webhook. Both accept {"text": …}; a link is written so each shows it as one.
 */
class SpaceWebhook
{
    /** @return string|null why it failed, or null when it went */
    public static function send(Space $space, string $text, ?string $url = null, ?string $label = null): ?string
    {
        if (! filled($space->notify_webhook)) {
            return 'This space has no webhook.';
        }
        if ($url !== null) {
            $text .= str_contains($space->notify_webhook, 'slack.com') ? " <{$url}|".($label ?? 'Open')."\u{203A}>" : " {$url}";
        }

        try {
            $response = Http::timeout(10)->asJson()->post($space->notify_webhook, ['text' => $text]);
        } catch (ConnectionException $e) {
            return 'No answer: '.$e->getMessage();
        }

        return $response->successful() ? null : "It answered {$response->status()}.";
    }
}
