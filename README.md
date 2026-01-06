# Image Upload Demo - PHP

A **human-readable, security-first** demonstration of image file upload functionality in PHP.

## Project Philosophy

This demo prioritizes:
- **Code Clarity**: Every line is understandable; complex logic is documented
- **Security**: Best practices applied from day one, not as an afterthought
- **Learnability**: Suitable for educational purposes and production reference

## Quick Start

### Requirements
- PHP 8.0+
- Composer
- A modern web browser

### Installation

```bash
# Clone the repository
git clone https://github.com/komazarari/image-upload-demo-php.git
cd image-upload-demo-php

# Install dependencies
composer install

# Set up local environment
cp .env.example .env
# Edit .env with your configuration (see Configuration section below)

# Create upload directory
mkdir -p storage/uploads
chmod 755 storage/uploads
```

### Running Locally

```bash
# Start development server (from project root)
php -S localhost:8000 -t public/

# Open browser
open http://localhost:8000
```

## Architecture

```
src/
├── Controllers/           # HTTP request handlers
├── Models/               # Data structures and business logic
├── Services/             # File handling, validation, storage
└── Exceptions/           # Custom error classes

public/
├── index.php            # Entry point
├── css/                 # Stylesheets
└── js/                  # Client-side code

storage/
└── uploads/             # Uploaded files (outside web root in production)

tests/
├── Unit/                # Business logic tests
└── Integration/         # Full upload workflow tests
```

## Key Features

### File Upload
- **Type Validation**: Only images (JPG, PNG, GIF, WebP) accepted
- **Size Limits**: Configurable max file size (default: 5MB)
- **Sanitization**: Filenames cleaned; stored with random hash
- **Security**: Uploaded files outside web root; served via controlled endpoint

### User Interface
- Clean, accessible HTML5 form
- Real-time file size validation
- Progress indication
- Error messaging with user-friendly descriptions

## Security Notes

### What This Demo Implements
- ✅ Server-side file type validation (extension + MIME type)
- ✅ File size limits enforced
- ✅ Filename sanitization and randomization
- ✅ CSRF token protection on forms
- ✅ Secure file serving (no direct path exposure)
- ✅ Input validation on all user data
- ✅ Error handling without information disclosure
- ✅ Logging of security events

### What You Should Add for Production
- 🔐 HTTPS enforcement
- 🔐 Virus/malware scanning on upload
- 🔐 Rate limiting per user/IP
- 🔐 Authentication & authorization
- 🔐 File access logging and audit trails
- 🔐 CDN for static assets
- 🔐 Regular security audits

## Configuration

### Environment Variables (.env)

```env
# File upload settings
MAX_FILE_SIZE=5242880              # 5MB in bytes
ALLOWED_MIME_TYPES=image/jpeg,image/png,image/gif,image/webp

# Storage
UPLOAD_DIR=storage/uploads

# Application
APP_DEBUG=false                    # Set to true only in development
APP_ENV=production
```

## Testing

```bash
# Run all tests
php vendor/bin/phpunit

# Run specific test suite
php vendor/bin/phpunit tests/Unit/
php vendor/bin/phpunit tests/Integration/

# Check test coverage
php vendor/bin/phpunit --coverage-html coverage/
```

## Code Style

Code adheres to **PSR-12** (PHP Standard Recommendation). Format before commit:

```bash
composer run-script format
```

## Common Tasks

### Adding a New Image Format

1. Update `ALLOWED_MIME_TYPES` in `.env`
2. Add validation rule in `ImageValidator::validate()`
3. Write test case in `tests/Unit/ImageValidatorTest.php`
4. Test the complete upload flow in integration tests

### Changing Max File Size

Edit `MAX_FILE_SIZE` in `.env`, but also update:
- HTML form `maxlength` attribute
- JavaScript validation
- Server-side validation constant
- Test fixtures

### Deploying to Production

1. Review all `TODO: PRODUCTION` comments in codebase
2. Set `APP_DEBUG=false` in `.env`
3. Ensure `storage/uploads` is outside web root
4. Set up HTTPS with valid certificate
5. Configure rate limiting at reverse proxy level
6. Run security checks: `composer audit`

## Debugging

Enable debug mode in `.env`:

```env
APP_DEBUG=true
```

This will:
- Show detailed error messages
- Enable query logging
- Load development dependencies

**Never enable in production.**

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines. All contributions must:
- Follow the [Project Constitution](.specify/memory/constitution.md)
- Pass security review
- Include tests
- Maintain code readability

## License

MIT License - see LICENSE file for details

## Resources

- [Project Constitution](.specify/memory/constitution.md) - Development principles & governance
- [Design Documents](specs/) - Feature specifications and technical designs
- [PHP Security Guide](https://owasp.org/www-project-top-ten/) - OWASP Top 10
- [File Upload Security](https://owasp.org/www-community/vulnerabilities/Unrestricted_File_Upload) - Common pitfalls

## Support

Found an issue? Please open a GitHub issue with:
- Description of the problem
- Steps to reproduce
- Your environment (PHP version, OS)
- Security implications (if any)
