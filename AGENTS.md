# SmartAsset Agent Guide

This repository is a legacy PHP/MySQL asset management web application. It is not built with a PHP framework; it uses plain PHP for routing and page logic, PDO for database access, and a small amount of frontend JS/CSS.

## What the agent should know

- Main app entry points are in `index.php`, `login.php`, and `pages/*.php`.
- API endpoints are in `api/*.php`.
- Shared helpers are in `helper/auth.php` and `database/db.php`.
- Global configuration lives in `config.php`.
- UI components are under `components/`.
- The database schema is loaded from `asset_db.sql`.
- Authentication is session-based with optional LDAP support.
- Role enforcement is handled by `requireRole(['admin','webadmin','inventory','viewer'])`.

## Key conventions

- Always preserve existing page structure and include patterns such as `require_once __DIR__ . '/../config.php';`.
- Use the repo's existing PHP style: procedural page scripts with `requireLogin()` and `requireRole()` guards.
- Database queries typically use prepared statements and PDO via `Database::getInstance()`.
- Avoid assuming framework routing, MVC, or modern dependency management.
- This repo does not use Composer or npm for build tooling.

## Common fix areas

- SQL query bugs in `pages/*.php` and `api/*.php`.
- Report filters and inventory session logic.
- User access and role restrictions in `helper/auth.php`.
- Asset import/export, file upload handling, and report generation.
- UI updates in `components/head.php`, `assets/css/`, and `assets/js/app.js`.

## Local validation

- Validate PHP syntax with `php -l <file>`.
- Review `README.md` for installation assumptions and environment requirements.
- Use `config.php` to understand runtime behavior and app URLs.

## Existing customization file

- This repository already includes `.agent.md` for a project-specific coding assistant.
- Use `.agent.md` for detailed contextual guidance when making edits.

## References

- `README.md` — installation, database schema summary, and feature overview.
- `config.php` — runtime constants, upload settings, timezone, and app URL logic.
- `helper/auth.php` — login requirements and permission checks.
- `database/db.php` — database connection and error handling.
