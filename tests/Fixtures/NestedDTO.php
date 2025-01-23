<?php

declare(strict_types=1);

namespace Vrok\ImportExport\Tests\Fixtures;

use Vrok\ImportExport\ExportableProperty;
use Vrok\ImportExport\ImportableProperty;

#[RepeatableAttribute(value: 2)]
class NestedDTO implements DtoInterface
{
    #[RepeatableAttribute(value: 2)]
    #[ExportableProperty]
    #[ImportableProperty]
    public string $description = '';

    #[ExportableProperty]
    #[ImportableProperty]
    public int|string $mixedProp = 0;
}
