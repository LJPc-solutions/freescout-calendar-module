<?php
define( 'ICALCREATOR_VERSION', 'LJPc Calendar Module' );

/**
 * load iCalcreator src and support classes and Traits
 */
spl_autoload_register(
	function ( $class ) {
		static $BS = '\\';
		static $PHP = '.php';
		static $PREFIX = 'Kigkonsult\\Icalcreator\\';
		static $SRC = '.';
		static $SRCDIR = null;
		static $TEST = 'test';
		static $TESTDIR = null;
		if ( is_null( $SRCDIR ) ) {
			$SRCDIR  = __DIR__ . DIRECTORY_SEPARATOR . $SRC . DIRECTORY_SEPARATOR;
			$TESTDIR = __DIR__ . DIRECTORY_SEPARATOR . $TEST . DIRECTORY_SEPARATOR;
		}
		if ( 0 !== strncmp( $PREFIX, $class, 23 ) ) {
			return false;
		}
		$class = substr( $class, 23 );
		if ( false !== strpos( $class, $BS ) ) {
			$class = str_replace( $BS, DIRECTORY_SEPARATOR, $class );
		}
		$file = $SRCDIR . $class . $PHP;
		if ( file_exists( $file ) ) {
			include $file;
		} else {
			$file = $TESTDIR . $class . $PHP;
			if ( file_exists( $file ) ) {
				include $file;
			}
		}
	}
);
