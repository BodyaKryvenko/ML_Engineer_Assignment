<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Connection
{
    public static function create(string $path): PDO
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $connection = new PDO('sqlite:' . $path);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $connection->exec('PRAGMA foreign_keys = ON');

        return $connection;
    }
}

