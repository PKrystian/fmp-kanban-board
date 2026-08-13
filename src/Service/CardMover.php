<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BoardColumn;
use App\Entity\Card;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CardMover
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function move(Card $card, BoardColumn $targetColumn, int $targetPosition): void
    {
        $this->moveInternal($card, $targetColumn, $targetPosition);
    }

    public function moveToEnd(Card $card, BoardColumn $targetColumn): void
    {
        $this->moveInternal($card, $targetColumn, null);
    }

    private function moveInternal(Card $card, BoardColumn $targetColumn, ?int $targetPosition): void
    {
        if ($card->isArchived()) {
            throw new \InvalidArgumentException('An archived card cannot be moved');
        }

        $sourceColumn = $card->getColumn();
        if (!$sourceColumn instanceof BoardColumn) {
            throw new \InvalidArgumentException('The card must belong to a column');
        }

        $cardId = $card->getId();
        $sourceColumnId = $sourceColumn->getId();
        $targetColumnId = $targetColumn->getId();
        if (null === $cardId || null === $sourceColumnId || null === $targetColumnId) {
            throw new \InvalidArgumentException('The card and columns must be persisted');
        }

        $this->entityManager->wrapInTransaction(function () use ($card, $cardId, $sourceColumnId, $targetColumnId, $targetPosition): void {
            $lockedColumns = $this->lockColumns([$sourceColumnId, $targetColumnId]);
            $sourceColumn = $lockedColumns[$sourceColumnId];
            $targetColumn = $lockedColumns[$targetColumnId];
            $managedCard = $this->entityManager->find(Card::class, $cardId);
            if (!$managedCard instanceof Card) {
                throw new \InvalidArgumentException('The card no longer exists');
            }
            $this->entityManager->lock($managedCard, LockMode::PESSIMISTIC_WRITE);

            $cardState = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(card.column) AS columnId')
                ->addSelect('CASE WHEN card.archivedAt IS NULL THEN 0 ELSE 1 END AS archived')
                ->from(Card::class, 'card')
                ->andWhere('card.id = :cardId')
                ->setParameter('cardId', $cardId)
                ->getQuery()
                ->getOneOrNullResult();

            if (!is_array($cardState)
                || (int) $cardState['columnId'] !== $sourceColumnId
                || 1 === (int) $cardState['archived']
            ) {
                throw new \InvalidArgumentException('The card is no longer in the source column');
            }

            $card = $managedCard;

            $sourceCards = array_values(array_filter(
                $this->activeCards($sourceColumn),
                static fn (Card $existingCard): bool => $existingCard->getId() !== $cardId,
            ));
            $targetCards = $sourceColumn === $targetColumn
                ? $sourceCards
                : array_values(array_filter(
                    $this->activeCards($targetColumn),
                    static fn (Card $existingCard): bool => $existingCard->getId() !== $cardId,
                ));

            $targetPosition ??= count($targetCards) + 1;
            if ($targetPosition < 1 || $targetPosition > count($targetCards) + 1) {
                throw new \InvalidArgumentException('The target position is outside the column');
            }

            array_splice($targetCards, $targetPosition - 1, 0, [$card]);
            $card->setColumn($targetColumn);

            if ($sourceColumn !== $targetColumn) {
                $this->setPositions($sourceCards);
            }
            $this->setPositions($targetCards);

            $this->entityManager->flush();
        });
    }

    /**
     * @param list<int> $columnIds
     * @return array<int, BoardColumn>
     */
    private function lockColumns(array $columnIds): array
    {
        sort($columnIds, SORT_NUMERIC);
        $lockedColumns = [];

        foreach (array_unique($columnIds) as $columnId) {
            $column = $this->entityManager->find(
                BoardColumn::class,
                $columnId,
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$column instanceof BoardColumn) {
                throw new \InvalidArgumentException('The column no longer exists');
            }

            $lockedColumns[$columnId] = $column;
        }

        return $lockedColumns;
    }

    /**
     * @return list<Card>
     */
    private function activeCards(BoardColumn $column): array
    {
        return $this->entityManager->getRepository(Card::class)->findBy(
            ['column' => $column, 'archivedAt' => null],
            ['position' => 'ASC', 'id' => 'ASC'],
        );
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
