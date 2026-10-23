<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Providers\LJPcCalendarModuleServiceProvider;

class LjpcCalendarV3CreateTables extends Migration
{
    public function up()
    {
        // FreeScout runs module migrations without the provider's boot guard and without checking requiredAppVersion.
        // This file stays parseable on old PHP (no arrow functions), so the refusal below is what an old server shows.
        LJPcCalendarModuleServiceProvider::assertPlatformSupported(
            PHP_VERSION_ID,
            \Helper::checkAppVersion(LJPcCalendarModuleServiceProvider::MIN_APP_VERSION)
        );

        $columns = [
            'provider'                 => function (Blueprint $t) { $t->string('provider', 20)->default('local'); },
            'connection_id'            => function (Blueprint $t) { $t->unsignedInteger('connection_id')->nullable(); },
            'remote_id'                => function (Blueprint $t) { $t->text('remote_id')->nullable(); },
            'timezone'                 => function (Blueprint $t) { $t->string('timezone', 64)->nullable(); },
            'fields'                   => function (Blueprint $t) { $t->json('fields')->nullable(); },
            'display_priority'         => function (Blueprint $t) { $t->integer('display_priority')->default(100); },
            'sync_interval'            => function (Blueprint $t) { $t->unsignedInteger('sync_interval')->default(5); },
            'sync_state'               => function (Blueprint $t) { $t->json('sync_state')->nullable(); },
            'next_sync_at'             => function (Blueprint $t) { $t->dateTime('next_sync_at')->nullable(); },
            'last_synced_at'           => function (Blueprint $t) { $t->dateTime('last_synced_at')->nullable(); },
            'last_error'               => function (Blueprint $t) { $t->text('last_error')->nullable(); },
            'sync_lease_until'         => function (Blueprint $t) { $t->dateTime('sync_lease_until')->nullable(); },
            'default_reminder_minutes' => function (Blueprint $t) { $t->integer('default_reminder_minutes')->nullable(); },
            'export_token'             => function (Blueprint $t) { $t->string('export_token', 40)->nullable(); },
            'export_busy_token'        => function (Blueprint $t) { $t->string('export_busy_token', 40)->nullable(); },
        ];
        foreach ($columns as $name => $define) {
            if (!Schema::hasColumn('calendars', $name)) {
                Schema::table('calendars', function (Blueprint $table) use ($define) {
                    $define($table);
                });
            }
        }

        if (!Schema::hasTable('calendar_connections')) {
            Schema::create('calendar_connections', function (Blueprint $table) {
                $table->increments('id');
                $table->string('provider', 20);
                $table->string('account')->nullable();
                $table->text('server_url')->nullable();
                $table->text('credentials')->nullable();
                $table->string('status', 20)->default('ok');
                $table->text('last_error')->nullable();
                $table->date('secret_expires_at')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('calendar_permissions')) {
            Schema::create('calendar_permissions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('calendar_id');
                $table->string('subject_type', 10);
                $table->unsignedInteger('subject_id');
                $table->boolean('show_in_dashboard')->default(false);
                $table->boolean('can_view')->default(false);
                $table->boolean('can_create')->default(false);
                $table->boolean('can_edit')->default(false);
                $table->unique(['calendar_id', 'subject_type', 'subject_id'], 'ljpc_cal_perm_subject_unique');
                $table->foreign('calendar_id')->references('id')->on('calendars')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('calendar_events')) {
            Schema::create('calendar_events', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('calendar_id');
                $table->text('uid');
                $table->char('uid_hash', 40);
                $table->longText('ics');
                $table->text('remote_id')->nullable();
                $table->text('remote_href')->nullable();
                $table->string('etag')->nullable();
                $table->unsignedInteger('sequence')->default(0);
                $table->string('summary', 500)->default('');
                $table->text('title_input')->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->boolean('all_day')->default(false);
                $table->string('timezone', 64)->nullable();
                $table->boolean('is_recurring')->default(false);
                $table->string('status', 20)->default('confirmed');
                $table->boolean('is_private')->default(false);
                $table->string('organizer_email')->nullable();
                $table->unsignedInteger('author_id')->nullable();
                $table->unsignedInteger('conversation_id')->nullable();
                $table->json('field_values')->nullable();
                $table->integer('reminder_minutes')->nullable();
                $table->boolean('read_only')->default(false);
                $table->string('legacy_id', 191)->nullable();
                $table->timestamps();
                $table->unique(['calendar_id', 'uid_hash'], 'ljpc_cal_events_uid_unique');
                $table->index('legacy_id', 'ljpc_cal_events_legacy_index');
                $table->index('conversation_id', 'ljpc_cal_events_conversation_index');
                $table->foreign('calendar_id')->references('id')->on('calendars')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('calendar_occurrences')) {
            Schema::create('calendar_occurrences', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event_id');
                $table->unsignedInteger('calendar_id');
                $table->string('recurrence_id', 20)->nullable();
                $table->string('summary', 500)->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->boolean('all_day')->default(false);
                $table->string('status', 20)->default('confirmed');
                $table->index('event_id', 'ljpc_cal_occ_event_index');
                $table->index(['calendar_id', 'starts_at'], 'ljpc_cal_occ_start_index');
                $table->index(['calendar_id', 'ends_at'], 'ljpc_cal_occ_end_index');
                $table->foreign('event_id')->references('id')->on('calendar_events')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('calendar_occurrences');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendar_permissions');
        Schema::dropIfExists('calendar_connections');
    }
}
