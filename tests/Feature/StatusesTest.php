<?php

namespace Tests\Feature;

use App\Models\Space;
use Tests\TestCase;

/** Status colours and sleep, on the statuses of the first real space (2026-10-02). */
class StatusesTest extends TestCase
{
    public function test_every_status_of_a_service_space_gets_its_own_colour(): void
    {
        $tones = [
            Space::defaultTone('In Progress', 'indeterminate'),
            Space::defaultTone('To Do', 'new'),
            Space::defaultTone('Waiting for support', 'indeterminate'),
            Space::defaultTone('On Hold', 'new'),
            Space::defaultTone('Waiting for customer', 'undefined'),
        ];

        $this->assertSame(['blue', 'grey', 'rose', 'violet', 'amber'], $tones);
        $this->assertSame('green', Space::defaultTone('Resolved', 'done'));
    }

    public function test_a_chosen_colour_wins_and_a_bad_one_is_ignored(): void
    {
        $space = new Space(['status_colours' => ['To Do' => 'teal', 'On Hold' => 'fuchsia']]);

        $this->assertSame('teal', $space->statusTone('To Do', 'new'));
        $this->assertSame('violet', $space->statusTone('On Hold', 'new'));
    }

    public function test_sleep_is_automatic_until_the_space_has_its_own_list(): void
    {
        $automatic = new Space(['sleep_statuses' => null]);
        $this->assertTrue($automatic->sleeps('Waiting for customer'));
        $this->assertTrue($automatic->sleeps('Awaiting the reporter'));
        $this->assertFalse($automatic->sleeps('Waiting for support'));
        $this->assertFalse($automatic->sleeps('On Hold'));

        $chosen = new Space(['sleep_statuses' => ['On Hold']]);
        $this->assertTrue($chosen->sleeps('On Hold'));
        $this->assertFalse($chosen->sleeps('Waiting for customer'));

        $none = new Space(['sleep_statuses' => []]);
        $this->assertFalse($none->sleeps('Waiting for customer'));
    }
}
