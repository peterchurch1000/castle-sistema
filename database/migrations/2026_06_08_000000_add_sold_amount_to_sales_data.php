<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_data', function (Blueprint $table) {
            // "Zona gris": sold but not yet invoiced (open sales orders) per rep, USD MTD.
            $table->decimal('sold_amount', 14, 2)->default(0)->after('sales_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales_data', function (Blueprint $table) {
            $table->dropColumn('sold_amount');
        });
    }
};
