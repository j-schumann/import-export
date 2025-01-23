<?php

declare(strict_types=1);

namespace Vrok\ImportExport\Tests\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Vrok\ImportExport\ExportableProperty;

#[ORM\Entity]
class ExportEntity
{
    // region builtin-typed property w/o getter
    #[ExportableProperty]
    #[ORM\Id]
    #[ORM\Column]
    public int $id = 0;
    // endregion

    // region nullable, builtin-typed property w/ getter
    #[ExportableProperty]
    private ?string $name = null;

    public function getName(): ?string
    {
        return $this->name.' via getter';
    }

    public function setName(?string $value): self
    {
        $this->name = $value;

        return $this;
    }
    // endregion

    // region Collection property
    #[ExportableProperty]
    private readonly Collection $collection;

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

    // region Collection property w/ referenceByIdentifier
    #[ExportableProperty(referenceByIdentifier: 'id')]
    private readonly Collection $refCollection;

    public function getRefCollection(): Collection
    {
        return $this->refCollection;
    }

    public function setRefCollection(array $elements): self
    {
        $this->refCollection->clear();
        foreach ($elements as $element) {
            $this->addToRefCollection($element);
        }

        return $this;
    }

    public function addToRefCollection(self $element): self
    {
        if ($this->refCollection->contains($element)) {
            return $this;
        }

        $this->refCollection->add($element);

        return $this;
    }
    // endregion

    // region self-referencing, nullable object property
    #[ExportableProperty]
    private ?self $parent = null;

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

    // region self-referencing, nullable object property w/ referenceByIdentifier
    #[ExportableProperty(referenceByIdentifier: 'name')]
    private ?self $reference = null;

    public function getReference(): ?self
    {
        return $this->reference;
    }

    public function setReference(?self $value): self
    {
        $this->reference = $value;

        return $this;
    }
    // endregion

    // region DateTime property
    #[ExportableProperty]
    public ?\DateTimeImmutable $timestamp = null;
    // endregion

    // region DTO list
    #[ExportableProperty(asList: true)]
    public array $dtoList = [];
    // endregion

    // region array property
    #[ExportableProperty]
    public array $arrayProp = [];
    // endregion

    public string $notExported = 'hidden';

    public function __construct()
    {
        $this->collection = new ArrayCollection();
        $this->refCollection = new ArrayCollection();
    }
}
