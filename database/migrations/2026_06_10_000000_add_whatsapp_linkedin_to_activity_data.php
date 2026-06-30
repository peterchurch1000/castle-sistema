<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_data', function (Blueprint $table) {
            $table->integer('whatsapp')->default(0)->after('visits');
            $table->integer('linkedin')->default(0)->after('whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('activity_data', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'linkedin']);
        });
    }
};
