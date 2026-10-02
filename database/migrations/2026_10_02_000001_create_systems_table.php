<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('systems', function (Blueprint $table) {
            $table->id();
            // The folder under shared/systems, and the name everything else uses.
            $table->string('name')->unique();
            // The branch your pushes update, which is the code the agents read.
            $table->string('branch')->default('main');
            // preparing, ready or failed: the folder is made by the worker, not by php-fpm.
            $table->string('state')->default('preparing');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('systems');
    }
};
