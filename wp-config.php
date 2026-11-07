<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'bazmet_wp' );

/** Database username */
define( 'DB_USER', 'bazmet' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         'QfEj2=vb6wWQ@{-fc!^<0 sFp7J WTuEOjYz}$6b[jKtpvtvEIfjheAJGQwRU(&]');
define('SECURE_AUTH_KEY',  '+F3NC0AbiBTzJ.zONv8~^^[>^|Mosd!k4^^J*xQWm>Y+/[4)8jMh}50ma22t~cgD');
define('LOGGED_IN_KEY',    'b |&oE>-Q6 fHeZSmT3Kvo-qm|/JpbU*|fV_r!urz@S@Sv0dUHHULrbd!u:X@<|N');
define('NONCE_KEY',        'C[_N>{@!*wKZ$ytjy3{Vf,uatpkk9XefjlLqx#[fTB8l~M:a(CyYS6Zdn_yZ#c|y');
define('AUTH_SALT',        ',ujf.<yKqmI]Vib xx3CnPw35&Teo(?V+v()R?C]Jn~aJQnsZ=~+J5]h1u5j!svr');
define('SECURE_AUTH_SALT', '#BBXx7vdKeS/o5;d;JRG$dY h{#{1>kTEO[R{;xvHs^l#(<LX7cV_FzFQh:oA7vM');
define('LOGGED_IN_SALT',   'x}0Yg|{qsFWF2gGA}1[>{|lJ`qOC=^O66@Rtq(,-iALlt[qoEhmK13qyP-SjWrmo');
define('NONCE_SALT',       '|i>h?9*}[>=9ySE4M8f]/X[^hBzS`W`#UKuV%i#+h,e.}q;YtZX(D>(=a.m^a+o<');

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */

/**
 * Адрес headless CMS Strapi (работа № 9).
 * WordPress ходит сюда за синхронизацией каталога (см. тему astra-bazmet/inc/strapi-sync.php).
 */
define('BAZMET_STRAPI_URL', 'http://127.0.0.1:1337');

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
