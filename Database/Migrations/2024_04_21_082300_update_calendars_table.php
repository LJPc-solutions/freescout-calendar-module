<?php

use App\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarItem;
use Modules\Teams\Providers\TeamsServiceProvider as Teams;

class UpdateCalendarsTable extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::table( 'calendars', function ( Blueprint $table ) {
            //new columns
            $table->boolean( 'enabled' )->default( true );
            $table->string( 'color' )->nullable();
            $table->string( 'type' )->default( 'normal' );
            $table->json( 'permissions' )->nullable();
            $table->json( 'custom_fields' )->nullable();
        } );

        $colors      = [
            '#3498db',
            '#e74c3c',
            '#e67e22',
            '#1abc9c',
            '#9b59b6',
            '#2ecc71',
            '#f1c40f',
            '#833471',
            '#9980FA',
        ];
        $permissions = $this->buildPermissions();

        //migrate data
        $calendars = Calendar::all();
        foreach ( $calendars as $i => $calendar ) {
            if ( $calendar->url ) {
                CalendarItem::where( 'calendar_id', $calendar->id )->forceDelete();

                $calendar->type          = 'ics';
                $calendar->custom_fields = [ 'url' => $calendar->url, 'refresh' => 'daily' ];
            }
            $calendar->color       = $colors[ $i % count( $colors ) ];
            $calendar->permissions = $permissions;

            $calendar->save();
        }

        Schema::table( 'calendars', function ( Blueprint $table ) {
            //drop old columns
            $table->dropColumn( 'url' );
            $table->dropColumn( 'synchronization_token' );
        } );

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table( 'calendars', function ( Blueprint $table ) {
            $table->text( 'url' )->nullable();
            $table->text( 'synchronization_token' )->nullable();
        } );

        $calendars = Calendar::all();
        foreach ( $calendars as $calendar ) {
            if ( $calendar->type === 'ics' ) {
                $calendar->url = $calendar->custom_fields['url'];
            }
            $calendar->save();
        }

        Schema::table( 'calendars', function ( Blueprint $table ) {
            $table->dropColumn( 'enabled' );
            $table->dropColumn( 'color' );
            $table->dropColumn( 'type' );
            $table->dropColumn( 'permissions' );
            $table->dropColumn( 'custom_fields' );
        } );
    }

    private function buildPermissions(): array {
        $result = [];
        // Get all teams
        $allTeams = [];
        if ( class_exists( Teams::class ) ) {
            $allTeams = Teams::getTeams( true );
        }

        // Get all users that are active
        $allUsers = User::where( 'status', User::STATUS_ACTIVE )
                        ->remember( Helper::cacheTime() )
                        ->get();

        /** @var Team $team */
        foreach ( $allTeams as $team ) {
            $result[ $team->id ] = [
                'showInDashboard' => true,
                'showInCalendar'  => true,
                'createItems'     => true,
                'editItems'       => true,
            ];
        }

        /** @var User $user */
        foreach ( $allUsers as $user ) {
            $result[ $user->id ] = [
                'showInDashboard' => true,
                'showInCalendar'  => true,
                'createItems'     => true,
                'editItems'       => true,
            ];
        }

        return $result;
    }
}
