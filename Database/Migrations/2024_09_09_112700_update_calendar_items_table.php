<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateCalendarItemsTable extends Migration {
		/**
		 * Run the migrations.
		 *
		 * @return void
		 */
		public function up() {
				Schema::table( 'calendar_items', function ( Blueprint $table ) {
						$table->json( 'custom_fields' )->nullable();
				} );

		}

		/**
		 * Reverse the migrations.
		 *
		 * @return void
		 */
		public function down() {
				Schema::table( 'calendar_items', function ( Blueprint $table ) {
						$table->dropColumn( 'custom_fields' );
				} );
		}
}
