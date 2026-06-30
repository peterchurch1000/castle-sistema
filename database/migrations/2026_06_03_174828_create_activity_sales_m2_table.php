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
        Schema::create('activity_sales_m2', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('category');
            $table->decimal('m2', 12, 2);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['date', 'category']);
            $table->index('date');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_sales_m2');
    }
};
