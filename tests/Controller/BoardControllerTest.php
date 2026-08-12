<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
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

        $board = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Board::class)
            ->findOneBy(['name' => 'Product roadmap']);

        self::assertInstanceOf(Board::class, $board);
        self::assertResponseRedirects('/boards/'.$board->getId());
        self::assertSame($user->getId(), $board->getOwner()?->getId());

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
