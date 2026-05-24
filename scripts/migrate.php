<?php

declare(strict_types=1);

use App\Database\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$database = Connection::create(dirname(__DIR__) . '/var/database.sqlite');
$migration = file_get_contents(dirname(__DIR__) . '/src/Database/migrations.sql');

if ($migration === false) {
    throw new RuntimeException('Could not read database migration.');
}

$database->exec($migration);

echo "Database migrated.\n";

