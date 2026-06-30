<?php
/**
 * Common interface for plugin and theme collectors.
 *
 * @package Kistn
 */

/**
 * Common interface for plugin and theme collectors.
 */
interface Kistn_Collector_Interface {

	/**
	 * Collects installed packages.
	 *
	 * @return array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool, depth: int, source_url: string|null, available_version: string|null}>
	 */
	public function collect(): array;
}
