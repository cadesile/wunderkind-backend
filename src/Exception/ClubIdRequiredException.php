<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ClubIdRequiredException extends BadRequestHttpException
{
    public function __construct(string $message = 'The X-Club-Id header is required for this action.')
    {
        parent::__construct($message);
    }
}
