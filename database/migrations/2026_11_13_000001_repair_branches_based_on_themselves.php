<?php

use App\Agent\ScratchCopy;
use App\Models\TicketBranch;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * A build on an existing branch could be given that branch as its base, and was then
     * counted against itself: "0 commits" (ODOSSER-898). Such branches get their system's
     * branch as base, and their counts again.
     */
    public function up(): void
    {
        foreach (TicketBranch::query()->with('system')->whereColumn('base', 'name')->get() as $branch) {
            if ($branch->system === null) {
                continue;
            }
            $branch->base = $branch->system->branch;
            try {
                $branch->fill(app(ScratchCopy::class)->stats($branch->system->path(), $branch->name, $branch->base));
            } catch (Throwable) {
                // Its repository is gone or the branch with it: the base alone is still right.
            }
            $branch->save();
        }
    }

    public function down(): void {}
};
