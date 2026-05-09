# Changelog

Auto-generated context log for vibe-coded commits.

## 2026-05-09 12:42 +0700 — chore: initial Laravel 13.5 + Sail + Blade/Tailwind scaffold

Files touched:
- `.claude/skills/deploying-laravel-cloud/SKILL.md`
- `.claude/skills/deploying-laravel-cloud/reference/checklists.md`
- `.editorconfig`
- `.env.example`
- `.gitattributes`
- `.githooks/commit-msg`
- `.githooks/pre-commit`
- `.githooks/prepare-commit-msg`
- `.github/dependabot.yml`
- `.github/pull_request_template.md`
- `.github/workflows/ci.yml`
- `.gitignore`
- `.npmrc`
- `LICENSE`
- `README.md`
- `app/Http/Controllers/Controller.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Providers/HorizonServiceProvider.php`
- `app/Providers/TelescopeServiceProvider.php`
- `artisan`
- `bin/setup-hooks.sh`
- `bootstrap/app.php`
- `bootstrap/cache/.gitignore`
- `bootstrap/providers.php`
- `compose.yaml`
- `composer.json`
- `composer.lock`
- `config/app.php`
- `config/auth.php`
- `config/cache.php`
- `config/database.php`
- `config/filesystems.php`
- `config/horizon.php`
- `config/logging.php`
- `config/mail.php`
- `config/queue.php`
- `config/services.php`
- `config/session.php`
- `config/telescope.php`
- `database/.gitignore`
- `database/factories/UserFactory.php`
- `database/migrations/0001_01_01_000000_create_users_table.php`
- `database/migrations/0001_01_01_000001_create_cache_table.php`
- `database/migrations/0001_01_01_000002_create_jobs_table.php`
- `database/migrations/2026_05_09_053548_create_telescope_entries_table.php`
- `database/migrations/2026_05_09_053549_create_pulse_tables.php`
- `database/seeders/DatabaseSeeder.php`
- `infection.json5`
- `package-lock.json`
- `package.json`
- `phpstan.neon`
- `phpunit.xml`
- `pint.json`
- `public/.htaccess`
- `public/favicon.ico`
- `public/index.php`
- `public/robots.txt`
- `rector.php`
- `renovate.json`
- `resources/css/app.css`
- `resources/js/app.js`
- `resources/views/welcome.blade.php`
- `routes/console.php`
- `routes/web.php`
- `storage/app/.gitignore`
- `storage/app/private/.gitignore`
- `storage/app/public/.gitignore`
- `storage/framework/.gitignore`
- `storage/framework/cache/.gitignore`
- `storage/framework/cache/data/.gitignore`
- `storage/framework/sessions/.gitignore`
- `storage/framework/testing/.gitignore`
- `storage/framework/views/.gitignore`
- `storage/logs/.gitignore`
- `tests/Feature/ArchTest.php`
- `tests/Feature/ExampleTest.php`
- `tests/Pest.php`
- `tests/TestCase.php`
- `tests/Unit/ExampleTest.php`
- `vite.config.js`


## 2026-05-09 12:49 +0700 — chore: drop laravel cloud cli, defer deploy to homelab

Files touched:
- `.claude/skills/deploying-laravel-cloud/SKILL.md`
- `.claude/skills/deploying-laravel-cloud/reference/checklists.md`
- `.env.example`
- `README.md`
- `composer.json`
- `composer.lock`

## 2026-05-09 13:17 +0700 — fix(hooks): move CHANGELOG append from prepare-commit-msg to post-commit+amend

Files touched:
- `.githooks/post-commit`
- `.githooks/prepare-commit-msg`

