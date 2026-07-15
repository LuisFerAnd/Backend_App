<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->timestamp('processing_started_at', 3)->nullable()->after('processing_status');
            $table->timestamp('processing_finished_at', 3)->nullable()->after('processing_started_at');
            $table->unsignedBigInteger('processing_time_ms')->nullable()->after('processing_finished_at');
            $table->decimal('processing_time_seconds', 10, 3)->nullable()->after('processing_time_ms');
            $table->unsignedTinyInteger('processing_time_range')->nullable()->after('processing_time_seconds');
            $table->string('processing_time_label', 32)->nullable()->after('processing_time_range');
            $table->string('error_code', 100)->nullable()->after('processing_time_label');
            $table->text('error_message')->nullable()->after('error_code');
            $table->string('error_stage', 40)->nullable()->after('error_message');
            $table->unsignedInteger('retry_count')->default(0)->after('error_stage');
            $table->boolean('soap_generated')->default(false)->after('retry_count');

            $table->index(['processing_status', 'processing_time_range']);
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropIndex(['processing_status', 'processing_time_range']);
            $table->dropColumn([
                'processing_started_at', 'processing_finished_at', 'processing_time_ms',
                'processing_time_seconds', 'processing_time_range', 'processing_time_label',
                'error_code', 'error_message', 'error_stage', 'retry_count', 'soap_generated',
            ]);
        });
    }
};
