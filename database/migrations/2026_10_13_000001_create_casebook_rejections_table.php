<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Not related": a ticket the matcher must never tie to this case again.
        Schema::create('casebook_rejections', function (Blueprint $table) {
            $table->foreignId('casebook_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['casebook_entry_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casebook_rejections');
    }
};
