<?php

use Livewire\Livewire;

$component = Livewire::test('pages.auth.login')
    ->set('view', 'register')
    ->set('name', 'Test User')
    ->set('register_email', 'test2@example.com')
    ->set('register_password', 'password123')
    ->set('password_confirmation', 'password123')
    ->call('register');

if ($component->errors()->has('register_password')) {
    echo "Validation error for register_password: " . $component->errors()->first('register_password') . "\n";
} else {
    echo "No validation errors for password.\n";
}
