# import-export
Doctrine Helper to import from / export to JSON object graphs

[![CI Status](https://github.com/j-schumann/import-export/actions/workflows/ci.yaml/badge.svg)](https://github.com/j-schumann/import-export/actions)
[![Coverage Status](https://coveralls.io/repos/github/j-schumann/import-export/badge.svg?branch=main)](https://coveralls.io/github/j-schumann/import-export?branch=main)

Uses Symfony's `PropertyAccess` to convert objects to arrays or generate objects
from arrays. Which classes & properties can be exported/imported (and how) can
be controlled with the `ExportableProperty` and `ImportableProperty` PHP 
attributes: Only classes that have at least one `ExportableProperty` can be
converted to array, only classes that have at least one `ImportableProperty` can
be instantiated from array.
Uses Doctrines `ClassUtils` to safely handle proxies.

## Usage

Short Example, for more details see [ExportEntity](tests/Fixtures/ExportEntity.php)
/ [ImportEntity](tests/Fixtures/ImportEntity.php) and [ExportHelperTest](tests/ExportHelperTest.php)
/ [ImportHelperTest](tests/ImportHelperTest.php) for all features.
Allows to export referenced entities (or only their identifiers) and collections.

```php
use Vrok\ImportExport\ExportableProperty;
use Vrok\ImportExport\ExportHelper;
use Vrok\ImportExport\ImportableProperty;
use Vrok\ImportExport\ImportHelper;

class Entity
{
    #[ExportableProperty]
    #[ImportableProperty]
    public int $id = 0;

    #[ExportableProperty]
    #[ImportableProperty]
    public ?\DateTimeImmutable $timestamp = null;
}

$entity = new Entity();
$entity->id = 1;
$entity->timestamp = new \DateTimeImmutable();

$helper = new ExportHelper();
$export = $helper->objectToArray($entity);
$exportList = $helper->collectionToArray([$entity]);

/*
  $export === [
    'id'        => 1,
    'timestamp' => '2022-03-23....',
  ]
*/

$helper = new ImportHelper();
$newInstance = $helper->objectFromArray($export, Entity::class);
$newInstances = $helper->collectionFromArray([$export], Entity::class);
```

## Features

### Export
* can output object graphs, if a property pointing to another entity or DTO is
  marked with `ExportableProperty`
* can export an identifier, to be later mapped to the actual record, by using
  the `referenceByIdentifier` argument of the `ExportableProperty` attribute,
  e.g. instead of exporting the whole `User`, only it's ID could be exported for
  the `createdBy` property of another record.
* can export collections of objects (either by using `collectionToArray` or when
  a property is a Doctrine `Collection` or an array and marked with `asList`),
  even when they are of different types (e.g. through inheritance). An
  `_entityClass` field will contain the actual class.
* can limit exported data to only allowed properties by using the 
  `propertyFilter` argument of `fromArray`
* can prevent (otherwise exportable property) from being exported by using the
  `propertyFilter` argument of `fromArray` and setting `isExcludeFilter` to `true`

### Import
* can reference existing records: if a property (collection or single object)
  points to an "importable entity" and the dataset contains an `int`/`string`
  the given `ObjectManager` is used to fetch the specified record from the
  database
* handle object graphs:
  ```json
  {
    "name": "base entity",
    "nested": {
      "description": "for a property with type hint",
      "child": {
        "value": "for a property that is e.g. typed as an Interface",
        "_entityClass": "\\My\\DtoClass"
      } 
    }
  }
  ```
* import only permitted fields (even when the dataset contains more, potentially
  importable properties) by using the `propertyFilter` argument of `fromArray`
* exclude fields from importing (even when they are in the dataset and potentially
  importable) by using the `propertyFilter` argument of `fromArray` and setting
  `isExcludeFilter` to `true`
* Automatically persists generated entities when they are marked for identity 
  mapping, keeps an internal map of old ⇒ new identifiers. So when a reference
  from entity A to entity B is found in a later import object, the identity map
  is checked for matches, to update the reference to the new identifier.

## Dependencies

* `doctrine/common` for the `ClassUtils` (proxy handling) and `Collection` properties
* `doctrine/persistence` for the `ObjectManager` (importing references)
* `symfony/property-access` for setting/reading (private, protected) properties
* dev: `symfony/uuid` for testing import of UUID values

## Future Improvements

* when only `doctrine/orm` ^4.0 is supported, remove the "if" in `AbstractOrmTestCase` 
* what could be done with Symfony's serializer?
  * can already transform object (graphs) to array
  * properties can be marked with groups to be exportable
  * how to mark properties to only be exported as identifier? new attribute? 
    how implement that, how is APIP doing this?
  * how to mark array properties to be treated as DTO-List?
  * how to ignore some properties without the need to hardcode groups? (exclude-filter)
  * how to limit to some properties without the need to hardcode groups? (include-filter)
* evaluate Doctrine's ORM collection attributes to check for allowed element class
* Improve union type handling (e.g. multiple base types: int|string)
* not only export to arrays but also directly to files
    * support exporting to multiple files (split to reduce size for large datasets)
* not only import from arrays but also directly from JSON files
  * support importing from multiple files (have been split to reduce size)
