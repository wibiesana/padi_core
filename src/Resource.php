<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

/**
 * Base Resource class for API response formatting
 * 
 * Similar to Laravel Resources or Yii Fields.
 * Transforms raw database arrays into clean API response shapes.
 */
abstract class Resource implements \JsonSerializable
{
    /** @var mixed The resource instance/array */
    protected mixed $resource;

    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Wrap a single resource instance (Singular)
     * 
     * @param mixed $resource The resource instance/array
     * @return static
     */
    public static function wrap(mixed $resource): static
    {
        return new static($resource);
    }

    /**
     * Wrap a collection of resources (Plural)
     * 
     * Handles both paginated results (with 'data' key)
     * and flat arrays of items.
     * 
     * @param mixed $resource Array of items or paginated result
     * @return array
     */
    public static function wraps(mixed $resource): array
    {
        // Handle paginated or enveloped results containing a 'data' array
        if (is_array($resource) && isset($resource['data']) && is_array($resource['data'])) {
            $resource['data'] = array_map(
                static fn(mixed $item): array => (new static($item))->resolve(),
                $resource['data']
            );
            return $resource;
        }

        // Handle flat array of items
        if (is_array($resource)) {
            return array_map(
                static fn(mixed $item): array => (new static($item))->resolve(),
                $resource
            );
        }

        return [];
    }

    /**
     * Resolve the resource to an array
     */
    public function resolve(): array
    {
        if ($this->resource === null) {
            return [];
        }
        return $this->toArray($this->resource);
    }

    /**
     * Transform the resource into an array
     */
    abstract public function toArray(mixed $request): array;

    /**
     * Serialize to JSON
     */
    public function jsonSerialize(): mixed
    {
        return $this->resolve();
    }

    /**
     * Conditionally include a relation/field if it exists (is loaded)
     */
    protected function whenLoaded(string $key, mixed $default = null): mixed
    {
        if (is_array($this->resource) && array_key_exists($key, $this->resource)) {
            return $this->resource[$key];
        }

        if (is_object($this->resource) && isset($this->resource->{$key})) {
            return $this->resource->{$key};
        }

        return $default;
    }

    /**
     * Access properties dynamically from the resource
     */
    public function __get(string $name): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource[$name] ?? null;
        }
        return $this->resource->{$name} ?? null;
    }
}
