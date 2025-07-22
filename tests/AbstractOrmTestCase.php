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
use Vrok\ImportExport\Tests\Fixtures\AbstractImportEntity;
use Vrok\ImportExport\Tests\Fixtures\AutoincrementEntity;
use Vrok\ImportExport\Tests\Fixtures\ExportEntity;
use Vrok\ImportExport\Tests\Fixtures\ImportEntity;

abstract class AbstractOrmTestCase extends TestCase
{
    protected Configuration $configuration;
    protected EntityManager $em;

    protected function setUp(): void
    {
        parent::setUp();

        if (method_exists(ORMSetup::class, 'createAttributeMetadataConfig')) {
            $configuration = ORMSetup::createAttributeMetadataConfig(
                [__DIR__.'/Fixtures'],
                true
            );
        }
        // @todo remove when only ORM 4.x is supported
        else {
            $configuration = ORMSetup::createAttributeMetadataConfiguration(
                [__DIR__.'/Fixtures'],
                true
            );

            $configuration->setProxyDir(sys_get_temp_dir());
            $configuration->setProxyNamespace('Tests\Fixtures\Proxies');
            $configuration->setAutoGenerateProxyClasses(true);
        }

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
            $this->em->getClassMetadata(AutoincrementEntity::class),
            $this->em->getClassMetadata(ExportEntity::class),
            $this->em->getClassMetadata(AbstractImportEntity::class),
            $this->em->getClassMetadata(ImportEntity::class),
        ];
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
