<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_xbclient_reward_logs', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 128)->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('ad_network', 64)->nullable();
            $table->string('ad_unit', 128);
            $table->unsignedBigInteger('gift_card_template_id')->nullable()->index();
            $table->unsignedBigInteger('gift_card_code_id')->nullable()->index();
            $table->text('custom_data');
            $table->string('key_id', 64);
            $table->text('signature');
            $table->string('status', 32)->index();
            $table->string('error', 255)->default('');
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('rewarded_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at'], 'v2_xbclient_reward_user_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_xbclient_reward_logs');
    }
};
