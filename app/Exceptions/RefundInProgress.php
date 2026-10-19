<?php

namespace App\Exceptions;

/** Rule R2: one refund in progress per order. */
class RefundInProgress extends RefundNotAllowed
{
    public function __construct()
    {
        parent::__construct('refund_in_progress', 'A refund for this order is already in progress.', 409);
    }
}
