<?php

namespace App\Exceptions;

/**
 * A business-rule violation that should be shown to the user (e.g. unbalanced entry, insufficient stock).
 */
class ErpException extends \RuntimeException {}
