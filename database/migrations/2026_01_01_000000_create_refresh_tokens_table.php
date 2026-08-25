<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Reiarseni\SanctumRefreshToken\Support\Identifier;

/**
 * One table holds every generation of every token family.
 *
 * Rotation appends a row and marks its predecessor rotated; it never deletes.
 * That is what makes reuse detection possible at all — a consumed token has to
 * still exist for its replay to be recognisable — and it turns the table into
 * an audit trail of the family's whole lineage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            // The family is the unit of identity, lifetime and revocation.
            $table->uuid('family_uuid');
            $table->morphs('tokenable');

            // The Sanctum access token minted alongside this generation.
            $table->unsignedBigInteger('access_token_id')->nullable();

            // The session label, client-supplied or the configured default.
            $table->string('name');

            // SHA-256 of the secret. The secret itself is never stored.
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();

            // Lineage: which row this one replaced, and how deep the chain is.
            $table->unsignedBigInteger('previous_id')->nullable();
            $table->unsignedInteger('generation')->default(1);

            // Observed client metadata, keyed-hashed unless plaintext storage
            // is explicitly enabled.
            $table->string('ip_hash')->nullable();
            $table->string('user_agent_hash')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('family_expires_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 32)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // The rotation hot path: resolve a family and its live generations.
            $table->index(['family_uuid', 'generation']);

            // Session listing: a tokenable's live families, most recent first.
            $table->index(['tokenable_type', 'tokenable_id', 'last_used_at']);

            // The prune predicate is a disjunction -- revoked, or expired, or
            // simply old -- and no composite index can serve one: a
            // `(revoked_at, expires_at)` index is never chosen by the planner,
            // which falls back to scanning the whole table. One index per term
            // lets it build a bitmap from all three. Measured on two million
            // rows, that is the difference between 3,605 and 853 buffers read.
            $table->index('revoked_at');
            $table->index('expires_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    /**
     * The table name is configurable, so it is validated before it reaches a
     * schema statement — the one place a consumer value cannot be bound.
     */
    private function table(): string
    {
        $table = config('sanctum-refresh-token.table', 'refresh_tokens');

        return Identifier::assertSafe(is_string($table) ? $table : 'refresh_tokens', 'sanctum-refresh-token.table');
    }
};
