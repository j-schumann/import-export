<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Vrok\ImportExport\Tests;

use Vrok\ImportExport\ImportHelper;
use Vrok\ImportExport\Tests\Fixtures\AbstractImportEntity;
use Vrok\ImportExport\Tests\Fixtures\AutoincrementEntity;
use Vrok\ImportExport\Tests\Fixtures\ExportEntity;
use Vrok\ImportExport\Tests\Fixtures\ImportEntity;
use Vrok\ImportExport\Tests\Fixtures\NestedDTO;
use Vrok\ImportExport\Tests\Fixtures\TestDTO;

class ImportHelperTest extends AbstractOrmTestCase
{
    // region objectFromArray
    public function testImportWithSetter(): void
    {
        $helper = new ImportHelper();

        $data = [
            'name'      => 'test',

            // will be ignored and throws no error
            'something' => 'else',
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertSame('test via setter', $instance->getName());

        self::assertCount(0, $instance->getCollection());
        self::assertNull($instance->getParent());
        self::assertNull($instance->timestamp);
    }

    public function testImportOfDatetime(): void
    {
        $helper = new ImportHelper();

        $data = [
            'timestamp' => 'tomorrow',
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertInstanceOf(\DateTimeImmutable::class, $instance->timestamp);

        $now = new \DateTimeImmutable();
        self::assertGreaterThan($now, $instance->timestamp);
    }

    public function testImportOfNull(): void
    {
        $helper = new ImportHelper();

        $data = [
            'name'      => null,
            'parent'    => null,
            'timestamp' => null,
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);
        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertNull($instance->getParent());
        self::assertNull($instance->getName());
        self::assertNull($instance->timestamp);
    }

    public function testImportIgnoresUnannotatedProperties(): void
    {
        $helper = new ImportHelper();

        $data = [
            'name'        => 'test',
            'notImported' => 'fail!',
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertSame('test via setter', $instance->getName());
        self::assertSame('initial', $instance->notImported);
    }

    public function testImportUntypedProperty(): void
    {
        $helper = new ImportHelper();

        $data = [
            'untypedProp' => 77,
        ];
        $instance = $helper->objectFromArray($data, ImportEntity::class);
        self::assertSame(77, $instance->untypedProp);

        $data = [
            'untypedProp' => '66',
        ];
        $instance = $helper->objectFromArray($data, ImportEntity::class);
        self::assertSame('66', $instance->untypedProp);

        $data = [
            'untypedProp' => [1, 2, 3],
        ];
        $instance = $helper->objectFromArray($data, ImportEntity::class);
        self::assertSame([1, 2, 3], $instance->untypedProp);

        $data = [
            'untypedProp' => [
                '_entityClass' => TestDTO::class,
                'name'         => 'Number 4',
            ],
        ];
        $instance = $helper->objectFromArray($data, ImportEntity::class);
        self::assertInstanceOf(TestDTO::class, $instance->untypedProp);
        self::assertSame('Number 4', $instance->untypedProp->name);

        $dto = new TestDTO();
        $dto->name = 'Number 5';
        $data = [
            'untypedProp' => $dto,
        ];
        $instance = $helper->objectFromArray($data, ImportEntity::class);
        self::assertInstanceOf(TestDTO::class, $instance->untypedProp);
        self::assertSame('Number 5', $instance->untypedProp->name);

        $data = [
            'untypedProp' => null,
        ];
        $instance = $helper->objectFromArray($data, ImportEntity::class);
        self::assertNull($instance->untypedProp);
    }

    public function testImportOfNestedRecordAsArray(): void
    {
        $helper = new ImportHelper();

        $data = [
            'parent' => [
                'name' => 'parentEntity',
            ],
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertInstanceOf(ImportEntity::class, $instance->getParent());
        self::assertSame('parentEntity via setter', $instance->getParent()->getName());
    }

    public function testImportOfNestedRecordAsInstance(): void
    {
        $helper = new ImportHelper();

        $parent = new ImportEntity();
        $parent->setName('parent');

        $data = [
            'parent' => $parent,
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertInstanceOf(ImportEntity::class, $instance->getParent());
        self::assertSame('parent via setter', $instance->getParent()->getName());

        self::assertSame('', $instance->getName());
        self::assertCount(0, $instance->getCollection());
        self::assertNull($instance->timestamp);
    }

    public function testImportOfNestedRecordAsReference(): void
    {
        // region prepare parent
        $em = $this->buildEntityManager();
        $this->setupSchema();

        $helper = new ImportHelper();
        $helper->setObjectManager($em);

        // even though the AbstractImportEntity only allows for ImportEntity
        // as $parent, the identity mapping works, as all subclasses of
        // AbstractImportEntity are also mapped:
        $helper->setIdentityMappingClasses([
            AbstractImportEntity::class,
        ]);

        // we not just simply create the entity, its identifier must be in
        // the identity map to test the mapping
        $parent = $helper->objectFromArray([
            'id'        => 9_999_999,
            'name'      => 'parent',
            'timestamp' => 'yesterday',
        ], ImportEntity::class);

        self::assertNotSame(9_999_999, $parent->id);
        // endregion

        $data = [
            'id'     => 9_999_998,
            'name'   => 'child',
            'parent' => 9_999_999,
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertNotSame(9_999_998, $instance->id);

        self::assertInstanceOf(ImportEntity::class, $instance->getParent());
        self::assertSame('parent via setter', $instance->getParent()->getName());
        self::assertsame($parent->id, $instance->getParent()->id);
        self::assertSame(
            $parent->timestamp->format(\DATE_ATOM),
            $instance->getParent()->timestamp->format(\DATE_ATOM)
        );

        self::assertSame('child via setter', $instance->getName());
        self::assertCount(0, $instance->getCollection());
        self::assertNull($instance->timestamp);
    }

    public function testImportOfNestedCollection(): void
    {
        $helper = new ImportHelper();

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

        $instance = $helper->objectFromArray($data, ImportEntity::class);

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

    public function testImportOfNestedCollectionWithInstances(): void
    {
        $helper = new ImportHelper();

        $element1 = new ImportEntity();
        $element1->setName('element1');

        $element2 = new ImportEntity();
        $element2->setName('element2');

        $data = [
            'collection' => [$element1, $element2],
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);

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

    public function testImportOfNestedCollectionWithReferences(): void
    {
        $em = $this->buildEntityManager();
        $this->setupSchema();

        $helper = new ImportHelper();
        $helper->setObjectManager($em);

        $element1 = new ImportEntity();
        $element1->setName('element1');
        $element1->timestamp = new \DateTimeImmutable('yesterday');
        $em->persist($element1);

        $element2 = new ImportEntity();
        $element2->setName('element2');
        $element2->timestamp = new \DateTimeImmutable('tomorrow');
        $em->persist($element2);

        $em->flush();
        $em->clear();

        $data = [
            'collection' => [
                $element1->id,
                $element2->id,
            ],
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertCount(2, $instance->getCollection());

        $collectionElement1 = $instance->getCollection()[0];
        self::assertInstanceOf(ImportEntity::class, $collectionElement1);
        self::assertSame('element1 via setter', $collectionElement1->getName());
        self::assertSame(
            $element1->timestamp->format(\DATE_ATOM),
            $collectionElement1->timestamp->format(\DATE_ATOM)
        );

        $collectionElement2 = $instance->getCollection()[1];
        self::assertInstanceOf(ImportEntity::class, $collectionElement2);
        self::assertSame('element2 via setter', $collectionElement2->getName());
        self::assertSame(
            $element2->timestamp->format(\DATE_ATOM),
            $collectionElement2->timestamp->format(\DATE_ATOM)
        );

        self::assertSame('', $instance->getName());
        self::assertNull($instance->getParent());
        self::assertNull($instance->timestamp);
    }

    public function testImportOfDtoList(): void
    {
        $helper = new ImportHelper();

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

        $instance = $helper->objectFromArray($data, ImportEntity::class);

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
        $helper = new ImportHelper();

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

        $helper->objectFromArray($data, ImportEntity::class);
    }

    public function testImportOfInterfaceList(): void
    {
        $helper = new ImportHelper();

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

        $instance = $helper->objectFromArray($data, ImportEntity::class);

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
        $helper = new ImportHelper();

        $data = [
            'interfaceList' => [
                [
                    '_entityClass' => TestDTO::class,
                    'name'         => 'element1',
                ],
                [
                    '_entityClass' => ExportEntity::class,
                    'name'         => 'element2',
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Given '_entityClass' Vrok\ImportExport\Tests\Fixtures\ExportEntity is not a subclass/implementation of Vrok\ImportExport\Tests\Fixtures\DtoInterface!");

        $helper->objectFromArray($data, ImportEntity::class);
    }

    public function testImportOfNestedDtos(): void
    {
        $helper = new ImportHelper();

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

        $instance = $helper->objectFromArray($data, ImportEntity::class);

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
        $helper = new ImportHelper();

        $data = [
            'dtoList' => [],
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertCount(0, $instance->dtoList);
    }

    public function testImportOfNullListFails(): void
    {
        $helper = new ImportHelper();

        $data = [
            'dtoList' => null,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Found NULL for Vrok\ImportExport\Tests\Fixtures\ImportEntity::dtoList, but property is not nullable!");

        $helper->objectFromArray($data, ImportEntity::class);
    }

    public function testImportOfListWithoutArrayFails(): void
    {
        $helper = new ImportHelper();

        $data = [
            'dtoList' => 'string',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("dtoList is marked as list of 'Vrok\ImportExport\Tests\Fixtures\TestDTO' but it is no array: \"string\"!");
        $helper->objectFromArray($data, ImportEntity::class);
    }

    public function testImportNestedListWithMixedEntries(): void
    {
        $helper = new ImportHelper();

        $data = [
            'dtoList' => [
                // empty array is valid as "listOf" specifies an importable class
                [],

                // int, float, string are allowed as the export of mixed lists
                // is possible
                12,
                'string',
                3.14,
            ],
        ];

        $instance = $helper->objectFromArray($data, ImportEntity::class);
        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertCount(4, $instance->dtoList);
        self::assertInstanceOf(TestDTO::class, $instance->dtoList[0]);
        self::assertSame(12, $instance->dtoList[1]);
        self::assertSame('string', $instance->dtoList[2]);
        self::assertSame(3.14, $instance->dtoList[3]);
    }

    public function testImportNestedListFailsWithInvalidObject(): void
    {
        $helper = new ImportHelper();

        $data = [
            'dtoList' => [
                $this,
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("dtoList is marked as list of 'Vrok\ImportExport\Tests\Fixtures\TestDTO'");
        $helper->objectFromArray($data, ImportEntity::class);
    }

    public function testImportOfInterfaceListFailsWithoutEntityClass(): void
    {
        $helper = new ImportHelper();

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

        $helper->objectFromArray($data, ImportEntity::class);
    }

    public function testImportWithIncludeFilter(): void
    {
        $helper = new ImportHelper();

        $data = [
            'name'          => 'test',
            'timestamp'     => 'tomorrow',
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

        $instance = $helper->objectFromArray(
            $data,
            ImportEntity::class,
            [
                'timestamp',
                'interfaceList',
                'interfaceList' => [
                    'nestedInterface', 'nestedInterface' => ['mixedProp'],
                ],
            ]
        );

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertSame('', $instance->getName());
        self::assertInstanceOf(\DateTimeImmutable::class, $instance->timestamp);
        self::assertCount(1, $instance->interfaceList);

        $element1 = $instance->interfaceList[0];
        self::assertInstanceOf(TestDTO::class, $element1);
        self::assertSame('', $element1->name);

        self::assertInstanceOf(NestedDTO::class, $element1->nestedInterface);
        self::assertSame('', $element1->nestedInterface->description);
        self::assertSame(111, $element1->nestedInterface->mixedProp);

        self::assertCount(0, $element1->nestedInterfaceList);
    }

    public function testImportWithExcludeFilter(): void
    {
        $helper = new ImportHelper();

        $data = [
            'name'          => 'test',
            'timestamp'     => 'tomorrow',
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

        $instance = $helper->objectFromArray(
            $data,
            ImportEntity::class,
            [
                'name',
                'interfaceList' => [
                    'name',
                    'nestedInterface' => ['description'],
                ],
            ],
            true
        );

        self::assertInstanceOf(ImportEntity::class, $instance);
        self::assertSame('', $instance->getName());
        self::assertInstanceOf(\DateTimeImmutable::class, $instance->timestamp);
        self::assertCount(1, $instance->interfaceList);

        $element1 = $instance->interfaceList[0];
        self::assertInstanceOf(TestDTO::class, $element1);
        self::assertSame('', $element1->name);

        self::assertInstanceOf(NestedDTO::class, $element1->nestedInterface);
        self::assertSame('', $element1->nestedInterface->description);
        self::assertSame(111, $element1->nestedInterface->mixedProp);

        self::assertCount(3, $element1->nestedInterfaceList);
    }
    // endregion

    // region Exceptions from objectFromArray
    public function testImportThrowsExceptionWithoutClassname(): void
    {
        $helper = new ImportHelper();

        $data = [
            'name' => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $helper->objectFromArray($data);
    }

    public function testImportThrowsExceptionWithUnknownClass(): void
    {
        $helper = new ImportHelper();

        $data = [
            'name' => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Class Fake\Class does not exist!');

        $helper->objectFromArray($data, 'Fake\Class');
    }

    public function testImportThrowsExceptionWithAbstractClass(): void
    {
        $helper = new ImportHelper();

        $data = [
            'name' => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create instance of the abstract class Vrok\ImportExport\Tests\AbstractOrmTestCase, concrete class needed!');

        $helper->objectFromArray($data, AbstractOrmTestCase::class);
    }

    public function testImportThrowsExceptionWithAbstractEntityClass(): void
    {
        $helper = new ImportHelper();

        $data = [
            '_entityClass' => AbstractOrmTestCase::class,
            'name'         => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create instance of the abstract class Vrok\ImportExport\Tests\AbstractOrmTestCase, concrete class needed!');

        $helper->objectFromArray($data);
    }

    public function testImportThrowsExceptionWithInvalidChildClass(): void
    {
        $helper = new ImportHelper();

        $data = [
            '_entityClass' => NestedDTO::class,
            'name'         => 'test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Given '_entityClass' Vrok\ImportExport\Tests\Fixtures\NestedDTO is not a subclass/implementation of Vrok\ImportExport\Tests\Fixtures\ImportEntity!");

        $helper->objectFromArray($data, ImportEntity::class);
    }

    public function testImportThrowsExceptionWithoutReferenceClassname(): void
    {
        $helper = new ImportHelper();

        $data = [
            'collection' => [
                [
                    'name' => 'element1',
                ],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No entityClass given to instantiate the data');
        $helper->objectFromArray($data, ExportEntity::class);
    }

    public function testImportThrowsExceptionForUnannotatedReference(): void
    {
        $helper = new ImportHelper();

        $data = [
            'otherReference' => ['test'],
        ];

        $this->expectException(\RuntimeException::class);
        $helper->objectFromArray($data);
    }

    public function testImportThrowsExceptionForInvalidReference(): void
    {
        $em = $this->buildEntityManager();
        $this->setupSchema();

        $helper = new ImportHelper();
        $helper->setObjectManager($em);

        $data = [
            'collection' => [
                'does not exist',
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find referenced & mapped record');

        $helper->objectFromArray($data, ImportEntity::class);
    }

    public function testImportThrowsExceptionWithoutObjectManager(): void
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
        $helper = new ImportHelper();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('objectManager is not set to find object!');
        $helper->objectFromArray($data, ImportEntity::class);
    }

    public function testImportThrowsExceptionForAmbiguousUnionType(): void
    {
        $data = [
            'union' => $this,
        ];

        // no objectManager set -> exception when referencing by identifier
        $helper = new ImportHelper();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot import object, found ambiguous union type');
        $helper->objectFromArray($data, ImportEntity::class);
    }
    // endregion

    // region collectionFromArray
    public function testCollectionFromArrayWithoutEntityClass(): void
    {
        $helper = new ImportHelper();

        $data = [
            ['name' => 'e1'],
            ['name' => 'e2'],
        ];

        $collection = $helper->collectionFromArray($data, AutoincrementEntity::class);

        self::assertCount(2, $collection);
        self::assertInstanceOf(AutoincrementEntity::class, $collection[0]);
        self::assertSame('e1', $collection[0]->name);
        self::assertInstanceOf(AutoincrementEntity::class, $collection[1]);
        self::assertSame('e2', $collection[1]->name);
    }

    public function testCollectionFromArrayAllowsMixedTypes(): void
    {
        $helper = new ImportHelper();

        $element2 = new AutoincrementEntity();
        $element2->name = 'e2';

        $data = [
            ['name' => 'e1'],
            $element2,
        ];

        $collection = $helper->collectionFromArray($data, AutoincrementEntity::class);

        self::assertCount(2, $collection);
        self::assertInstanceOf(AutoincrementEntity::class, $collection[0]);
        self::assertSame('e1', $collection[0]->name);
        self::assertInstanceOf(AutoincrementEntity::class, $collection[1]);
        self::assertSame('e2', $collection[1]->name);
    }

    public function testCollectionFromArrayRejectsInvalidObjects(): void
    {
        $helper = new ImportHelper();

        $data = [
            ['name' => 'e1'],
            new \DateTimeImmutable(),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Collection should be instances of');
        $helper->collectionFromArray($data, AutoincrementEntity::class);
    }

    public function testCollectionFromArrayRejectsInvalidTypes(): void
    {
        $helper = new ImportHelper();

        $data = [
            ['name' => 'e1'],
            3.14,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Don't know how to import collection element");
        $helper->collectionFromArray($data, AutoincrementEntity::class);
    }
    // endregion

    // region importEntityCollection
    public function testImportEntityCollection(): void
    {
        $em = $this->buildEntityManager();
        $this->setupSchema();

        $helper = new ImportHelper();
        $helper->setObjectManager($em);

        $data = [
            ['name' => 'e1'],
            ['name' => 'e2'],
        ];

        $helper->importEntityCollection($data, AutoincrementEntity::class);

        $collection = $em->getRepository(AutoincrementEntity::class)->findAll();

        self::assertCount(2, $collection);
        self::assertInstanceOf(AutoincrementEntity::class, $collection[0]);
        self::assertSame('e1', $collection[0]->name);
        self::assertInstanceOf(AutoincrementEntity::class, $collection[1]);
        self::assertSame('e2', $collection[1]->name);
    }

    public function testImportEntityCollectionRejectsNonArrays(): void
    {
        $em = $this->buildEntityManager();
        $this->setupSchema();

        $helper = new ImportHelper();
        $helper->setObjectManager($em);

        $data = [
            ['name' => 'e1'],
            $this,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Collection element must be the array representation');
        $helper->importEntityCollection($data, AutoincrementEntity::class);
    }

    public function testImportEntityCollectionRequiresObjectManager(): void
    {
        $helper = new ImportHelper();

        $data = [
            ['name' => 'e1'],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ObjectManager must be set first!');
        $helper->importEntityCollection($data, AutoincrementEntity::class);
    }
    // endregion

    // region IdentityMapping
    public function testSetIdentityMappingClassesRequiresObjectManager(): void
    {
        $helper = new ImportHelper();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Object manager must be set to use identity mapping!');
        $helper->setIdentityMappingClasses([ImportEntity::class]);
    }

    public function testImportWithIdentityMapping(): void
    {
        $em = $this->buildEntityManager();
        $this->setupSchema();

        $helper = new ImportHelper();
        $helper->setObjectManager($em);
        $helper->setIdentityMappingClasses([
            AutoincrementEntity::class,
        ]);

        self::assertSame([], $helper->getIdentityMap());

        $data = [
            [
                'id'   => 99999,
                'name' => 'e1',
            ],
            [
                'id'     => 77777,
                'name'   => 'e2',
                'parent' => 99999,
            ],
        ];

        $collection = $helper->collectionFromArray($data, AutoincrementEntity::class);

        self::assertCount(2, $collection);

        self::assertSame('e1', $collection[0]->name);
        self::assertNotSame(99999, $collection[0]->id);

        self::assertSame('e2', $collection[1]->name);
        self::assertNotSame(77777, $collection[1]->id);
        self::assertInstanceOf(AutoincrementEntity::class, $collection[1]->parent);
        self::assertSame('e1', $collection[1]->parent->name);

        self::assertSame(
            [
                AutoincrementEntity::class => [
                    99999 => 1,
                    77777 => 2,
                ],
            ],
            $helper->getIdentityMap());
    }

    public function testImportThrowsWithNonMappedIdentity(): void
    {
        $em = $this->buildEntityManager();
        $this->setupSchema();

        $helper = new ImportHelper();
        $helper->setObjectManager($em);
        $helper->setIdentityMappingClasses([
            AutoincrementEntity::class,
        ]);

        $data = [
            [
                'id'     => 77777,
                'name'   => 'e2',
                'parent' => 99999,
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ID for referenced record');
        $helper->collectionFromArray($data, AutoincrementEntity::class);
    }
    // endregion
}
