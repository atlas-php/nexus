<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Class TestUser
 *
 * Minimal authenticatable model for prompt variable resolution during tests.
 *
 * @property string|null $name
 * @property string|null $email
 */
class TestUser extends Authenticatable
{
    protected $table = 'users';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
