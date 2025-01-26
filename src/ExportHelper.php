<?php

declare(strict_types=1);

namespace Vrok\ImportExport;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Util\ClassUtils;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Helper to convert Doctrine entities or DTOs to arrays.
 * Uses Reflection to check for properties tagged #[ExportableProperty].
 * Uses Symfony's PropertyAccess to get properties using the correct
 * getters (which also supports hassers and issers).
 * Uses Doctrine's ClassUtils to handle proxies, as we need the correct class
 * name in "_entityClass" and they may prevent access to the property PHP
 * attributes.
 */
class ExportHelper
{
    // static caches to reduce Reflection calls when exporting multiple
    // objects of the same class
    protected static array $exportableProperties = [];

    protected PropertyAccessorInterface $propertyAccessor;

    public function __construct()
    {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor();
    }

    /**
     * Converts the given object to an array, converting referenced entities
     * and collections to arrays too. Datetime instances are returned as ATOM
     * strings.
     * Exports only properties that are marked with #[ExportableProperty]. If a
     * reference uses the referenceByIdentifier argument in the attribute, only
     * the value of the field named in that argument is returned.
     *
     * @param object $object          the object to export, must have at least
     *                                one ExportableProperty
     * @param array  $propertyFilter  Only properties with the given names are
     *                                returned, ignored if empty. May contain
     *                                definitions for sub-records by using the
     *                                property name as key and specifying an
     *                                array of ignored (sub) properties as value.
     * @param bool   $isExcludeFilter flips the meaning of the propertyFilter:
     *                                only properties that are *not* in the
     *                                list are returned, same for sub-records
     *
     * @throws \ReflectionException
     */
    public function objectToArray(
        object $object,
        array $propertyFilter = [],
        bool $isExcludeFilter = false,
    ): array {
        $className = ClassUtils::getClass($object);
        $properties = $this->getExportableProperties($className);
        if ([] === $properties) {
            throw new \RuntimeException("Don't know how to export instance of $className, it has no exportable properties!");
        }

        $data = [];
        /* @var ExportableProperty $attribute */
        foreach ($properties as $propertyName => $attribute) {
            // empty array also counts as "no filter applied"
            if ([] !== $propertyFilter && (
                (!in_array($propertyName, $propertyFilter, true) && !$isExcludeFilter)
                || (in_array($propertyName, $propertyFilter, true) && $isExcludeFilter)
            )
            ) {
                continue;
            }

            $propValue = $this->propertyAccessor->getValue($object, $propertyName);

            if (null === $propValue) {
                $data[$propertyName] = null;
            } elseif ($propValue instanceof \DateTimeInterface) {
                $data[$propertyName] = $propValue->format(DATE_ATOM);
            } elseif ($attribute->asList || $propValue instanceof Collection) {
                if ('' !== $attribute->referenceByIdentifier) {
                    $data[$propertyName] = $this->collectionToArray(
                        $propValue,
                        [$attribute->referenceByIdentifier]
                    );
                } else {
                    $data[$propertyName] = $this->collectionToArray(
                        $propValue,
                        $propertyFilter[$propertyName] ?? [],
                        $isExcludeFilter
                    );
                }
            } elseif (is_object($propValue) && $this->isExportableClass($propValue::class)) {
                if ('' !== $attribute->referenceByIdentifier) {
                    $identifier = $this->objectToArray(
                        $propValue,
                        [$attribute->referenceByIdentifier]
                    );
                    $data[$propertyName] = $identifier[$attribute->referenceByIdentifier];
                } else {
                    $data[$propertyName] = $this->objectToArray(
                        $propValue,
                        $propertyFilter[$propertyName] ?? [],
                        $isExcludeFilter
                    );

                    // We always store the classname, even when only one class
                    // is currently possible, to be future-proof in case a
                    // property can accept an interface / parent class later.
                    // This is also consistent with the handling of collections,
                    // which also always store the _entityClass.
                    $data[$propertyName]['_entityClass'] = ClassUtils::getClass($propValue);
                }
            } elseif (
                // Keep base types as-is. This can/will cause errors if an array
                // contains objects. Lists of DTOs should be marked with
                // 'asList' on the ExportableProperty attribute.
                is_array($propValue)
                || is_int($propValue)
                || is_float($propValue)
                || is_bool($propValue)
                || is_string($propValue)
            ) {
                $data[$propertyName] = $propValue;
            } else {
                throw new \RuntimeException("Don't know how to export $className::$propertyName, it is an object without exportable properties!");
            }
        }

        return $data;
    }

    /**
     * Allows to export a list of elements at once. Used by objectToArray() if
     * it finds a property that is a Collection/list. Can also be used to export
     * Doctrine collections, e.g. of a complete table.
     *
     * The propertyFilter is applied to each collection element, see
     * objectToArray(). If it contains no value it is ignored. If it contains
     * exactly one value this is interpreted as name of an identifier property
     * (e.g. "id"), so only a list of identifiers is returned.
     */
    public function collectionToArray(
        Collection|array $collection,
        array $propertyFilter = [],
        bool $isExcludeFilter = false,
    ): array {
        // If the propertyFilter contains only one element we assume that this
        // is the identifier that is to be exported, instead of the whole
        // entity.
        $referenceByIdentifier = false;
        if (!$isExcludeFilter && 1 === count($propertyFilter)) {
            $referenceByIdentifier = array_values($propertyFilter)[0];
        }

        $values = [];
        foreach ($collection as $element) {
            // fail-safe for mixed collections, e.g. either DTO or string
            if (!is_object($element)) {
                $values[] = $element;
                continue;
            }

            if (false !== $referenceByIdentifier) {
                // in this case we return only an array of identifiers instead
                // of an array of arrays
                $export = $this->objectToArray(
                    $element,
                    [$referenceByIdentifier]
                );
                $values[] = $export[$referenceByIdentifier];
            } else {
                $export = $this->objectToArray(
                    $element,
                    $propertyFilter,
                    $isExcludeFilter
                );

                // we need the entityClass here, as the collection may contain
                // mixed types (table inheritance or different DTO versions)
                $export['_entityClass'] = ClassUtils::getClass($element);

                $values[] = $export;
            }
        }

        return $values;
    }

    /**
     * We use a static cache here as the properties of classes won't change
     * while the PHP instance is running and this method could be called
     * multiple times, e.g. when exporting many objects of the same class.
     *
     * @return array [propertyName => ExportableProperty instance]
     *
     * @throws \ReflectionException
     */
    protected function getExportableProperties(string $className): array
    {
        if (!isset(self::$exportableProperties[$className])) {
            $reflection = new \ReflectionClass($className);
            self::$exportableProperties[$className] = [];

            $properties = $reflection->getProperties();
            foreach ($properties as $property) {
                $attribute = ReflectionHelper::getPropertyAttribute(
                    $property,
                    ExportableProperty::class
                );
                if ($attribute instanceof ExportableProperty) {
                    // cache the attribute instance, to check for "asList" and
                    // "referenceByIdentifier"
                    self::$exportableProperties[$className][$property->name]
                        = $attribute;
                }
            }
        }

        return self::$exportableProperties[$className];
    }

    /**
     * @throws \ReflectionException
     */
    protected function isExportableClass(string $className): bool
    {
        return [] !== $this->getExportableProperties($className);
    }
}
