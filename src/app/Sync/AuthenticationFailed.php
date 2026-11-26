<?php

declare(strict_types=1);

namespace SkemaService\Sync;

use RuntimeException;

/** Studie+ afviste loginoplysningerne. Beskeden må aldrig indeholde hemmeligheder. */
final class AuthenticationFailed extends RuntimeException
{
}
