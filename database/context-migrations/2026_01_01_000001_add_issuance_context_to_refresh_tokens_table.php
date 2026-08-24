<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Reiarseni\SanctumRefreshToken\Support\Identifier;

/**
 * Adds the issuance-context column.
 *
 * Published separately from the main schema because an application that does
 * not isolate families by tenant, region or anything else has no use for the
 * column, and an unused indexed column is not free.
 *
 * The column is nullable on purpose: families opened before binding was
 * switched on carry no context and are not bound by one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $column = $this->column();

        Schema::table($this->table(), function (Blueprint $table) use ($column): void {
            $table->string($column)->nullable()->index();
        });
    }

    public function down(): void
    {
        $column = $this->column();

        Schema::table($this->table(), function (Blueprint $table) use ($column): void {
            $table->dropIndex([$column]);
            $table->dropColumn($column);
        });
    }

    private function table(): string
    {
        $table = config('sanctum-refresh-token.table', 'refresh_tokens');

        return Identifier::assertSafe(
            is_string($table) ? $table : 'refresh_tokens',
            'sanctum-refresh-token.table',
        );
    }

    private function column(): string
    {
        $column = config('sanctum-refresh-token.context.column', 'context');

        return Identifier::assertSafe(
            is_string($column) ? $column : 'context',
            'sanctum-refresh-token.context.column',
        );
    }
};
