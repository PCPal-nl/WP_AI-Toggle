<?php
/**
 * Clean up when the plugin is deleted.
 *
 * @package AI_Toggle
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ai_toggle_options = array(
	'ai_toggle_version',
	'ai_toggle_category',
	'ai_toggle_locations',
	'ai_toggle_label',
	// Option names used by builds that predate the directory release.
	'pcpal_ai_toggle_category',
	'pcpal_ai_toggle_locations',
	'pcpal_ai_toggle_label',
	'pcpal_ai_category_id',
);

foreach ( $ai_toggle_options as $ai_toggle_option ) {
	delete_option( $ai_toggle_option );
}

unset( $ai_toggle_options, $ai_toggle_option );
