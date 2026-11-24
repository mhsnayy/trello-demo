<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // member: görür ve düzenler, viewer: yalnızca görür. Mevcut atamalar üye kalır.
        Schema::table('card_user', fn (Blueprint $table) => $table->string('role', 10)->default('member'));

        // Otomasyon günlüğü yalnızca kartı görebilenlere gösterilir.
        Schema::table('automation_logs', fn (Blueprint $table) => $table->unsignedBigInteger('card_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('card_user', fn (Blueprint $table) => $table->dropColumn('role'));
        Schema::table('automation_logs', fn (Blueprint $table) => $table->dropColumn('card_id'));
    }
};
