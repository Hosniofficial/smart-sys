<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** Indicates that a journal entry targets a closed accounting period. */
final class AccountingPeriodClosedException extends DomainException
{
}
