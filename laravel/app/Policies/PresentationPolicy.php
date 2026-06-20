<?php

namespace App\Policies;

use App\Models\Presentation;
use App\Models\User;

class PresentationPolicy
{
    public function view(User $user, Presentation $presentation): bool
    {
        return $user->id === $presentation->user_id;
    }

    public function update(User $user, Presentation $presentation): bool
    {
        return $user->id === $presentation->user_id;
    }

    public function delete(User $user, Presentation $presentation): bool
    {
        return $user->id === $presentation->user_id;
    }

    /**
     * Download hanya untuk owner dan hanya saat artefak sudah "ready".
     */
    public function download(User $user, Presentation $presentation): bool
    {
        return $user->id === $presentation->user_id && $presentation->isReady();
    }
}
