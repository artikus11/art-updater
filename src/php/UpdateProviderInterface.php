<?php

declare(strict_types=1);

namespace Art\Updater;

interface UpdateProviderInterface {

	/**
	 * Returns an update when the source has a newer version for the plugin.
	 * Returns null when the plugin is missing from the source or the version is not newer.
	 */
	public function get_update( Plugin $plugin ): ?Update;
}
