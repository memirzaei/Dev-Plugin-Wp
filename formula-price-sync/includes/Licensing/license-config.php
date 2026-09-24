<?php
/**
 * Server-side marketplace license configuration.
 *
 * This file contains no secrets by default. Production installations should
 * define provider/endpoint/token constants in wp-config.php or environment
 * variables. The release ZIP must never contain a real marketplace token.
 *
 * Supported providers: zhaket, rastchin
 *
 * @package FormulaPriceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$provider = defined( 'FPS_LICENSE_PROVIDER' )
	? sanitize_key( (string) FPS_LICENSE_PROVIDER )
	: 'zhaket';

$rastchin_token = '';
if ( defined( 'FPS_RASTCHIN_PRODUCT_TOKEN' ) ) {
	$rastchin_token = (string) FPS_RASTCHIN_PRODUCT_TOKEN;
} else {
	$env_token = getenv( 'FPS_RASTCHIN_PRODUCT_TOKEN' );
	$rastchin_token = false !== $env_token ? (string) $env_token : '';
}

return array(
	'provider' => $provider,
	'rastchin' => array(
		'product_token' => $rastchin_token,
		'activate_url'  => defined( 'FPS_RASTCHIN_ACTIVATE_URL' ) ? esc_url_raw( (string) FPS_RASTCHIN_ACTIVATE_URL ) : '',
		'validate_url'  => defined( 'FPS_RASTCHIN_VALIDATE_URL' ) ? esc_url_raw( (string) FPS_RASTCHIN_VALIDATE_URL ) : '',
	),
);
