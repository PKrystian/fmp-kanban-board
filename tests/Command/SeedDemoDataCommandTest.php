<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedDemoDataCommand;
use App\Entity\Board;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SeedDemoDataCommandTest extends KernelTestCase
{
    public function testCommandDoesNotModifyExistingDemoAccounts(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\Board board')->execute();
        $entityManager->createQuery('DELETE FROM App\Entity\User user')->execute();

        $application = new Application(self::$kernel);
        $commandTester = new CommandTester($application->find('app:seed-demo-data'));
        self::assertSame(0, $commandTester->execute([]));

        $entityManager->clear();
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => 'jan.nowak@example.com']);
        self::assertInstanceOf(User::class, $existingUser);
        $existingUser
            ->setDisplayName('Existing Jan')
            ->setPassword($passwordHasher->hashPassword($existingUser, 'private-password'));
        $entityManager->persist(
            (new Board())
                ->setName('Private board')
                ->setOwner($existingUser),
        );
        $entityManager->flush();

        self::assertSame(0, $commandTester->execute([]));

        $entityManager->clear();

        foreach (['jan.nowak@example.com', 'anna.nowak@example.com'] as $email) {
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(User::class, $user);
            if ('jan.nowak@example.com' === $email) {
                self::assertSame('Existing Jan', $user->getDisplayName());
                self::assertTrue($passwordHasher->isPasswordValid($user, 'private-password'));
                self::assertSame(3, $entityManager->getRepository(Board::class)->count(['owner' => $user]));
            } else {
                self::assertTrue($passwordHasher->isPasswordValid($user, 'zaq1@WSX'));
                self::assertSame(2, $entityManager->getRepository(Board::class)->count(['owner' => $user]));
            }
            self::assertSame(8, $this->countCardsFor($user, $entityManager));
        }
    }

    public function testCommandIsBlockedInProduction(): void
    {
        $kernel = self::createMock(KernelInterface::class);
        $kernel
            ->expects(self::once())
            ->method('getEnvironment')
            ->willReturn('prod');

        $command = new SeedDemoDataCommand(
            self::createStub(EntityManagerInterface::class),
            self::createStub(UserPasswordHasherInterface::class),
            self::createStub(UserRepository::class),
            $kernel,
        );
        $commandTester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $commandTester->execute([]));
        self::assertStringContainsString('cannot be seeded', $commandTester->getDisplay());
    }

    private function countCardsFor(User $user, EntityManagerInterface $entityManager): int
    {
        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(card.id)')
            ->from('App\Entity\Card', 'card')
            ->innerJoin('card.column', 'board_column')
            ->innerJoin('board_column.board', 'board')
            ->andWhere('board.owner = :owner')
            ->setParameter('owner', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
