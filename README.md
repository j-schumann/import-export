# import-export
Doctrine Helper to import from / export to JSON object graphs

[![CI Status](https://github.com/j-schumann/import-export/actions/workflows/ci.yaml/badge.svg)](https://github.com/j-schumann/import-export/actions)
[![Coverage Status](https://coveralls.io/repos/github/j-schumann/import-export/badge.svg?branch=main)](https://coveralls.io/github/j-schumann/import-export?branch=main)

Uses Symfony's `PropertyAccess` to convert objects to arrays or generate objects
from arrays. Which classes & properties can be exported/imported and how can be
controlled with the `ExportableEntity`, `ImportableEntity` and `ExportableProperty`,
`ImportableProperty` PHP attributes.  
Uses Doctrines `ClassUtils` to safely handle proxies.

## Usage

Short Example, for more details see [ExportEntity](tests/Fixtures/ExportEntity.php)
/ [ImportEntity](tests/Fixtures/ImportEntity.php) and [ExportTest](tests/ExportTest.php)
/ [ImportTest](tests/ImportTest.php) for all features.
Allows to export referenced entities (or only their identifiers) and collections.

```php
use Vrok\DoctrineAddons\ImportExport\ExportableEntity;
use Vrok\DoctrineAddons\ImportExport\ExportableProperty;
use Vrok\DoctrineAddons\ImportExport\Helper;
use Vrok\DoctrineAddons\ImportExport\ImportableEntity;
use Vrok\DoctrineAddons\ImportExport\ImportableProperty;

#[ExportableEntity]
#[ImportableEntity]
class Entity
{
    #[ExportableProperty]
    #[ImportableProperty]
    public int $id = 0;

    #[ExportableProperty]
    #[ImportableProperty]
    public ?DateTimeImmutable $timestamp = null;
}

$entity = new Entity();
$entity->id = 1;
$entity->timestamp = new DateTimeImmutable();

$helper = new Helper();
$export = $helper->toArray($entity);

// $export === [
//     'id'        => 1,
//     'timestamp' => '2022-03-23....',
// ]

$newInstance = $helper->fromArray($export, Entity::class);
```

## Features

### Export
* can output object graphs, if a property pointing to another entity or DTO is
  marked with `ExportableProperty`
* can export an identifier, to be later mapped to the actual record, by using
  the `referenceByIdentifier` argument of the `ExportableProperty` attribute,
  e.g. instead of exporting the whole `User`, only it's ID could be exported for
  the `createdBy` property of another record.
* can export collections of objects (either by using `exportCollection` or when
  a property is a Doctrine `Collection` or an array and marked with `asList`),
  even when they are of different types (e.g. through inheritance). An
  `_entityClass` field will contain the actual class.

### Import
* can reference existing records: if a property (collection or single object)
  points to an `ImportableEntity` and the dataset contains an `int`/`string`
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
  `filterAsExclude` to `true`

## Future Improvements

* evaluate Doctrine's ORM collection attributes to check for allowed element class
* Improve union type handling (e.g. multiple base types: int|string)
* not only export to arrays but also directly to files
    * support exporting to multiple files (split to reduce size for large datasets)
* not only import from arrays but also directly from JSON files
  * support importing from multiple files (have been split to reduce size)
