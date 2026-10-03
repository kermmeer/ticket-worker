<?php

namespace App\Providers;

use App\Jira\JiraClient;
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
        //
    }
}
