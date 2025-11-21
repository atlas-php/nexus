<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Prism\Prism\Contracts\Schema;

/**
 * Class ToolParameter
 *
 * Captures a Prism schema and requirement indicator for building tool parameter definitions.
 */
class ToolParameter
{
    public function __construct(
        private readonly Schema $schema,
        private readonly bool $required = true
    ) {}

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }
}
