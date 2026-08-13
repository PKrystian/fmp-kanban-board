<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\BoardColumn;
use App\Entity\Card;
use App\Form\BoardColumnType;
use App\Security\BoardVoter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/boards/{boardId}/columns', requirements: ['boardId' => '\\d+'])]
final class BoardColumnController extends AbstractController
{
    #[Route('/new', name: 'app_board_column_new', methods: ['POST'])]
    public function new(
        #[MapEntity(id: 'boardId')] Board $board,
        Request $request,
        EntityManagerInterface $entityManager,
        FormFactoryInterface $formFactory,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        $column = (new BoardColumn())
            ->setBoard($board);
        $form = $formFactory->createNamed('new_column', BoardColumnType::class, $column);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $boardId = $board->getId();
            if (null === $boardId) {
                throw $this->createNotFoundException();
            }

            $entityManager->wrapInTransaction(function () use ($boardId, $column, $entityManager): void {
                $lockedBoard = $entityManager->find(
                    Board::class,
                    $boardId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedBoard instanceof Board) {
                    throw $this->createNotFoundException();
                }

                $column
                    ->setBoard($lockedBoard)
                    ->setPosition($this->nextPosition($lockedBoard, $entityManager));
                $entityManager->persist($column);
                $entityManager->flush();
            });
            $this->addFlash('success', 'Column created');
        } else {
            $this->addFlash('danger', 'The column could not be created. Check its name and WIP limit');
        }

        return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
    }

    #[Route('/{columnId}/edit', name: 'app_board_column_edit', requirements: ['columnId' => '\\d+'], methods: ['POST'])]
    public function edit(
        #[MapEntity(id: 'boardId')] Board $board,
        #[MapEntity(id: 'columnId')] BoardColumn $column,
        Request $request,
        EntityManagerInterface $entityManager,
        FormFactoryInterface $formFactory,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);
        $this->denyUnlessColumnBelongsToBoard($column, $board);

        $form = $formFactory->createNamed('edit_column_'.$column->getId(), BoardColumnType::class, $column);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Column updated');
        } else {
            $this->addFlash('danger', 'The column could not be updated. Check its name and WIP limit');
        }

        return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
    }

    #[Route('/reorder', name: 'app_board_column_reorder', methods: ['POST'])]
    public function reorder(
        #[MapEntity(id: 'boardId')] Board $board,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        if (!$this->isCsrfTokenValid('reorder_columns_'.$board->getId(), $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $columnIds = array_map('intval', $request->getPayload()->all('columnIds'));
        $columnsById = [];
        foreach ($board->getColumns() as $column) {
            $columnsById[$column->getId()] = $column;
        }

        if (count($columnIds) !== count($columnsById)
            || count(array_unique($columnIds)) !== count($columnIds)
            || array_diff($columnIds, array_keys($columnsById))
        ) {
            return $this->json(['error' => 'Invalid column order'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entityManager->wrapInTransaction(function () use ($columnIds, $columnsById, $entityManager): void {
            foreach ($columnIds as $index => $columnId) {
                $columnsById[$columnId]->setPosition($index + 1);
            }

            $entityManager->flush();
        });

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{columnId}/delete', name: 'app_board_column_delete', requirements: ['columnId' => '\\d+'], methods: ['POST'])]
    public function delete(
        #[MapEntity(id: 'boardId')] Board $board,
        #[MapEntity(id: 'columnId')] BoardColumn $column,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);
        $this->denyUnlessColumnBelongsToBoard($column, $board);

        if (!$this->isCsrfTokenValid('delete_column_'.$column->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $boardId = $board->getId();
        $columnId = $column->getId();
        if (null === $boardId || null === $columnId) {
            throw $this->createNotFoundException();
        }

        $deleteResult = $entityManager->wrapInTransaction(function () use ($boardId, $columnId, $entityManager): string {
            $lockedBoard = $entityManager->find(
                Board::class,
                $boardId,
                LockMode::PESSIMISTIC_WRITE,
            );
            $lockedColumn = $entityManager->find(
                BoardColumn::class,
                $columnId,
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$lockedBoard instanceof Board
                || !$lockedColumn instanceof BoardColumn
                || $lockedColumn->getBoard()?->getId() !== $boardId
            ) {
                throw $this->createNotFoundException();
            }

            if ($entityManager->getRepository(Card::class)->count(['column' => $lockedColumn]) > 0) {
                return 'has_cards';
            }

            if ($entityManager->getRepository(BoardColumn::class)->count(['board' => $lockedBoard]) <= 1) {
                return 'last_column';
            }

            $remainingColumns = array_values(array_filter(
                $entityManager->getRepository(BoardColumn::class)->findBy(
                    ['board' => $lockedBoard],
                    ['position' => 'ASC', 'id' => 'ASC'],
                ),
                static fn (BoardColumn $existingColumn): bool => $existingColumn->getId() !== $columnId,
            ));

            $entityManager->remove($lockedColumn);
            foreach ($remainingColumns as $index => $remainingColumn) {
                $remainingColumn->setPosition($index + 1);
            }
            $entityManager->flush();

            return 'deleted';
        });

        if ('has_cards' === $deleteResult) {
            $this->addFlash('warning', 'This column cannot be deleted because it contains cards');

            return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
        }

        if ('last_column' === $deleteResult) {
            $this->addFlash('warning', 'The last column on a board cannot be deleted');

            return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
        }

        $this->addFlash('success', 'Column deleted');

        return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
    }

    private function denyUnlessColumnBelongsToBoard(BoardColumn $column, Board $board): void
    {
        if ($column->getBoard()?->getId() !== $board->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function nextPosition(Board $board, EntityManagerInterface $entityManager): int
    {
        $lastPosition = $entityManager->createQueryBuilder()
            ->select('COALESCE(MAX(board_column.position), 0)')
            ->from(BoardColumn::class, 'board_column')
            ->andWhere('board_column.board = :board')
            ->setParameter('board', $board)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $lastPosition + 1;
    }
}
