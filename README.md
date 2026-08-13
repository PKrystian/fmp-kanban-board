# FMP Kanban

Kanban application built with PHP 8.4, Symfony 7.4, MySQL 8.4, Twig, AssetMapper and Bootstrap 5.

## Development

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console importmap:install
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:seed-demo-data
```

Open <http://localhost:8080>. Stop the environment with `docker compose down`.

## Demo accounts

The seed command creates two accounts and replaces only their documented demo boards. Other boards are left unchanged, so the command can be run again safely.

| Name | Email | Password | Example boards |
| --- | --- | --- | --- |
| Jan Nowak | `jan.nowak@example.com` | `zaq1@WSX` | Remont mieszkania, Plan nauki Symfony |
| Anna Nowak | `anna.nowak@example.com` | `zaq1@WSX` | Organizacja konferencji, Wydanie aplikacji mobilnej |

To inspect or edit a Kanban board, open <http://localhost:8080/login>, sign in with either account, select **Boards** in the navigation and open one of the example boards. Cards can be opened by selecting their title or the **Edit** button.

Recreate the demo data at any time with:

```bash
docker compose exec app php bin/console app:seed-demo-data
```

## Tests and checks

Prepare the test database once, then run the suite:

```bash
docker compose exec app php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec app php bin/phpunit
```

Useful project checks:

```bash
docker compose exec app php bin/console doctrine:schema:validate
docker compose exec app php bin/console lint:container
docker compose exec app php bin/console lint:twig templates
docker compose exec app php bin/console lint:yaml config
docker compose exec app php bin/console asset-map:compile
docker compose config --quiet
```

Create or apply development migrations with:

```bash
docker compose exec app php bin/console make:migration
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

## Production image

Copy `.env.prod.example` to `.env.prod` and set random `APP_SECRET`, database passwords and `DATABASE_URL`. The database host in that URL is `database`; URL-encode special characters in its password.

Build, migrate and start the production stack:

```bash
docker compose --env-file .env.prod -f compose.prod.yaml build
docker compose --env-file .env.prod -f compose.prod.yaml up -d database
docker compose --env-file .env.prod -f compose.prod.yaml run --rm app php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file .env.prod -f compose.prod.yaml up -d
```

The production build installs dependencies without development packages, compiles AssetMapper assets and warms the Symfony production cache. Application sources are copied into the images; they are not bind-mounted. Check the resolved configuration with:

```bash
docker compose --env-file .env.prod -f compose.prod.yaml config --quiet
```
