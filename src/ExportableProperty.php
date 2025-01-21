<?php

declare(strict_types=1);

namespace Vrok\ImportExport;

/**
 * Used to mark properties available for export.
 * The argument 'referenceByIdentifier' can be used on properties that reference
 * other entities: Instead of an array only the value of the property named in
 * this argument is returned, e.g. the ID.
 * The argument 'asList' is used for properties with the type 'array': Only
 * if 'asList' is set, the contents will be handled as list of exportable
 * entites/DTOs, else the array is returned as-is.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ExportableProperty
{
    public function __construct(
        public bool $asList = false,
        public string $referenceByIdentifier = '',
    ) {
    }
}
