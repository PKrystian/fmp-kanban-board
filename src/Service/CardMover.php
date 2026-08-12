<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BoardColumn;
use App\Entity\Card;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CardMover
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function move(Card $card, BoardColumn $targetColumn, int $targetPosition): void
    {
        $sourceColumn = $card->getColumn();
        if (!$sourceColumn instanceof BoardColumn) {
            throw new \InvalidArgumentException('The card must belong to a column.');
        }

        $sourceCards = array_values(array_filter(
            $sourceColumn->getCards()->toArray(),
            static fn (Card $existingCard): bool => $existingCard !== $card,
        ));
        $targetCards = $sourceColumn === $targetColumn
            ? $sourceCards
            : array_values(array_filter(
                $targetColumn->getCards()->toArray(),
                static fn (Card $existingCard): bool => $existingCard !== $card,
            ));

        if ($targetPosition < 1 || $targetPosition > count($targetCards) + 1) {
            throw new \InvalidArgumentException('The target position is outside the column.');
        }

        array_splice($targetCards, $targetPosition - 1, 0, [$card]);

        $this->entityManager->wrapInTransaction(function () use ($card, $sourceColumn, $sourceCards, $targetColumn, $targetCards): void {
            $card->setColumn($targetColumn);

            if ($sourceColumn !== $targetColumn) {
                $this->setPositions($sourceCards);
            }
            $this->setPositions($targetCards);

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
