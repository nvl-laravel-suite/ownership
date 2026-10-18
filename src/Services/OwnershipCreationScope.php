<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Permits exactly one concrete new root per nested writer invocation. @internal */
final class OwnershipCreationScope
{
    /** @var list<array{class: class-string<Model>, attributes: array<string, int|string|null>, record: Model|null}> */
    private array $frames = [];

    /**
     * Restore the previous write permit even when persistence or assignment fails.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @param  array<string, int|string|null>  $attributes
     * @param  Closure(): T  $persist
     * @return T
     */
    public function during(string $class, array $attributes, Closure $persist): Model
    {
        $this->frames[] = ['class' => $class, 'attributes' => $attributes, 'record' => null];
        try {
            $record = $persist();
            $frame = $this->frames[array_key_last($this->frames)];
            if ($frame['record'] !== $record || ! $record->exists || $record::class !== $class) {
                throw new OwnershipViolation('The ownership writer must return its single persisted concrete record.');
            }

            return $record;
        } finally {
            array_pop($this->frames);
        }
    }

    /** @param array<string, int|string|null> $current */
    public function claim(Model $model, array $current): void
    {
        $index = array_key_last($this->frames);
        if ($index === null || $this->frames[$index]['class'] !== $model::class
            || $this->frames[$index]['attributes'] !== $current
            || ($this->frames[$index]['record'] !== null && $this->frames[$index]['record'] !== $model)) {
            throw new OwnershipViolation('Owned creation requires its current canonical writer assignment.');
        }
        $actual = $model->getAttributes();
        foreach ($current as $field => $expected) {
            $value = $actual[$field] ?? null;
            if ($value === $expected) {
                continue;
            }
            if ((! is_int($value) && ! is_string($value)) || (! is_int($expected) && ! is_string($expected)) || (string) $value !== (string) $expected) {
                throw new OwnershipViolation('Ownership attributes must match the trusted source assignment.');
            }
        }
        $this->frames[$index]['record'] = $model;
    }
}
