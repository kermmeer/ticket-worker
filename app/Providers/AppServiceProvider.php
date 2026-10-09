<?php

namespace App\Providers;

use App\Jira\JiraClient;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Route;
use App\Outbox\OutboxClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(JiraClient::class, fn () => new JiraClient(
            (string) config('services.jira.base'),
            config('services.jira.email'),
            config('services.jira.token'),
            config('services.jira.open_url'),
        ));

        $this->app->singleton(OutboxClient::class, fn () => new OutboxClient(
            config('services.outbox.url'),
            config('services.outbox.token'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A ticket or space someone may not see does not exist for them: every route that
        // names one goes through these, so no page or action can reach around it.
        Route::bind('ticket', fn (string $id) => Ticket::query()->visibleTo(auth()->user())->findOrFail($id));
        Route::bind('space', fn (string $id) => Space::query()->visibleTo(auth()->user())->findOrFail($id));
    }
}
