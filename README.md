# Resik — Backend API

Multi-tenant SaaS backend for F&B business management, built for Indonesian F&B SMEs (warung, restoran, kafe). Handles inventory, HR & payroll, point-of-sale, online ordering, table booking, and cash reconciliation across multiple outlets under a single tenant.

Frontend: [resik-frontend](https://github.com/Naufalfebri2/resik-frontend)

## Tech Stack

- **Framework**: Laravel 13
- **Database**: PostgreSQL
- **Auth**: Laravel Sanctum (token-based API auth)
- **Architecture**: Multi-tenant — one tenant can own multiple outlets, with outlet-level RBAC scoping

## Core Modules

| Module | Highlights |
|---|---|
| Auth & Tenant Management | Multi-tenant registration, Sanctum token auth, role-based access (`owner`, `admin`, `manager`, `staf`) |
| Inventory | Daily stock tracking, stock adjustments, purchase orders, low-stock alerts, auto-disable menu on stockout |
| HR | Shift scheduling, GPS-geofenced attendance, automated payroll with late/absence deductions |
| POS (Point of Sale) | Table-based orders, split billing, per-item refunds, multi-method payment |
| Online Ordering | QR dine-in ordering, self-service pickup orders, manual delivery-order logging (Grab/Gojek/ShopeeFood) |
| Table Booking | Table-specific reservations with overlap detection, multi-table Event bookings, automated no-show grace period |
| Finance | Cash account reconciliation with owner approval workflow, cashflow reports (per account / per outlet / cross-outlet) |
| RBAC Outlet Scoping | Manager accounts locked to a single outlet via middleware + model-level validation |

## Architecture Notes

- All primary keys are UUIDs (`HasUuid` trait)
- Tenant isolation enforced on every query — either directly via `tenant_id` or through nested relations (`section.outlet`)
- Dynamic custom fields per entity, validated via `CustomFieldValidator`
- Business logic lives in dedicated service classes, not controllers (`CashTransactionService`, `PayrollService`, `BookingAvailabilityService`, etc.)
- Strict state-machine validation for order prep status, courier status, and booking status transitions

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Requires PostgreSQL running locally and configured in `.env`.

## API Testing

A full Postman collection covering all endpoints is maintained separately (not committed to this repo — available on request).

## Development Notes

This project was built iteratively across defined phases (auth → inventory → HR → payroll → POS → online ordering → table booking → RBAC → finance), with schema deviations and design decisions documented per phase in the project's internal spec.