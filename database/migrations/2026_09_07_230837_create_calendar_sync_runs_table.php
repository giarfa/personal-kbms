<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->unsignedInteger('events_upserted')->default(0);
            $table->unsignedInteger('events_cancelled')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('started_at');
            $table->index(['status', 'finished_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_sync_runs');
    }
};
