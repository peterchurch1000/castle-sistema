<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_data', function (Blueprint $table) {
            $table->id();
            $table->string('rep_name');
            $table->integer('year');
            $table->integer('month');
            $table->integer('emails')->default(0);
            $table->integer('calls')->default(0);
            $table->integer('visits')->default(0);
            $table->integer('weighted_score')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['rep_name', 'year', 'month']);
        });

        Schema::create('activity_quotas', function (Blueprint $table) {
            $table->id();
            $table->string('rep_name');
            $table->integer('year');
            $table->integer('month');
            $table->integer('emails_target')->default(0);
            $table->integer('calls_target')->default(0);
            $table->integer('visits_target')->default(0);
            $table->integer('weighted_target')->default(0);
            $table->timestamps();
            $table->unique(['rep_name', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_data');
        Schema::dropIfExists('activity_quotas');
    }
};
