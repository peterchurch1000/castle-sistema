<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_gp', function (Blueprint $table) {
            // GP % per card (saved search 422 "% GP APROX"), one row per metric/month.
            $table->id();
            $table->string('metric');           // invoiced | sold | sold_next | pending | total
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('gp_pct', 6, 2)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['metric', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_gp');
    }
};
