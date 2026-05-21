<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('log_cursors_legacy_snapshot')) {
            $legacy = DB::table('log_cursors_legacy_snapshot')->get();

            foreach ($legacy as $row) {
                $instanceId = DB::table('log_instances')->insertGetId([
                    'file_path' => $row->file_path,
                    'identity_hash' => sha1('legacy:'.$row->file_path.':'.$row->id),
                    'file_ctime' => null,
                    'head_hash' => $row->head_hash ?: sha1('legacy:'.$row->id),
                    'anchor_offset' => null,
                    'anchor_hash' => null,
                    'tail_hash' => null,
                    'local_username' => $row->local_username,
                    'first_seen_at' => $row->created_at ?? $now,
                    'last_seen_at' => $row->updated_at ?? $now,
                    'sealed_at' => $now,
                    'seal_reason' => 'pre_migration',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('log_events')
                    ->where('file_path', $row->file_path)
                    ->whereNull('log_instance_id')
                    ->update(['log_instance_id' => $instanceId]);
            }
        }

        $orphanPaths = DB::table('log_events')
            ->whereNull('log_instance_id')
            ->distinct()
            ->pluck('file_path');

        foreach ($orphanPaths as $path) {
            $instanceId = DB::table('log_instances')->insertGetId([
                'file_path' => $path,
                'identity_hash' => sha1('legacy_orphan:'.$path),
                'file_ctime' => null,
                'head_hash' => sha1('legacy_orphan:'.$path),
                'anchor_offset' => null,
                'anchor_hash' => null,
                'tail_hash' => null,
                'local_username' => null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'sealed_at' => $now,
                'seal_reason' => 'pre_migration_orphan',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('log_events')
                ->where('file_path', $path)
                ->whereNull('log_instance_id')
                ->update(['log_instance_id' => $instanceId]);
        }

        Schema::dropIfExists('log_cursors_legacy_snapshot');
    }

    public function down(): void
    {
        DB::table('log_events')->update(['log_instance_id' => null]);
        DB::table('log_instances')->where('seal_reason', 'like', 'pre_migration%')->delete();
    }
};
