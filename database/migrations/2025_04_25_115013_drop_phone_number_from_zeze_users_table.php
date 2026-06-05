<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('zeze_users', function (Blueprint $table) {

        });
        
        Schema::table('zeze_users', function (Blueprint $table) {
            $table->dropColumn('phone_number');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zeze_users', function (Blueprint $table) {

        });
        
        Schema::table('zeze_users', function (Blueprint $table) {
            // Original column definitions not available for reverse migration

        });
    }
};