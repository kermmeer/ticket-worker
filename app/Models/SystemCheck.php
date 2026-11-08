<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One check of a system: a command that passes or fails, switched on or off. */
class SystemCheck extends Model
{
    protected $fillable = ['system_id', 'name', 'command', 'enabled', 'note', 'position'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
