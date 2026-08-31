<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edbo_runs', function (Blueprint $table) {
            // 同一优化课题（任务）下的多次推荐共享同一个 task_uuid；
            // 单次推荐即「该任务的一个批次」。为兼容旧数据，允许为空。
            $table->string('task_uuid')->nullable()->after('uuid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('edbo_runs', function (Blueprint $table) {
            $table->dropIndex(['task_uuid']);
            $table->dropColumn('task_uuid');
        });
    }
};
