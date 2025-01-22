<?php

declare(strict_types=1);

namespace Vrok\ImportExport\Tests\Fixtures;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class RepeatableAttribute
{
    public function __construct(
        public int $value = 0,
    ) {
    }
}
