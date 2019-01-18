<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTranslationsTable extends Migration {

	/**
	 * Run the migrations.
	 */
	public function up() {
		Schema::create('translations', function(Blueprint $table) {
			$table->increments('id');
			$table->string('locale');
			$table->string('group');
			$table->string('name');
			$table->text('value')->nullable();

			$table->unique(['locale', 'group', 'name']);
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down() {
		Schema::drop('translations');
	}
}
