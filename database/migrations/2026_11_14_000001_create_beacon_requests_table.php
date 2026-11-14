<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated Beacon request-history table. Publish and migrate deliberately.
 * Beacon never runs this migration automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beacon_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 64);
            $table->string('correlation_id', 64)->nullable();
            $table->string('method', 16);
            $table->string('route_name', 191)->nullable();
            $table->string('route_uri', 191);
            $table->string('action', 191)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('environment', 64)->nullable();
            $table->boolean('had_exception')->default(false);
            $table->string('exception_id', 32)->nullable();
            $table->string('exception_class', 191)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('started_at');
            $table->index('request_id');
            $table->index('correlation_id');
            $table->index('route_uri');
            $table->index('status');
            $table->index(['had_exception', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beacon_requests');
    }
};
