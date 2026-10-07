<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FieldDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = [
        'field_lcp_ms',
        'field_lcp_category',
        'field_cls',
        'field_cls_category',
        'field_inp_ms',
        'field_inp_category',
        'field_source',
    ];

    public function test_field_data_columns_exist_after_migrating(): void
    {
        $this->assertTrue(Schema::hasColumns('monitor_lighthouse_scores', self::COLUMNS));
    }

    public function test_migration_down_drops_and_up_restores_the_columns(): void
    {
        $migration = require database_path('migrations/2026_10_07_100000_add_field_data_to_monitor_lighthouse_scores_table.php');

        $migration->down();
        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('monitor_lighthouse_scores', $column));
        }

        $migration->up();
        $this->assertTrue(Schema::hasColumns('monitor_lighthouse_scores', self::COLUMNS));
    }
}
