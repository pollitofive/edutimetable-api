# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel 12 API project for an education timetable system (`edutimetable-api`). The project uses Laravel Sanctum for API authentication and includes both PHP backend and Vite-based frontend asset compilation.

## Development Commands

### Common Development Tasks
```bash
# Start development server with queue listener and Vite
composer run dev

# Run individual components
php artisan serve                    # Start Laravel development server
php artisan queue:listen --tries=1  # Run queue worker
npm run dev                         # Start Vite development server

# Build frontend assets
npm run build
```

### Testing
```bash
# Run all tests (uses Pest testing framework)
composer test
# or directly
php artisan test

# Clear config before testing
php artisan config:clear
```

### Code Quality
```bash
# Format code (Laravel Pint)
./vendor/bin/pint

# Clear caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### Database Operations
```bash
# Run migrations
php artisan migrate

# Seed database
php artisan db:seed

# Fresh migration with seeding
php artisan migrate:fresh --seed
```

## Architecture

### Framework & Dependencies
- **Laravel 12** with PHP 8.2+
- **Laravel Sanctum** for API token authentication
- **Pest** for testing framework
- **Vite** with TailwindCSS for frontend asset compilation

### Key Directories
- `app/Http/Controllers/Api/` - API controllers
- `app/Models/` - Eloquent models
- `routes/api.php` - API routes with Sanctum authentication
- `database/migrations/` - Database schema
- `tests/` - Pest test files

### Authentication Architecture
The API uses Laravel Sanctum for token-based authentication:
- Login endpoint: `POST /api/login-token`
- Protected routes use `auth:sanctum` middleware
- Token management through `AuthController`

### API Structure
- All API routes are in `routes/api.php`
- Controllers follow Laravel conventions in `app/Http/Controllers/Api/`
- Authentication endpoints: login, logout, user profile (`/me`)

### Frontend Assets
- Vite configuration for modern asset bundling
- TailwindCSS 4.0 for styling
- Assets compiled from `resources/` directory