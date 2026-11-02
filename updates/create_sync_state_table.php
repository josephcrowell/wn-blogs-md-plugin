<?php

namespace JosephCrowell\BlogsMd\Updates;

use Winter\Storm\Database\Updates\Migration;
use Winter\Storm\Support\Facades\Schema;

class CreateSyncStateTable extends Migration
{
    public function up()
    {
        Schema::create('josephcrowell_blogsmd_sync_state', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('processed_sha', 40)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('josephcrowell_blogsmd_sync_state');
    }
}
