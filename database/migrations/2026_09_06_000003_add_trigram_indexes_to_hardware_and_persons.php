<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Hardware search columns
        $hardwareColumns = ['pc_name', 'n_code', 'ip_valid', 'ip_local', 'mac', 'comments'];
        foreach ($hardwareColumns as $column) {
            DB::statement("CREATE INDEX IF NOT EXISTS idx_hardware_{$column}_trgm ON hardwares USING gin ({$column} gin_trgm_ops)");
        }

        // Person name columns (used in JOIN search)
        $personColumns = ['f_name', 'l_name'];
        foreach ($personColumns as $column) {
            DB::statement("CREATE INDEX IF NOT EXISTS idx_persons_{$column}_trgm ON persons USING gin ({$column} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        $hardwareColumns = ['pc_name', 'n_code', 'ip_valid', 'ip_local', 'mac', 'comments'];
        foreach ($hardwareColumns as $column) {
            DB::statement("DROP INDEX IF EXISTS idx_hardware_{$column}_trgm");
        }

        $personColumns = ['f_name', 'l_name'];
        foreach ($personColumns as $column) {
            DB::statement("DROP INDEX IF EXISTS idx_persons_{$column}_trgm");
        }
    }
};
