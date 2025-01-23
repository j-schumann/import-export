<?php

declare(strict_types=1);

namespace Vrok\ImportExport\Tests\Fixtures;

use Vrok\ImportExport\ExportableProperty;
use Vrok\ImportExport\ImportableProperty;

#[\DoesNotExist]
#[RepeatableAttribute]
#[RepeatableAttribute(value: 1)]
class TestDTO implements DtoInterface
{
    #[\DoesNotExist]
    #[ExportableProperty]
    #[ImportableProperty]
    #[RepeatableAttribute]
    #[RepeatableAttribute(value: 1)]
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
