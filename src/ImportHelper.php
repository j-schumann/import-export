<?php

declare(strict_types=1);

namespace Vrok\ImportExport;

use Doctrine\Common\Collections\Collection;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Uid\AbstractUid;

/**
 * Helper to convert arrays to (Doctrine) entities and DTOs. Uses Reflection to
 * determine property types and check for properties tagged with
 * #[ImportableProperty].
 * Uses Symfony's PropertyAccess to set properties using the correct
 * setters (which also supports hassers and issers).
 */
class ImportHelper
{
    // static caches to reduce Reflection calls when importing multiple
    // objects of the same class
    protected static array $typeDetails = [];
    protected static array $importableProperties = [];

    protected array $identityMap = [];
    protected array $identityMappingClasses = [];

    protected PropertyAccessorInterface $propertyAccessor;

    protected ?ObjectManager $objectManager = null;

    public function __construct()
    {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor();
    }

    /**
     * Allows to set a list of (abstract/parent) classes; any objects that are
     * imported that are instances of those classes have their old & new 'id'
     * field values logged in the identity map. This is used for entities that
     * use autoincrement IDs, where the old ID cannot be imported and the new
     * ID in the database is probably different.
     * This is only useful for cases where other records, imported later with
     * the same helper instance reference those previously imported records by
     * their old IDs.
     * This will cause all those mapped records to be automatically persisted
     * & flushed to the database, to get the new ID; all other records must
     * be persisted manually or be cascade-persisted by Doctrine.
     *
     * @param array $mappingClasses list of (parent) class names
     */
    public function setIdentityMappingClasses(array $mappingClasses): void
    {
        if (null === $this->objectManager) {
            throw new \RuntimeException('Object manager must be set to use identity mapping!');
        }

        $this->identityMappingClasses = $mappingClasses;
    }

    /**
     * Returns the current state of the identity map [old ID => new ID],
     * for postprocessing or pre-processing other records before importing
     * (e.g. modifying references that cannot be auto-detected in IRIs or DTOs).
     */
    public function getIdentityMap(): array
    {
        return $this->identityMap;
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
    public function objectFromArray(
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
            $encoded = json_encode($data, \JSON_THROW_ON_ERROR);
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
                (!\in_array($propName, $propertyFilter, true) && !$isExcludeFilter)
                || (\in_array($propName, $propertyFilter, true) && $isExcludeFilter)
            )
            ) {
                continue;
            }

            if (!\array_key_exists($propName, $data)) {
                continue;
            }

            $value = null;
            $typeDetails = $this->getTypeDetails($propData['reflection']);
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
            } elseif (\is_object($data[$propName])) {
                // set already instantiated objects, we cannot modify/convert those,
                // and the may have different classes, e.g. when the type is a union.
                // If the object type is not allowed the propertyAccessor will throw
                // an exception.
                $value = $data[$propName];
            } elseif (\is_array($data[$propName]) && !$typeDetails['classname']) {
                // We have an array but no type information -> the target property
                // could be a unionType that allows multiple classes or it could
                // be untyped. So if the importer expects us to create an instance
                // ('_entityClass' is set) try to create & set it, else use the
                // value as is.
                $value = isset($data[$propName]['_entityClass'])
                    ? $this->objectFromArray(
                        $data[$propName],
                        null,
                        $propertyFilter[$propName] ?? [],
                        $isExcludeFilter
                    )
                    : $data[$propName];
            } elseif (!$typeDetails['classname']) {
                // if we are this deep in the IFs it means the property is no
                // array, instead a union type with no classes (e.g. int|string)
                // -> let the propertyAccessor try to set the value as is.
                $value = $data[$propName];
            } elseif ($this->isImportableClass($typeDetails['classname'])
                // the classname could be an interface without properties, in
                // that case the data must have an '_entityClass' to determine
                // the actual class
                || isset($data[$propName]['_entityClass'])
            ) {
                $value = \is_int($data[$propName]) || \is_string($data[$propName])
                    ? $this->getRecordFromReference(
                        $typeDetails['classname'],
                        $data[$propName]
                    )
                    : $this->objectFromArray(
                        $data[$propName],
                        $typeDetails['classname'],
                        $propertyFilter[$propName] ?? [],
                        $isExcludeFilter
                    );
            } elseif (is_a($typeDetails['classname'], Collection::class, true)) {
                // @todo We simply assume here that
                // a) the collection members are importable
                // -> use Doctrine Schema data to determine the collection type
                // c) the collection can be set as array at once
                $value = $this->collectionFromArray(
                    $data[$propName],
                    $listOf,
                    $propertyFilter[$propName] ?? [],
                    $isExcludeFilter
                );
            } elseif (is_a($typeDetails['classname'], \DateTimeInterface::class, true)) {
                $value = new ($typeDetails['classname'])($data[$propName]);
            } elseif (is_a($typeDetails['classname'], AbstractUid::class, true)) {
                $value = ($typeDetails['classname'])::fromString($data[$propName]);
            } else {
                throw new \RuntimeException("Don't know how to import $className::$propName!");
            }

            $this->propertyAccessor->setValue(
                $instance,
                $propName,
                $value
            );
        }

        // only does something if a matching mapping class was set
        $this->processIdentityMapping($data, $instance);

        return $instance;
    }

    /**
     * Allows to create multiple records at once from the given list of
     * elements. Used by objectFromArray if it finds a property that is a
     * Collection. Can find entities by their identifier (requires className).
     * The propertyFilter is applied to each element, see objectFromArray(),
     * if it contains no value it is ignored.
     *
     * @throws \JsonException
     * @throws \ReflectionException
     * @throws \RuntimeException
     */
    public function collectionFromArray(
        array $data,
        ?string $className = null,
        array $propertyFilter = [],
        bool $isExcludeFilter = false,
    ): array {
        $collection = [];
        foreach ($data as $element) {
            if ((\is_int($element) || \is_string($element)) && $className) {
                $collection[] = $this->getRecordFromReference($className, $element);
            } elseif (\is_object($element)) {
                if ($className && !$element instanceof $className) {
                    $elementClass = $element::class;
                    throw new \RuntimeException("Collection should be instances of $className but found $elementClass!");
                }

                // use objects directly...
                $collection[] = $element;
            } elseif (\is_array($element)) {
                // ... or try to create, if className is not set than each
                // element must contain an _entityClass
                $collection[] = $this->objectFromArray(
                    $element,
                    $className,
                    $propertyFilter,
                    $isExcludeFilter
                );
            } else {
                throw new \RuntimeException("Don't know how to import collection element, either no className given ('listOf' setting is required for Collection properties that are imported as reference) or no array/object!");
            }
        }

        return $collection;
    }

    /**
     * Similar to 'collectionFromArray', but instead of returning the new
     * instances they are automatically persisted & flushed to the database.
     * This also means that only Doctrine entity classes are supported here.
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    public function importEntityCollection(
        array $data,
        ?string $className = null,
        array $propertyFilter = [],
        bool $isExcludeFilter = false,
    ): void {
        if (null === $this->objectManager) {
            throw new \RuntimeException('ObjectManager must be set first!');
        }

        foreach ($data as $element) {
            if (!\is_array($element)) {
                throw new \RuntimeException("Collection element must be the array representation of $className!");
            }

            $entity = $this->objectFromArray(
                $element,
                $className,
                $propertyFilter,
                $isExcludeFilter
            );

            // the entity could already be flushed, if its class is enabled for
            // identity mapping, then this is a no-op
            $this->objectManager->persist($entity);
            $this->objectManager->flush();
        }
    }

    /**
     * This is only used for array properties that hold lists of DTOs.
     *
     * @throws \JsonException|\RuntimeException|\ReflectionException
     */
    protected function processList(
        mixed $list,
        \ReflectionProperty $property,
        string $listOf,
        array $propertyFilter = [],
        bool $isExcludeFilter = false,
    ): array {
        if (!\is_array($list)) {
            $json = json_encode($list, \JSON_THROW_ON_ERROR);
            throw new \RuntimeException("Property $property->class::$property->name is marked as list of '$listOf' but it is no array: $json!");
        }

        foreach ($list as $key => $entry) {
            if (\is_object($entry) && !($entry instanceof $listOf)) {
                $entryClass = $entry::class;
                throw new \RuntimeException("Property $property->class::$property->name is marked as list of '$listOf' but found an instance of $entryClass!");
            }

            if (!\is_array($entry)) {
                // Do not throw an error here: This is a concession to allowing
                // the export of mixed lists (e.g. DTOs + strings), we have to
                // allow base types or already instantiated objects here too,
                // even if listOf only contains one DTO classname.
                $list[$key] = $entry;
                continue;
            }

            $list[$key] = $this->objectFromArray($entry, $listOf, $propertyFilter, $isExcludeFilter);
        }

        return $list;
    }

    /**
     * If the given entity is an instance of the classes defined for identity
     * mapping (includes parent classes), store the old & new ID in the map.
     */
    protected function processIdentityMapping(array $data, object $instance): void
    {
        foreach ($this->identityMappingClasses as $mappingClass) {
            if ($instance instanceof $mappingClass) {
                $this->objectManager->persist($instance);
                $this->objectManager->flush();

                if (!isset($this->identityMap[$mappingClass])) {
                    $this->identityMap[$mappingClass] = [];
                }

                // @todo: currently, only "id" is supported for those
                // (auto-generated) identifiers - non-autogenerated identifiers
                // should need no mapping, as their identifier can be imported
                // while autoincrement IDs can most probably not
                $this->identityMap[$mappingClass][$data['id']] = $instance->id;

                break;
            }
        }
    }

    /**
     * If an ID was found (int/string instead of an object/array to import),
     * try to use the objectManager to find the referenced record. Uses the
     * identity mapping to map old to new IDs, if any mapping classes where
     * defined.
     */
    protected function getRecordFromReference(string $className, int|string $id): object
    {
        if (null === $this->objectManager) {
            throw new \RuntimeException("Found ID for $className, but objectManager is not set to find object!");
        }

        $mappedId = $id;

        foreach ($this->identityMappingClasses as $mappingClass) {
            if (is_a($className, $mappingClass, true)) {
                if (!isset($this->identityMap[$mappingClass][$id])) {
                    throw new \RuntimeException("ID for referenced record $className#$id was not yet mapped, check import order!");
                }
                $mappedId = $this->identityMap[$mappingClass][$id];
            }
        }

        $record = $this->objectManager->find($className, $mappedId);
        if (!$record) {
            throw new \RuntimeException("Could not find referenced & mapped record $className#$mappedId!");
        }

        return $record;
    }

    // @todo: catch union types w/ multiple builtin types
    protected function getTypeDetails(
        \ReflectionProperty $property,
    ): array {
        if (isset(self::$typeDetails["$property->class::$property->name"])) {
            return self::$typeDetails["$property->class::$property->name"];
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
            self::$typeDetails["$property->class::$property->name"] = $data;

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
            $data['classname'] = 'self' === $propClass ? $property->class : $propClass;
        }

        self::$typeDetails["$property->class::$property->name"] = $data;

        return $data;
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
     * @throws \ReflectionException
     */
    protected function isImportableClass(string $className): bool
    {
        return [] !== $this->getImportableProperties($className);
    }
}
