<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BoardColumn;
use App\Entity\Card;
use Doctrine\DBAL\LockMode;
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

        $cardId = $card->getId();
        $columnId = $column->getId();
        if (null === $cardId || null === $columnId) {
            throw new \InvalidArgumentException('The card and column must be persisted');
        }

        $this->entityManager->wrapInTransaction(function () use ($cardId, $columnId): void {
            $column = $this->lockColumn($columnId);
            $card = $this->entityManager->find(Card::class, $cardId, LockMode::PESSIMISTIC_WRITE);
            if (!$card instanceof Card
                || $card->isArchived()
                || $card->getColumn()?->getId() !== $columnId
            ) {
                throw new \InvalidArgumentException('The card is no longer in the column');
            }

            $activeCards = array_values(array_filter(
                $this->activeCards($column),
                static fn (Card $existingCard): bool => $existingCard->getId() !== $cardId,
            ));

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

        $cardId = $card->getId();
        $columnId = $column->getId();
        if (null === $cardId || null === $columnId) {
            throw new \InvalidArgumentException('The card and column must be persisted');
        }

        $this->entityManager->wrapInTransaction(function () use ($cardId, $columnId): void {
            $column = $this->lockColumn($columnId);
            $card = $this->entityManager->find(Card::class, $cardId, LockMode::PESSIMISTIC_WRITE);
            if (!$card instanceof Card
                || !$card->isArchived()
                || $card->getColumn()?->getId() !== $columnId
            ) {
                throw new \InvalidArgumentException('The card is no longer in the column');
            }

            $activeCards = $this->activeCards($column);
            $card
                ->setArchivedAt(null)
                ->setPosition(count($activeCards) + 1);
            $this->entityManager->flush();
        });
    }

    private function lockColumn(int $columnId): BoardColumn
    {
        $column = $this->entityManager->find(
            BoardColumn::class,
            $columnId,
            LockMode::PESSIMISTIC_WRITE,
        );
        if (!$column instanceof BoardColumn) {
            throw new \InvalidArgumentException('The column no longer exists');
        }

        return $column;
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
