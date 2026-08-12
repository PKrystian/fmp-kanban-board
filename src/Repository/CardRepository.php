<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Board;
use App\Entity\Card;
use App\Enum\CardPriority;
use App\Enum\CardType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 */
final class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /**
     * @return list<Card>
     */
    public function findForBoard(
        Board $board,
        string $search,
        ?CardType $type,
        ?CardPriority $priority,
        ?\DateTimeImmutable $dueDate,
    ): array {
        $queryBuilder = $this->createQueryBuilder('card')
            ->innerJoin('card.column', 'board_column')
            ->andWhere('board_column.board = :board')
            ->setParameter('board', $board)
            ->orderBy('board_column.position', 'ASC')
            ->addOrderBy('card.position', 'ASC');

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(LOWER(card.title) LIKE :search OR LOWER(card.description) LIKE :search)')
                ->setParameter('search', '%'.strtolower($search).'%');
        }

        if (null !== $type) {
            $queryBuilder
                ->andWhere('card.type = :type')
                ->setParameter('type', $type->value);
        }

        if (null !== $priority) {
            $queryBuilder
                ->andWhere('card.priority = :priority')
                ->setParameter('priority', $priority->value);
        }

        if (null !== $dueDate) {
            $queryBuilder
                ->andWhere('card.dueDate = :dueDate')
                ->setParameter('dueDate', $dueDate, Types::DATE_IMMUTABLE);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @return array<int, int>
     */
    public function countByColumnForBoard(Board $board): array
    {
        $rows = $this->createQueryBuilder('card')
            ->select('IDENTITY(card.column) AS columnId', 'COUNT(card.id) AS cardCount')
            ->innerJoin('card.column', 'board_column')
            ->andWhere('board_column.board = :board')
            ->setParameter('board', $board)
            ->groupBy('card.column')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['columnId']] = (int) $row['cardCount'];
        }

        return $counts;
    }
}
