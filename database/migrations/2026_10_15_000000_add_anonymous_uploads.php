<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uploads and shares made by people who are not signed in (Light NC Share).
     */
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            // Same steps as 2025_03_14_161635: drop the key, allow null, put the key back.
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Which upload token this upload belongs to (null for signed-in uploads).
            $table->string('anon_token')->nullable()->index();
        });

        Schema::table('shares', function (Blueprint $table) {
            $table->boolean('anonymous')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropIndex(['anonymous']);
            $table->dropColumn('anonymous');
        });

        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropIndex(['anon_token']);
            $table->dropColumn('anon_token');
        });
    }
};
