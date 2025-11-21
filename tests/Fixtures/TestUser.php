<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Class TestUser
 *
 * Minimal authenticatable model for prompt variable resolution during tests.
 */
class TestUser extends Authenticatable
{
    protected $table = 'users';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
