<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One step of a turn as the page shows it: something said, a tool used, an error. */
class AgentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['agent_turn_id', 'type', 'summary'];
}
