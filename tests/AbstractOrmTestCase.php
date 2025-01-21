<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Vrok\ImportExport\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Vrok\ImportExport\Tests\Fixtures\ExportEntity;
use Vrok\ImportExport\Tests\Fixtures\ImportEntity;

abstract class AbstractOrmTestCase extends TestCase
{
    protected Configuration $configuration;
    protected EntityManager $em;

    protected function setUp(): void
    {
        parent::setUp();

        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__.'/Fixtures'],
            true
        );
        $configuration->setProxyDir(sys_get_temp_dir());
        $configuration->setProxyNamespace('Tests\Fixtures\Proxies');
        $configuration->setAutoGenerateProxyClasses(true);

        $this->configuration = $configuration;
    }

    protected function buildEntityManager(): EntityManager
    {
        $conn = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $this->configuration
        );

        $this->em = new EntityManager($conn, $this->configuration);

        return $this->em;
    }

    protected function setupSchema(): void
    {
        if (!$this->em) {
            $this->buildEntityManager();
        }

        $tool = new SchemaTool($this->em);
        $classes = [
            $this->em->getClassMetadata(ImportEntity::class),
            $this->em->getClassMetadata(ExportEntity::class),
        ];
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
