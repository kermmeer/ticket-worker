<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One version of a system's context, by the agent or by you. */
class SystemContext extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['system_id', 'body', 'commit', 'written_by'];
}
