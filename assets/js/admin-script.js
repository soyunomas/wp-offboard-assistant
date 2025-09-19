/**
 * Lógica de JavaScript para el Asistente de Offboarding de WP.
 *
 * Se encarga de:
 * - Abrir y cerrar el modal.
 * - Realizar la llamada AJAX para obtener los datos del usuario.
 * - Construir dinámicamente los pasos del asistente.
 * - Realizar la llamada AJAX para ejecutar el proceso de offboarding.
 * - Mostrar feedback al usuario.
 *
 * @package           WP_Offboard_Assistant
 * @author            soyunomas
 * @since             1.0.0
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // Variables para los elementos del DOM
        const modal = $('#wpoa-modal');
        const spinner = $('#wpoa-spinner');
        const stepsContainer = $('#wpoa-steps-container');
        const userLoginSpan = $('#wpoa-user-login');

        /**
         * Maneja el clic en el enlace "Iniciar Offboarding".
         */
        $('.wrap').on('click', 'a.wpoa-start-offboard', function(e) {
            e.preventDefault();

            const userId = $(this).data('user-id');
            const userLogin = $(this).data('user-login');

            // Guardar el ID de usuario en el modal para referencia futura
            modal.data('userId', userId);

            // Preparar y mostrar el modal
            userLoginSpan.text(userLogin);
            stepsContainer.hide().empty();
            spinner.show();
            modal.removeClass('wpoa-modal-hidden');

            // Obtener los datos del usuario vía AJAX
            $.ajax({
                url: wpoa_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpoa_get_user_data',
                    user_id: userId,
                    nonce: wpoa_ajax_object.nonce
                },
                success: function(response) {
                    if (response.success) {
                        buildModalSteps(response.data);
                    } else {
                        handleAjaxError(response.data.message);
                    }
                },
                error: function() {
                    handleAjaxError('Error de comunicación con el servidor.');
                },
                complete: function() {
                    spinner.hide();
                }
            });
        });

        /**
         * Construye el HTML para los pasos del modal basado en los datos del usuario.
         * @param {object} data - Los datos recibidos del AJAX (content_count, admins).
         */
        function buildModalSteps(data) {
            let stepsHtml = '';

            // --- PASO 1: Reasignación de contenido (si lo hay) ---
            if (data.content_count > 0) {
                let adminOptions = '<option value="0">' + 'No reasignar contenido' + '</option>';
                if (data.admins.length > 0) {
                    $.each(data.admins, function(index, admin) {
                        // El backend ya formatea el texto como "username (Role)"
                        adminOptions += `<option value="${admin.id}">${admin.login}</option>`;
                    });
                }

                stepsHtml += `
                    <div class="wpoa-step">
                        <h4>Paso 1: Reasignar Contenido</h4>
                        <p>Este usuario tiene <strong>${data.content_count}</strong> entrada(s). ¿A quién quieres reasignar su contenido?</p>
                        <select id="wpoa-reassign-user" style="width: 100%;">
                            ${adminOptions}
                        </select>
                    </div>`;
            }

            // --- PASO 2: Medidas de Seguridad Adicionales ---
            // --- ESTA ES LA NUEVA SECCIÓN PARA EL CHECKBOX DE EMAIL ---
            stepsHtml += `
                <div class="wpoa-step">
                    <h4>Paso 2: Medidas de Seguridad Adicionales</h4>
                    <p>Puedes tomar acciones para asegurar que la cuenta no pueda ser recuperada.</p>
                    <label>
                        <input type="checkbox" id="wpoa-anonymize-email" value="1">
                        <strong>Anonimizar correo electrónico:</strong> Cambia el email a uno ficticio (ej: <code>${userLoginSpan.text()}@deleted.local</code>) para impedir la recuperación de contraseña.
                    </label>
                </div>`;


            // --- PASO 3: Acción Final ---
            stepsHtml += `
                <div class="wpoa-step">
                    <h4>Paso 3: Acción Final sobre la Cuenta</h4>
                    <p>Selecciona qué hacer con la cuenta de usuario. Esta acción es irreversible.</p>
                    <label>
                        <input type="radio" name="wpoa-final-action" value="degrade" checked>
                        <strong>Degradar cuenta:</strong> Cambia el rol a "Suscriptor" y genera una contraseña nueva y aleatoria.
                    </label>
                    <br>
                    <label>
                        <input type="radio" name="wpoa-final-action" value="delete">
                        <strong style="color: #d9534f;">Eliminar cuenta permanentemente:</strong> Esta acción borrará al usuario de la base de datos.
                    </label>
                </div>`;


            // --- PASO 4: Confirmación ---
            stepsHtml += `
                <div class="wpoa-step wpoa-confirmation">
                    <h4>Paso 4: Confirmación Final</h4>
                    <p>Para confirmar todas las acciones seleccionadas, por favor escribe <strong>OFFBOARD</strong> en el campo de abajo.</p>
                    <input type="text" id="wpoa-confirmation-text" placeholder="OFFBOARD" style="width: 100%;">
                </div>`;


            // --- Botón de Ejecución y área de feedback ---
            stepsHtml += `
                <div style="text-align: right; margin-top: 20px;">
                    <div id="wpoa-feedback" style="text-align: left; margin-bottom: 10px;"></div>
                    <button id="wpoa-execute-offboard" class="button button-primary button-large">Ejecutar Offboarding</button>
                </div>`;

            stepsContainer.html(stepsHtml).show();
        }

        /**
         * Maneja el clic en el botón "Ejecutar Offboarding".
         */
        modal.on('click', '#wpoa-execute-offboard', function(e) {
            e.preventDefault();
            const executeButton = $(this);
            const feedbackDiv = $('#wpoa-feedback');

            // Recoger todos los datos del formulario
            const userId = modal.data('userId');
            const reassignTo = $('#wpoa-reassign-user').length ? $('#wpoa-reassign-user').val() : 0;
            const actionType = $('input[name="wpoa-final-action"]:checked').val();
            const confirmationText = $('#wpoa-confirmation-text').val();

            // --- RECOGER EL ESTADO DEL NUEVO CHECKBOX ---
            const anonymizeEmail = $('#wpoa-anonymize-email').is(':checked');

            // Validación del lado del cliente
            if (confirmationText.toUpperCase() !== 'OFFBOARD') {
                feedbackDiv.text('Debes escribir "OFFBOARD" para confirmar.').addClass('error').removeClass('success');
                return;
            }

            // Deshabilitar botón y mostrar spinner visualmente
            executeButton.prop('disabled', true).text('Procesando...');
            feedbackDiv.empty().removeClass('error success');

            // Ejecutar el offboarding vía AJAX
            $.ajax({
                url: wpoa_ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpoa_execute_offboarding',
                    user_id: userId,
                    reassign_to: reassignTo,
                    action_type: actionType,
                    confirmation_text: confirmationText,
                    anonymize_email: anonymizeEmail, // --- ENVIAR EL NUEVO DATO AL BACKEND ---
                    nonce: wpoa_ajax_object.nonce
                },
                success: function(response) {
                    if (response.success) {
                        stepsContainer.html(`<p class="success" style="color: #5cb85c; font-size: 1.2em;">${response.data.message}</p>`);
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        handleAjaxError(response.data.message);
                        executeButton.prop('disabled', false).text('Ejecutar Offboarding');
                    }
                },
                error: function() {
                    handleAjaxError('Error de comunicación con el servidor.');
                    executeButton.prop('disabled', false).text('Ejecutar Offboarding');
                }
            });
        });

        /**
         * Cierra el modal.
         */
        function closeModal() {
            modal.addClass('wpoa-modal-hidden');
            // Limpiar el contenido para la próxima vez
            stepsContainer.empty();
            userLoginSpan.empty();
            modal.removeData('userId');
        }

        // Eventos para cerrar el modal
        modal.on('click', '.wpoa-close-button', closeModal);
        $(window).on('click', function(e) {
            if (e.target == modal[0]) {
                closeModal();
            }
        });

        /**
         * Muestra un mensaje de error en el área de feedback.
         * @param {string} message - El mensaje de error a mostrar.
         */
        function handleAjaxError(message) {
            const feedbackDiv = $('#wpoa-feedback');
            if (feedbackDiv.length) {
                feedbackDiv.text(message).addClass('error').removeClass('success');
            } else {
                // Si el feedbackDiv no existe aún, mostrarlo en el contenedor principal
                stepsContainer.html(`<p class="error" style="color: #d9534f;">${message}</p>`);
            }
        }
    });

})(jQuery);
