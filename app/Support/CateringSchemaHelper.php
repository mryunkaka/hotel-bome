<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class CateringSchemaHelper
{
    public static function hasColumn(string $table, string $column): bool
    {
        // Sanitasi defensif untuk mencegah injection pada identifier
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }

        // MySQL tidak reliable dengan binding pada SHOW COLUMNS ... LIKE ?
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'";
        $row = DB::selectOne($sql);

        return (bool) $row;
    }
}
