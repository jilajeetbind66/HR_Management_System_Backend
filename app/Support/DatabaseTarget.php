<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class DatabaseTarget
{
    public static function description(): string
    {
        $configuration = DB::connection()->getConfig();

        return sprintf(
            'Target database: DB_HOST=%s; DB_DATABASE=%s',
            $configuration['host'] ?? '(local)',
            $configuration['database'] ?? '(unknown)'
        );
    }
}
