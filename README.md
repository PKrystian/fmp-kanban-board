# FMP

FMP is a Kanban board application built with Symfony, MySQL, Docker, Bootstrap 5 and jQuery.

## Stack

- PHP 8.4.1+
- Symfony 7.4
- MySQL 8.4
- Twig, AssetMapper, Bootstrap 5 and jQuery
- Docker Compose

## Installation

Build the application image:

```bash
docker compose build
```

Start the containers:

```bash
docker compose up -d
```

Install PHP dependencies:

```bash
docker compose exec app composer install
```

The application is available at:

```text
http://localhost:8080
```

## Frontend assets

Bootstrap 5 and jQuery are managed by AssetMapper. Restore the browser packages after installing PHP dependencies:

```bash
docker compose exec app php bin/console importmap:install
```

## Quality checks

Run the test suite:

```bash
docker compose exec app php bin/phpunit
```

Validate templates and configuration:

```bash
docker compose exec app php bin/console lint:twig templates
docker compose exec app php bin/console lint:yaml config compose.yaml compose.override.yaml
```

## Symfony console

Run Symfony commands inside the application container:

```bash
docker compose exec app php bin/console
```

## Database

Run database migrations:

```bash
docker compose exec app php bin/console doctrine:migrations:migrate
```

Run a SQL query:

```bash
docker compose exec app php bin/console dbal:run-sql "SELECT 1"
```

## Stopping the application

```bash
docker compose down
```
