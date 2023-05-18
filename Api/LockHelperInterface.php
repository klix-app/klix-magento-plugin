<?php

/**
 *  © Developers-Alliance, Inc. All rights reserved.
 */

namespace SpellPayment\ExpressCheckout\Api;

interface LockHelperInterface
{
    /**
     * Generic lock name
     */
    const LOCK_NAME = 'spell_payment';

    /**
     * @param string $name
     * @param int $timeout
     * @return bool
     */
    public function acquireLock(string $name, int $timeout = 15) : bool;

    /**
     * @param string $lockName
     * @return mixed
     */
    public function releaseLock(string $lockName);
}
