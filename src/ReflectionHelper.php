<?php

declare(strict_types=1);

namespace Vrok\ImportExport;

class ReflectionHelper
{
    /**
     * @param  string $className         the FQCN of the class for which the
     *                                   attribute is fetched
     * @param  string $attribute         the FQCN of the attribute to fetch
     * @param  bool   $inheritFromParent If true, the parent class (if any) is
     *                                   evaluated when no attributes where
     *                                   found on the given class
     * @return mixed  null if no attribute of the given FQCN was
     *                found, an instance of the attribute class if
     *                one was found, an array if multiple were found
     *
     * @throws \Error
     * @throws \ReflectionException
     */
    public static function getClassAttribute(
        string $className,
        string $attribute,
        bool $inheritFromParent = true,
    ): mixed {
        $reflection = new \ReflectionClass($className);
        $attributes = $reflection->getAttributes($attribute);

        if ($inheritFromParent
            && [] === $attributes
            && $parent = $reflection->getParentClass()
        ) {
            return self::getClassAttribute($parent->name, $attribute);
        }

        return self::mapAttributes($attributes);
    }

    /**
     * @param  \ReflectionProperty $property  the property for which the attribute
     *                                        is fetched
     * @param  string              $attribute the FQCN of the attribute to fetch
     * @return mixed               null if no attribute of the given FQCN was
     *                             found, an instance of the attribute class if
     *                             one was found, an array if multiple were found
     * @throws \Error
     */
    public static function getPropertyAttribute(
        \ReflectionProperty $property,
        string $attribute,
    ): mixed {
        return self::mapAttributes($property->getAttributes($attribute));
    }

    /**
     * Returns an instance of the attribute class if one attribute is in the
     * given list, an array of instances if multiple were found, else null.
     *
     * @throws \Error when the class specified as attribute does not exist
     */
    private static function mapAttributes(array $attributes): mixed
    {
        if ([] === $attributes) {
            return null;
        }

        $attributes = array_map(
            static fn (\ReflectionAttribute $attribute) => $attribute->newInstance(),
            $attributes
        );

        return 1 === \count($attributes) ? $attributes[0] : $attributes;
    }
}
