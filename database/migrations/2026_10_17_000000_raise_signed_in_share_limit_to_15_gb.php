<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// S27-29 (R14): one transfer may be 15 GB for visitors and signed-in staff alike.
// A fresh install gets 15 from the seeder; this lifts an existing install that is still below 15 GB.
// A value the administrator already set at 15 GB or more is left alone.
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('settings')->where('key', 'max_share_size')->exists()) {
            return; // not seeded yet: the seeder writes 15
        }

        $size = (float) DB::table('settings')->where('key', 'max_share_size')->value('value');
        $unit = (string) DB::table('settings')->where('key', 'max_share_size_unit')->value('value');
        $bytes = $unit === 'MB' ? $size * 1024 * 1024 : ($unit === 'GB' ? $size * 1024 * 1024 * 1024 : 0);

        if ($bytes < 15 * 1024 * 1024 * 1024) {
            DB::table('settings')->where('key', 'max_share_size')
                ->update(['previous_value' => (string) $size, 'value' => '15', 'updated_at' => now()]);
            DB::table('settings')->where('key', 'max_share_size_unit')
                ->update(['value' => 'GB', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The earlier value is not restored: the limit only ever goes up here.
    }
};
