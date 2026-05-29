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
        Schema::create('sales_quotas', function (Blueprint $table) {
            $table->id();
            $table->string('rep_name');
            $table->integer('year');
            $table->integer('month'); // 1-12
            $table->decimal('quota_amount', 15, 2);
            $table->timestamps();
            $table->unique(['rep_name', 'year', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_quotas');
    }
};
