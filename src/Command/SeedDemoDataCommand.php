<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Board;
use App\Entity\BoardColumn;
use App\Entity\Card;
use App\Entity\User;
use App\Enum\CardPriority;
use App\Enum\CardType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed-demo-data',
    description: 'Creates the demo users, boards and cards documented in README',
)]
final class SeedDemoDataCommand extends Command
{
    private const string DEMO_PASSWORD = 'zaq1@WSX';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->accounts() as $account) {
            $user = $this->userRepository->findOneBy(['email' => $account['email']]) ?? new User();
            $user
                ->setDisplayName($account['name'])
                ->setEmail($account['email']);
            $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $demoBoardNames = array_column($account['boards'], 'name');
            foreach ($this->entityManager->getRepository(Board::class)->findBy(['owner' => $user]) as $board) {
                if (in_array($board->getName(), $demoBoardNames, true)) {
                    $this->entityManager->remove($board);
                }
            }
            $this->entityManager->flush();

            foreach ($account['boards'] as $boardData) {
                $this->entityManager->persist($this->createBoard($user, $boardData));
            }
            $this->entityManager->flush();
        }

        $io->success('Demo data is ready. Existing demo boards were replaced; other boards were left unchanged.');
        $io->table(
            ['Name', 'Email', 'Password'],
            array_map(
                static fn (array $account): array => [$account['name'], $account['email'], self::DEMO_PASSWORD],
                $this->accounts(),
            ),
        );

        return Command::SUCCESS;
    }

    /**
     * @param array{
     *     name: string,
     *     cards: list<array{
     *         column: string,
     *         title: string,
     *         description: string,
     *         type: CardType,
     *         priority: CardPriority,
     *         dueInDays?: int
     *     }>
     * } $boardData
     */
    private function createBoard(User $owner, array $boardData): Board
    {
        $board = (new Board())
            ->setName($boardData['name'])
            ->setOwner($owner);
        $columns = [];

        foreach (['Backlog', 'To do', 'In progress', 'Done'] as $index => $name) {
            $column = (new BoardColumn())
                ->setName($name)
                ->setPosition($index + 1);
            $board->addColumn($column);
            $columns[$name] = $column;
        }

        $positions = [];
        foreach ($boardData['cards'] as $cardData) {
            $columnName = $cardData['column'];
            $position = ($positions[$columnName] ?? 0) + 1;
            $positions[$columnName] = $position;

            $card = (new Card())
                ->setTitle($cardData['title'])
                ->setDescription($cardData['description'])
                ->setType($cardData['type'])
                ->setPriority($cardData['priority'])
                ->setPosition($position);

            if (isset($cardData['dueInDays'])) {
                $card->setDueDate(new \DateTimeImmutable(sprintf('+%d days', $cardData['dueInDays'])));
            }

            $columns[$columnName]->addCard($card);
        }

        return $board;
    }

    /**
     * @return list<array{
     *     name: string,
     *     email: string,
     *     boards: list<array{
     *         name: string,
     *         cards: list<array{
     *             column: string,
     *             title: string,
     *             description: string,
     *             type: CardType,
     *             priority: CardPriority,
     *             dueInDays?: int
     *         }>
     *     }>
     * }>
     */
    private function accounts(): array
    {
        return [
            [
                'name' => 'Jan Nowak',
                'email' => 'jan.nowak@example.com',
                'boards' => [
                    [
                        'name' => 'Remont mieszkania',
                        'cards' => [
                            ['column' => 'Backlog', 'title' => 'Wybrać lampy do salonu', 'description' => 'Porównać trzy modele i sprawdzić barwę światła.', 'type' => CardType::Task, 'priority' => CardPriority::Low],
                            ['column' => 'To do', 'title' => 'Zamówić farbę', 'description' => 'Kupić 20 litrów zmywalnej farby w kolorze złamanej bieli.', 'type' => CardType::Task, 'priority' => CardPriority::High, 'dueInDays' => 3],
                            ['column' => 'In progress', 'title' => 'Przygotować ściany', 'description' => 'Uzupełnić ubytki, wyszlifować powierzchnię i zabezpieczyć podłogę.', 'type' => CardType::Story, 'priority' => CardPriority::Medium, 'dueInDays' => 5],
                            ['column' => 'Done', 'title' => 'Ustalić budżet remontu', 'description' => 'Budżet obejmuje materiały, robociznę oraz 10% rezerwy.', 'type' => CardType::Task, 'priority' => CardPriority::Medium],
                        ],
                    ],
                    [
                        'name' => 'Plan nauki Symfony',
                        'cards' => [
                            ['column' => 'Backlog', 'title' => 'Poznać Messenger', 'description' => 'Przejść dokumentację transportów i obsługi nieudanych wiadomości.', 'type' => CardType::Story, 'priority' => CardPriority::Low],
                            ['column' => 'To do', 'title' => 'Powtórzyć Doctrine ORM', 'description' => 'Przećwiczyć relacje, migracje oraz analizę zapytań w profilerze.', 'type' => CardType::Task, 'priority' => CardPriority::High, 'dueInDays' => 7],
                            ['column' => 'In progress', 'title' => 'Zbudować formularz', 'description' => 'Dodać walidację i obsłużyć czytelne komunikaty błędów.', 'type' => CardType::Task, 'priority' => CardPriority::Medium],
                            ['column' => 'Done', 'title' => 'Skonfigurować środowisko', 'description' => 'Docker Compose uruchamia PHP, Nginx i MySQL.', 'type' => CardType::Task, 'priority' => CardPriority::Medium],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Anna Nowak',
                'email' => 'anna.nowak@example.com',
                'boards' => [
                    [
                        'name' => 'Organizacja konferencji',
                        'cards' => [
                            ['column' => 'Backlog', 'title' => 'Przygotować ankietę', 'description' => 'Zebrać opinie uczestników o prelekcjach i organizacji wydarzenia.', 'type' => CardType::Story, 'priority' => CardPriority::Low],
                            ['column' => 'To do', 'title' => 'Potwierdzić catering', 'description' => 'Uzgodnić menu, alergeny oraz godzinę dostawy.', 'type' => CardType::Task, 'priority' => CardPriority::Critical, 'dueInDays' => 2],
                            ['column' => 'In progress', 'title' => 'Ułożyć agendę', 'description' => 'Rozmieścić wystąpienia, przerwy i sesję pytań w salach.', 'type' => CardType::Task, 'priority' => CardPriority::High, 'dueInDays' => 4],
                            ['column' => 'Done', 'title' => 'Zarezerwować salę', 'description' => 'Sala główna i dwie sale warsztatowe są potwierdzone.', 'type' => CardType::Task, 'priority' => CardPriority::High],
                        ],
                    ],
                    [
                        'name' => 'Wydanie aplikacji mobilnej',
                        'cards' => [
                            ['column' => 'Backlog', 'title' => 'Dodać tryb offline', 'description' => 'Opisać zachowanie aplikacji bez połączenia z siecią.', 'type' => CardType::Story, 'priority' => CardPriority::Medium],
                            ['column' => 'To do', 'title' => 'Naprawić ekran logowania', 'description' => 'Komunikat błędu zasłania przycisk na małych ekranach.', 'type' => CardType::Bug, 'priority' => CardPriority::Critical, 'dueInDays' => 1],
                            ['column' => 'In progress', 'title' => 'Przygotować opis sklepu', 'description' => 'Napisać krótki opis funkcji i listę zmian w nowej wersji.', 'type' => CardType::Task, 'priority' => CardPriority::Medium, 'dueInDays' => 6],
                            ['column' => 'Done', 'title' => 'Zatwierdzić ikonę aplikacji', 'description' => 'Finalna ikona została sprawdzona na jasnym i ciemnym tle.', 'type' => CardType::Task, 'priority' => CardPriority::Low],
                        ],
                    ],
                ],
            ],
        ];
    }
}
