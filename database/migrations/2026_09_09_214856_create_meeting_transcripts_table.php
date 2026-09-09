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
        Schema::create('meeting_transcripts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_uid');
            $table->string('event_recurrence_id')->default('');
            // Nullable on purpose: a manual row with path=NULL is the
            // manual-unlink tombstone (decision journal: "transcript clear
            // link semantics") — it is what stops the convention from
            // re-linking a file the operator just rejected. Do not add a
            // NOT NULL constraint.
            $table->string('path')->nullable();
            $table->string('link_source');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('file_mtime')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            // No foreign key to calendar_events: identical reasoning to
            // meeting_notes (decision journal: "meeting note storage
            // keying") — the only targetable column is the UUID surrogate
            // id, exactly the identity a resync may change, and a cascade
            // would give the feed a route to destroy operator data. This
            // index also serves the batched agenda coverage lookup
            // (whereIn('event_uid', ...)) by leftmost prefix.
            $table->unique(['event_uid', 'event_recurrence_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_transcripts');
    }
};
