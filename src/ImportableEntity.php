<?php

declare(strict_types=1);

namespace Vrok\ImportExport;

/**
 * Used to mark (Doctrine) entities allowed for import.
 * The import helper will not try to instantiate classes that are not marked
 * as they maybe have a constructor that requires arguments and they probably
 * have no properties marked as #[ImportableProperty].
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ImportableEntity
{
}
