<?php

declare(strict_types=1);

namespace Vrok\ImportExport;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Helper to convert arrays to (Doctrine) entities and export entities as arrays.
 * Uses Reflection to determine property types and check for properties tagged
 * with #[ImportableProperty] or #[ExportableProperty].
 * Uses Symfony's PropertyAccess to get/set properties using the correct
 * getters/setters (which also supports hassers and issers).
 * Uses Doctrine's ClassUtils to handle proxies, as we need the correct class
 * name in "_entityClass" and they may prevent access to the property PHP
 * attributes.
 */
class Helper
{
    // static caches to reduce Reflection calls when im-/exporting multiple
    // objects of the same class
    protected static array $typeDetails = [];
    protected static array $exportableProperties = [];
    protected static array $importableProperties = [];

    protected PropertyAccessorInterface $propertyAccessor;

    protected ?ObjectManager $objectManager = null;

    public function __construct()
    {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor();
    }

    /**
     * Required when importing data that uses references to existing records
     * by giving an identifier (int|string) instead of an array or object for
     * a property that holds a (list of) importable object(s).
     */
    public function setObjectManager(ObjectManager $objectManager): void
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Creates an instance of the given entityClass and populates it with the
     * data given as array.
     * Alternatively the entityClass can be given as additional array element
     * with index _entityClass.
     * Can recurse over properties that themselves are entities or collections
     * of entities.
     * Also instantiates Datetime[Immutable] properties from strings.
     * To determine, which properties to populate the attribute
     * #[ImportableProperty] is used. Can infer the class of nested records from
     * the property's type.
     *
     * @param array   $data            The list of all fields to set on the new
     *                                 object
     * @param ?string $entityClass     Class of the new object, necessary if the
     *                                 data does not contain  '_entityClass'
     * @param array   $propertyFilter  Only properties with the given names are
     *                                 imported, ignored if empty. May contain
     *                                 definitions for sub-records by using the
     *                                 property name as key and specifying an
     *                                 array of (sub-) properties as value.
     * @param bool    $isExcludeFilter flips the meaning of the propertyFilter:
     *                                 only properties that are *not* in the
     *                                 list are imported, same for sub-records
     *
     * @throws \JsonException|\ReflectionException
     */
    public function fromArray(
        array $data,
        ?string $entityClass = null,
        array $propertyFilter = [],
        bool $isExcludeFilter = false,
    ): object {
        // let the defined _entityClass take precedence over the (possibly
        // inferred) $entityClass from a property type, which may be an abstract
        // superclass or an interface
        $className = $data['_entityClass'] ?? $entityClass;

        if (empty($className)) {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
            throw new \RuntimeException("No entityClass given to instantiate the data: $encoded");
        }

        if (interface_exists($className)) {
            throw new \RuntimeException("Cannot create instance of the interface $className, concrete class needed!");
        }

        if (!class_exists($className)) {
            throw new \RuntimeException("Class $className does not exist!");
        }

        if ($entityClass && isset($data['_entityClass'])
            && !is_a($data['_entityClass'], $entityClass, true)
        ) {
            throw new \RuntimeException("Given '_entityClass' {$data['_entityClass']} is not a subclass/implementation of $entityClass!");
        }

        $classReflection = new \ReflectionClass($className);
        if ($classReflection->isAbstract()) {
            throw new \RuntimeException("Cannot create instance of the abstract class $className, concrete class needed!");
        }

        $instance = new $className();

        foreach ($this->getImportableProperties($className) as $propName => $propData) {
            // empty array also counts as "no filter applied"
            if ([] !== $propertyFilter && (
                (!in_array($propName, $propertyFilter, true) && !$isExcludeFilter)
                || (in_array($propName, $propertyFilter, true) && $isExcludeFilter)
            )
            ) {
                continue;
            }

            if (!array_key_exists($propName, $data)) {
                continue;
            }

            $value = null;
            $typeDetails = $this->getTypeDetails($className, $propData['reflection']);
            $listOf = $propData['attribute']->listOf;

            if (null === $data[$propName]) {
                if (!$typeDetails['allowsNull']) {
                    throw new \RuntimeException("Found NULL for $className::$propName, but property is not nullable!");
                }

                $value = null;
            } elseif ($typeDetails['isBuiltin']) {
                // check for listOf, the property could be an array of DTOs etc.
                $value = $listOf
                    ? $this->processList(
                        $data[$propName],
                        $propData['reflection'],
                        $listOf,
                        $propertyFilter[$propName] ?? [],
                        $isExcludeFilter
                    )
                    // simply set standard properties, the propertyAccessor will throw
                    // an exception if the types don't match.
                    : $data[$propName];
            } elseif (is_object($data[$propName])) {
                // set already instantiated objects, we cannot modify/convert those,
                // and the may have different classes, e.g. when the type is a union.
                // If the object type is not allowed the propertyAccessor will throw
                // an exception.
                $value = $data[$propName];
            } elseif (is_array($data[$propName]) && !$typeDetails['classname']) {
                // We have an array but no type information -> the target property
                // could be a unionType that allows multiple classes or it could
                // be untyped. So if the importer expects us to create an instance
                // ('_entityClass' is set) try to create & set it, else use the
                // value as is.
                $value = isset($data[$propName]['_entityClass'])
                    ? $this->fromArray($data[$propName],
                        null,
                        $propertyFilter[$propName] ?? [],
                        $isExcludeFilter
                    )
                    : $data[$propName];
            } elseif (!$typeDetails['classname']) {
                // if we are this deep in the IFs it means the data is no array and this
                // is a union type with no classes (e.g. int|string) -> let the
                // propertyAccessor try to set the value as is.
                $value = $data[$propName];
            } elseif ($this->isImportableClass($typeDetails['classname'])
                || isset($data[$propName]['_entityClass'])
            ) {
                if (is_int($data[$propName]) || is_string($data[$propName])) {
                    if (null === $this->objectManager) {
                        throw new \RuntimeException("Found ID for $className::$propName, but objectManager is not set to find object!");
                    }

                    $value = $this->objectManager->find(
                        $typeDetails['classname'],
                        $data[$propName]
                    );
                } else {
                    $value = $this->fromArray(
                        $data[$propName],
                        $typeDetails['classname'],
                        $propertyFilter[$propName] ?? [],
                        $isExcludeFilter
                    );
                }
            } elseif (is_a($typeDetails['classname'], Collection::class, true)) {
                // @todo We simply assume here that
                // a) the collection members are importable
                // -> use Doctrine Schema data to determine the collection type
                // c) the collection can be set as array at once
                $value = [];
                foreach ($data[$propName] as $element) {
                    if (is_int($element) || is_string($element)) {
                        if (null === $this->objectManager) {
                            throw new \RuntimeException("Found ID for $className::$propName, but objectManager is not set to find object!");
                        }
                        if (!$listOf) {
                            throw new \RuntimeException("Cannot import elements for $className::$propName, 'listOf' setting is required as this helper cannot evaluate Doctrine's relation attributes!");
                        }

                        $value[] = $this->objectManager->find(
                            $listOf,
                            $element
                        );
                    } elseif (is_object($element)) {
                        // use objects directly...
                        $value[] = $element;
                    } elseif (is_array($element)) {
                        // ... or try to create, if listOf is not set than each
                        // element must contain an _entityClass
                        $value[] =  $this->fromArray($element, $listOf);
                    } else {
                        throw new \RuntimeException("Don't know how to import elements for $className::$propName!");
                    }
                }
            } elseif (is_a($typeDetails['classname'], \DateTimeInterface::class, true)) {
                $value = new ($typeDetails['classname'])($data[$propName]);
            } else {
                throw new \RuntimeException("Don't know how to import $className::$propName!");
            }

            $this->propertyAccessor->setValue(
                $instance,
                $propName,
                $value
            );
        }

        return $instance;
    }

    // @todo: catch union types w/ multiple builtin types
    protected function getTypeDetails(
        string $classname,
        \ReflectionProperty $property,
    ): array {
        $propName = $property->getName();
        if (isset(self::$typeDetails["$classname::$propName"])) {
            return self::$typeDetails["$classname::$propName"];
        }

        $type = $property->getType();
        $data = [
            'allowsArray' => null === $type, // untyped allows arrays of course
            'allowsNull'  => $type?->allowsNull() ?? true, // also works for union types
            'classname'   => null,
            'typename'    => null,
            'isBuiltin'   => false,
            'isUnion'     => $type instanceof \ReflectionUnionType,
        ];

        if (null === $type) {
            self::$typeDetails["$classname::$propName"] = $data;

            return $data;
        }

        if ($data['isUnion']) {
            foreach ($type->getTypes() as $unionVariant) {
                /** @var \ReflectionNamedType $unionVariant */
                $variantName = $unionVariant->getName();
                if ('array' === $variantName) {
                    $data['allowsArray'] = true;
                    continue;
                }

                if (!$unionVariant->isBuiltin()) {
                    if (null !== $data['classname']) {
                        // @todo Improve this. We could store a list of classnames
                        // to check against in fromArray()
                        throw new \RuntimeException("Cannot import object, found ambiguous union type: $type");
                    }

                    $data['classname'] = $variantName;
                }
            }
        } elseif ($type->isBuiltin()) {
            $data['isBuiltin'] = true;
            $data['allowsNull'] = $type->allowsNull();
            $data['typename'] = $type->getName();
            if ('array' === $data['typename']) {
                $data['allowsArray'] = true;
            }
        } else {
            $propClass = $type->getName();
            $data['classname'] = 'self' === $propClass ? $classname : $propClass;
        }

        self::$typeDetails["$classname::$propName"] = $data;

        return $data;
    }

    /**
     * @throws \JsonException|\RuntimeException|\ReflectionException
     */
    protected function processList(
        mixed $list,
        \ReflectionProperty $property,
        string $listOf,
        array $propertyFilter = [],
        bool $isExcludeFilter = false,
    ): array {
        if (null === $list) {
            return [];
        }

        if (!is_array($list)) {
            $json = json_encode($list, JSON_THROW_ON_ERROR);
            throw new \RuntimeException("Property $property->class::$property->name is marked as list of '$listOf' but it is no array: $json!");
        }

        foreach ($list as $key => $entry) {
            if (!is_array($entry)) {
                $json = json_encode($entry, JSON_THROW_ON_ERROR);
                throw new \RuntimeException("Property $property->class::$property->name is marked as list of '$listOf' but entry is no array: $json!");
            }

            $list[$key] = $this->fromArray($entry, $listOf, $propertyFilter, $isExcludeFilter);
        }

        return $list;
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
    public function toArray(
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
                    $data[$propertyName] = $this->exportCollection(
                        $propValue,
                        [$attribute->referenceByIdentifier]
                    );
                } else {
                    $data[$propertyName] = $this->exportCollection(
                        $propValue,
                        $propertyFilter[$propertyName] ?? [],
                        $isExcludeFilter
                    );
                }
            } elseif (is_object($propValue) && $this->isExportableClass($propValue::class)) {
                if ('' !== $attribute->referenceByIdentifier) {
                    $identifier = $this->toArray(
                        $propValue,
                        [$attribute->referenceByIdentifier]
                    );
                    $data[$propertyName] = $identifier[$attribute->referenceByIdentifier];
                } else {
                    $data[$propertyName] = $this->toArray(
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
     * Allows to export a list of elements at once. Used by toArray() if it
     * finds a property that is a Collection/list. Can also be used to export
     * Doctrine collections, e.g. of a complete table.
     *
     * The propertyFilter is applied to each collection element, see toArray().
     * If it contains no value it is ignored. If it contains exactly one value
     * this is interpreted as name of an identifier property (e.g. "id"), so
     * only a list of identifiers is returned.
     */
    public function exportCollection(
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
                $export = $this->toArray(
                    $element,
                    [$referenceByIdentifier]
                );
                $values[] = $export[$referenceByIdentifier];
            } else {
                $export = $this->toArray(
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
     * multiple times, e.g. when importing many objects of the same class.
     *
     * @return array [
     *               propertyName => [
     *               'reflection' => ReflectionProperty
     *               'attribute' => ImportableProperty
     *               ]
     *               ]
     *
     * @throws \ReflectionException
     */
    protected function getImportableProperties(string $className): array
    {
        if (!isset(self::$importableProperties[$className])) {
            $reflection = new \ReflectionClass($className);
            self::$importableProperties[$className] = [];

            $properties = $reflection->getProperties();
            foreach ($properties as $property) {
                $attribute = ReflectionHelper::getPropertyAttribute(
                    $property,
                    ImportableProperty::class
                );
                if ($attribute instanceof ImportableProperty) {
                    // cache the reflection object, we need it for type analysis
                    // later and reflection is expensive, also the attribute
                    // instance, to check for "listOf"
                    self::$importableProperties[$className][$property->name] = [
                        'reflection' => $property,
                        'attribute'  => $attribute,
                    ];
                }
            }
        }

        return self::$importableProperties[$className];
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
    protected function isImportableClass(string $className): bool
    {
        return [] !== $this->getImportableProperties($className);
    }

    /**
     * @throws \ReflectionException
     */
    protected function isExportableClass(string $className): bool
    {
        return [] !== $this->getExportableProperties($className);
    }
}
