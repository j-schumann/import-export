<?php

declare(strict_types=1);

namespace Vrok\ImportExport\Tests\Fixtures;

use Vrok\ImportExport\ExportableEntity;
use Vrok\ImportExport\ExportableProperty;
use Vrok\ImportExport\ImportableEntity;
use Vrok\ImportExport\ImportableProperty;

#[ExportableEntity]
#[ImportableEntity]
class NestedDTO implements DtoInterface
{
    #[ExportableProperty]
    #[ImportableProperty]
    public string $description = '';

    #[ExportableProperty]
    #[ImportableProperty]
    public int|string $mixedProp = 0;
}
