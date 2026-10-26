// Minimal upload widget for all admin image uploads
// Usage: <div class="admin-upload-widget" data-name="image" data-accept="image/*" data-multiple="true|false"></div>

(function () {
    function createWidget(el) {
        const name = el.dataset.name || 'image';
        const accept = el.dataset.accept || 'image/*';
        const multiple = el.dataset.multiple === 'true';
        const required = el.dataset.required === 'true';
        // Create input
        const input = document.createElement('input');
        input.type = 'file';
        input.name = name;
        input.accept = accept;
        if (multiple) input.multiple = true;
        input.style.display = 'none';
        const form = el.closest('form');
        const removeImage = form && form.querySelector('input[name="remove_image"]');
        // Create visible button
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'admin-upload-btn';
        btn.textContent = multiple ? 'Afbeeldingen kiezen' : 'Afbeelding kiezen';
        // Preview
        const preview = document.createElement('div');
        preview.className = 'admin-upload-preview';
        // Error
        const error = document.createElement('div');
        error.className = 'admin-upload-error';
        error.style.color = '#d32f2f';
        error.style.fontWeight = '600';
        error.style.marginTop = '8px';
        // Converter link
        const converter = document.createElement('a');
        converter.href = '#';
        converter.target = '_blank';
        converter.style.display = 'none';
        converter.style.fontSize = '1.07em';
        converter.style.marginLeft = '0';
        converter.style.marginTop = '10px';
        converter.style.background = '#fffbe6';
        converter.style.color = '#856404';
        converter.style.border = '1.5px solid #ffe58f';
        converter.style.padding = '10px 18px';
        converter.style.borderRadius = '8px';
        converter.style.fontWeight = '600';
        converter.style.textDecoration = 'none';
        converter.style.display = 'none';
        converter.style.boxShadow = '0 2px 8px rgba(255,193,7,0.10)';
        converter.style.transition = 'background 0.2s, box-shadow 0.2s';
        converter.onmouseover = function () { this.style.background = '#fff3cd'; this.style.boxShadow = '0 4px 16px rgba(255,193,7,0.13)'; };
        converter.onmouseout = function () { this.style.background = '#fffbe6'; this.style.boxShadow = '0 2px 8px rgba(255,193,7,0.10)'; };
        converter.innerHTML = '<span style="font-size:1.25em;vertical-align:middle;margin-right:7px;">🖼️</span>Bestand te groot — maak het kleiner.';
        // Insert
        el.appendChild(btn);
        el.appendChild(input);
        el.appendChild(preview);
        el.appendChild(error);
        el.appendChild(converter);
        if (required && form) {
            form.addEventListener('submit', function (event) {
                const existingImage = form.querySelector('input[name="existing_image"]');
                const hasExistingImage = Boolean(existingImage && existingImage.value);
                const isRemovingImage = Boolean(removeImage && removeImage.checked);
                if (input.files.length || (hasExistingImage && !isRemovingImage)) return;

                event.preventDefault();
                error.textContent = 'Een afbeelding is verplicht voor een evenement.';
                btn.focus();
            }, true);
        }
        // Logic
        btn.addEventListener('click', () => input.click());
        input.addEventListener('change', function () {
            error.textContent = '';
            preview.innerHTML = '';
            converter.style.display = 'none';
            if (!input.files.length) return;
            const altInput = form && Array.from(form.elements).find(element => element.name === name.replace(/\[\]$/, '') + '_alt');
            if (altInput) altInput.value = '';
            let oversize = false;
            Array.from(input.files).forEach(file => {
                if (file.size > 10 * 1024 * 1024) oversize = true;
                const reader = new FileReader();
                reader.onload = e => {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.alt = '';
                    img.style.maxWidth = '120px';
                    img.style.maxHeight = '120px';
                    img.style.margin = '6px';
                    img.style.borderRadius = '8px';
                    img.style.border = '1.5px solid #e0f2e9';
                    preview.appendChild(img);
                };
                reader.readAsDataURL(file);
            });
            if (oversize) {
                error.textContent = 'Bestand te groot — maak het kleiner.';
                converter.style.display = 'inline-block';
            }
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.admin-upload-widget').forEach(createWidget);
    });
})();
