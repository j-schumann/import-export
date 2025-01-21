<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Vrok\ImportExport\Tests;

use Vrok\ImportExport\Helper;
use Vrok\ImportExport\Tests\Fixtures\ExportEntity;
use Vrok\ImportExport\Tests\Fixtures\ImportEntity;
use Vrok\ImportExport\Tests\Fixtures\NestedDTO;
use Vrok\ImportExport\Tests\Fixtures\TestDTO;
use Vrok\ImportExport\Tests\Fixtures\TestEntity;

class ImportTest extends AbstractOrmTestCase
{
    public function testImportWithSetter(): void
    {
        $helper = new Helper();

        $data = [
            'name'      => 'test',

            // will be ignored and throws no error
            'something' => 'else',
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertSame('test via setter', $instance->getName());

        self::assertCount(0, $instance->getCollection());
        self::assertNull($instance->getParent());
        self::assertNull($instance->timestamp);
    }

    public function testImportOfDatetime(): void
    {
        $helper = new Helper();

        $data = [
            'timestamp' => 'tomorrow',
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertInstanceOf(\DateTimeImmutable::class, $instance->timestamp);

        $now = new \DateTimeImmutable();
        self::assertGreaterThan($now, $instance->timestamp);
    }

    public function testImportOfNull(): void
    {
        $helper = new Helper();

        $data = [
            'name'      => null,
            'parent'    => null,
            'timestamp' => null,
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);
        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertNull($instance->getParent());
        self::assertNull($instance->getName());
        self::assertNull($instance->timestamp);
    }

    public function testImportIgnoresUnannotatedProperties(): void
    {
        $helper = new Helper();

        $data = [
            'name'        => 'test',
            'notImported' => 'fail!',
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertSame('test via setter', $instance->getName());
        self::assertSame('initial', $instance->notImported);
    }

    public function testImportUntypedProperty(): void
    {
        $helper = new Helper();

        $data = [
            'untypedProp' => 77,
        ];
        $instance = $helper->fromArray($data, ImportEntity::class);
        self::assertSame(77, $instance->untypedProp);

        $data = [
            'untypedProp' => '66',
        ];
        $instance = $helper->fromArray($data, ImportEntity::class);
        self::assertSame('66', $instance->untypedProp);

        $data = [
            'untypedProp' => [1, 2, 3],
        ];
        $instance = $helper->fromArray($data, ImportEntity::class);
        self::assertSame([1, 2, 3], $instance->untypedProp);

        $data = [
            'untypedProp' => [
                '_entityClass' => TestDTO::class,
                'name'         => 'Number 4',
            ],
        ];
        $instance = $helper->fromArray($data, ImportEntity::class);
        self::assertInstanceOf(TestDTO::class, $instance->untypedProp);
        self::assertSame('Number 4', $instance->untypedProp->name);

        $dto = new TestDTO();
        $dto->name = 'Number 5';
        $data = [
            'untypedProp' => $dto,
        ];
        $instance = $helper->fromArray($data, ImportEntity::class);
        self::assertInstanceOf(TestDTO::class, $instance->untypedProp);
        self::assertSame('Number 5', $instance->untypedProp->name);

        $data = [
            'untypedProp' => null,
        ];
        $instance = $helper->fromArray($data, ImportEntity::class);
        self::assertNull($instance->untypedProp);
    }

    public function testImportOfReference(): void
    {
        $helper = new Helper();

        $data = [
            'parent' => [
                'name' => 'parentEntity',
            ],
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertInstanceOf(ImportEntity::class, $instance->getParent());
        self::assertSame('parentEntity via setter', $instance->getParent()->getName());
    }

    public function testImportOfReferenceInstance(): void
    {
        $helper = new Helper();

        $parent = new ImportEntity();
        $parent->setName('parent');

        $data = [
            'parent' => $parent,
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertInstanceOf(ImportEntity::class, $instance->getParent());
        self::assertSame('parent via setter', $instance->getParent()->getName());

        self::assertSame('', $instance->getName());
        self::assertCount(0, $instance->getCollection());
        self::assertNull($instance->timestamp);
    }

    public function testReferencingExistingRecord(): void
    {
        $em = $this->buildEntityManager();
        $this->setupSchema();

        $parent = new ImportEntity();
        $parent->setName('parent');
        $em->persist($parent);
        $em->flush();
        $em->clear();

        $data = [
            'parent' => $parent->getName(),
        ];

        $helper = new Helper();
        $helper->setObjectManager($em);
        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertInstanceOf(ImportEntity::class, $instance->getParent());
        self::assertSame('parent via setter', $instance->getParent()->getName());

        self::assertSame('', $instance->getName());
        self::assertCount(0, $instance->getCollection());
        self::assertNull($instance->timestamp);
    }

    public function testImportOfCollection(): void
    {
        $helper = new Helper();

        $data = [
            'collection' => [
                [
                    '_entityClass' => ImportEntity::class,
                    'name'         => 'element1',
                ],
                [
                    '_entityClass' => ImportEntity::class,
                    'name'         => 'element2',
                ],
            ],
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertCount(2, $instance->getCollection());

        $element1 = $instance->getCollection()[0];
        self::assertInstanceOf(ImportEntity::class, $element1);
        self::assertSame('element1 via setter', $element1->getName());

        $element2 = $instance->getCollection()[1];
        self::assertInstanceOf(ImportEntity::class, $element2);
        self::assertSame('element2 via setter', $element2->getName());

        self::assertSame('', $instance->getName());
        self::assertNull($instance->getParent());
        self::assertNull($instance->timestamp);
    }

    public function testImportOfCollectionWithInstances(): void
    {
        $helper = new Helper();

        $element1 = new ImportEntity();
        $element1->setName('element1');

        $element2 = new ImportEntity();
        $element2->setName('element2');

        $data = [
            'collection' => [$element1, $element2],
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertCount(2, $instance->getCollection());

        $collectionElement1 = $instance->getCollection()[0];
        self::assertInstanceOf(ImportEntity::class, $collectionElement1);
        self::assertSame('element1 via setter', $collectionElement1->getName());

        $collectionElement2 = $instance->getCollection()[1];
        self::assertInstanceOf(ImportEntity::class, $collectionElement2);
        self::assertSame('element2 via setter', $collectionElement2->getName());

        self::assertSame('', $instance->getName());
        self::assertNull($instance->getParent());
        self::assertNull($instance->timestamp);
    }

    public function testImportOfDtoList(): void
    {
        $helper = new Helper();

        $data = [
            'dtoList' => [
                [
                    'name' => 'element1',
                ],
                [
                    'name' => 'element2',
                ],
            ],
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertCount(2, $instance->dtoList);

        $element1 = $instance->dtoList[0];
        self::assertInstanceOf(TestDTO::class, $element1);
        self::assertSame('element1', $element1->name);

        $element2 = $instance->dtoList[1];
        self::assertInstanceOf(TestDTO::class, $element2);
        self::assertSame('element2', $element2->name);
    }

    public function testImportOfDtoListFailsWithInvalidElement(): void
    {
        $helper = new Helper();

        $data = [
            'dtoList' => [
                [
                    'name' => 'element1',
                ],
                [
                    '_entityClass' => NestedDTO::class,
                    'description'  => 'element2',
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Given '_entityClass' Vrok\ImportExport\Tests\Fixtures\NestedDTO is not a subclass/implementation of Vrok\ImportExport\Tests\Fixtures\TestDTO!");

        $helper->fromArray($data, ImportEntity::class);
    }

    public function testImportOfInterfaceList(): void
    {
        $helper = new Helper();

        $data = [
            'interfaceList' => [
                [
                    '_entityClass' => NestedDTO::class,
                    'description'  => 'element1',
                    'mixedProp'    => 'string',
                ],
                [
                    '_entityClass' => NestedDTO::class,
                    'description'  => 'element2',
                    'mixedProp'    => 111,
                ],
                [
                    '_entityClass' => TestDTO::class,
                    'name'         => 'element3',
                ],
            ],
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertCount(3, $instance->interfaceList);

        $element1 = $instance->interfaceList[0];
        self::assertInstanceOf(NestedDTO::class, $element1);
        self::assertSame('element1', $element1->description);
        self::assertSame('string', $element1->mixedProp);

        $element2 = $instance->interfaceList[1];
        self::assertInstanceOf(NestedDTO::class, $element2);
        self::assertSame('element2', $element2->description);
        self::assertSame(111, $element2->mixedProp);

        $element3 = $instance->interfaceList[2];
        self::assertInstanceOf(TestDTO::class, $element3);
        self::assertSame('element3', $element3->name);
    }

    public function testImportOfInterfaceListFailsWithInvalidElement(): void
    {
        $helper = new Helper();

        $data = [
            'interfaceList' => [
                [
                    '_entityClass' => TestDTO::class,
                    'name'         => 'element1',
                ],
                [
                    '_entityClass' => ExportEntity::class,
                    'name'  => 'element2',
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Given '_entityClass' Vrok\ImportExport\Tests\Fixtures\ExportEntity is not a subclass/implementation of Vrok\ImportExport\Tests\Fixtures\DtoInterface!");

        $helper->fromArray($data, ImportEntity::class);
    }

    public function testImportOfNestedDtos(): void
    {
        $helper = new Helper();

        $data = [
            'interfaceList' => [
                [
                    '_entityClass'        => TestDTO::class,
                    'name'                => 'element1',
                    'nestedInterface'     => [
                        '_entityClass' => NestedDTO::class,
                        'description'  => 'element a',
                        'mixedProp'    => 111,
                    ],
                    'nestedInterfaceList' => [
                        [
                            '_entityClass' => NestedDTO::class,
                            'description'  => 'element b',
                            'mixedProp'    => 'string',
                        ],
                        [
                            '_entityClass' => NestedDTO::class,
                            'description'  => 'element c',
                        ],
                        [
                            '_entityClass' => TestDTO::class,
                            'name'         => 'element d',
                        ],
                    ],
                ],
            ],
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertCount(1, $instance->interfaceList);

        $element1 = $instance->interfaceList[0];
        self::assertInstanceOf(TestDTO::class, $element1);
        self::assertSame('element1', $element1->name);

        self::assertInstanceOf(NestedDTO::class, $element1->nestedInterface);
        self::assertSame('element a', $element1->nestedInterface->description);
        self::assertSame(111, $element1->nestedInterface->mixedProp);

        self::assertCount(3, $element1->nestedInterfaceList);

        self::assertInstanceOf(NestedDTO::class, $element1->nestedInterfaceList[0]);
        self::assertSame('element b', $element1->nestedInterfaceList[0]->description);
        self::assertSame('string', $element1->nestedInterfaceList[0]->mixedProp);

        self::assertInstanceOf(NestedDTO::class, $element1->nestedInterfaceList[1]);
        self::assertSame('element c', $element1->nestedInterfaceList[1]->description);

        self::assertInstanceOf(TestDTO::class, $element1->nestedInterfaceList[2]);
        self::assertSame('element d', $element1->nestedInterfaceList[2]->name);
    }

    public function testImportOfEmptyList(): void
    {
        $helper = new Helper();

        $data = [
            'dtoList' => [],
        ];

        $instance = $helper->fromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertCount(0, $instance->dtoList);
    }

    public function testImportOfNullListFails(): void
    {
        $helper = new Helper();

        $data = [
            'dtoList' => null,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Found NULL for Vrok\ImportExport\Tests\Fixtures\ImportEntity::dtoList, but property is not nullable!");

        $helper->fromArray($data, ImportEntity::class);
    }

    public function testImportOfListWithoutArrayFails(): void
    {
        $helper = new Helper();

        $data = [
            'dtoList' => 'string',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Property Vrok\ImportExport\Tests\Fixtures\ImportEntity::dtoList is marked as list of 'Vrok\ImportExport\Tests\Fixtures\TestDTO' but it is no array: \"string\"!");
        $helper->fromArray($data, ImportEntity::class);
    }

    public function testImportOfListWithInvalidEntryFails(): void
    {
        $helper = new Helper();

        $data = [
            'dtoList' => [
                'string',
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Property Vrok\ImportExport\Tests\Fixtures\ImportEntity::dtoList is marked as list of 'Vrok\ImportExport\Tests\Fixtures\TestDTO' but entry is no array: \"string\"!");
        $helper->fromArray($data, ImportEntity::class);
    }

    public function testImportOfInterfaceListFailsWithoutEntityClass(): void
    {
        $helper = new Helper();

        $data = [
            'interfaceList' => [
                [
                    'description' => 'element1',
                    'mixedProp'   => 'string',
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create instance of the interface Vrok\ImportExport\Tests\Fixtures\DtoInterface, concrete class needed!');

        $helper->fromArray($data, ImportEntity::class);
    }

    public function testThrowsExceptionWithoutClassname(): void
    {
        $helper = new Helper();

        $data = [
            'name' => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $helper->fromArray($data);
    }

    public function testThrowsExceptionWithUnknownClass(): void
    {
        $helper = new Helper();

        $data = [
            'name' => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Class Fake\Class does not exist!');

        $helper->fromArray($data, 'Fake\Class');
    }

    public function testThrowsExceptionWithAbstractClass(): void
    {
        $helper = new Helper();

        $data = [
            'name' => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create instance of the abstract class Vrok\ImportExport\Tests\AbstractOrmTestCase, concrete class needed!');

        $helper->fromArray($data, AbstractOrmTestCase::class);
    }

    public function testThrowsExceptionWithAbstractEntityClass(): void
    {
        $helper = new Helper();

        $data = [
            '_entityClass' => AbstractOrmTestCase::class,
            'name'         => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create instance of the abstract class Vrok\ImportExport\Tests\AbstractOrmTestCase, concrete class needed!');

        $helper->fromArray($data);
    }

    public function testThrowsExceptionWithInvalidChildClass(): void
    {
        $helper = new Helper();

        $data = [
            '_entityClass' => NestedDTO::class,
            'name'         => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Given '_entityClass' Vrok\ImportExport\Tests\Fixtures\NestedDTO is not a subclass/implementation of Vrok\ImportExport\Tests\Fixtures\ImportEntity!");

        $helper->fromArray($data, ImportEntity::class);
    }

    public function testThrowsExceptionWithoutReferenceClassname(): void
    {
        $helper = new Helper();

        $data = [
            'collection' => [
                [
                    'name' => 'element1',
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $helper->fromArray($data, ImportEntity::class);
    }

    public function testThrowsExceptionForUnannotatedReference(): void
    {
        $helper = new Helper();

        $data = [
            'otherReference' => ['test'],
        ];

        $this->expectException(\RuntimeException::class);
        $helper->fromArray($data);
    }

    public function testThrowsExceptionWithoutObjectManager(): void
    {
        $em = $this->buildEntityManager();
        $this->setupSchema();

        $parent = new ImportEntity();
        $parent->setName('parent');
        $em->persist($parent);
        $em->flush();
        $em->clear();

        $data = [
            'parent' => $parent->getName(),
        ];

        // no objectManager set -> exception when referencing by identifier
        $helper = new Helper();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('objectManager is not set to find object!');
        $helper->fromArray($data, ImportEntity::class);
    }

    public function testThrowsExceptionForAmbiguousUnionType(): void
    {
        $data = [
            'union' => $this,
        ];

        // no objectManager set -> exception when referencing by identifier
        $helper = new Helper();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot import object, found ambiguous union type');
        $helper->fromArray($data, ImportEntity::class);
    }
}
