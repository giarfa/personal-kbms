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
        Schema::create('meeting_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_uid');
            $table->string('event_recurrence_id')->default('');
            $table->text('body')->default('');
            $table->timestamps();

            // No foreign key to calendar_events: the only targetable column is
            // the UUID surrogate id, exactly the identity a resync may change,
            // and a cascade would give the feed a route to destroy operator
            // data. This index also serves the batched agenda coverage lookup
            // (whereIn('event_uid', ...)) by leftmost prefix.
            $table->unique(['event_uid', 'event_recurrence_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_notes');
    }
};
