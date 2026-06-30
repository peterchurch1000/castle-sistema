<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_data', function (Blueprint $table) {
            // "Remito por facturar": delivered (remito issued) but not yet invoiced,
            // per rep, USD. Reproduces NetSuite saved search 810. Full outstanding
            // backlog regardless of date, stored against the current month's row.
            $table->decimal('pending_billing_amount', 14, 2)->default(0)->after('sold_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales_data', function (Blueprint $table) {
            $table->dropColumn('pending_billing_amount');
        });
    }
};
