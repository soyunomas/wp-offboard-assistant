<?php
/**
 * Maneja todas las peticiones AJAX del plugin.
 *
 * Se encarga de la obtención de datos del usuario y la ejecución
 * del proceso de offboarding de forma segura.
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

class WPOA_Ajax {

    public function __construct() {
        // Hooks (sin cambios)
        add_action( 'wp_ajax_wpoa_get_user_data', array( $this, 'get_user_data' ) );
        add_action( 'wp_ajax_wpoa_execute_offboarding', array( $this, 'execute_offboarding' ) );
    }

    public function get_user_data() {
        // Seguridad y validación inicial
        check_ajax_referer( 'wpoa_offboard_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permiso denegado.' ) );
        }
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => 'ID de usuario no válido.' ) );
        }

        $content_count = count_user_posts( $user_id );

        $potential_owners = get_users( array(
            'capability' => 'edit_others_posts',
            'exclude'    => array( $user_id ),
            'fields'     => 'all',
        ) );
        
        $admin_options = array();
        foreach ( $potential_owners as $owner ) {
            $display_text = esc_html( $owner->user_login );

            if ( ! empty( $owner->roles ) ) {
                $role_slug = $owner->roles[0];
                $role_name = translate_user_role( $role_slug );
                $display_text = sprintf( '%s (%s)', esc_html( $owner->user_login ), esc_html( $role_name ) );
            }

            $admin_options[] = array( 'id' => $owner->ID, 'login' => $display_text );
        }

        wp_send_json_success( array(
			'content_count' => $content_count,
			'admins'        => $admin_options,
		) );
    }

    /**
     * AJAX Handler: Ejecuta las acciones de offboarding.
     * (VERSIÓN 1.1.0 CON LÓGICA DE ARCHIVADO)
     */
    public function execute_offboarding() {
        // 1. SEGURIDAD Y SANITIZACIÓN (extendida)
        check_ajax_referer( 'wpoa_offboard_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permiso denegado.' ) );
        }
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( get_current_user_id() === $user_id ) {
            wp_send_json_error( array( 'message' => 'Error de seguridad: No puedes realizar un offboarding a tu propia cuenta.' ) );
        }

        // Nuevos parámetros para v1.1.0
        $action_type = isset( $_POST['action_type'] ) ? sanitize_key( $_POST['action_type'] ) : ''; // 'degrade', 'archive', 'delete'
        $confirmation = isset( $_POST['confirmation_text'] ) ? sanitize_text_field( $_POST['confirmation_text'] ) : '';
        
        $reassign_to = isset( $_POST['reassign_to'] ) ? absint( $_POST['reassign_to'] ) : 0;
        $anonymize_comments = isset( $_POST['anonymize_comments'] ) && 'true' === $_POST['anonymize_comments'];
        $export_data = isset( $_POST['export_data'] ) && 'true' === $_POST['export_data'];
        
        // 2. VALIDACIÓN
        if ( ! $user_id || ! in_array( $action_type, array( 'delete', 'degrade', 'archive' ) ) || strtoupper( $confirmation ) !== 'OFFBOARD' ) {
            wp_send_json_error( array( 'message' => 'Datos de solicitud inválidos.' ) );
        }
        $user_to_offboard = get_userdata( $user_id );
        if ( ! $user_to_offboard ) {
            wp_send_json_error( array( 'message' => 'El usuario a dar de baja no existe.' ) );
        }
        
        $actions_performed = array();

        // 3. EJECUCIÓN BASADA EN EL TIPO DE ACCIÓN
        switch ( $action_type ) {
            case 'archive':
                $actions_performed[] = 'Iniciando proceso de archivado.';

                // a) Exportación de datos (si se solicitó) - Debe ejecutarse antes de anonimizar el email.
                if ( $export_data ) {
                    $request_id = wp_send_personal_data_export_email( $user_to_offboard->user_email );
                    if ( is_wp_error( $request_id ) ) {
                        $actions_performed[] = 'AVISO: No se pudo iniciar la exportación de datos. Error: ' . $request_id->get_error_message();
                    } else {
                        $actions_performed[] = 'Solicitud de exportación de datos personales enviada al email del usuario.';
                    }
                }

                // b) Anonimizar comentarios (si se solicitó)
                if ( $anonymize_comments ) {
                    $this->_anonymize_user_comments( $user_id );
                    $actions_performed[] = 'Comentarios públicos del usuario anonimizados.';
                }

                // c) Reasignar contenido (si se solicitó)
                if ( $reassign_to > 0 ) {
                    $this->_reassign_content( $user_id, $reassign_to, $actions_performed );
                }

                // d) Anonimizar perfil de usuario y revocar acceso (acción principal del archivado)
                $this->_anonymize_user_profile( $user_id );
                $actions_performed[] = 'Perfil de usuario anonimizado (email, nombre, etc).';
                wp_set_password( wp_generate_password( 64, true, true ), $user_id );
                $actions_performed[] = 'Contraseña reiniciada a un valor aleatorio y seguro.';
                
                // e) Destruir sesiones (común a todas las acciones no destructivas)
                WP_Session_Tokens::get_instance( $user_id )->destroy_all();
                $actions_performed[] = 'Todas las sesiones activas han sido destruidas.';
                break;

            case 'degrade':
                if ( $reassign_to > 0 ) {
                    $this->_reassign_content( $user_id, $reassign_to, $actions_performed );
                }
                wp_update_user( array( 'ID' => $user_id, 'role' => 'subscriber' ) );
                wp_set_password( wp_generate_password( 24, true, true ), $user_id );
                $actions_performed[] = 'Cuenta degradada a rol "Suscriptor" y contraseña reiniciada.';
                
                WP_Session_Tokens::get_instance( $user_id )->destroy_all();
                $actions_performed[] = 'Todas las sesiones activas han sido destruidas.';
                break;

            case 'delete':
                require_once( ABSPATH . 'wp-admin/includes/user.php' );
                // La función wp_delete_user ya gestiona la reasignación si se le pasa el ID.
                wp_delete_user( $user_id, $reassign_to );
                $actions_performed[] = 'Cuenta de usuario eliminada permanentemente.';
                if ($reassign_to > 0) {
                    $reassign_user = get_userdata($reassign_to);
                    $reassign_login = $reassign_user ? $reassign_user->user_login : "ID {$reassign_to}";
                    $actions_performed[] = "Contenido reasignado al usuario " . esc_html($reassign_login) . ".";
                }
                break;
        }

        // 4. REGISTRO Y RESPUESTA
        WPOA_DB::add_log_entry( $user_id, $user_to_offboard->user_login, implode( ' ', $actions_performed ) );
        wp_send_json_success( array( 'message' => 'Proceso de offboarding completado con éxito.' ) );
    }

    /**
     * Helper privado para reasignar contenido de un usuario a otro.
     *
     * @param int   $user_id           ID del usuario cuyo contenido se reasignará.
     * @param int   $reassign_to       ID del usuario que recibirá el contenido.
     * @param array &$actions_performed Array de acciones para el log (pasado por referencia).
     */
    private function _reassign_content( $user_id, $reassign_to, &$actions_performed ) {
        global $wpdb;
        $reassign_user = get_userdata( $reassign_to );
        // Comprobación de seguridad CLAVE: verificar que el destino tiene permisos.
        if ( $reassign_user && user_can( $reassign_user->ID, 'edit_others_posts' ) ) {
            $wpdb->update( $wpdb->posts, array( 'post_author' => $reassign_to ), array( 'post_author' => $user_id ) );
            $actions_performed[] = "Contenido reasignado a " . esc_html( $reassign_user->user_login ) . ".";
        } else {
            $actions_performed[] = "AVISO: Intento de reasignación a un usuario sin privilegios (ID: {$reassign_to}) fue denegado.";
        }
    }
    
    /**
     * Helper privado para anonimizar el perfil de un usuario.
     *
     * @param int $user_id ID del usuario a anonimizar.
     */
    private function _anonymize_user_profile( $user_id ) {
        $user_data = get_userdata( $user_id );
        if ( ! $user_data ) return;

        wp_update_user( array(
            'ID'           => $user_id,
            'user_email'   => 'archived.user.' . $user_id . '@deleted.local',
            'display_name' => __( 'Antiguo Colaborador', 'wp-offboard-assistant' ),
            'first_name'   => '',
            'last_name'    => '',
            'user_url'     => '',
            'description'  => '',
            'role'         => '' // Eliminar todos los roles para neutralizar la cuenta.
        ) );
    }

    /**
     * Helper privado para anonimizar los comentarios de un usuario.
     *
     * @param int $user_id ID del usuario cuyos comentarios se anonimizarán.
     */
    private function _anonymize_user_comments( $user_id ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->comments,
            array(
                'comment_author'       => __( 'Antiguo Colaborador', 'wp-offboard-assistant' ),
                'comment_author_email' => '',
                'comment_author_url'   => ''
            ),
            array( 'user_id' => $user_id ),
            array( '%s', '%s', '%s' ), // Formatos para los datos
            array( '%d' )              // Formato para el WHERE
        );
    }
}
