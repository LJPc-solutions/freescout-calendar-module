<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCalendarEventIndexTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('calendar_event_index', function (Blueprint $table) {
            $table->bigIncrements('id');
            
            // Calendar relationship
            $table->unsignedInteger('calendar_id');
            
            // Event identification
            $table->string('event_uid', 255);
            $table->string('event_summary', 500)->nullable();
            
            // Event timing (stored in UTC)
            $table->dateTime('event_start')->nullable();
            $table->dateTime('event_end')->nullable();
            $table->boolean('is_all_day')->default(false);
            
            // Event data storage
            $table->json('event_data'); // Full event data as JSON
            $table->string('event_location', 255)->nullable();
            
            // Sync tracking
            $table->unsignedInteger('sync_version')->default(1);
            $table->timestamp('last_synced_at')->nullable();
            
            // Calendar-specific flags
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_id', 255)->nullable(); // For recurring event instances
            
            // Performance and tracking
            $table->unsignedInteger('access_count')->default(0); // Track popular events
            $table->timestamp('last_accessed_at')->nullable();
            
            // Laravel timestamps
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['calendar_id', 'event_uid'], 'idx_calendar_event');
            $table->index('event_uid', 'idx_event_uid');
            $table->index(['event_start', 'event_end'], 'idx_event_dates');
            $table->index('sync_version', 'idx_sync_version');
            $table->index(['calendar_id', 'last_synced_at'], 'idx_calendar_sync');
            
            // Foreign key constraint
            $table->foreign('calendar_id')
                  ->references('id')
                  ->on('calendars')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('calendar_event_index');
    }
}
