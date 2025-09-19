/**
 * Lógica del frontend para el asistente de Offboarding de WP.
 * Versión 1.1.0: Introduce un flujo de varios pasos y lógica condicional.
 *
 * @package           WP_Offboard_Assistant
 * @author            soyunomas
 * @since             1.0.0
 */
jQuery(function($) {
    'use strict';

    // Variables para los elementos del DOM.
    var $modal = $('#wpoa-modal');
    var $stepsContainer = $('#wpoa-steps-container');
    var $spinner = $('#wpoa-spinner');
    var $userLogin = $('#wpoa-user-login');

    var currentUserId = 0;
    var currentUserLogin = '';

    /**
     * Abre el modal e inicia la carga de datos del usuario.
     */
    $(document).on('click', '.wpoa-start-offboard', function(e) {
        e.preventDefault();

        currentUserId = $(this).data('user-id');
        currentUserLogin = $(this).data('user-login');

        // Resetear y mostrar el modal
        $userLogin.text(currentUserLogin);
        $stepsContainer.hide().empty();
        $spinner.show();
        $modal.removeClass('wpoa-modal-hidden');

        // Obtener los datos del usuario vía AJAX
        $.post(wpoa_ajax_object.ajax_url, {
            action: 'wpoa_get_user_data',
            nonce: wpoa_ajax_object.nonce,
            user_id: currentUserId
        })
        .done(function(response) {
            if (response.success) {
                renderInitialSteps(response.data);
            } else {
                handleError('No se pudieron cargar los datos del usuario.');
            }
        })
        .fail(function() {
            handleError('Error de comunicación con el servidor.');
        })
        .always(function() {
            $spinner.hide();
            $stepsContainer.show();
        });
    });

    /**
     * Renderiza el contenido HTML inicial del modal con todos los pasos.
     * @param {object} data - Datos del usuario (content_count, admins).
     */
    function renderInitialSteps(data) {
        var adminOptionsHtml = data.admins.map(function(admin) {
            return `<option value="${admin.id}">${admin.login}</option>`;
        }).join('');

        var html = `
            <!-- PASO 1: Tipo de Offboarding -->
            <div class="wpoa-step">
                <h4>Paso 1: Elige el tipo de Offboarding</h4>
                <label><input type="radio" name="wpoa-offboard-type" value="degrade" checked> <strong>Degradar Cuenta:</strong> Revoca privilegios pero mantiene al usuario.</label><br>
                <label><input type="radio" name="wpoa-offboard-type" value="archive"> <strong>Archivar Cuenta:</strong> Anonimiza al usuario y sus datos, preservando el contenido.</label><br>
                <label><input type="radio" name="wpoa-offboard-type" value="delete"> <strong style="color: #d9534f;">Eliminar Cuenta Permanentemente</strong></label>
            </div>

            <!-- PASO 2: Opciones Condicionales -->
            <div class="wpoa-step">
                <h4>Paso 2: Configura las opciones</h4>
                
                <!-- Opciones para ARCHIVAR -->
                <div id="wpoa-archive-options" style="display:none;">
                    <p>La cuenta será neutralizada y sus datos personales anonimizados. Adicionalmente puedes:</p>
                    <label><input type="checkbox" id="wpoa-anonymize-comments-chk" checked> <strong>Anonimizar todos los comentarios públicos:</strong> Cambia el nombre y email en sus comentarios por "Antiguo Colaborador".</label><br>
                    <label><input type="checkbox" id="wpoa-export-data-chk"> <strong>Enviar solicitud de exportación de datos:</strong> Se enviará un email al usuario (antes de ser anonimizado) para que confirme la exportación de sus datos personales (Cumplimiento GDPR).</label>
                </div>

                <!-- Opciones para REASIGNAR CONTENIDO (Común a Degradar, Archivar y Eliminar) -->
                ${ data.content_count > 0 ? `
                <div id="wpoa-reassign-options">
                    <p>Este usuario tiene <strong>${data.content_count}</strong> pieza(s) de contenido.</p>
                    <label><input type="checkbox" id="wpoa-reassign-content-chk"> Reasignar su contenido a otro usuario:</label>
                    <div id="wpoa-reassign-container" style="display:none;">
                        <select id="wpoa-reassign-to-user">
                            ${adminOptionsHtml}
                        </select>
                    </div>
                </div>
                ` : '<p>Este usuario no tiene contenido para reasignar.</p>' }
            </div>

            <!-- PASO 3: Confirmación -->
            <div class="wpoa-step wpoa-confirmation">
                <h4>Paso 3: Confirmación Final</h4>
                <p>Esta acción es irreversible. Por favor, escribe <strong>OFFBOARD</strong> para confirmar.</p>
                <input type="text" id="wpoa-confirmation-text" placeholder="OFFBOARD">
            </div>
            
            <div id="wpoa-feedback"></div>

            <button id="wpoa-execute-btn" class="button button-primary">Ejecutar Offboarding</button>
        `;
        $stepsContainer.html(html);
    }
    
    /**
     * Maneja la visibilidad de las opciones condicionales.
     */
    $(document).on('change', 'input[name="wpoa-offboard-type"]', function() {
        var selectedType = $(this).val();
        $('#wpoa-archive-options').toggle(selectedType === 'archive');
    });

    $(document).on('change', '#wpoa-reassign-content-chk', function() {
        $('#wpoa-reassign-container').toggle($(this).is(':checked'));
    });

    /**
     * Maneja la ejecución del proceso de offboarding.
     */
    $(document).on('click', '#wpoa-execute-btn', function() {
        var $btn = $(this);
        var $feedback = $('#wpoa-feedback');
        
        // Recopilar todos los datos del formulario
        var data = {
            action: 'wpoa_execute_offboarding',
            nonce: wpoa_ajax_object.nonce,
            user_id: currentUserId,
            action_type: $('input[name="wpoa-offboard-type"]:checked').val(),
            confirmation_text: $('#wpoa-confirmation-text').val(),
            reassign_to: $('#wpoa-reassign-content-chk').is(':checked') ? $('#wpoa-reassign-to-user').val() : 0,
            anonymize_comments: $('#wpoa-anonymize-comments-chk').is(':checked'),
            export_data: $('#wpoa-export-data-chk').is(':checked')
        };
        
        $btn.prop('disabled', true);
        $feedback.removeClass('error success').text('Procesando...');

        $.post(wpoa_ajax_object.ajax_url, data)
        .done(function(response) {
            if (response.success) {
                $feedback.addClass('success').text(response.data.message);
                setTimeout(function() {
                    closeModal();
                    location.reload(); // Recargar la página para ver los cambios
                }, 2000);
            } else {
                handleError(response.data.message);
                $btn.prop('disabled', false);
            }
        })
        .fail(function() {
            handleError('Error de comunicación con el servidor.');
            $btn.prop('disabled', false);
        });
    });

    /**
     * Cierra el modal y resetea su estado.
     */
    function closeModal() {
        $modal.addClass('wpoa-modal-hidden');
        $stepsContainer.empty();
        currentUserId = 0;
        currentUserLogin = '';
    }
    $(document).on('click', '.wpoa-close-button', closeModal);
    $(document).on('click', function(e) {
        if ($(e.target).is($modal)) {
            closeModal();
        }
    });

    /**
     * Muestra un mensaje de error en el modal.
     * @param {string} message - El mensaje de error a mostrar.
     */
    function handleError(message) {
        $('#wpoa-feedback').removeClass('success').addClass('error').text(message || 'Ha ocurrido un error inesperado.');
    }
});
