# MITHQAL Merchant Portal

The merchant-facing web app of MITHQAL POS 2.0 (port **8087**). Merchants log in here to configure their branches, devices, staff, catalogue, customers, and reports for the POS family.

Sibling app to `pos_admin` (port 8086). Both apps share a single Postgres database (`charity_db`) — the data model is described in `MITHQAL_2.0_Blueprint.pdf` §3.2.

## Stack

- Laravel 13 + PHP 8.3
- Sanctum session auth, spatie/laravel-permission, spatie/laravel-data
- Vue 3 + Vite 8 + TypeScript + Pinia + vue-i18n + Tailwind 4
- Redis for sessions, cache, queues (`pos_merchant_redis`, host port **6381**)

## Local dev

```bash
docker compose up -d
# → http://localhost:8087
```

Sub-services:

| Service               | Host port | Notes                                                 |
| ---                   | ---       | ---                                                   |
| nginx                 | 8087      | Serves the SPA + the Laravel API                      |
| vite                  | 5174      | HMR + Vue dev bundle (pos_admin uses 5175)            |
| pos_merchant_redis    | 6381      | Sessions/cache/queues. pos_admin uses 6380.           |
| pos_merchant          | —         | PHP-FPM (no host port; only nginx talks to it)        |

Composer + Artisan one-offs:

```bash
docker compose run --rm --no-deps composer install
docker compose run --rm --no-deps artisan php artisan migrate
```

## Auth

Portal users live in the shared `pos_users` table (the same table `pos_admin` writes to), distinguished by `user_type='merchant'`. The first merchant admin user is created from `pos_admin` (Merchants → Show → Portal Users tab → Create admin user), which generates a one-time password the platform admin shares with the merchant out of band. The merchant then logs in here with email + that password.

See `pos_admin/README.md` and the blueprint §4.5 for the full provisioning flow.

## Charity DB sharing

This app does **not** own the database. Migrations run against `charity_db` but only touch tables the merchant portal needs that don't yet exist (pos_staff, pos_floors, pos_tables, pos_categories, pos_products, pos_add_ons — landing in Phase 4.5+). Shared business tables (pos_companies, pos_branches, pos_devices, pos_users, pos_audit_logs) are owned by `pos_admin` and read here through Eloquent models that point at the same `$table`.

Each app keeps its own migration history table (`pos_admin_migrations`, `pos_merchant_migrations`, charity's own) so they can migrate independently.
