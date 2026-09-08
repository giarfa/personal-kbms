---
paths:
  - 'app/Livewire/**'
---

# Livewire

## Livewire components are class-based, not single-file
This project uses class-based Livewire components (`app/Livewire/*.php` + `resources/views/livewire/*.blade.php`), not Livewire 4's default single-file components. `config/livewire.php` pins `make_command.type = class` and `emoji = false`; generate with `php artisan make:livewire Name --class`.

Two reasons, both load-bearing: Larastan does not scan Blade files, so an SFC's anonymous class body escapes the mandatory level-5 gate entirely; and `make:livewire --test` scaffolds a Pest file while this project runs PHPUnit. Write tests as `php artisan make:test --phpunit` under `tests/Feature/`.

Recorded decision `livewire component style` (2026-09-08, US-004).
