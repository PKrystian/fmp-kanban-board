<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BoardColumn;
use App\Entity\Card;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CardArchiver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function archive(Card $card): void
    {
        if ($card->isArchived()) {
            throw new \InvalidArgumentException('The card is already archived');
        }

        $column = $card->getColumn();
        if (!$column instanceof BoardColumn) {
            throw new \InvalidArgumentException('The card must belong to a column');
        }

        $activeCards = array_values(array_filter(
            $column->getCards()->toArray(),
            static fn (Card $existingCard): bool => $existingCard !== $card && !$existingCard->isArchived(),
        ));

        $this->entityManager->wrapInTransaction(function () use ($card, $activeCards): void {
            $card->setArchivedAt(new \DateTimeImmutable());
            $this->setPositions($activeCards);
            $this->entityManager->flush();
        });
    }

    public function restore(Card $card): void
    {
        if (!$card->isArchived()) {
            throw new \InvalidArgumentException('The card is not archived');
        }

        $column = $card->getColumn();
        if (!$column instanceof BoardColumn) {
            throw new \InvalidArgumentException('The card must belong to a column');
        }

        $activeCards = array_values(array_filter(
            $column->getCards()->toArray(),
            static fn (Card $existingCard): bool => !$existingCard->isArchived(),
        ));

        $this->entityManager->wrapInTransaction(function () use ($card, $activeCards): void {
            $card
                ->setArchivedAt(null)
                ->setPosition(count($activeCards) + 1);
            $this->entityManager->flush();
        });
    }

    /**
     * @param list<Card> $cards
     */
    private function setPositions(array $cards): void
    {
        foreach ($cards as $index => $card) {
            $card->setPosition($index + 1);
        }
    }
}
