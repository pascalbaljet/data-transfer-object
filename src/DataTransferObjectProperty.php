<?php

declare(strict_types=1);

namespace Spatie\DataTransferObject;

use ReflectionProperty;
use Spatie\DataTransferObject\DataTransferObjectDefinition;

class DataTransferObjectProperty extends ReflectionProperty
{
    /** @var array */
    protected static $typeMapping = [
        'int' => 'integer',
        'bool' => 'boolean',
    ];

    /** @var \Spatie\DataTransferObject\DataTransferObject */
    protected $dataTransferObject;

    /** @var bool */
    protected $hasTypeDeclaration = false;

    /** @var bool */
    protected $isNullable = false;

    /** @var bool */
    protected $isInitialised = false;

    /** @var array */
    protected $types = [];

    /** @var \Spatie\DataTransferObject\DataTransferObjectDefinition */
    protected $dataTransferObjectDefinition;

    public static function fromReflection(
        DataTransferObject $dataTransferObject,
        DataTransferObjectDefinition $dataTransferObjectDefinition,
        ReflectionProperty $reflectionProperty
    ) {
        return new self($dataTransferObject, $dataTransferObjectDefinition, $reflectionProperty);
    }

    public function __construct(
        DataTransferObject $dataTransferObject,
        DataTransferObjectDefinition $dataTransferObjectDefinition,
        ReflectionProperty $reflectionProperty
    ) {
        parent::__construct($reflectionProperty->class, $reflectionProperty->getName());

        $this->dataTransferObject = $dataTransferObject;

        $this->dataTransferObjectDefinition = $dataTransferObjectDefinition;

        $this->resolveTypeDefinition();
    }

    public function set($value)
    {
        if (is_array($value)) {
            $value = $this->cast($value);
        }

        if (! $this->isValidType($value)) {
            throw DataTransferObjectError::invalidType($this, $value);
        }

        $this->isInitialised = true;

        $this->dataTransferObject->{$this->getName()} = $value;
    }

    public function getTypes(): array
    {
        return $this->types;
    }

    public function getFqn(): string
    {
        return "{$this->getDeclaringClass()->getName()}::{$this->getName()}";
    }

    public function isNullable(): bool
    {
        return $this->isNullable;
    }

    protected function resolveTypeDefinition()
    {
        $docComment = $this->getDocComment();

        if (! $docComment) {
            $this->isNullable = true;

            return;
        }

        preg_match('/\@var ((?:(?:[\w|\\\\])+(?:\[\])?)+)/', $docComment, $matches);

        if (! count($matches)) {
            $this->isNullable = true;

            return;
        }

        $varDocComment = end($matches);

        $this->types = explode('|', $varDocComment);

        $this->isNullable = strpos($varDocComment, 'null') !== false;

        $this->hasTypeDeclaration = true;
    }

    protected function isValidType($value): bool
    {
        if (! $this->hasTypeDeclaration) {
            return true;
        }

        if ($this->isNullable && $value === null) {
            return true;
        }

        foreach ($this->types as $currentType) {
            $isValidType = $this->assertTypeEquals($currentType, $value);

            if ($isValidType) {
                return true;
            }
        }

        return false;
    }

    protected function cast($value)
    {
        $castTo = null;

        foreach ($this->types as $type) {
            if (! is_subclass_of($type, DataTransferObject::class)) {
                continue;
            }

            $castTo = $type;

            break;
        }

        if (! $castTo) {
            return $value;
        }

        return new $castTo($value);
    }

    protected function assertTypeEquals(string $type, $value): bool
    {
        if (strpos($type, '[]') !== false) {
            return $this->isValidGenericCollection($type, $value);
        }

        if ($type === 'mixed' && $value !== null) {
            return true;
        }

        if ($this->dataTransferObjectDefinition->hasAlias($type)) {
            $type = $this->dataTransferObjectDefinition->resolveAlias($type);

            return $value instanceof $type;
        }

        return $value instanceof $type
            || gettype($value) === (self::$typeMapping[$type] ?? $type);
    }

    protected function isValidGenericCollection(string $type, $collection): bool
    {
        if (! is_array($collection)) {
            return false;
        }

        $valueType = str_replace('[]', '', $type);

        foreach ($collection as $value) {
            if (! $this->assertTypeEquals($valueType, $value)) {
                return false;
            }
        }

        return true;
    }
}