<?php

namespace App\Models;

use App\Jira\JiraClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Jira site besides the one in .env (docs/TEAM.md, item 8): some teams' projects live in
 * another Jira, a customer's say. Each space reads from one site.
 */
class JiraSite extends Model
{
    protected $fillable = ['name', 'base_url', 'email', 'token', 'open_url', 'open_label'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    public function client(): JiraClient
    {
        return new JiraClient(rtrim($this->base_url, '/'), $this->email, $this->token, $this->open_url);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }
}
