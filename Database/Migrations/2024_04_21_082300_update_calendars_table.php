<?php

use App\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $calendars = DB::table('calendars')->orderBy('id')->get();
        foreach ($calendars as $i => $calendar) {
            $update = [
                'color'       => $colors[$i % count($colors)],
                'permissions' => json_encode($permissions),
            ];
            if ($calendar->url) {
                DB::table('calendar_items')->where('calendar_id', $calendar->id)->delete();
                $update['type']          = 'ics';
                $update['custom_fields'] = json_encode(['url' => $calendar->url, 'refresh' => 'daily']);
            }
            DB::table('calendars')->where('id', $calendar->id)->update($update);
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

        foreach (DB::table('calendars')->where('type', 'ics')->get() as $calendar) {
            $customFields = json_decode($calendar->custom_fields ?? '{}', true) ?: [];
            DB::table('calendars')->where('id', $calendar->id)->update(['url' => $customFields['url'] ?? null]);
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
        $allUsers = User::where( 'status', User::STATUS_ACTIVE )->get();

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
