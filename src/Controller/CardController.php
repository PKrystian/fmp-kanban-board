<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\BoardColumn;
use App\Entity\Card;
use App\Form\CardDeleteType;
use App\Form\CardType;
use App\Security\BoardVoter;
use App\Service\CardArchiver;
use App\Service\CardMover;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/boards/{boardId}', requirements: ['boardId' => '\\d+'])]
final class CardController extends AbstractController
{
    #[Route('/columns/{columnId}/cards/new', name: 'app_card_new', requirements: ['columnId' => '\\d+'], methods: ['GET', 'POST'])]
    public function new(
        #[MapEntity(id: 'boardId')] Board $board,
        #[MapEntity(id: 'columnId')] BoardColumn $column,
        Request $request,
        EntityManagerInterface $entityManager,
        FormFactoryInterface $formFactory,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);
        $this->denyUnlessColumnBelongsToBoard($column, $board);

        $card = (new Card())->setColumn($column);
        $quickFormName = 'new_card_'.$column->getId();
        $isQuickCreate = $request->request->has($quickFormName);
        $form = $isQuickCreate
            ? $this->createQuickCreateForm($formFactory, $board, $column, $card)
            : $this->createForm(CardType::class, $card, ['board' => $board]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $columnId = $column->getId();
            if (null === $columnId) {
                throw $this->createNotFoundException();
            }

            $entityManager->wrapInTransaction(function () use ($card, $columnId, $entityManager): void {
                $lockedColumn = $entityManager->find(
                    BoardColumn::class,
                    $columnId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedColumn instanceof BoardColumn) {
                    throw $this->createNotFoundException();
                }

                $card
                    ->setColumn($lockedColumn)
                    ->setPosition($this->nextPosition($lockedColumn, $entityManager));
                $entityManager->persist($card);
                $entityManager->flush();
            });

            if ($request->isXmlHttpRequest()) {
                return $this->render('card/_mutation_success.html.twig', [
                    'card' => $card,
                    'deleteForm' => $this->createDeleteForm($formFactory, $board, $card)->createView(),
                    'message' => 'Card created',
                    'quickCreateForm' => $this->createQuickCreateForm($formFactory, $board, $column)->createView(),
                    'column' => $column,
                ]);
            }

            $this->addFlash('success', 'Card created');

            return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
        }

        if ($request->isXmlHttpRequest() && $isQuickCreate) {
            return $this->render('card/_quick_create_form.html.twig', [
                'board' => $board,
                'column' => $column,
                'cardForm' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('card/form.html.twig', [
            'board' => $board,
            'column' => $column,
            'cardForm' => $form,
            'heading' => 'Create a card',
            'submitLabel' => 'Create card',
        ]);
    }

    #[Route('/cards/{cardId}/edit', name: 'app_card_edit', requirements: ['cardId' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(id: 'boardId')] Board $board,
        #[MapEntity(id: 'cardId')] Card $card,
        Request $request,
        EntityManagerInterface $entityManager,
        FormFactoryInterface $formFactory,
        CardMover $cardMover,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);
        $this->denyUnlessCardBelongsToBoard($card, $board);
        $this->denyUnlessCardIsActive($card);

        $originalColumn = $card->getColumn();
        $form = $this->createForm(CardType::class, $card, [
            'action' => $this->generateUrl('app_card_edit', [
                'boardId' => $board->getId(),
                'cardId' => $card->getId(),
            ]),
            'board' => $board,
            'include_column' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedColumn = $card->getColumn();
            if (!$selectedColumn instanceof BoardColumn || $selectedColumn->getBoard()?->getId() !== $board->getId()) {
                throw $this->createAccessDeniedException();
            }

            if ($selectedColumn !== $originalColumn) {
                $card->setColumn($originalColumn);
                $cardMover->moveToEnd($card, $selectedColumn);
            } else {
                $entityManager->flush();
            }

            if ($request->isXmlHttpRequest()) {
                return $this->render('card/_mutation_success.html.twig', [
                    'card' => $card,
                    'deleteForm' => $this->createDeleteForm($formFactory, $board, $card)->createView(),
                    'message' => 'Card updated',
                ]);
            }

            $this->addFlash('success', 'Card updated');

            return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
        }

        $template = $request->isXmlHttpRequest() ? 'card/_panel.html.twig' : 'card/form.html.twig';
        $response = $this->render($template, [
            'board' => $board,
            'column' => $originalColumn,
            'cardForm' => $form,
            'heading' => 'Edit card',
            'submitLabel' => 'Save changes',
        ]);

        if ($request->isXmlHttpRequest() && $form->isSubmitted()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/cards/{cardId}/move', name: 'app_card_move', requirements: ['cardId' => '\\d+'], methods: ['POST'])]
    public function move(
        #[MapEntity(id: 'boardId')] Board $board,
        #[MapEntity(id: 'cardId')] Card $card,
        Request $request,
        EntityManagerInterface $entityManager,
        CardMover $cardMover,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);
        $this->denyUnlessCardBelongsToBoard($card, $board);
        $this->denyUnlessCardIsActive($card);

        if (!$this->isCsrfTokenValid('move_cards_'.$board->getId(), $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $payload = $request->getPayload();
        $targetColumn = $entityManager->find(BoardColumn::class, $payload->getInt('columnId'));
        if (!$targetColumn instanceof BoardColumn || $targetColumn->getBoard()?->getId() !== $board->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $cardMover->move($card, $targetColumn, $payload->getInt('position'));
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid card position'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/cards/{cardId}/delete', name: 'app_card_delete', requirements: ['cardId' => '\\d+'], methods: ['POST'])]
    public function delete(
        #[MapEntity(id: 'boardId')] Board $board,
        #[MapEntity(id: 'cardId')] Card $card,
        Request $request,
        EntityManagerInterface $entityManager,
        FormFactoryInterface $formFactory,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);
        $this->denyUnlessCardBelongsToBoard($card, $board);
        $this->denyUnlessCardIsActive($card);

        $form = $formFactory->createNamed(
            'delete_card_'.$card->getId(),
            CardDeleteType::class,
            null,
            ['csrf_token_id' => 'delete_card_'.$card->getId()],
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->remove($card);
            $entityManager->flush();

            $this->addFlash('success', 'Card deleted');
        } else {
            $this->addFlash('danger', 'The card could not be deleted');
        }

        return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
    }

    #[Route('/cards/{cardId}/archive', name: 'app_card_archive', requirements: ['cardId' => '\\d+'], methods: ['POST'])]
    public function archive(
        #[MapEntity(id: 'boardId')] Board $board,
        #[MapEntity(id: 'cardId')] Card $card,
        Request $request,
        CardArchiver $cardArchiver,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);
        $this->denyUnlessCardBelongsToBoard($card, $board);

        if (!$this->isCsrfTokenValid('archive_card_'.$card->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if ($card->isArchived()) {
            throw $this->createNotFoundException();
        }

        $cardArchiver->archive($card);
        $this->addFlash('success', 'Card archived');

        return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
    }

    #[Route('/cards/{cardId}/restore', name: 'app_card_restore', requirements: ['cardId' => '\\d+'], methods: ['POST'])]
    public function restore(
        #[MapEntity(id: 'boardId')] Board $board,
        #[MapEntity(id: 'cardId')] Card $card,
        Request $request,
        CardArchiver $cardArchiver,
    ): Response {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);
        $this->denyUnlessCardBelongsToBoard($card, $board);

        if (!$this->isCsrfTokenValid('restore_card_'.$card->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if (!$card->isArchived()) {
            throw $this->createNotFoundException();
        }

        $cardArchiver->restore($card);
        $this->addFlash('success', 'Card restored to '.$card->getColumn()?->getName());

        return $this->redirectToRoute('app_board_archive', ['id' => $board->getId()]);
    }

    private function denyUnlessColumnBelongsToBoard(BoardColumn $column, Board $board): void
    {
        if ($column->getBoard()?->getId() !== $board->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function denyUnlessCardBelongsToBoard(Card $card, Board $board): void
    {
        $column = $card->getColumn();
        if (!$column instanceof BoardColumn || $column->getBoard()?->getId() !== $board->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function denyUnlessCardIsActive(Card $card): void
    {
        if ($card->isArchived()) {
            throw $this->createNotFoundException();
        }
    }

    private function nextPosition(BoardColumn $column, EntityManagerInterface $entityManager): int
    {
        $lastPosition = $entityManager->createQueryBuilder()
            ->select('COALESCE(MAX(card.position), 0)')
            ->from(Card::class, 'card')
            ->andWhere('card.column = :column')
            ->andWhere('card.archivedAt IS NULL')
            ->setParameter('column', $column)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $lastPosition + 1;
    }

    private function createQuickCreateForm(
        FormFactoryInterface $formFactory,
        Board $board,
        BoardColumn $column,
        ?Card $card = null,
    ): FormInterface {
        return $formFactory->createNamed(
            'new_card_'.$column->getId(),
            CardType::class,
            $card ?? (new Card())->setColumn($column),
            [
                'action' => $this->generateUrl('app_card_new', [
                    'boardId' => $board->getId(),
                    'columnId' => $column->getId(),
                ]),
                'board' => $board,
                'method' => 'POST',
            ],
        );
    }

    private function createDeleteForm(
        FormFactoryInterface $formFactory,
        Board $board,
        Card $card,
    ): FormInterface {
        return $formFactory->createNamed(
            'delete_card_'.$card->getId(),
            CardDeleteType::class,
            null,
            [
                'action' => $this->generateUrl('app_card_delete', [
                    'boardId' => $board->getId(),
                    'cardId' => $card->getId(),
                ]),
                'csrf_token_id' => 'delete_card_'.$card->getId(),
            ],
        );
    }
}
