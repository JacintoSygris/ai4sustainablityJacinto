<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Social login (Google/Microsoft via Socialite) was removed on 2026-11-17; the
// linked-identity table has no remaining reader. users.auth_version stays: it
// still invalidates sessions after password changes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('oauth_identities');
    }

    public function down(): void
    {
        Schema::create('oauth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('issuer');
            $table->string('subject');
            $table->char('identity_hash', 64)->unique();
            $table->timestamps();
            $table->index(['user_id', 'provider']);
        });
    }
};
