<?php

use Illuminate\Database\Migrations\Migration;

class CreateLanguagesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection(config('translator.connection'))->create('translator_languages', function ($table) {
            $table->increments('id');
            $table->string('locale', 20)->unique()->nullable(false);
            $table->string('name', 60)->unique()->nullable(false);
            $table->integer('sort')->default(0)->nullable();
            $table->string('logo')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('translator_languages');
    }

}
