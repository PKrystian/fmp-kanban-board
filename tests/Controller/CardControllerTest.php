<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Board;
use App\Entity\BoardColumn;
use App\Entity\Card;
use App\Entity\User;
use App\Enum\CardPriority;
use App\Enum\CardType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

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

    public function testUnauthenticatedAjaxRequestReturnsUnauthorizedResponse(): void
    {
        $client = self::createClient();
        $client->request(
            'GET',
            '/boards/1/cards/1/edit',
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringContainsString('Authentication required', (string) $client->getResponse()->getContent());
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
            'card[description]' => 'Cover the new board workflow',
        ]));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $card = $entityManager->getRepository(Card::class)->findOneBy(['title' => 'Write release notes']);

        self::assertInstanceOf(Card::class, $card);
        self::assertResponseRedirects('/boards/'.$board->getId());
        self::assertSame('Cover the new board workflow', $card->getDescription());
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
            'card[description]' => 'Reviewed and ready',
            'card[type]' => 'bug',
            'card[priority]' => 'critical',
            'card[dueDate]' => '2026-08-20',
            'card[column]' => (string) $done->getId(),
        ]));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $updatedCard = $entityManager->find(Card::class, $cardId);

        self::assertInstanceOf(Card::class, $updatedCard);
        self::assertResponseRedirects('/boards/'.$board->getId());
        self::assertSame('Ready card', $updatedCard->getTitle());
        self::assertSame('Reviewed and ready', $updatedCard->getDescription());
        self::assertSame(CardType::Bug, $updatedCard->getType());
        self::assertSame(CardPriority::Critical, $updatedCard->getPriority());
        self::assertSame('2026-08-20', $updatedCard->getDueDate()?->format('Y-m-d'));
        self::assertSame($done->getId(), $updatedCard->getColumn()?->getId());
        self::assertSame(2, $updatedCard->getPosition());

        $crawler = $client->request('GET', '/boards/'.$board->getId().'/cards/'.$cardId.'/edit');
        $reopenedForm = $crawler->selectButton('Save changes')->form();

        self::assertSame('bug', $reopenedForm->get('card[type]')->getValue());
        self::assertSame('critical', $reopenedForm->get('card[priority]')->getValue());
        self::assertSame('2026-08-20', $reopenedForm->get('card[dueDate]')->getValue());
    }

    public function testLoggedInUserCanMoveCardAndBothColumnsKeepSequentialOrder(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        [$board, $backlog, $done] = $this->createBoard($user);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach ([
            ['Backlog first', 1, $backlog],
            ['Card to move', 2, $backlog],
            ['Backlog last', 3, $backlog],
            ['Done first', 1, $done],
            ['Done last', 2, $done],
        ] as [$title, $position, $column]) {
            $entityManager->persist(
                (new Card())
                    ->setTitle($title)
                    ->setPosition($position)
                    ->setColumn($column),
            );
        }
        $entityManager->flush();
        $card = $entityManager->getRepository(Card::class)->findOneBy(['title' => 'Card to move']);
        self::assertInstanceOf(Card::class, $card);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/boards/'.$board->getId());
        $csrfToken = $crawler->filter('[data-kanban-board]')->attr('data-card-move-token');
        $client->request(
            'POST',
            '/boards/'.$board->getId().'/cards/'.$card->getId().'/move',
            ['columnId' => $done->getId(), 'position' => 2],
            server: ['HTTP_X_CSRF_TOKEN' => $csrfToken],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        self::assertSame(
            ['Backlog first', 'Backlog last'],
            array_map(
                static fn (Card $existingCard): ?string => $existingCard->getTitle(),
                $entityManager->getRepository(Card::class)->findBy(['column' => $backlog->getId()], ['position' => 'ASC']),
            ),
        );
        self::assertSame(
            [1, 2],
            array_map(
                static fn (Card $existingCard): ?int => $existingCard->getPosition(),
                $entityManager->getRepository(Card::class)->findBy(['column' => $backlog->getId()], ['position' => 'ASC']),
            ),
        );
        self::assertSame(
            ['Done first', 'Card to move', 'Done last'],
            array_map(
                static fn (Card $existingCard): ?string => $existingCard->getTitle(),
                $entityManager->getRepository(Card::class)->findBy(['column' => $done->getId()], ['position' => 'ASC']),
            ),
        );
        self::assertSame(
            [1, 2, 3],
            array_map(
                static fn (Card $existingCard): ?int => $existingCard->getPosition(),
                $entityManager->getRepository(Card::class)->findBy(['column' => $done->getId()], ['position' => 'ASC']),
            ),
        );
    }

    public function testMoveRejectsAColumnFromAnotherUsersBoard(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        $otherUser = $this->createUser('other@example.com');
        [$board, $backlog] = $this->createBoard($user);
        [, $foreignColumn] = $this->createBoard($otherUser);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $card = (new Card())
            ->setTitle('Private card')
            ->setPosition(1)
            ->setColumn($backlog);
        $entityManager->persist($card);
        $entityManager->flush();
        $cardId = $card->getId();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/boards/'.$board->getId());
        $csrfToken = $crawler->filter('[data-kanban-board]')->attr('data-card-move-token');
        $client->request(
            'POST',
            '/boards/'.$board->getId().'/cards/'.$cardId.'/move',
            ['columnId' => $foreignColumn->getId(), 'position' => 1],
            server: ['HTTP_X_CSRF_TOKEN' => $csrfToken],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $unchangedCard = $entityManager->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $unchangedCard);
        self::assertSame($backlog->getId(), $unchangedCard->getColumn()?->getId());
        self::assertSame(1, $unchangedCard->getPosition());
    }

    public function testUserCannotOpenAnotherUsersCardThroughOwnedBoardUrl(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        $otherUser = $this->createUser('other@example.com');
        [$board] = $this->createBoard($user);
        [, $foreignColumn] = $this->createBoard($otherUser);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $foreignCard = (new Card())
            ->setTitle('Private card')
            ->setPosition(1)
            ->setColumn($foreignColumn);
        $entityManager->persist($foreignCard);
        $entityManager->flush();
        $client->loginUser($user);

        $client->request('GET', '/boards/'.$board->getId().'/cards/'.$foreignCard->getId().'/edit');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDangerousMarkdownIsRenderedWithoutHtmlOrUnsafeLinks(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        [$board, $backlog] = $this->createBoard($user);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(
            (new Card())
                ->setTitle('Security notes')
                ->setDescription('**Visible text** [unsafe link](javascript:alert(1)) <script>alert("xss")</script>')
                ->setPosition(1)
                ->setColumn($backlog),
        );
        $entityManager->flush();
        $client->loginUser($user);

        $client->request('GET', '/boards/'.$board->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('strong', 'Visible text');
        self::assertSelectorTextContains('[data-card-entry]', 'unsafe link');
        self::assertSelectorNotExists('[data-card-description] script');
        self::assertSelectorNotExists('[data-card-description] a[href]');
        self::assertStringNotContainsString('javascript:', (string) $client->getResponse()->getContent());
    }

    public function testCardCanBeArchivedAndRestoredToItsPreviousColumn(): void
    {
        $client = self::createClient();
        $user = $this->createUser('owner@example.com');
        [$board, $backlog] = $this->createBoard($user);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $card = (new Card())
            ->setTitle('Temporarily archived')
            ->setPosition(1)
            ->setColumn($backlog);
        $entityManager->persist($card);
        $entityManager->flush();
        $cardId = $card->getId();
        $columnId = $backlog->getId();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/boards/'.$board->getId());
        $archiveForm = $crawler
            ->filter('#archive-card-'.$cardId)
            ->selectButton('Archive card')
            ->form();
        $client->submit($archiveForm);

        self::assertResponseRedirects('/boards/'.$board->getId());
        $entityManager->clear();
        $archivedCard = $entityManager->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $archivedCard);
        self::assertNotNull($archivedCard->getArchivedAt());

        $crawler = $client->followRedirect();
        self::assertSelectorNotExists('[data-card-id="'.$cardId.'"]');
        self::assertSame('0', trim($crawler->filter('[data-column-id="'.$columnId.'"] [data-card-count]')->text()));

        $crawler = $client->request('GET', '/boards/'.$board->getId().'/archive');
        self::assertSelectorTextContains('.card-title', 'Temporarily archived');
        self::assertSelectorTextContains('article', 'Previous column: Backlog');
        $client->submit($crawler->selectButton('Restore card')->form());

        self::assertResponseRedirects('/boards/'.$board->getId().'/archive');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $restoredCard = $entityManager->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $restoredCard);
        self::assertNull($restoredCard->getArchivedAt());
        self::assertSame($columnId, $restoredCard->getColumn()?->getId());

        $client->request('GET', '/boards/'.$board->getId());
        self::assertSelectorTextContains('[data-card-id="'.$cardId.'"] .card-title', 'Temporarily archived');
        self::assertSelectorTextContains('[data-column-id="'.$columnId.'"] [data-card-count]', '1');
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
