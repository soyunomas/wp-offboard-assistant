<?php
/**
 * Archivo principal del Core del plugin.
 *
 * Responsable de instanciar y ejecutar las clases principales del plugin.
 *
 * @package           WP_Offboard_Assistant
 * @subpackage        Includes
 * @author            soyunomas
 * @since             1.0.0
 */

// ¡Control de seguridad! Si este archivo es llamado directamente, abortar.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class WPOA_Core {
    
    public function run() {
        // Instanciar las clases que manejan las diferentes áreas.
        new WPOA_Admin_UI();
        new WPOA_Ajax();
    }
}
