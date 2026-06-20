<?php

namespace App\Policies;

use App\Models\GeneratedPrompt;
use App\Models\User;

class GeneratedPromptPolicy
{
    public function view(User $user, GeneratedPrompt $prompt): bool
    {
        return $user->id === $prompt->user_id;
    }

    public function delete(User $user, GeneratedPrompt $prompt): bool
    {
        return $user->id === $prompt->user_id;
    }
}
