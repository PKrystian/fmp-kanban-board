<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Board;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SeedDemoDataCommandTest extends KernelTestCase
{
    public function testCommandCanBeRunAgainWithoutDuplicatingDemoData(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\Board board')->execute();
        $entityManager->createQuery('DELETE FROM App\Entity\User user')->execute();

        $application = new Application(self::$kernel);
        $commandTester = new CommandTester($application->find('app:seed-demo-data'));
        self::assertSame(0, $commandTester->execute([]));
        self::assertSame(0, $commandTester->execute([]));

        $entityManager->clear();
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        foreach (['jan.nowak@example.com', 'anna.nowak@example.com'] as $email) {
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(User::class, $user);
            self::assertTrue($passwordHasher->isPasswordValid($user, 'zaq1@WSX'));
            self::assertSame(2, $entityManager->getRepository(Board::class)->count(['owner' => $user]));
            self::assertSame(8, $this->countCardsFor($user, $entityManager));
        }
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
