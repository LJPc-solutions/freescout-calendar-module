<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Providers\LJPcCalendarModuleServiceProvider;

/**
 * Invites (W4): calendar_invites holds every iTIP message found in a conversation's attachments, and calendar_events
 * keeps what the providers don't: the attendees of our own invites (Google and Microsoft get none) and whether an
 * invite's event was cancelled or declined. Checked first, so a run that stopped can run again.
 */
class LjpcCalendarV3Invites extends Migration
{
    public function up()
    {
        // Like 000001: the refusal an old server shows. This file stays parseable on PHP 7.1 (no arrow functions).
        LJPcCalendarModuleServiceProvider::assertPlatformSupported(
            PHP_VERSION_ID,
            \Helper::checkAppVersion(LJPcCalendarModuleServiceProvider::MIN_APP_VERSION)
        );

        if (!Schema::hasTable('calendar_invites')) {
            Schema::create('calendar_invites', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('conversation_id');
                $table->unsignedInteger('thread_id')->nullable();
                $table->unsignedInteger('attachment_id');
                $table->text('uid');
                $table->char('uid_hash', 40);
                // '' for the whole event, so the unique key below also holds for it (NULLs never collide).
                $table->string('recurrence_id', 20)->default('');
                $table->string('method', 20);
                $table->unsignedInteger('sequence')->default(0);
                $table->dateTime('dtstamp')->nullable();
                $table->string('organizer_email')->nullable();
                // Kept here: Google and Microsoft get no organizer, so the readable block names it from the invite.
                $table->string('organizer_name')->nullable();
                $table->string('sender_email')->nullable();
                $table->string('summary', 500)->default('');
                $table->string('status', 20)->default('pending');
                $table->unsignedInteger('event_id')->nullable();
                $table->string('response', 20)->nullable();
                $table->unsignedInteger('applied_by')->nullable();
                $table->dateTime('applied_at')->nullable();
                $table->timestamps();
                $table->unique(['attachment_id', 'uid_hash', 'recurrence_id'], 'ljpc_cal_invites_message_unique');
                $table->index('uid_hash', 'ljpc_cal_invites_uid');
                $table->index('conversation_id', 'ljpc_cal_invites_conversation');
                $table->foreign('event_id')->references('id')->on('calendar_events')->onDelete('set null');
            });
        }
        foreach (array('attendees', 'invite_state') as $column) {
            if (!Schema::hasColumn('calendar_events', $column)) {
                Schema::table('calendar_events', function (Blueprint $table) use ($column) {
                    if ($column === 'attendees') {
                        $table->json('attendees')->nullable();
                    } else {
                        $table->string('invite_state', 20)->nullable();
                    }
                });
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('calendar_invites');
        foreach (array('attendees', 'invite_state') as $column) {
            if (Schema::hasColumn('calendar_events', $column)) {
                Schema::table('calendar_events', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
}
