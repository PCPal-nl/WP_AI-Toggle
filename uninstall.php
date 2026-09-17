<?php
/**
 * Opruimen bij verwijderen van de plugin.
 *
 * @package AI_Toggle
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pcpal_ai_toggle_category' );
delete_option( 'pcpal_ai_toggle_locations' );
delete_option( 'pcpal_ai_toggle_label' );
