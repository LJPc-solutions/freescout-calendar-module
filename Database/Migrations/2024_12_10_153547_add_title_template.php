<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTitleTemplate extends Migration {
		/**
		 * Run the migrations.
		 *
		 * @return void
		 */
		public function up() {
				Schema::table('calendars', function (Blueprint $table) {
						$table->string('title_template')->nullable();
				});
		}

		/**
		 * Reverse the migrations.
		 *
		 * @return void
		 */
		public function down() {
				Schema::table('calendars', function (Blueprint $table) {
						$table->dropColumn('title_template');
				});
		}
}
