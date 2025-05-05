<?php

declare(strict_types=1);

namespace Vrok\ImportExport\Tests\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Vrok\ImportExport\ImportableProperty;

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type')]
abstract class AbstractImportEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    // region typed (builtin), nullable property with getter/setter
    #[ImportableProperty]
    #[ORM\Column]
    protected ?string $name = '';

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $value): self
    {
        $this->name = null !== $value ? $value.' via setter' : null;

        return $this;
    }
    // endregion

    // region untyped Collection property
    #[ImportableProperty(listOf: self::class)]
    protected Collection $collection;

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function setCollection(array $elements): self
    {
        $this->collection->clear();
        foreach ($elements as $element) {
            $this->addToCollection($element);
        }

        return $this;
    }

    public function addToCollection(self $element): self
    {
        if ($this->collection->contains($element)) {
            return $this;
        }

        $this->collection->add($element);

        return $this;
    }
    // endregion

    // region typed (object), nullable property with getter/setter
    #[ImportableProperty]
    #[ORM\ManyToOne(targetEntity: ImportEntity::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?ImportEntity $parent = null;

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $value): self
    {
        $this->parent = $value;

        return $this;
    }
    // endregion

    // region DateTime nullable property w/o getter/setter
    #[ImportableProperty]
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $timestamp = null;
    // endregion

    // region typed (object), nullable property w/o getter/setter
    #[ImportableProperty]
    public ?ExportEntity $otherReference = null;
    // endregion

    // region property without Importable attribute
    public string $notImported = 'initial';
    // endregion

    // region array property with listof Attribute for a DTO
    #[ImportableProperty(listOf: TestDTO::class)]
    #[ORM\Column]
    public array $dtoList = [];
    // endregion

    // region array property with listof Attribute for an Interface
    #[ImportableProperty(listOf: DtoInterface::class)]
    #[ORM\Column]
    public array $interfaceList = [];
    // endregion

    // region union-typed, nullable property
    #[ImportableProperty]
    public TestCase|EntityManager|null $union = null;
    // endregion

    // region untyped property
    #[ImportableProperty]
    public $untypedProp;
    // endregion

    public function __construct()
    {
        $this->collection = new ArrayCollection();
    }
}
