<?php

declare(strict_types=1);

namespace Art\Updater;

interface UpdateProviderInterface {

	public function get_update( Plugin $plugin ): ?Update;
}
