<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Vrok\ImportExport\Tests;

use PHPUnit\Framework\TestCase;
use Vrok\ImportExport\ReflectionHelper;
use Vrok\ImportExport\Tests\Fixtures\ChildDTO;
use Vrok\ImportExport\Tests\Fixtures\ExportEntity;
use Vrok\ImportExport\Tests\Fixtures\NestedDTO;
use Vrok\ImportExport\Tests\Fixtures\RepeatableAttribute;
use Vrok\ImportExport\Tests\Fixtures\TestDTO;

final class ReflectionHelperTest extends TestCase
{
    public function testGetClassAttributeFindsNone(): void
    {
        $result = ReflectionHelper::getClassAttribute(
            ExportEntity::class,
            RepeatableAttribute::class
        );

        self::assertNull($result);
    }

    public function testGetClassAttributeFindsSingle(): void
    {
        $single = ReflectionHelper::getClassAttribute(
            NestedDTO::class,
            RepeatableAttribute::class
        );

        self::assertInstanceOf(RepeatableAttribute::class, $single);
        self::assertSame(2, $single->value);
    }

    public function testGetClassAttributeFindsMultiple(): void
    {
        $result = ReflectionHelper::getClassAttribute(
            TestDTO::class,
            RepeatableAttribute::class
        );

        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertInstanceOf(RepeatableAttribute::class, $result[0]);
        self::assertSame(0, $result[0]->value);
        self::assertInstanceOf(RepeatableAttribute::class, $result[1]);
        self::assertSame(1, $result[1]->value);
    }

    public function testGetClassAttributeWorksWithParentClass(): void
    {
        $result = ReflectionHelper::getClassAttribute(
            ChildDTO::class,
            RepeatableAttribute::class
        );

        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertInstanceOf(RepeatableAttribute::class, $result[0]);
        self::assertSame(0, $result[0]->value);
        self::assertInstanceOf(RepeatableAttribute::class, $result[1]);
        self::assertSame(1, $result[1]->value);

        $result2 = ReflectionHelper::getClassAttribute(
            ChildDTO::class,
            RepeatableAttribute::class,
            false
        );

        self::assertNull($result2);
    }

    public function testGetClassAttributeThrowsWithNonExistentAttribClass(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Attribute class "DoesNotExist" not found');
        ReflectionHelper::getClassAttribute(
            TestDTO::class,
            'DoesNotExist'
        );
    }

    public function testGetPropertyAttributeFindsNone(): void
    {
        $reflection = new \ReflectionClass(TestDTO::class);
        $property = $reflection->getProperty('nestedInterface');

        $result = ReflectionHelper::getPropertyAttribute(
            $property,
            RepeatableAttribute::class
        );

        self::assertNull($result);
    }

    public function testGetPropertyAttributeFindsSingle(): void
    {
        $reflection = new \ReflectionClass(NestedDTO::class);
        $property = $reflection->getProperty('description');

        $single = ReflectionHelper::getPropertyAttribute(
            $property,
            RepeatableAttribute::class
        );

        self::assertInstanceOf(RepeatableAttribute::class, $single);
        self::assertSame(2, $single->value);
    }

    public function testGetPropertyAttributeFindsMultiple(): void
    {
        $reflection = new \ReflectionClass(TestDTO::class);
        $property = $reflection->getProperty('name');

        $result = ReflectionHelper::getPropertyAttribute(
            $property,
            RepeatableAttribute::class
        );

        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertInstanceOf(RepeatableAttribute::class, $result[0]);
        self::assertSame(0, $result[0]->value);
        self::assertInstanceOf(RepeatableAttribute::class, $result[1]);
        self::assertSame(1, $result[1]->value);
    }

    public function testGetPropertyAttributeWorksWithParentClass(): void
    {
        $reflection = new \ReflectionClass(ChildDTO::class);
        $property = $reflection->getProperty('name');

        $result = ReflectionHelper::getPropertyAttribute(
            $property,
            RepeatableAttribute::class
        );

        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertInstanceOf(RepeatableAttribute::class, $result[0]);
        self::assertSame(0, $result[0]->value);
        self::assertInstanceOf(RepeatableAttribute::class, $result[1]);
        self::assertSame(1, $result[1]->value);
    }

    public function testGetPropertyAttributeThrowsWithNonExistentAttribClass(): void
    {
        $reflection = new \ReflectionClass(TestDTO::class);
        $property = $reflection->getProperty('name');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Attribute class "DoesNotExist" not found');
        ReflectionHelper::getPropertyAttribute(
            $property,
            'DoesNotExist'
        );
    }
}
