<?php

declare(strict_types=1);

namespace Vrok\ImportExport;

/**
 * Used to mark (Doctrine) entities allowed for export.
 * The import helper will not try to export classes that are not marked, to prevent
 * export of unwanted/private data. Additionally all properties that should/can be
 * exported must be marked with #[ExportableProperty].
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ExportableEntity
{
}
