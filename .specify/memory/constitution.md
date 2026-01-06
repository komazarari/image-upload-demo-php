<!-- 
SYNC IMPACT REPORT
==================
Constitution Version: 1.1.0
Date: 2026-01-06

VERSION BUMP RATIONALE:
- MINOR version bump (1.0.0 → 1.1.0): Added new development guideline for Docker-based PHP development
- Non-breaking change; clarifies tooling requirement for existing principle compliance

CHANGES MADE:
- Added VI. Docker-First Development Environment principle
- Updated Development Workflow section with Docker requirements
- All existing principles and security requirements maintained

NEW PRINCIPLE:
- VI. Docker-First Development Environment

TEMPLATES UPDATED:
- ✅ README.md - Maintains comprehensive project README
- ✅ docs/DEVELOPMENT.md - Maintains development guidance  
- ✅ docs/SECURITY.md - Maintains security implementation guide

CLARIFICATIONS:
- Host machine has no native PHP runtime; all PHP development uses Docker
- Ensures consistent development environment across team members
- Isolates project dependencies and prevents host system pollution
-->

# Image Upload Demo Constitution

## Core Principles

### I. Human-Readable Code First
Every line of code must be clear, maintainable, and understandable at a glance. Use descriptive variable names, consistent formatting, and clear logic flow. Comments explain the "why," not the "what." Demo code sets the example—if it's incomprehensible, it's not suitable for learning. Code complexity must be justified; prefer clarity over cleverness.

### II. Security by Default (Non-Negotiable)
Demo code is still code that could be copy-pasted into production. All security best practices are MANDATORY, not optional: input validation, sanitization, secure storage of secrets, HTTPS enforcement, CSRF protection, XSS prevention, SQL injection protection. Security reviews required before all PRs. Mark any intentional deviations (none permitted without escalation) explicitly.

### III. Test-Driven Development (TDD)
Tests written before implementation; Red-Green-Refactor cycle enforced. Unit tests for all business logic, integration tests for file uploads and API endpoints. Aim for >80% code coverage. Tests serve as documentation of intended behavior.

### IV. Secure File Upload Handling
File upload endpoints MUST validate: file type, size, MIME type verification, virus/malware scanning (if applicable). Store uploaded files outside web root. Implement rate limiting. Log all upload attempts. Prevent directory traversal and path injection attacks.

### V. Simple, Focused Scope
Demo application focuses on core image upload functionality. No unnecessary features or complexity. YAGNI principle strictly applied. Clear separation of concerns: routing, business logic, storage, security.

### VI. Docker-First Development Environment
All PHP development is conducted inside Docker containers. The host machine does not have a native PHP runtime environment. Developers MUST use Docker Compose to run all PHP-related tasks: development server, running tests, composer commands, database operations. This ensures a consistent, reproducible development environment and prevents dependency conflicts on the host machine.

## Security Requirements

- **Input Validation**: All user input (filename, file content, request parameters) validated server-side
- **Authentication**: If implemented, use secure session management; never store passwords in plain text
- **File Storage**: Uploaded files stored outside web root; served via controlled endpoint with proper MIME types
- **HTTPS**: Use HTTPS in production; no credentials/files over HTTP
- **Logging**: Log all security-relevant events (upload attempts, validation failures, unauthorized access)
- **Dependencies**: Regular security audits of Composer dependencies; no deprecated packages

## Development Workflow

- **Docker-Based Development**: ALL PHP development occurs in Docker containers
  - Start application: `cd docker && docker compose up -d`
  - Run tests: `docker compose exec php ./vendor/bin/phpunit tests/Unit/`
  - Run composer: `docker compose exec php composer <command>`
  - Access logs: `docker compose logs php`
  - Stop application: `cd docker && docker compose down`
- **Code Review**: All changes reviewed for security and readability before merge
- **Naming Conventions**: Clear, English function/variable names; no abbreviations unless standard (e.g., `$file` not `$f`)
- **Comments**: Explain non-obvious security decisions; document assumptions
- **Testing Gates**: All tests pass before merge; coverage not to decrease
- **Documentation**: README updated with usage examples and security notes

## Governance

Constitution supersedes all development practices. Amendments require documentation of rationale and migration plan. All PRs must verify compliance with security, readability, and Docker usage principles. Use `.specify/memory/` for governance tracking and `.specify/templates/` for development templates. Complexity deviations require documented justification.

**Version**: 1.1.0 | **Ratified**: 2026-01-06 | **Last Amended**: 2026-01-06
