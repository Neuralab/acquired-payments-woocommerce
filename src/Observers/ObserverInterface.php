<?php
/**
 * ObserverInterface.
 */

declare(strict_types=1);

namespace AcquiredComForWooCommerce\Observers;

/**
 * ObserverInterface interface.
 */
interface ObserverInterface {
	public function init_hooks() : void;
}
