# Security Policy

## Reporting a Vulnerability

**Please do not open public issues for security vulnerabilities.**

If you discover a security vulnerability, please report it responsibly by emailing:

**security@radiank.com**

You will receive a response within 48 hours. Please include:

- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

## Supported Versions

| Version | Supported |
|---------|-----------|
| Latest  | Yes       |

## Security Practices

- All secrets externalized via environment variables
- Input validation on all endpoints (Form Requests)
- SQL injection prevention via Eloquent ORM
- XSS prevention via Vue.js template escaping
- CSRF protection on all state-changing requests
- Security headers enforced (X-Frame-Options, X-Content-Type-Options, X-XSS-Protection)
- Authentication via Laravel Sanctum (API) and session (Web)
- Shell commands use `escapeshellarg()` for input sanitization
- Dependencies regularly updated
