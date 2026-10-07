<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_lighthouse_scores', function (Blueprint $table) {
            $table->unsignedInteger('field_lcp_ms')->nullable();
            $table->string('field_lcp_category', 10)->nullable();
            $table->decimal('field_cls', 6, 4)->nullable();
            $table->string('field_cls_category', 10)->nullable();
            $table->unsignedInteger('field_inp_ms')->nullable();
            $table->string('field_inp_category', 10)->nullable();
            $table->string('field_source', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitor_lighthouse_scores', function (Blueprint $table) {
            $table->dropColumn([
                'field_lcp_ms',
                'field_lcp_category',
                'field_cls',
                'field_cls_category',
                'field_inp_ms',
                'field_inp_category',
                'field_source',
            ]);
        });
    }
};
