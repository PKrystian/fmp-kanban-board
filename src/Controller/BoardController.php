<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\BoardColumn;
use App\Entity\Card;
use App\Entity\User;
use App\Enum\CardPriority;
use App\Enum\CardType as CardTypeEnum;
use App\Form\BoardType;
use App\Form\BoardColumnType;
use App\Form\CardDeleteType;
use App\Form\CardType;
use App\Security\BoardVoter;
use App\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/boards', name: 'app_board_')]
final class BoardController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $board = (new Board())->setOwner($user);
        $form = $this->createForm(BoardType::class, $board);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach (['Backlog', 'To do', 'In progress', 'Done'] as $position => $name) {
                $board->addColumn(
                    (new BoardColumn())
                        ->setName($name)
                        ->setPosition($position + 1),
                );
            }

            $entityManager->persist($board);
            $entityManager->flush();

            $this->addFlash('success', 'Board created');

            return $this->redirectToRoute('app_board_show', ['id' => $board->getId()]);
        }

        return $this->render('board/index.html.twig', [
            'boards' => $entityManager->getRepository(Board::class)->findBy(
                ['owner' => $user],
                ['id' => 'DESC'],
            ),
            'boardForm' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(
        Board $board,
        Request $request,
        FormFactoryInterface $formFactory,
        CardRepository $cardRepository,
    ): Response
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        $search = trim($request->query->getString('q'));
        $type = CardTypeEnum::tryFrom($request->query->getString('type'));
        $priority = CardPriority::tryFrom($request->query->getString('priority'));
        $dueDate = $this->parseDueDate($request->query->getString('due'));
        $filtersActive = '' !== $search || null !== $type || null !== $priority || null !== $dueDate;
        $cards = $cardRepository->findForBoard($board, $search, $type, $priority, $dueDate);
        $cardsByColumn = [];
        $visibleCardCounts = [];
        foreach ($board->getColumns() as $column) {
            $columnId = $column->getId();
            if (null === $columnId) {
                continue;
            }

            $cardsByColumn[$columnId] = [];
            $visibleCardCounts[$columnId] = 0;
        }

        foreach ($cards as $card) {
            $columnId = $card->getColumn()?->getId();
            if (null === $columnId) {
                continue;
            }

            $cardsByColumn[$columnId][] = $card;
            ++$visibleCardCounts[$columnId];
        }

        $totalCardCounts = $filtersActive
            ? $cardRepository->countByColumnForBoard($board)
            : $visibleCardCounts;

        $cardDeleteForms = [];
        $columnEditForms = [];
        $quickCreateForms = [];
        foreach ($board->getColumns() as $column) {
            $columnEditForms[$column->getId()] = $formFactory->createNamed(
                'edit_column_'.$column->getId(),
                BoardColumnType::class,
                $column,
                [
                    'action' => $this->generateUrl('app_board_column_edit', [
                        'boardId' => $board->getId(),
                        'columnId' => $column->getId(),
                    ]),
                    'method' => 'POST',
                ],
            )->createView();

            $quickCreateForms[$column->getId()] = $formFactory->createNamed(
                'new_card_'.$column->getId(),
                CardType::class,
                (new Card())->setColumn($column),
                [
                    'action' => $this->generateUrl('app_card_new', [
                        'boardId' => $board->getId(),
                        'columnId' => $column->getId(),
                    ]),
                    'board' => $board,
                    'method' => 'POST',
                ],
            )->createView();

            foreach ($cardsByColumn[$column->getId()] ?? [] as $card) {
                $cardDeleteForms[$card->getId()] = $formFactory->createNamed(
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
                )->createView();
            }
        }

        return $this->render('board/show.html.twig', [
            'board' => $board,
            'cardPriorities' => CardPriority::cases(),
            'cardsByColumn' => $cardsByColumn,
            'cardDeleteForms' => $cardDeleteForms,
            'cardTypes' => CardTypeEnum::cases(),
            'columnCreateForm' => $formFactory->createNamed(
                'new_column',
                BoardColumnType::class,
                new BoardColumn(),
                [
                    'action' => $this->generateUrl('app_board_column_new', ['boardId' => $board->getId()]),
                    'method' => 'POST',
                ],
            )->createView(),
            'columnEditForms' => $columnEditForms,
            'filters' => [
                'q' => $search,
                'type' => $type?->value ?? '',
                'priority' => $priority?->value ?? '',
                'due' => $dueDate?->format('Y-m-d') ?? '',
            ],
            'filtersActive' => $filtersActive,
            'quickCreateForms' => $quickCreateForms,
            'totalCardCounts' => $totalCardCounts,
            'visibleCardCounts' => $visibleCardCounts,
        ]);
    }

    #[Route('/{id}/archive', name: 'archive', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function archive(Board $board, CardRepository $cardRepository): Response
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        return $this->render('board/archive.html.twig', [
            'board' => $board,
            'cards' => $cardRepository->findArchivedForBoard($board),
        ]);
    }

    private function parseDueDate(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if (false === $date || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count']))) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }
}
