<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CardPriority;
use App\Enum\CardType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Card
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: CardType::class, options: ['default' => 'task'])]
    private CardType $type = CardType::Task;

    #[ORM\Column(length: 20, enumType: CardPriority::class, options: ['default' => 'medium'])]
    private CardPriority $priority = CardPriority::Medium;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column]
    private ?int $position = null;

    #[ORM\ManyToOne(inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BoardColumn $column = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): ?string
    {
        return null === $this->id ? null : 'FMP-'.$this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getType(): CardType
    {
        return $this->type;
    }

    public function setType(CardType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPriority(): CardPriority
    {
        return $this->priority;
    }

    public function setPriority(CardPriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getColumn(): ?BoardColumn
    {
        return $this->column;
    }

    public function setColumn(BoardColumn $column): static
    {
        $this->column = $column;

        return $this;
    }
}
