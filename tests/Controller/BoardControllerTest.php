<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\BoardColumn;
use App\Entity\Card;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class BoardControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\Board b')->execute();
        $entityManager->createQuery('DELETE FROM App\Entity\User u')->execute();
        self::ensureKernelShutdown();
    }

    public function testLoggedInUserCanCreateBoard(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/boards');
        $client->submit($crawler->selectButton('Create board')->form([
            'board[name]' => 'Product roadmap',
        ]));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $board = $entityManager
            ->getRepository(Board::class)
            ->findOneBy(['name' => 'Product roadmap']);

        self::assertInstanceOf(Board::class, $board);
        self::assertResponseRedirects('/boards/'.$board->getId());
        self::assertSame($user->getId(), $board->getOwner()?->getId());
        self::assertSame(
            ['Backlog', 'To do', 'In progress', 'Done'],
            $board->getColumns()->map(static fn ($column): ?string => $column->getName())->toArray(),
        );
        self::assertSame(
            [1, 2, 3, 4],
            $board->getColumns()->map(static fn ($column): ?int => $column->getPosition())->toArray(),
        );

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Product roadmap');
    }

    public function testUserCannotOpenAnotherUsersBoard(): void
    {
        $client = self::createClient();
        $owner = $this->createUser('owner@example.com');
        $otherUser = $this->createUser('other@example.com');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $board = (new Board())
            ->setName('Private board')
            ->setOwner($owner);
        $entityManager->persist($board);
        $entityManager->flush();

        $client->loginUser($otherUser);
        $client->request('GET', '/boards/'.$board->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAjaxRequestToAnotherUsersBoardReturnsForbiddenResponse(): void
    {
        $client = self::createClient();
        $owner = $this->createUser('owner@example.com');
        $otherUser = $this->createUser('other@example.com');
        [$board] = $this->createBoard($owner);

        $client->loginUser($otherUser);
        $client->request(
            'GET',
            '/boards/'.$board->getId(),
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringContainsString('Access denied', (string) $client->getResponse()->getContent());
    }

    public function testNonEmptyColumnCannotBeDeleted(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        $backlog = (new BoardColumn())
            ->setName('Backlog')
            ->setPosition(1);
        $board = (new Board())
            ->setName('Product roadmap')
            ->setOwner($user)
            ->addColumn($backlog)
            ->addColumn(
                (new BoardColumn())
                    ->setName('Done')
                    ->setPosition(2),
            );
        $card = (new Card())
            ->setTitle('Keep this card')
            ->setPosition(1)
            ->setColumn($backlog);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($board);
        $entityManager->persist($card);
        $entityManager->flush();
        $columnId = $backlog->getId();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/boards/'.$board->getId());
        $deleteForm = $crawler
            ->filter('#delete-column-'.$columnId)
            ->selectButton('Delete column')
            ->form();
        $client->submit($deleteForm);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'cannot be deleted because it contains cards');
        $entityManager->clear();
        self::assertInstanceOf(BoardColumn::class, $entityManager->find(BoardColumn::class, $columnId));
    }

    public function testLastColumnCannotBeDeleted(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        $column = (new BoardColumn())
            ->setName('Backlog')
            ->setPosition(1);
        $board = (new Board())
            ->setName('Product roadmap')
            ->setOwner($user)
            ->addColumn($column);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($board);
        $entityManager->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/boards/'.$board->getId());
        $client->submit(
            $crawler
                ->filter('#delete-column-'.$column->getId())
                ->selectButton('Delete column')
                ->form(),
        );
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'last column on a board');
        $entityManager->clear();
        self::assertInstanceOf(BoardColumn::class, $entityManager->find(BoardColumn::class, $column->getId()));
    }

    /**
     * @return array{Board, BoardColumn}
     */
    private function createBoard(User $owner): array
    {
        $backlog = (new BoardColumn())
            ->setName('Backlog')
            ->setPosition(1);
        $board = (new Board())
            ->setName('Product roadmap')
            ->setOwner($owner)
            ->addColumn($backlog)
            ->addColumn(
                (new BoardColumn())
                    ->setName('Done')
                    ->setPosition(2),
            );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($board);
        $entityManager->flush();

        return [$board, $backlog];
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setDisplayName('Test User')
            ->setEmail($email)
            ->setPassword('password');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
