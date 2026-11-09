<?php

namespace Porro\Filament\Tests;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;

/**
 * A panel user, as an app would have one: canAccessPanel() decides.
 */
class SignInUser extends User implements FilamentUser
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['can_use_panel' => 'boolean'];

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->can_use_panel;
    }
}
