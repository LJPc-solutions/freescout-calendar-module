<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCalendarItemsTable extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::create( 'calendar_items', function ( Blueprint $table ) {
			$table->increments( 'id' );
			$table->integer( 'calendar_id' );
			$table->integer( 'author_id' );

			$table->boolean( 'is_all_day' );
			$table->boolean( 'is_private' );
			$table->boolean( 'is_read_only' )->default( false );

			$table->string( 'title' );
			$table->text( 'body' );
			$table->string( 'state' );
			$table->string( 'location' )->nullable();

			$table->dateTimeTz( 'start' );
			$table->dateTimeTz( 'end' );

			$table->timestamps();

			$table->index( [ 'calendar_id' ] );
			$table->index( [ 'author_id' ] );
			$table->index( [ 'start' ] );
			$table->index( [ 'is_all_day' ] );
		} );
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::dropIfExists( 'calendar_items' );
	}
}
