<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Vrok\ImportExport\Tests;

use PHPUnit\Framework\TestCase;
use Vrok\ImportExport\Helper;
use Vrok\ImportExport\Tests\Fixtures\ExportEntity;
use Vrok\ImportExport\Tests\Fixtures\ImportEntity;
use Vrok\ImportExport\Tests\Fixtures\NestedDTO;
use Vrok\ImportExport\Tests\Fixtures\TestDTO;

class ExportTest extends TestCase
{
    public function testExportWithGetter(): void
    {
        $helper = new Helper();

        $entity = new ExportEntity();
        $entity->id = 1;
        $entity->setName('test');

        $data = $helper->toArray($entity);

        self::assertSame([
            'id'            => 1,
            'name'          => 'test via getter',
            'collection'    => [],
            'refCollection' => [],
            'parent'        => null,
            'reference'     => null,
            'timestamp'     => null,
            'dtoList'       => [],
            'arrayProp'     => [],
            // notExported is NOT in the array
        ], $data);
    }

    public function testExportWithIncludeFilter(): void
    {
        $helper = new Helper();

        $entity = new ExportEntity();
        $entity->id = 1;
        $entity->setName('test');

        $data = $helper->toArray($entity, ['name', 'parent']);

        self::assertSame([
            'name'   => 'test via getter',
            'parent' => null,
        ], $data);
    }

    public function testExportWithExcludeFilter(): void
    {
        $helper = new Helper();

        $entity = new ExportEntity();
        $entity->id = 1;
        $entity->setName('test');

        $data = $helper->toArray(
            $entity,
            ['id', 'collection', 'refCollection'],
            true
        );

        self::assertSame([
            'name'      => 'test via getter',
            'parent'    => null,
            'reference' => null,
            'timestamp' => null,
            'dtoList'   => [],
            'arrayProp' => [],
        ], $data);
    }

    public function testExportDatetime(): void
    {
        $helper = new Helper();

        $now = new \DateTimeImmutable();
        $entity = new ExportEntity();
        $entity->timestamp = $now;

        $data = $helper->toArray($entity);
        self::assertSame($now->format(DATE_ATOM), $data['timestamp']);
    }

    public function testExportWithCollections(): void
    {
        $helper = new Helper();

        $element1 = new ExportEntity();
        $element1->id = 1;
        $element1->setName('element1');
        $element2 = new ExportEntity();
        $element2->id = 2;
        $element2->setName('element2');

        $refElement1 = new ExportEntity();
        $refElement1->id = 3;
        $refElement1->setName('refElement1');
        $refElement2 = new ExportEntity();
        $refElement2->id = 4;
        $refElement2->setName('refElement2');

        $entity = new ExportEntity();
        $entity->setCollection([$element1, $element2]);
        $entity->setRefCollection([$refElement1, $refElement2]);

        $data = $helper->toArray($entity);
        self::assertSame([
            'id'            => 0,
            'name'          => ' via getter',
            'collection'    => [
                [
                    'id'            => 1,
                    'name'          => 'element1 via getter',
                    'collection'    => [],
                    'refCollection' => [],
                    'parent'        => null,
                    'reference'     => null,
                    'timestamp'     => null,
                    'dtoList'       => [],
                    'arrayProp'     => [],
                    '_entityClass'  => ExportEntity::class,
                ],
                [
                    'id'            => 2,
                    'name'          => 'element2 via getter',
                    'collection'    => [],
                    'refCollection' => [],
                    'parent'        => null,
                    'reference'     => null,
                    'timestamp'     => null,
                    'dtoList'       => [],
                    'arrayProp'     => [],
                    '_entityClass'  => ExportEntity::class,
                ],
            ],
            'refCollection' => [3, 4],
            'parent'        => null,
            'reference'     => null,
            'timestamp'     => null,
            'dtoList'       => [],
            'arrayProp'     => [],
        ], $data);
    }

    public function testExportReferences(): void
    {
        $helper = new Helper();

        $parent = new ExportEntity();
        $parent->id = 1;
        $parent->setName('parent');

        $reference = new ExportEntity();
        $reference->id = 2;
        $reference->setName('reference');

        $entity = new ExportEntity();
        $entity->id = 3;
        $entity->setParent($parent);
        $entity->setReference($reference);

        $data = $helper->toArray($entity);
        self::assertSame([
            'id'            => 3,
            'name'          => ' via getter',
            'collection'    => [],
            'refCollection' => [],
            'parent'        => [
                'id'            => 1,
                'name'          => 'parent via getter',
                'collection'    => [],
                'refCollection' => [],
                'parent'        => null,
                'reference'     => null,
                'timestamp'     => null,
                'dtoList'       => [],
                'arrayProp'     => [],
                '_entityClass'  => ExportEntity::class,
            ],
            'reference'     => 'reference via getter',
            'timestamp'     => null,
            'dtoList'       => [],
            'arrayProp'     => [],
        ], $data);
    }

    public function testExportDtoList(): void
    {
        $helper = new Helper();

        $entity = new ExportEntity();
        $entity->id = 3;

        $dto1 = new TestDTO();
        $dto1->name = 'element 1';
        $entity->dtoList[] = $dto1;

        $dto2 = new TestDTO();
        $dto2->name = 'element 2';
        $entity->dtoList[] = $dto2;

        $data = $helper->toArray($entity);
        self::assertSame([
            'id'            => 3,
            'name'          => ' via getter',
            'collection'    => [],
            'refCollection' => [],
            'parent'        => null,
            'reference'     => null,
            'timestamp'     => null,
            'dtoList'       => [
                0 => [
                    'name'                => 'element 1',
                    'nestedInterface'     => null,
                    'nestedInterfaceList' => [],
                    '_entityClass'        => TestDTO::class,
                ],
                1 => [
                    'name'                => 'element 2',
                    'nestedInterface'     => null,
                    'nestedInterfaceList' => [],
                    '_entityClass'        => TestDTO::class,
                ],
            ],
            'arrayProp'     => [],
        ], $data);
    }

    public function testExportMixedList(): void
    {
        $helper = new Helper();

        $entity = new ExportEntity();
        $entity->id = 3;

        $dto1 = new TestDTO();
        $dto1->name = 'element 1';
        $entity->dtoList[] = $dto1;

        $entity->dtoList[] = 'string';

        $data = $helper->toArray($entity);
        self::assertSame(3, $data['id']);
        self::assertCount(2, $data['dtoList']);
        self::assertSame([
            'name'                => 'element 1',
            'nestedInterface'     => null,
            'nestedInterfaceList' => [],
            '_entityClass'        => TestDTO::class,
        ], $data['dtoList'][0]);

        self::assertSame('string', $data['dtoList'][1]);
    }

    public function testExportNestedDtos(): void
    {
        $helper = new Helper();

        $entity = new ExportEntity();
        $entity->id = 3;

        $baseDto = new TestDTO();
        $baseDto->name = 'element 1';

        $nestedDto1 = new NestedDTO();
        $nestedDto1->description = 'element a';
        $baseDto->nestedInterface = $nestedDto1;

        $nestedDto2 = new NestedDTO();
        $nestedDto2->description = 'element b';
        $nestedDto2->mixedProp = 'string';
        $baseDto->nestedInterfaceList[] = $nestedDto2;

        $nestedDto3 = new NestedDTO();
        $nestedDto3->description = 'element c';
        $nestedDto3->mixedProp = 999;
        $baseDto->nestedInterfaceList[] = $nestedDto3;

        $nestedDto4 = new TestDTO();
        $nestedDto4->name = 'element d';
        $baseDto->nestedInterfaceList[] = $nestedDto4;

        $entity->dtoList[] = $baseDto;

        $data = $helper->toArray($entity);
        self::assertSame(3, $data['id']);
        self::assertCount(1, $data['dtoList']);
        self::assertSame([
            'name'                => 'element 1',
            'nestedInterface'     => [
                'description'  => 'element a',
                'mixedProp'    => 0,
                '_entityClass' => NestedDTO::class,
            ],
            'nestedInterfaceList' => [
                [
                    'description'  => 'element b',
                    'mixedProp'    => 'string',
                    '_entityClass' => NestedDTO::class,
                ],
                [
                    'description'  => 'element c',
                    'mixedProp'    => 999,
                    '_entityClass' => NestedDTO::class,
                ],
                [
                    'name'                => 'element d',
                    'nestedInterface'     => null,
                    'nestedInterfaceList' => [],
                    '_entityClass'        => TestDTO::class,
                ],
            ],
            '_entityClass'        => TestDTO::class,
        ], $data['dtoList'][0]);
    }

    public function testExportWithNestedIncludeFilter(): void
    {
        $helper = new Helper();

        $entity = new ExportEntity();
        $entity->id = 3;

        $baseDto = new TestDTO();
        $baseDto->name = 'element 1';

        $nestedDto1 = new NestedDTO();
        $nestedDto1->description = 'element a';
        $baseDto->nestedInterface = $nestedDto1;

        $nestedDto2 = new NestedDTO();
        $nestedDto2->description = 'element b';
        $nestedDto2->mixedProp = 'string';
        $baseDto->nestedInterfaceList[] = $nestedDto2;

        $nestedDto3 = new NestedDTO();
        $nestedDto3->description = 'element c';
        $nestedDto3->mixedProp = 999;
        $baseDto->nestedInterfaceList[] = $nestedDto3;

        $nestedDto4 = new TestDTO();
        $nestedDto4->name = 'element d';
        $baseDto->nestedInterfaceList[] = $nestedDto4;

        $entity->dtoList[] = $baseDto;

        $data = $helper->toArray(
            $entity,
            ['id', 'dtoList', 'dtoList' => ['name', 'nestedInterface']],
        );

        self::assertSame([
            'id'      => 3,
            'dtoList' => [
                [
                    'name'            => 'element 1',
                    'nestedInterface' => [
                        'description'  => 'element a',
                        'mixedProp'    => 0,
                        '_entityClass' => 'Vrok\ImportExport\Tests\Fixtures\NestedDTO',
                    ],
                    '_entityClass'    => 'Vrok\ImportExport\Tests\Fixtures\TestDTO',
                ],
            ],
        ], $data);
    }

    public function testExportWithNestedExcludeFilter(): void
    {
        $helper = new Helper();

        $entity = new ExportEntity();
        $entity->id = 3;

        $baseDto = new TestDTO();
        $baseDto->name = 'element 1';

        $nestedDto1 = new NestedDTO();
        $nestedDto1->description = 'element a';
        $baseDto->nestedInterface = $nestedDto1;

        $nestedDto2 = new NestedDTO();
        $nestedDto2->description = 'element b';
        $nestedDto2->mixedProp = 'string';
        $baseDto->nestedInterfaceList[] = $nestedDto2;

        $nestedDto3 = new NestedDTO();
        $nestedDto3->description = 'element c';
        $nestedDto3->mixedProp = 999;
        $baseDto->nestedInterfaceList[] = $nestedDto3;

        $nestedDto4 = new TestDTO();
        $nestedDto4->name = 'element d';
        $baseDto->nestedInterfaceList[] = $nestedDto4;

        $entity->dtoList[] = $baseDto;

        $data = $helper->toArray(
            $entity,
            ['id', 'collection', 'refCollection', 'dtoList' => ['nestedInterface', 'nestedInterfaceList']],
            true
        );

        self::assertSame([
            'name'      => ' via getter',
            'parent'    => null,
            'reference' => null,
            'timestamp' => null,
            'dtoList'   => [
                [
                    'name'         => 'element 1',
                    '_entityClass' => 'Vrok\ImportExport\Tests\Fixtures\TestDTO',
                ],
            ],
            'arrayProp' => [],
        ], $data);
    }

    public function testThrowsExceptionWithNonExportableEntity(): void
    {
        $helper = new Helper();
        $entity = new ImportEntity();

        $this->expectException(\RuntimeException::class);
        $helper->toArray($entity);
    }

    public function testExportCollection(): void
    {
        $helper = new Helper();

        $element1 = new ExportEntity();
        $element1->id = 1;
        $element1->setName('element1');
        $element2 = new ExportEntity();
        $element2->id = 2;
        $element2->setName('element2');

        $data = $helper->exportCollection([$element1, $element2]);

        self::assertSame([
            [
                'id'            => 1,
                'name'          => 'element1 via getter',
                'collection'    => [],
                'refCollection' => [],
                'parent'        => null,
                'reference'     => null,
                'timestamp'     => null,
                'dtoList'       => [],
                'arrayProp'     => [],
                '_entityClass'  => ExportEntity::class,
            ],
            [
                'id'            => 2,
                'name'          => 'element2 via getter',
                'collection'    => [],
                'refCollection' => [],
                'parent'        => null,
                'reference'     => null,
                'timestamp'     => null,
                'dtoList'       => [],
                'arrayProp'     => [],
                '_entityClass'  => ExportEntity::class,
            ],
        ], $data);
    }

    public function testExportCollectionWithIncludeFilter(): void
    {
        $helper = new Helper();

        $element1 = new ExportEntity();
        $element1->id = 1;
        $element1->setName('element1');
        $element2 = new ExportEntity();
        $element2->id = 2;
        $element2->setName('element2');

        $data = $helper->exportCollection([$element1, $element2], ['id', 'name']);

        self::assertSame([
            [
                'id'           => 1,
                'name'         => 'element1 via getter',
                '_entityClass' => ExportEntity::class,
            ],
            [
                'id'           => 2,
                'name'         => 'element2 via getter',
                '_entityClass' => ExportEntity::class,
            ],
        ], $data);
    }

    public function testExportCollectionWithExcludeFilter(): void
    {
        $helper = new Helper();

        $element1 = new ExportEntity();
        $element1->id = 1;
        $element1->setName('element1');
        $element2 = new ExportEntity();
        $element2->id = 2;
        $element2->setName('element2');

        $data = $helper->exportCollection(
            [$element1, $element2],
            ['id', 'collection', 'refCollection'],
            true
        );

        self::assertSame([
            [
                'name'         => 'element1 via getter',
                'parent'       => null,
                'reference'    => null,
                'timestamp'    => null,
                'dtoList'      => [],
                'arrayProp'    => [],
                '_entityClass' => ExportEntity::class,
            ],
            [
                'name'         => 'element2 via getter',
                'parent'       => null,
                'reference'    => null,
                'timestamp'    => null,
                'dtoList'      => [],
                'arrayProp'    => [],
                '_entityClass' => ExportEntity::class,
            ],
        ], $data);
    }

    public function testExportCollectionWithSingleField(): void
    {
        $helper = new Helper();

        $element1 = new ExportEntity();
        $element1->id = 1;
        $element1->setName('element1');
        $element2 = new ExportEntity();
        $element2->id = 2;
        $element2->setName('element2');

        $data = $helper->exportCollection([$element1, $element2], ['id']);

        self::assertSame([1, 2], $data);
    }
}
