function initDxfExport() {
    document.getElementById('export-dxf')?.addEventListener('click', async function (event) {
        const dxfUrl = this.getAttribute('data-dxf-url');
        if (!dxfUrl) return;
        event.preventDefault();
        const originalText = this.textContent;
        this.textContent = 'Pripremam…';
        this.style.pointerEvents = 'none';
        try {
            const backgroundLayers = window.ftthDxfLayer ? await window.ftthDxfLayer.getLayersForExport() : [];
            const command = document.getElementById('cad-command');
            if (command && backgroundLayers.length > 0) command.textContent = `Export: ${backgroundLayers.length} DXF podlog(a) uključeno...`;
            const response = await fetch(dxfUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    Accept: 'application/octet-stream,application/dxf,*/*',
                },
                body: JSON.stringify({ background_layers: backgroundLayers }),
            });
            if (!response.ok) {
                let message = `HTTP ${response.status}`;
                try {
                    const error = await response.json();
                    if (error.error) message = error.error;
                } catch {}
                throw new Error(message);
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            const disposition = response.headers.get('Content-Disposition') ?? '';
            anchor.download = disposition.match(/filename[^;=\n]*=["']?([^"'\n]+)/i)?.[1] ?? 'export.dxf';
            anchor.href = url;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            URL.revokeObjectURL(url);
        } catch (error) {
            alert(`Greška pri DXF exportu: ${error.message}`);
        } finally {
            this.textContent = originalText;
            this.style.pointerEvents = '';
        }
    });
}
