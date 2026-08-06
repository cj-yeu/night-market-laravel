# Coding Standards

## Project Stack

- Framework: Laravel 12
- Frontend: Laravel Blade and Bootstrap 5
- Database: MySQL
- ORM: Eloquent ORM
- Architecture: Modular Monolith with Layered MVC and Service Layer

## Architecture Rules

Each feature must follow this flow:

Route -> Controller -> Form Request -> Service -> Model -> MySQL

Controller -> Blade View

1. Routes must be defined in `routes/web.php`.
2. Controllers handle request coordination only; they must not contain complex business logic.
3. Form Request classes validate user input.
4. Services contain business logic and database-related workflows.
5. Eloquent Models manage database relationships.
6. Blade files are for presentation only. Do not place SQL queries or complex business logic in Blade files.
7. Database changes must be created using Laravel migrations. Do not manually create project tables in phpMyAdmin.
8. Sample data must be added through seeders or factories where appropriate.

## Folder Structure

```text
app/
  Http/
    Controllers/
    Requests/
  Models/
  Services/

resources/
  views/
    layouts/
    components/
    auth/
    client/
    admin/

database/
  migrations/
  seeders/
  factories/

  Naming Conventions
Item	         Convention	                         Example
Controller	     PascalCase + Controller	        VisitPlanController
Form Request	 PascalCase + Request	            StoreVisitPlanRequest
Service	         PascalCase + Service	            VisitPlanService
Model	         Singular PascalCase	            VisitPlan
Migration	     Laravel generated descriptive name	create_visit_plans_table
Blade View	     kebab-case	                        create-plan.blade.php
Route name	     dot notation	                    plans.store
Database table	 plural snake_case	                visit_plans
Database column	 snake_case	                        visit_date

UI Rules
1. All screens must follow the approved Figma mockups and Design System.
2. Use Bootstrap 5 components and utility classes consistently.
3. Reuse shared Blade layouts and components where possible.
4. Do not introduce new colours, fonts, spacing rules or UI patterns without updating the Design System.
5.Client and Admin pages must use their appropriate layouts.
6. Every form must show validation feedback and preserve input where appropriate.
7. Every list page should include suitable empty states.

Security and Data Rules
Never commit .env, API keys, passwords or private credentials.
Use Laravel authentication and authorization middleware for protected routes.
Validate all form input using Form Request classes.
Use Eloquent ORM or query builder; do not concatenate user input into SQL.
Use @csrf in every POST, PUT, PATCH or DELETE Blade form.
Uploaded images must be validated by file type and size.

Git Rules
main contains only stable, demo-ready code.
develop is the shared integration branch.
Module work begins from its assigned feature/... branch.
Use clear commit messages such as:
feat: add market search filter
fix: validate visit plan date
docs: update module workflow
test: add review approval tests
Do not commit vendor/, .env, generated uploads or unrelated files.
Merge module branches into develop through a Pull Request after testing.

Definition of Done

A task is complete only when:

The feature follows the approved Figma design.
Validation and error messages work.
Database changes use migrations.
The code follows the MVC and Service Layer rules.
The feature has been tested locally.
The task is committed with a meaningful commit message.