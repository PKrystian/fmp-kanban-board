<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\BoardColumn;
use App\Entity\Card;
use App\Entity\User;
use App\Form\BoardType;
use App\Form\CardDeleteType;
use App\Form\CardType;
use App\Security\BoardVoter;
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

            $this->addFlash('success', 'Board created.');

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
    public function show(Board $board, FormFactoryInterface $formFactory): Response
    {
        $this->denyAccessUnlessGranted(BoardVoter::VIEW, $board);

        $cardDeleteForms = [];
        $quickCreateForms = [];
        foreach ($board->getColumns() as $column) {
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

            foreach ($column->getCards() as $card) {
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
            'cardDeleteForms' => $cardDeleteForms,
            'quickCreateForms' => $quickCreateForms,
        ]);
    }
}
