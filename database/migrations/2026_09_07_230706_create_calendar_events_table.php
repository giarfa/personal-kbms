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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_uid');
            $table->string('recurrence_id');
            $table->string('summary')->nullable();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('organizer')->nullable();
            $table->json('attendees')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_all_day')->default(false);
            $table->string('timezone')->nullable();
            $table->string('join_url')->nullable();
            $table->string('event_url')->nullable();
            $table->string('content_hash', 64);
            $table->dateTime('last_seen_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['source_uid', 'recurrence_id']);
            $table->index('starts_at');
            $table->index('cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
