<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCalendarsTable extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::create( 'calendars', function ( Blueprint $table ) {
			$table->increments( 'id' );
			$table->string( 'name' )->unique();
			$table->text( 'url' )->nullable();
			$table->text( 'synchronization_token' )->nullable();
			$table->timestamps();
		} );
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::dropIfExists( 'calendars' );
	}
}
