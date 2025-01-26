<?php

declare(strict_types=1);

namespace Vrok\ImportExport\Tests\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Vrok\ImportExport\ImportableProperty;

#[ORM\Entity]
class AutoincrementEntity
{
    #[ImportableProperty]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ImportableProperty]
    public string $name = '';

    #[ImportableProperty]
    public ?self $parent = null;
}
