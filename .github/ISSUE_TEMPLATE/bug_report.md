---
name: Bug report
about: Something the package does that it should not, or does not do that it should
title: ''
labels: bug
assignees: ''
---

## Environment

- laravel-app-version version:
- Laravel version:
- PHP version:
- Where the middleware is registered (`api` group, route, global):
- Behind a proxy or CDN? (which one):

## Policy

Paste the relevant platform entry from `config/app-version.php` with real
version numbers, or the output of `php artisan app-version:policy`.

```
```

## Request

The exact request, with headers. `curl -i` output is ideal.

```
```

## Expected

What you expected: status code, body and headers.

## Actual

What happened instead: status code, body and headers.

## Notes

Anything else that helps: a failing test, a stack trace, a link to a minimal
reproduction.
