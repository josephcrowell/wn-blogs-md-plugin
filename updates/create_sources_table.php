<?php

namespace JosephCrowell\BlogsMd\Updates;

use Winter\Storm\Database\Updates\Migration;
use Winter\Storm\Support\Facades\Schema;

class CreateSourcesTable extends Migration
{
    public function up()
    {
        Schema::create('josephcrowell_blogsmd_sources', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('type', 16);
            $table->integer('model_id')->unsigned()->index();
            $table->string('source_path')->unique();
            $table->string('content_hash', 40);
            $table->timestamp('removed_at')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->timestamp('author_date')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('josephcrowell_blogsmd_sources');
    }
}
