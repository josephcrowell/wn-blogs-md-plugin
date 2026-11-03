<?php

namespace JosephCrowell\BlogsMd\Updates;

use Winter\Storm\Database\Updates\Migration;
use Winter\Storm\Support\Facades\Schema;

class AddAuthorGithubToSourcesTable extends Migration
{
    public function up()
    {
        Schema::table('josephcrowell_blogsmd_sources', function ($table) {
            $table->string('author_github')->nullable()->after('author_email');
        });
    }

    public function down()
    {
        Schema::table('josephcrowell_blogsmd_sources', function ($table) {
            $table->dropColumn('author_github');
        });
    }
}
