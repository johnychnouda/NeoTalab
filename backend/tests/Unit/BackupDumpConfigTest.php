<?php

namespace Tests\Unit;

use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Spatie\DbDumper\Databases\MySql;
use Tests\TestCase;

class BackupDumpConfigTest extends TestCase
{
    public function test_nightly_dump_does_not_verify_the_database_certificate(): void
    {
        config(['database.connections.mysql.host' => 'mysql.railway.internal', 'database.connections.mysql.username' => 'root']);

        $dumper = DbDumperFactory::createFromConnection('mysql');

        $this->assertInstanceOf(MySql::class, $dumper);
        $this->assertStringContainsString('skip-ssl', $dumper->getContentsOfCredentialsFile());
    }
}
