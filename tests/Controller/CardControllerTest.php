<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\BoardColumn;
use App\Entity\Card;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CardControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\Board b')->execute();
        $entityManager->createQuery('DELETE FROM App\Entity\User u')->execute();
        self::ensureKernelShutdown();
    }

    public function testLoggedInUserCanCreateCardAtEndOfColumn(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        [$board, $backlog] = $this->createBoard($user);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(
            (new Card())
                ->setTitle('Existing card')
                ->setPosition(1)
                ->setColumn($backlog),
        );
        $entityManager->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/boards/'.$board->getId());
        $crawler = $client->click($crawler->selectLink('Add card')->first()->link());
        $client->submit($crawler->selectButton('Create card')->form([
            'card[title]' => 'Write release notes',
            'card[description]' => 'Cover the new board workflow.',
        ]));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $card = $entityManager->getRepository(Card::class)->findOneBy(['title' => 'Write release notes']);

        self::assertInstanceOf(Card::class, $card);
        self::assertResponseRedirects('/boards/'.$board->getId());
        self::assertSame('Cover the new board workflow.', $card->getDescription());
        self::assertSame($backlog->getId(), $card->getColumn()?->getId());
        self::assertSame(2, $card->getPosition());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('.card-title', 'Write release notes');
    }

    public function testLoggedInUserCanEditCardAndMoveItToAnotherColumn(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        [$board, $backlog, $done] = $this->createBoard($user);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(
            (new Card())
                ->setTitle('Published card')
                ->setPosition(1)
                ->setColumn($done),
        );
        $card = (new Card())
            ->setTitle('Draft card')
            ->setPosition(1)
            ->setColumn($backlog);
        $entityManager->persist($card);
        $entityManager->flush();
        $cardId = $card->getId();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/boards/'.$board->getId().'/cards/'.$cardId.'/edit');
        $client->submit($crawler->selectButton('Save changes')->form([
            'card[title]' => 'Ready card',
            'card[description]' => 'Reviewed and ready.',
            'card[column]' => (string) $done->getId(),
        ]));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $updatedCard = $entityManager->find(Card::class, $cardId);

        self::assertInstanceOf(Card::class, $updatedCard);
        self::assertResponseRedirects('/boards/'.$board->getId());
        self::assertSame('Ready card', $updatedCard->getTitle());
        self::assertSame('Reviewed and ready.', $updatedCard->getDescription());
        self::assertSame($done->getId(), $updatedCard->getColumn()?->getId());
        self::assertSame(2, $updatedCard->getPosition());
    }

    /**
     * @return array{Board, BoardColumn, BoardColumn}
     */
    private function createBoard(User $owner): array
    {
        $backlog = (new BoardColumn())
            ->setName('Backlog')
            ->setPosition(1);
        $done = (new BoardColumn())
            ->setName('Done')
            ->setPosition(2);
        $board = (new Board())
            ->setName('Product roadmap')
            ->setOwner($owner)
            ->addColumn($backlog)
            ->addColumn($done);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($board);
        $entityManager->flush();

        return [$board, $backlog, $done];
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
