<?php

declare(strict_types=1);

/**
 * Livewire only registers its test-only macros (assertSeeLivewire, ...) when
 * app()->environment('testing') — see
 * vendor/livewire/livewire/src/Features/SupportTesting/SupportTesting.php.
 * Larastan boots the app from bootstrap/app.php under whatever APP_ENV the
 * shell has (local by default), so those macros are invisible to static
 * analysis unless this runs before Larastan's own bootstrap.
 */
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
