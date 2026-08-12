<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthenticationControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\User u')
            ->execute();
        self::ensureKernelShutdown();
    }

    public function testUserCanRegister(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/register');

        $client->submit($crawler->selectButton('Register')->form([
            'registration_form[displayName]' => 'Jan Nowak',
            'registration_form[email]' => 'jan@example.com',
            'registration_form[plainPassword]' => 'password123',
        ]));

        self::assertResponseRedirects('/login');
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'jan@example.com']);
        self::assertSame('Jan Nowak', $user?->getDisplayName());
    }

    public function testUserCanLogIn(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setDisplayName('Jan Nowak')
            ->setEmail('jan@example.com');
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password123'));
        $entityManager->persist($user);
        $entityManager->flush();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Log in')->form([
            '_username' => 'jan@example.com',
            '_password' => 'password123',
        ]));

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('.navbar-text', 'Jan Nowak');
    }
}
