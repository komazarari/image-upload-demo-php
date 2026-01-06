# Development Guidance

Guide for implementing features and maintaining the Image Upload Demo application while adhering to project principles.

## Before You Start

1. **Read the Constitution**: Review [`.specify/memory/constitution.md`](.specify/memory/constitution.md) - all development activities must align with these principles
2. **Understand the Architecture**: Review [README.md](README.md) to understand project structure and security considerations
3. **Check Tests First**: Read existing tests to understand testing patterns and conventions

## Code Readability Checklist

Every function and class should pass this checklist:

- [ ] **Function name is a verb or verb phrase**: `validateUpload()`, not `check()`
- [ ] **Variable names are self-documenting**: `uploadedFile`, not `f` or `file1`
- [ ] **No nested conditionals deeper than 2 levels**: Extract to separate function if needed
- [ ] **Line length under 100 characters**: Break long lines for readability
- [ ] **Comments explain "why", not "what"**: Code should read like English
- [ ] **Single Responsibility**: One function does one thing
- [ ] **Testable without mocking infrastructure**: Pure functions where possible

### Example: Readable vs. Unclear

❌ **Unclear**
```php
function f($f) {
    $s = getimagesize($f);
    $m = $s['mime'];
    if(in_array($m, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        return true;
    }
    return false;
}
```

✅ **Readable**
```php
/**
 * Check if uploaded file is a supported image format.
 * 
 * @param string $filePath Path to the uploaded file
 * @return bool True if file is a valid image format
 */
function isSupportedImageFormat(string $filePath): bool
{
    $imageInfo = getimagesize($filePath);
    if (!$imageInfo) {
        return false;
    }
    
    $mimeType = $imageInfo['mime'];
    $supportedFormats = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    return in_array($mimeType, $supportedFormats);
}
```

## Security Review Checklist

**All PRs must verify these before merge:**

### Input Validation
- [ ] File extension validated on server (not just client)
- [ ] File MIME type verified (not just extension)
- [ ] Filename sanitized of special characters and path traversal attempts
- [ ] File size validated before processing
- [ ] All request parameters validated with type checking

### File Handling
- [ ] Uploaded files stored outside web root (`storage/` not `public/`)
- [ ] Files served via controlled endpoint with proper MIME type headers
- [ ] No directory traversal possible in file retrieval (`basename()` applied)
- [ ] File permissions set correctly (not world-readable if sensitive)

### Session & Forms
- [ ] CSRF tokens present on all form submissions
- [ ] Session tokens not exposed in logs or error messages
- [ ] Login/auth uses secure session management (if implemented)

### Error Handling
- [ ] Error messages don't leak system paths or implementation details
- [ ] Exceptions caught and logged, not exposed to users
- [ ] Failed upload attempts logged with timestamp and details

### Dependencies
- [ ] No deprecated packages in `composer.json`
- [ ] Run `composer audit` before PR
- [ ] Security advisories for used packages reviewed

## Testing Requirements

### Unit Tests
Test business logic in isolation:

```php
// tests/Unit/ImageValidatorTest.php
public function testRejectsInvalidMimeType()
{
    $validator = new ImageValidator();
    $result = $validator->validate('malicious.exe');
    
    $this->assertFalse($result->isValid());
    $this->assertContains('not a valid image', $result->getErrors());
}
```

**Coverage Goal**: > 80% of business logic

### Integration Tests
Test real workflows:

```php
// tests/Integration/UploadFlowTest.php
public function testCompleteUploadWorkflow()
{
    // 1. Create a test image file
    // 2. Submit upload form
    // 3. Verify file stored securely
    // 4. Verify file is accessible via controlled endpoint
    // 5. Verify database record created
}
```

### Running Tests

```bash
# All tests
php vendor/bin/phpunit

# Specific test file
php vendor/bin/phpunit tests/Unit/ImageValidatorTest.php

# With coverage report
php vendor/bin/phpunit --coverage-html coverage/
```

## Git Workflow

### Branch Naming
- Feature: `feature/short-description`
- Fix: `fix/issue-description`
- Security: `security/issue-description`
- Docs: `docs/topic`

Example: `feature/add-image-crop-endpoint`

### Commit Messages

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add image dimension validation

- Validate image doesn't exceed 4000x3000 pixels
- Log attempted uploads of oversized images
- Return helpful error to user

Fixes #42
```

```
security: sanitize filename before storage

Prevent directory traversal by using basename()
and validating against safe character set.

Reviewed-by: @security-team
```

### Pull Request Checklist

Before opening a PR, verify:
- [ ] All tests pass locally
- [ ] Code follows PSR-12 formatting
- [ ] Security checklist passed
- [ ] README updated if feature added
- [ ] No debug code or console.log statements
- [ ] No secrets committed (API keys, passwords)

## Adding Features: Step-by-Step

### 1. Design Phase
- Describe feature in issue or specification
- Discuss security implications (file types, size, access patterns)
- Sketch data model if needed
- Get approval before coding

### 2. Test-First Development
```php
// tests/Unit/NewFeatureTest.php - WRITE THIS FIRST
public function testNewFeatureDoesWhatIsNeeded()
{
    // Arrange: Set up test data
    // Act: Call the feature
    // Assert: Verify expected behavior
}
```

Then implement to make test pass.

### 3. Security Implementation
- Implement all validation upfront, don't add later
- Log security-relevant events
- Review OWASP vulnerabilities for your feature type
- Add error handling that doesn't leak info

### 4. Code Review
- Request review from maintainer
- Address feedback with inline commits
- Mark conversations resolved when done

### 5. Merge & Deploy
- Ensure all checks pass
- Merge to `main`
- Verify in staging environment
- Document deployment notes if needed

## Common Patterns

### Validating File Uploads

```php
class ImageUploadValidator
{
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];
    
    private int $maxFileSize = 5 * 1024 * 1024; // 5MB
    
    /**
     * Validate uploaded file is safe to store.
     * 
     * @return ValidationResult with errors if invalid
     */
    public function validate(UploadedFile $file): ValidationResult
    {
        $errors = [];
        
        // Check size
        if ($file->getSize() > $this->maxFileSize) {
            $errors[] = 'File exceeds maximum size of 5MB';
        }
        
        // Check MIME type
        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            $errors[] = 'File type not supported';
        }
        
        // Verify actual content matches MIME type
        if (!$this->verifyImageContent($file->getPath())) {
            $errors[] = 'File content does not match declared type';
        }
        
        return new ValidationResult(!empty($errors), $errors);
    }
    
    private function verifyImageContent(string $filePath): bool
    {
        $imageInfo = getimagesize($filePath);
        return $imageInfo !== false;
    }
}
```

### Secure File Storage

```php
class FileStorage
{
    private string $uploadDirectory;
    
    public function __construct(string $uploadDirectory)
    {
        // Ensure directory is outside web root
        if (strpos($uploadDirectory, 'public/') !== false) {
            throw new InvalidArgumentException('Upload directory must be outside web root');
        }
        
        $this->uploadDirectory = $uploadDirectory;
    }
    
    /**
     * Store validated image file securely.
     * 
     * Returns publicly accessible URL to retrieve file.
     */
    public function store(UploadedFile $file): string
    {
        // Generate safe filename
        $hash = bin2hex(random_bytes(16));
        $extension = $this->getExtensionFromMimeType($file->getMimeType());
        $filename = "{$hash}.{$extension}";
        
        $storagePath = $this->uploadDirectory . '/' . $filename;
        
        // Move file to storage
        $file->moveTo($storagePath);
        chmod($storagePath, 0644); // Readable but not executable
        
        // Log successful storage
        $this->logStorageEvent($filename, $file->getOriginalName());
        
        // Return URL for retrieval via controlled endpoint
        return "/api/images/{$hash}";
    }
    
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        
        return $extensions[$mimeType] ?? 'bin';
    }
    
    private function logStorageEvent(string $filename, string $originalName): void
    {
        // Log with timestamp and IP for audit trail
        error_log("Image stored: {$filename} (originally: {$originalName})");
    }
}
```

### Serving Files Securely

```php
// In controller - NOT direct filesystem access
public function serveImage(Request $request): Response
{
    $hash = $request->getParameter('hash');
    
    // Validate hash format (e.g., 32 hex chars)
    if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
        return new Response('Not found', 404);
    }
    
    $filePath = $this->storage->getFilePath($hash);
    
    // Verify file exists and is readable
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return new Response('Not found', 404);
    }
    
    // Set correct MIME type
    $mimeType = mime_content_type($filePath);
    if (!in_array($mimeType, $this->allowedMimeTypes)) {
        return new Response('Forbidden', 403);
    }
    
    return new FileResponse($filePath, $mimeType);
}
```

## Debugging Tips

### Enable Detailed Logging
```php
// In your error handler
if (getenv('APP_DEBUG') === 'true') {
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/logs/debug.log');
}
```

### Check File Permissions
```bash
# Uploaded files should be readable but not executable
ls -l storage/uploads/

# Upload directory should be writable by web server
stat storage/uploads/
```

### Verify MIME Types
```bash
# Check what the system thinks a file is
file -i storage/uploads/abc123.jpg

# Check what PHP thinks
php -r "var_dump(mime_content_type('storage/uploads/abc123.jpg'));"
```

## Performance Considerations

- Use thumbnail generation library only for display, not storage
- Implement image caching headers for browser caching
- Consider async processing for large images
- Monitor upload endpoint response times

## Questions?

- Check existing issues: Does someone else have the same question?
- Review Constitution: Does it clarify your question?
- Ask in PR review: Get feedback from maintainers
