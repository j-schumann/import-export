<?php

declare(strict_types=1);

namespace Vrok\ImportExport\Tests\Fixtures;

use Vrok\ImportExport\ExportableEntity;
use Vrok\ImportExport\ExportableProperty;
use Vrok\ImportExport\ImportableEntity;
use Vrok\ImportExport\ImportableProperty;

#[ExportableEntity]
#[ImportableEntity]
class TestDTO implements DtoInterface
{
    #[ExportableProperty]
    #[ImportableProperty]
    public string $name = '';

    #[ExportableProperty]
    #[ImportableProperty]
    public ?DtoInterface $nestedInterface = null;

    /**
     * @var array|DtoInterface[]
     */
    #[ExportableProperty(asList: true)]
    #[ImportableProperty(listOf: DtoInterface::class)]
    public array $nestedInterfaceList = [];
}
