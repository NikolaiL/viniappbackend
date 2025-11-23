<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('viniapps', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_hash')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('prompt')->nullable();
            $table->string('description')->nullable();
            $table->string('logo_image')->nullable();
            $table->string('link')->nullable();
            $table->string('created_by')->nullable();
            $table->string('owned_by')->nullable();
            $table->string('status')->nullable();
            $table->string('wallet_address')->nullable();
            $table->string('wallet_private_key')->nullable();
            $table->string('wallet_signer')->nullable();
            $table->string('agent_session_id')->nullable();
            $table->string('github_url')->nullable();
            $table->string('website_url')->nullable();
            $table->string('ens')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('viniapps');
    }
};
