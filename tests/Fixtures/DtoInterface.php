<?php

declare(strict_types=1);

namespace Vrok\ImportExport\Tests\Fixtures;

use Vrok\ImportExport\ExportableEntity;
use Vrok\ImportExport\ImportableEntity;

#[ExportableEntity]
#[ImportableEntity]
interface DtoInterface
{
}
