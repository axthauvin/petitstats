<?php

declare(strict_types=1);

namespace Petitstats\Mcp;

use Closure;

/**
 * Defines a MCP tool. Tools read the analytics, except the few that save
 * funnels, goals and journeys ($writes): those only run for a connection the
 * user allowed to edit, and no tool ever deletes anything. A tool with a $view
 * names the `ui://` page that assistants supporting MCP Apps display with its
 * result, such as a chart.
 */
final class Tool
{
    /**
     * @param array<string, array<string, mixed>> $properties JSON Schema of each argument
     * @param list<string> $required
     * @param Closure(array<string, mixed>): array<string, mixed> $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        private readonly array $properties,
        private readonly array $required,
        private readonly Closure $handler,
        public readonly bool $writes = false,
        public readonly ?string $view = null,
    ) {}

    /** The tool as listed by `tools/list`. */
    public function definition(): array
    {
        $schema = ['type' => 'object', 'properties' => (object) $this->properties, 'additionalProperties' => false];
        if ($this->required) {
            $schema['required'] = $this->required;
        }
        $definition = [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $schema,
            'annotations' => $this->writes
                ? ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]
                : ['readOnlyHint' => true, 'openWorldHint' => false],
        ];
        if ($this->view !== null) {
            $definition['_meta'] = ['ui' => ['resourceUri' => $this->view]];
        }
        return $definition;
    }

    /** @param array<string, mixed> $arguments */
    public function call(array $arguments): array
    {
        return ($this->handler)($arguments);
    }
}
