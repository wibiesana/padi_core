<?php

namespace Wibiesana\Padi\Core;

/**
 * Base Resource class for API response formatting
 * Similar to Laravel Resources or Yii Fields
 */
abstract class Resource implements \JsonSerializable
{
    /**
     * The resource instance/array
     * @var mixed
     */
    protected $resource;

    public function __construct($resource)
    {
        $this->resource = $resource;
    }

    /**
     * Create a new resource instance
     * @param mixed $resource
     * @return static
     */
    public static function make($resource)
    {
        return new static($resource);
    }

    /**
     * Create a collection of resources
     * @param mixed $resource
     * @return array
     */
    public static function collection($resource)
    {
        // Handle paginated results ['data' => [...], 'meta' => [...]]
        if (is_array($resource) && isset($resource['data']) && isset($resource['meta'])) {
            $resource['data'] = array_map(function ($item) {
                return (new static($item))->resolve();
            }, $resource['data']);
            return $resource;
        }

        // Handle array of items
        if (is_array($resource)) {
            return array_map(function ($item) {
                return (new static($item))->resolve();
            }, $resource);
        }

        return [];
    }

    /**
     * Resolve the resource to an array
     * @return array
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
     * @param mixed $request
     * @return array
     */
    abstract public function toArray($request): array;

    /**
     * Serialize to JSON
     */
    public function jsonSerialize(): mixed
    {
        return $this->resolve();
    }

    /**
     * Conditionally include a relation/field if it exists (is loaded)
     * Useful to avoid errors when a relation wasn't eager loaded
     * 
     * @param string $key The key/relation name in the source array
     * @param mixed $default Value to return if missing (null or hidden)
     * @return mixed
     */
    protected function whenLoaded(string $key, $default = null)
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
     * Access properties dynamically from the resource array
     */
    public function __get($name)
    {
        if (is_array($this->resource)) {
            return $this->resource[$name] ?? null;
        }
        return $this->resource->{$name} ?? null;
    }
}
