<?php

declare(strict_types=1);

namespace Shale\Interfaces\DataSource;

use Result\Result;

/**
 * Interface DataSourceInterface
 *
 * @package Shale\Interfaces\DataSource
 */
interface DataSourceInterface
{
    /**
     * Request a module by its external route identifier
     *
     * @param array $context
     * @return Result
     */
    public function requestUrl(array $context): Result;
}
