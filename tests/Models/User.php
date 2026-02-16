<?php

namespace Parallel\L1\Test\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Parallel\L1\Test\Database\Factories\UserFactory;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'name', 'email', 'password',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * @return UserFactory
     */
    protected static function newFactory()
    {
        return UserFactory::new();
    }
}
