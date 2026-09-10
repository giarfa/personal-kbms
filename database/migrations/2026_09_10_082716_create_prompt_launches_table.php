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
        Schema::create('prompt_launches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_uid');
            $table->string('event_recurrence_id')->default('');
            $table->string('context_path');
            $table->text('question');
            $table->json('command');
            $table->string('status');
            $table->integer('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamps();

            // No foreign key to calendar_events: identical reasoning to
            // meeting_transcripts and meeting_notes (decision journal: "meeting
            // note storage keying") — the only targetable column is the UUID
            // surrogate id, exactly the identity a resync may change, and a
            // cascade would give the feed a route to destroy operator data.
            // This index also serves the "latest launch for this occurrence"
            // lookup now and FR-012's history list later, by leftmost prefix.
            $table->index(['event_uid', 'event_recurrence_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prompt_launches');
    }
};
