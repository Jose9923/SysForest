/* Preparación y progreso de archivos; almacenamiento y autorización se validan en el servidor. */
(function () {
    'use strict';

    document.querySelectorAll('[data-evidence-image]').forEach((image) => {
        const unavailable = () => {
            image.hidden = true;
            const message = image.parentElement.querySelector('.evidence-image-unavailable');
            if (message) message.hidden = false;
        };
        image.addEventListener('error', unavailable);
        if (image.complete && image.naturalWidth === 0) unavailable();
    });

    document.querySelectorAll('[data-evidence-upload]').forEach((form) => {
        if (!window.FormData || !window.XMLHttpRequest || !window.URL?.createObjectURL) return;
        const picker = form.querySelector('[data-evidence-files]');
        const camera = form.querySelector('[data-camera-input]');
        const cameraButton = form.querySelector('[data-camera-button]');
        const queue = form.querySelector('[data-upload-queue]');
        const queueSection = form.querySelector('[data-upload-queue-section]');
        const count = form.querySelector('[data-upload-count]');
        const template = form.querySelector('[data-upload-template]');
        const feedback = form.querySelector('[data-upload-errors]');
        const submit = form.querySelector('[data-upload-submit]');
        const progressPanel = form.querySelector('[data-upload-progress-panel]');
        const progress = form.querySelector('[data-upload-progress]');
        const progressText = form.querySelector('[data-upload-progress-text]');
        const maxFiles = Number(form.dataset.maxFiles);
        const maxSizeMb = Number(form.dataset.maxSizeMb);
        const maxSizeBytes = maxSizeMb * 1024 * 1024;
        const allowedMimes = form.dataset.allowedMimes.split(',');
        let files = [];
        let serial = 0;
        let uploading = false;
        let request = null;

        const globalError = (message) => {
            feedback.textContent = message;
            feedback.hidden = !message;
        };
        const summary = () => {
            queueSection.hidden = files.length === 0;
            count.textContent = '(' + files.length + ')';
            submit.disabled = uploading || files.length === 0;
        };
        const rowErrors = (entry, messages) => {
            const list = entry.card.querySelector('[data-upload-file-errors]');
            list.replaceChildren();
            messages.forEach((message) => {
                const item = document.createElement('li');
                item.textContent = message;
                list.appendChild(item);
            });
            list.hidden = messages.length === 0;
            entry.card.classList.toggle('has-error', messages.length > 0);
        };
        const status = (entry, text) => {
            entry.card.querySelector('[data-upload-file-status]').textContent = text;
        };
        const release = (entry) => {
            if (entry.previewUrl) URL.revokeObjectURL(entry.previewUrl);
            const select = entry.card.querySelector('[data-upload-field="evidence_type_id"]');
            if (window.jQuery?.fn?.select2 && window.jQuery(select).hasClass('select2-hidden-accessible')) {
                window.jQuery(select).select2('destroy');
            }
        };
        const addFiles = (selected) => {
            if (uploading) return;
            globalError('');
            let excess = 0;
            Array.from(selected).forEach((file) => {
                if (files.length >= maxFiles) {
                    excess++;
                    return;
                }
                const card = template.content.firstElementChild.cloneNode(true);
                const entry = {file, card, previewUrl: null, clientError: null};
                serial++;
                card.dataset.uploadEntry = String(serial);
                card.querySelector('[data-upload-filename]').textContent = file.name;
                card.querySelector('[data-upload-filesize]').textContent = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
                card.querySelectorAll('[data-upload-field]').forEach((field) => {
                    field.id = 'evidence-file-' + serial + '-' + field.dataset.uploadField;
                    card.querySelector('[data-upload-label="' + field.dataset.uploadField + '"]').htmlFor = field.id;
                });
                const preview = card.querySelector('[data-upload-preview]');
                const previewError = card.querySelector('[data-upload-preview-error]');
                preview.alt = 'Previsualización: ' + file.name;
                preview.addEventListener('error', () => {
                    preview.hidden = true;
                    previewError.hidden = false;
                });
                if (file.size > maxSizeBytes) {
                    entry.clientError = 'La imagen supera el tamaño máximo permitido de ' + maxSizeMb + ' MB.';
                }
                if (!entry.clientError && allowedMimes.includes(file.type)) {
                    entry.previewUrl = URL.createObjectURL(file);
                    preview.src = entry.previewUrl;
                } else {
                    preview.hidden = true;
                    previewError.hidden = false;
                    previewError.textContent = entry.clientError ? 'Archivo demasiado grande para esta carga.' : 'La previsualización no está disponible. El servidor comprobará el formato.';
                }
                card.querySelector('[data-upload-remove]').addEventListener('click', () => {
                    if (uploading) return;
                    release(entry);
                    card.remove();
                    files = files.filter((candidate) => candidate !== entry);
                    summary();
                    if (files.length === 0) picker.focus();
                });
                queue.appendChild(card);
                files.push(entry);
                const type = card.querySelector('[data-upload-field="evidence_type_id"]');
                if (window.jQuery?.fn?.select2) {
                    window.jQuery(type).select2({width: '100%', language: 'es', minimumResultsForSearch: 0});
                }
                if (entry.clientError) {
                    rowErrors(entry, [entry.clientError]);
                    status(entry, 'Requiere corrección');
                }
            });
            if (excess > 0) globalError('Se permiten hasta ' + maxFiles + ' imágenes por carga. No se añadieron ' + excess + ' archivos que superaban este límite.');
            summary();
        };
        const busy = (value) => {
            uploading = value;
            form.setAttribute('aria-busy', value ? 'true' : 'false');
            form.querySelectorAll('input:not([type="hidden"]), textarea, select, button').forEach((control) => {
                control.disabled = value;
            });
            summary();
        };
        const completeFailure = (message) => {
            busy(false);
            globalError(message);
            progressText.textContent = message;
            files.forEach((entry) => status(entry, 'Carga no confirmada'));
        };
        const upload = () => {
            globalError('');
            if (files.length === 0) {
                globalError('Selecciona al menos una fotografía.');
                return;
            }
            let invalid = false;
            files.forEach((entry) => {
                const errors = entry.clientError ? [entry.clientError] : [];
                const type = entry.card.querySelector('[data-upload-field="evidence_type_id"]');
                if (!type.value) errors.push('El tipo de evidencia es obligatorio.');
                entry.card.querySelectorAll('[data-upload-field]').forEach((field) => {
                    if (field.dataset.uploadField !== 'evidence_type_id' && !field.validity.valid) {
                        errors.push('Revisa el valor de ' + entry.card.querySelector('[data-upload-label="' + field.dataset.uploadField + '"]').textContent + '.');
                    }
                });
                rowErrors(entry, errors);
                status(entry, errors.length ? 'Requiere corrección' : 'Lista para subir');
                invalid = invalid || errors.length > 0;
            });
            if (invalid) {
                globalError('Revisa los archivos señalados antes de subir las fotografías.');
                return;
            }
            const payload = new FormData();
            const token = form.querySelector('input[name="_token"]').value;
            payload.append('_token', token);
            files.forEach((entry, index) => {
                payload.append('items[' + index + '][file]', entry.file, entry.file.name);
                entry.card.querySelectorAll('[data-upload-field]').forEach((field) => {
                    payload.append('items[' + index + '][' + field.dataset.uploadField + ']', field.value);
                });
                status(entry, 'Preparada para envío');
            });
            busy(true);
            progressPanel.hidden = false;
            progress.value = 0;
            progressText.textContent = 'Enviando fotografías…';
            request = new XMLHttpRequest();
            request.open('POST', form.action);
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-CSRF-TOKEN', token);
            request.timeout = 180000;
            request.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    const percent = Math.round((event.loaded / event.total) * 100);
                    progress.value = percent;
                    progressText.textContent = percent < 100 ? 'Enviando fotografías: ' + percent + ' %' : 'Archivos enviados. Validando y guardando…';
                } else {
                    progress.removeAttribute('value');
                    progressText.textContent = 'Enviando fotografías…';
                }
            });
            request.addEventListener('load', () => {
                let result = {};
                try { result = JSON.parse(request.responseText); } catch (_) { /* Puede recibirse un error del servidor web sin JSON. */ }
                if (request.status === 201) {
                    progress.value = 100;
                    progressText.textContent = result.message || 'Fotografías guardadas.';
                    files.forEach((entry) => status(entry, 'Guardada'));
                    let destination = null;
                    try {
                        const url = new URL(result.redirect, window.location.href);
                        if (typeof result.redirect === 'string' && result.redirect !== '' && url.origin === window.location.origin) destination = url.href;
                    } catch (_) { /* Solo se navega a una ruta de la aplicación. */ }
                    if (destination) {
                        files.forEach(release);
                        files = [];
                        window.location.assign(destination);
                    } else {
                        files.forEach(release);
                        files = [];
                        busy(false);
                        globalError('Las fotografías fueron guardadas. Vuelve al registro para consultarlas.');
                    }
                    return;
                }
                busy(false);
                if (request.status === 422) {
                    const global = [];
                    const byIndex = files.map(() => []);
                    Object.entries(result.errors || {}).forEach(([field, messages]) => {
                        const values = (Array.isArray(messages) ? messages : [messages]).filter((message) => typeof message === 'string');
                        const match = /^items\.(\d+)(?:\.|$)/.exec(field);
                        if (match && byIndex[Number(match[1])]) byIndex[Number(match[1])].push(...values);
                        else global.push(...values);
                    });
                    files.forEach((entry, index) => {
                        rowErrors(entry, byIndex[index]);
                        status(entry, byIndex[index].length ? 'Requiere corrección' : 'Pendiente; no se guardó el lote');
                    });
                    globalError(global.join('\n') || 'No se guardaron las fotografías. Revisa los errores de cada archivo y vuelve a enviar.');
                    progressText.textContent = 'La validación del lote requiere correcciones.';
                    return;
                }
                const message = request.status === 401 || request.status === 419
                    ? 'Tu sesión debe renovarse. Las fotografías siguen seleccionadas; vuelve a ingresar antes de continuar.'
                    : request.status === 403
                        ? 'No tienes permiso para adjuntar evidencias a este registro.'
                        : request.status === 413
                            ? 'La carga supera el tamaño máximo permitido. Reduce el número o tamaño de las imágenes.'
                            : 'No se pudo confirmar la carga. Consulta el registro antes de reenviar las fotografías.';
                completeFailure(message);
            });
            request.addEventListener('error', () => completeFailure('Se interrumpió la conexión. No se pudo confirmar la carga; consulta el registro antes de reenviar.'));
            request.addEventListener('timeout', () => completeFailure('La carga tardó más de lo esperado. Consulta el registro antes de reenviar las fotografías.'));
            request.send(payload);
        };

        const fallback = form.querySelector('[data-upload-fallback]');
        if (window.jQuery?.fn?.select2) {
            window.jQuery(fallback).find('.select2-hidden-accessible').select2('destroy');
        }
        fallback.remove();
        picker.multiple = true;
        picker.required = false;
        picker.removeAttribute('name');
        form.noValidate = true;
        cameraButton.hidden = false;
        picker.addEventListener('change', () => { addFiles(picker.files); picker.value = ''; });
        cameraButton.addEventListener('click', () => camera.click());
        camera.addEventListener('change', () => { addFiles(camera.files); camera.value = ''; });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!uploading) upload();
        });
        window.addEventListener('pagehide', (event) => { if (!event.persisted) files.forEach(release); });
        summary();
    });
})();
