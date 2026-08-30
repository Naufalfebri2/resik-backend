<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_bookings', function (Blueprint $table) {
            $table->string('no_show_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('table_bookings', function (Blueprint $table) {
            $table->dropColumn('no_show_reason');
        });
    }
};
