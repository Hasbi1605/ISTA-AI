<?php

use Livewire\Livewire;

$component = Livewire::test('pages.auth.register')
    ->set('name', 'Test User')
    ->set('email', 'test@example.com')
    ->set('password', 'password123')
    ->set('password_confirmation', 'password123')
    ->call('register');

if ($component->errors()->has('password')) {
    echo "Validation error for password: " . $component->errors()->first('password') . "\n";
} else {
    echo "No validation errors for password.\n";
}
