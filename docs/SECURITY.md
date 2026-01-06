# Security Implementation Guide

This guide walks through the security decisions made in this demo and how to extend them safely.

## Overview

Image file uploads are a high-risk operation. This demo implements a security-first approach with validation at multiple layers.

## Defense-in-Depth Strategy

```
┌─────────────────────────────────────────────┐
│  Client-Side (User Experience)              │
│  - File picker filtering                    │
│  - File size check before upload            │
│  - CSRF token inclusion                     │
└────────────────┬────────────────────────────┘
                 │
┌────────────────▼────────────────────────────┐
│  Server-Side (Validation Layer)             │
│  - Extension whitelist                      │
│  - MIME type verification                   │
│  - File size enforcement                    │
│  - Magic byte validation                    │
│  - Rate limiting (if needed)                │
└────────────────┬────────────────────────────┘
                 │
┌────────────────▼────────────────────────────┐
│  Storage Layer (Safe Storage)               │
│  - Outside web root                         │
│  - Randomized filename (no user input)      │
│  - Restricted file permissions              │
│  - Audit logging                            │
└────────────────┬────────────────────────────┘
                 │
┌────────────────▼────────────────────────────┐
│  Access Layer (Controlled Retrieval)        │
│  - File served via endpoint (not direct)    │
│  - MIME type re-verified on serve           │
│  - No path traversal possible               │
│  - Optional: Authentication check           │
└─────────────────────────────────────────────┘
```

## Security Features Implemented

### 1. Extension Whitelist (Client + Server)

**What**: Only allow specific file extensions: `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`

**Why**: Extensions can be faked, but combined with MIME type checking, they're part of defense-in-depth

**Implementation**:
```php
// Server-side validation
private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

private function validateExtension(string $filename): bool {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, self::ALLOWED_EXTENSIONS);
}
```

**Weakness if alone**: User could rename `malware.exe` to `malware.jpg`
**Solution**: Combine with MIME type validation (see #2)

### 2. MIME Type Verification

**What**: Check that the file's MIME type matches a safe set

**Why**: The server declares what type of file it is. We validate this matches expectations.

**Implementation**:
```php
private const ALLOWED_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
];

private function validateMimeType(UploadedFile $file): bool {
    return in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES);
}
```

**Important**: `$_FILES['file']['type']` is NOT secure (user-controlled). Use `finfo_open()` or `mime_content_type()`:

```php
// ✅ SECURE - Server-verified
$mimeType = mime_content_type($tmpFile);

// ❌ INSECURE - User provided
$mimeType = $_FILES['file']['type'];
```

### 3. Magic Bytes Validation (Optional but Recommended)

**What**: Verify the actual file content matches its declared MIME type

**Why**: An attacker might rename an executable to `.jpg` and fake the MIME type

**Implementation**:
```php
private function verifyImageMagicBytes(string $filePath): bool {
    // Use getimagesize() which verifies actual image structure
    $imageInfo = getimagesize($filePath);
    
    // Returns false if file is not a valid image
    return $imageInfo !== false;
}
```

This checks for "magic bytes" (file format signatures):
- JPEG: `FF D8 FF`
- PNG: `89 50 4E 47`
- GIF: `47 49 46 38` (GIF8)
- WebP: `52 49 46 46` (RIFF header)

### 4. File Size Validation

**What**: Reject files larger than 5MB (configurable)

**Why**: 
- Prevent disk space exhaustion attacks
- Protect against slow-speed attacks
- Manage memory usage in image processing

**Implementation**:
```php
private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

private function validateFileSize(UploadedFile $file): bool {
    return $file->getSize() <= self::MAX_FILE_SIZE;
}
```

**Where to set this**:
- PHP config: `php.ini` `upload_max_filesize = 5M`
- Application code: Validation class above
- Web server: nginx `client_max_body_size 5M;`
- Client: HTML `<input ... accept="image/*" />`

### 5. Filename Sanitization

**What**: Never store uploaded files with user-provided names

**Why**: Users might supply:
- `../../../etc/passwd` (directory traversal)
- `$(rm -rf /)` (command injection if filename used in scripts)
- `file'; DROP TABLE users; --` (SQL injection if filename stored in DB)

**Implementation**:
```php
private function generateSafeFilename(UploadedFile $file): string {
    // Option 1: Random hash (recommended for security)
    $hash = bin2hex(random_bytes(16)); // 32-char hex string
    $extension = $this->getExtensionFromMimeType($file->getMimeType());
    return "{$hash}.{$extension}";
}

// Store original filename separately in database for user reference
private function storeMetadata(string $hash, UploadedFile $file): void {
    // In database only - never used in filesystem operations
    $this->database->insert('uploads', [
        'hash' => $hash,
        'original_filename' => $file->getClientFilename(),
        'uploaded_at' => date('Y-m-d H:i:s'),
    ]);
}
```

### 6. Storage Outside Web Root

**What**: Store uploaded files outside `public/` directory

**Why**: 
- Prevents direct access: `http://example.com/storage/uploads/file.jpg` won't work
- Server must explicitly serve the file (you control the logic)
- No risk of accidentally running uploaded PHP scripts

**Structure**:
```
project/
├── public/          ← Web root, served directly
│   ├── index.php
│   ├── css/
│   └── js/
│
├── storage/         ← NOT accessible directly
│   └── uploads/     ← Uploaded files stored here
│
└── src/
    └── Controllers/
```

**Web Server Config** (Nginx example):
```nginx
root /path/to/project/public;

# uploads directory is NOT served directly
# Access only through controlled endpoint
location /storage/ {
    return 403;
}

# Controlled endpoint handles file access
location /api/images/ {
    try_files $uri @upload_handler;
}

location @upload_handler {
    rewrite ^/api/images/(.+)$ /index.php?action=serve&hash=$1 last;
}
```

### 7. Controlled File Serving

**What**: Serve files through PHP code that verifies access

**Why**: You can add access control, logging, and additional validation

**Implementation**:
```php
public function serveImage(string $hash): Response {
    // 1. Validate hash format
    if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
        return new Response('Not found', 404);
    }
    
    // 2. Retrieve file path
    $filePath = $this->storage->getFilePath($hash);
    
    // 3. Verify file exists
    if (!file_exists($filePath)) {
        return new Response('Not found', 404);
    }
    
    // 4. Verify it's actually an image
    $mimeType = mime_content_type($filePath);
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        return new Response('Forbidden', 403);
    }
    
    // 5. Log access
    $this->logger->info("Image served: {$hash}");
    
    // 6. Serve with correct content type
    return new FileResponse($filePath, $mimeType);
}
```

### 8. CSRF Token Protection

**What**: Include unique token in forms to prevent cross-site requests

**Why**: Prevent attackers from uploading files on behalf of users

**Implementation**:
```html
<!-- HTML Form -->
<form method="POST" action="/upload" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="file" name="image" accept="image/*" required>
    <button type="submit">Upload</button>
</form>
```

```php
// Server-side validation
public function upload(Request $request): Response {
    // Verify CSRF token
    if (!$this->validateCsrfToken($request->getPost('csrf_token'))) {
        return new Response('CSRF token invalid', 403);
    }
    
    // Process upload...
}

private function validateCsrfToken(string $token): bool {
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Generate token on page load
public function showUploadForm(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $this->renderTemplate('upload.html');
}
```

**Key point**: Use `hash_equals()` for timing-safe comparison

### 9. Input Validation

**What**: Validate all request parameters, not just files

**Why**: Attackers may target metadata, query parameters, or form fields

**Implementation**:
```php
private function validateUploadRequest(Request $request): ValidationResult {
    $errors = [];
    
    // File parameter required
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed';
    }
    
    // Optional: Description field validation
    $description = $request->getPost('description', '');
    if (strlen($description) > 500) {
        $errors[] = 'Description too long';
    }
    
    // No special characters in description
    if (!preg_match('/^[a-zA-Z0-9\s\.,\-]*$/', $description)) {
        $errors[] = 'Description contains invalid characters';
    }
    
    return new ValidationResult(count($errors) === 0, $errors);
}
```

### 10. Logging & Audit Trail

**What**: Log all upload attempts (success and failure)

**Why**:
- Detect attack patterns
- Audit compliance
- Troubleshoot issues
- Security incident investigation

**Implementation**:
```php
private function logUploadAttempt(
    string $status,
    UploadedFile $file,
    ?string $error = null
): void {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => $status,
        'ip_address' => $this->getClientIp(),
        'filename' => $file->getClientFilename(),
        'size' => $file->getSize(),
        'mime_type' => $file->getMimeType(),
        'error' => $error,
    ];
    
    error_log(json_encode($logEntry));
}

private function getClientIp(): string {
    // Check in order of reliability
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP']; // Cloudflare
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'];
}
```

## Common Vulnerabilities & Mitigations

| Vulnerability | Attack | Mitigation |
|---|---|---|
| **Unrestricted Upload** | Upload PHP shell | Extension + MIME + magic bytes whitelist |
| **Path Traversal** | Upload as `../../../shell.php` | Randomized filename, validate hash |
| **File Overwrite** | Upload same name repeatedly | Random hash prevents collisions |
| **Storage in Web Root** | Direct access to `uploads/shell.php` | Store outside web root |
| **Unverified MIME Type** | Fake MIME header | Use `getimagesize()` to verify actual content |
| **No Size Limit** | Fill disk with uploads | Enforce max file size server-side |
| **Directory Listing** | Browse `/uploads/` | Store outside web root, use controlled endpoint |
| **CSRF** | Upload on behalf of user | Use CSRF tokens in all forms |
| **Race Condition** | Upload simultaneously | Use atomic operations, database transactions |
| **Information Disclosure** | Error messages leak paths | Return generic errors, log details separately |

## Testing Security

### Manual Testing Checklist

- [ ] Upload valid image → succeeds
- [ ] Upload `.txt` file → rejected
- [ ] Upload `malware.exe` renamed to `.jpg` → rejected by MIME type check
- [ ] Modify MIME type → rejected (Python: `requests` library, Burp Suite)
- [ ] Try path traversal: `../../../etc/passwd.jpg` → fails (random hash)
- [ ] Try accessing `/storage/uploads/` directly → 403 Forbidden
- [ ] Try accessing non-existent image hash → 404 Not Found
- [ ] Upload with no CSRF token → 403 Forbidden
- [ ] Monitor logs for upload attempts → entries appear

### Automated Security Testing

```php
// tests/Security/FileUploadSecurityTest.php

public function testRejectsPHPFile() {
    $response = $this->uploadFile('shell.php', 'text/plain');
    $this->assertEquals(400, $response->getStatusCode());
}

public function testRejectsExecutable() {
    $response = $this->uploadFile('malware.exe', 'image/jpeg');
    $this->assertEquals(400, $response->getStatusCode());
}

public function testNoPathTraversalPossible() {
    $response = $this->uploadFile('../../../etc/passwd.jpg', 'image/jpeg');
    
    // File stored in hash, not user-provided name
    $storedPath = $response->getStoragePath();
    $this->assertNotContains('..', $storedPath);
}

public function testDirectAccessBlocked() {
    $response = $this->get('/storage/uploads/abc123.jpg');
    $this->assertEquals(403, $response->getStatusCode());
}

public function testCsrfTokenRequired() {
    // Upload without CSRF token
    $response = $this->uploadFile('image.jpg', 'image/jpeg', null);
    $this->assertEquals(403, $response->getStatusCode());
}

public function testFileServedWithCorrectMimeType() {
    $response = $this->uploadFile('test.jpg', 'image/jpeg');
    $imageUrl = $response->getImageUrl();
    
    $serveResponse = $this->get($imageUrl);
    $this->assertEquals('image/jpeg', $serveResponse->getHeader('Content-Type'));
}
```

## Production Checklist

Before deploying to production, verify:

- [ ] `APP_DEBUG=false` in `.env`
- [ ] HTTPS enabled (redirect HTTP to HTTPS)
- [ ] File size limits updated in `php.ini`, nginx, and application code
- [ ] Upload directory writable by web server but outside `public/`
- [ ] Uploaded files served only via controlled endpoint
- [ ] All forms have CSRF tokens
- [ ] Security headers set: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`
- [ ] Logging configured, old logs rotated
- [ ] Rate limiting configured (at reverse proxy or application)
- [ ] Firewall rules in place
- [ ] Security audit completed
- [ ] Backup strategy includes uploaded files
- [ ] Monitoring alerts configured for upload endpoint

## Further Reading

- [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)
- [OWASP Top 10 - File Upload Vulnerability](https://owasp.org/www-community/vulnerabilities/Unrestricted_File_Upload)
- [PHP Security Guide](https://www.php.net/manual/en/security.php)
- [File Function Vulnerabilities](https://owasp.org/www-community/Path_Traversal)

## Questions or Concerns?

File an issue with the `security` label, or open a PR with your questions.
