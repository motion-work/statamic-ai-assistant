<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Upload an image for Claude') }}</title>
    <style>
        body { margin: 0; font: 16px/1.45 system-ui, sans-serif; background: #f4f4f5; color: #18181b; }
        main { max-width: 26rem; margin: 8vh auto; padding: 1.5rem; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        p { margin: 0 0 1rem; color: #3f3f46; }
        .drop { display: block; padding: 2rem 1rem; margin-bottom: 1rem; border: 2px dashed #d4d4d8; border-radius: 10px; text-align: center; cursor: pointer; color: #52525b; }
        .drop.over { border-color: #18181b; background: #fafafa; }
        .drop img { display: block; max-width: 100%; max-height: 14rem; margin: 0 auto 0.5rem; border-radius: 6px; }
        .drop input { display: none; }
        button { width: 100%; padding: 0.65rem; border: 0; border-radius: 8px; background: #18181b; color: #fff; font: inherit; }
        button:disabled { opacity: 0.5; }
        .hint { font-size: 0.85rem; color: #71717a; }
        .error { color: #b91c1c; }
        .done { color: #15803d; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('Upload an image for Claude') }}</h1>
    @if ($record === null)
        <p class="error">{{ __('This upload link is unknown or has expired. Ask Claude for a new one.') }}</p>
    @elseif ($record['path'] !== null)
        <p class="done">{{ __('Uploaded as :path. You can go back to Claude and say it is done.', ['path' => $record['path']]) }}</p>
    @else
        <p>{{ __('The image goes into :folder in the :container assets of :site. Claude then shows you where it will be placed before anything on a page changes.', ['folder' => $record['folder'] !== '' ? $record['folder'] : '/', 'container' => $record['container'], 'site' => $site]) }}</p>
        <form id="upload">
            <label class="drop" id="drop">
                <img id="preview" alt="" hidden>
                <span id="label">{{ __('Drop the image here or choose a file.') }}</span>
                <input type="file" name="file" id="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,image/svg+xml">
            </label>
            <p class="hint">{{ __('One image, up to :size MB (JPG, PNG, GIF, WebP, AVIF or SVG).', ['size' => $maxMegabytes]) }}</p>
            <p id="status" role="status"></p>
            <button type="submit" id="submit" disabled>{{ __('Upload') }}</button>
        </form>
        <script>
            (() => {
                const form = document.getElementById('upload');
                const drop = document.getElementById('drop');
                const input = document.getElementById('file');
                const preview = document.getElementById('preview');
                const label = document.getElementById('label');
                const status = document.getElementById('status');
                const submit = document.getElementById('submit');
                let file = null;

                const pick = (picked) => {
                    if (!picked) return;
                    file = picked;
                    label.textContent = picked.name;
                    preview.src = URL.createObjectURL(picked);
                    preview.hidden = false;
                    submit.disabled = false;
                    status.textContent = '';
                };

                input.addEventListener('change', () => pick(input.files[0]));
                ['dragenter', 'dragover'].forEach((type) => drop.addEventListener(type, (e) => { e.preventDefault(); drop.classList.add('over'); }));
                ['dragleave', 'drop'].forEach((type) => drop.addEventListener(type, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
                drop.addEventListener('drop', (e) => pick(e.dataTransfer.files[0]));

                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    if (!file) return;
                    submit.disabled = true;
                    status.className = '';
                    status.textContent = @json(__('Uploading…'));
                    const body = new FormData();
                    body.append('file', file);
                    try {
                        const response = await fetch(location.pathname, { method: 'POST', body, headers: { Accept: 'application/json' } });
                        const result = await response.json();
                        if (!response.ok) throw new Error(result.message || response.statusText);
                        form.querySelectorAll('.drop, .hint').forEach((el) => el.remove());
                        submit.remove();
                        status.className = 'done';
                        status.textContent = @json(__('Uploaded as :path. You can go back to Claude and say it is done.')).replace(':path', result.path);
                    } catch (error) {
                        status.className = 'error';
                        status.textContent = error.message;
                        submit.disabled = false;
                    }
                });
            })();
        </script>
    @endif
</main>
</body>
</html>
