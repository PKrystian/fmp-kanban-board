# FMP

FMP is a Kanban board application built with Symfony, MySQL, Docker, jQuery and SCSS.

## Requirements

- Docker
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

## SCSS development

Run the SCSS watcher in a separate terminal:

```bash
docker compose exec app php bin/console sass:build --watch
```

To compile SCSS once:

```bash
docker compose exec app php bin/console sass:build
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
