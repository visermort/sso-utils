<?php

declare(strict_types=1);

namespace Erg\SsoUtils\Entities;

use Erg\Client\Sso\Entities\Position as BasePosition;

/**
 * Class SsoPosition
 * @package App\Utils
 */
class Position extends BasePosition
{
    /**
     * @var string
     */
    public string $start_date;

    /**
     * @var string
     */
    public string $end_date;
}
