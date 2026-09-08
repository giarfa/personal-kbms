---
paths:
  - phpstan.neon.dist
---

# General

## Larastan must boot the app as APP_ENV=testing to see Livewire test macros
Livewire only registers `assertSeeLivewire`/`assertDontSeeLivewire` on `Illuminate\Testing\TestResponse` when `app()->environment('testing')` (see `vendor/livewire/livewire/src/Features/SupportTesting/SupportTesting.php`). Larastan boots `bootstrap/app.php` itself before analysis, under whatever `APP_ENV` the shell has (`local` by default), so those macros are invisible to static analysis and any test file calling them fails Larastan with "Call to an undefined method".

Fixed via `phpstan-bootstrap.php` (repo root) forcing `APP_ENV=testing` before boot, wired in with the Nette "reset" merge operator so it runs *before* Larastan's own `vendor/larastan/larastan/bootstrap.php`:

```neon
parameters:
    bootstrapFiles!:
        - phpstan-bootstrap.php
        - vendor/larastan/larastan/bootstrap.php
```

Plain `bootstrapFiles:` (no `!`) appends after the vendor include, which is too late — the app is already booted under the wrong env by then. Do not replace this with a stub file for `TestResponse`: stubbing that class wholesale drops its real inherited methods (`getContent()`, etc.) since PHPStan stubs fully replace the class's reflection data.
