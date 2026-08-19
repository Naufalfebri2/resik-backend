<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_role_check');

        Schema::table('employees', function ($table) {
            $table->string('role', 50)->change();
        });
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_role_check CHECK (role IN ('staff', 'admin', 'owner'))");

        Schema::table('employees', function ($table) {
            $table->enum('role', ['staff', 'admin', 'owner'])->change();
        });
    }
};
