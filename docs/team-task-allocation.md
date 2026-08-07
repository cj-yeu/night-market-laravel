# Team Task Allocation

## Project
Night Market Discovery and Visit Planning System

## Development Approach
This project is developed using Laravel 12, Blade, Bootstrap 5 and MySQL.
The team follows a Modular Monolith architecture with Laravel MVC and a Service Layer.

## Module Ownership

| Member | Assigned Module | Main Responsibilities | Main Feature Branch |
|---|---|---|---|
| Member 1 | User Account Module | Registration, login, logout, profile management, role-based access control | `feature/user-account` |
| Member 2 | Night Market Discovery Module | Market listing, market details, search, Selangor area filters and empty states | `feature/night-market-discovery` |
| Member 3 | Stall and Must-Try Food Module | Stall management, food recommendations, food and stall display | `feature/stalls-must-try-food` |
| Member 4 | Review and Rating Module | Review submission, star rating, image upload, review approval and display | `feature/reviews-ratings` |
| Member 5 | Visit Planner Module | Create, edit, delete visit plans and add market, stall or food items | `feature/visit-planner` |
| Member 6 | Social Media Data Module | Admin manual social-media data input, viewing extracted information and management | `feature/social-media-data` |

## Week 7 Responsibilities

| Member | Week 7 Task | Deliverable |
|---|---|---|
| Member 1 | Confirm authentication and role-access requirements | User Account module scope and acceptance criteria |
| Member 2 | Confirm discovery search and filter requirements | Discovery module scope and Figma screen list |
| Member 3 | Confirm stall and food data requirements | Stall/Food module scope and data-field list |
| Member 4 | Confirm review workflow and approval rules | Review module scope and approval flow |
| Member 5 | Confirm visit-plan workflow | Visit Planner scope and user flow |
| Member 6 | Confirm manual social-media data workflow | Social Media module scope and admin flow |

## Collaboration Rules

1. `main` contains the stable and demo-ready version.
2. `develop` is the integration branch.
3. Each module is developed in its assigned `feature/...` branch.
4. A feature branch must be merged into `develop` through a Pull Request.
5. Each task must have clear acceptance criteria and testing evidence.
6. No `.env`, `vendor/` folder or sensitive database credentials may be committed.
7. All UI implementation must follow the approved Figma design system.
8. The final ERD, Use Case Diagram and Architecture Diagram will be produced from the actual Laravel implementation.

## Week 9 Responsibilities