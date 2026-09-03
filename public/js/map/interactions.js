// Global CAD keyboard shortcuts. Kept separate from map boot so commands can
// evolve without turning init.js back into a controller for every tool.
function initMapKeyboardInteractions() {
    initCadCommandLine();
    document.addEventListener('keydown', event => {
        const target = event.target;
        const tag = target?.tagName?.toLowerCase();

        if (event.key === 'F2') {
            event.preventDefault();
            document.getElementById('cad-command-input')?.focus();
            return;
        }
        if (event.key === 'F3') {
            event.preventDefault();
            toggleCadSnap();
            return;
        }
        if (event.key === 'F7') {
            event.preventDefault();
            toggleCadGrid();
            return;
        }
        if (event.key === 'F8') {
            event.preventDefault();
            toggleCadOrtho();
            return;
        }

        if (event.key === '/' && !['input', 'select', 'textarea'].includes(tag) && !target?.isContentEditable) {
            event.preventDefault();
            document.getElementById('cad-command-input')?.focus();
            return;
        }

        if (event.code === 'Space' && !event.repeat && !['input', 'select', 'textarea', 'button', 'a'].includes(tag) && !target?.isContentEditable) {
            event.preventDefault();
            temporaryPanActive = true;
            map.dragging.enable();
            document.getElementById('map-container')?.classList.add('cad-temporary-pan');
            if (cadDynamicInput) {
                cadDynamicInput.innerHTML = '<strong>PAN</strong> · pusti SPACE za nastavak';
                cadDynamicInput.classList.add('is-visible');
            }
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            if (routeEdit) cancelRouteEdit();
            if (selectedAttributeRoute || highlightedPickedRouteId !== null) closeRouteAttributePanel();
            if (selectedDraftElement) closeDraftElementEditor();
            if (joinRoutes.length) resetJoinRoutes();
            window.clearBulkMapSelection?.();
            map.closePopup();
            routeStackPick.signature = '';
            routeStackPick.index = 0;
            if (mode === 'draw') cancelActiveDrawing();
            if (mode === 'ruler') clearRuler();
            if (mode === 'trace-branch') {
                traceBranchStart = null;
                traceBranchStartSnap = null;
                if (traceBranchPreviewLine) { map.removeLayer(traceBranchPreviewLine); traceBranchPreviewLine = null; }
            }
            setMode('pan');
            return;
        }

        if (['input', 'select', 'textarea'].includes(tag) || target?.isContentEditable) return;
        if (event.key === 'Enter' && mode === 'draw') { event.preventDefault(); finishBranch(); return; }
        if (event.key === 'Enter' && mode === 'join') { event.preventDefault(); joinSelectedRoutes(); return; }
        if (event.key === 'Backspace' && mode === 'draw') { event.preventDefault(); undoDraw(); return; }

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            if (routeEdit) undoRouteEdit();
            else if (mode === 'draw' && undoStack.length > 0) undoLast();
            else mapHistory.undo();
            return;
        }
        if ((event.ctrlKey || event.metaKey) && (event.key.toLowerCase() === 'y' || (event.shiftKey && event.key.toLowerCase() === 'z'))) {
            event.preventDefault();
            if (routeEdit) redoRouteEdit();
            else if (mode === 'draw' && redoStack.length > 0) redoLast();
            else mapHistory.redo();
            return;
        }
        if (!event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'o') {
            event.preventDefault(); toggleCadOrtho();
        }
        if (!event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'r') {
            event.preventDefault();
            osmRoutingEnabled = !osmRoutingEnabled;
            const checkbox = document.getElementById('osm-routing-toggle');
            if (checkbox) checkbox.checked = osmRoutingEnabled;
            updateCommandBar();
        }
        if (!event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'g') {
            event.preventDefault();
            gisRoutingEnabled = !gisRoutingEnabled;
            const checkbox = document.getElementById('gis-routing-toggle');
            if (checkbox) checkbox.checked = gisRoutingEnabled;
            updateCommandBar();
        }
    });

    document.addEventListener('keyup', event => {
        if (event.code !== 'Space' || !temporaryPanActive) return;
        event.preventDefault();
        temporaryPanActive = false;
        document.getElementById('map-container')?.classList.remove('cad-temporary-pan');
        cadDynamicInput?.classList.remove('is-visible');
        if (mode === 'select') map.dragging.disable();
    });

    window.addEventListener('blur', () => {
        if (!temporaryPanActive) return;
        temporaryPanActive = false;
        document.getElementById('map-container')?.classList.remove('cad-temporary-pan');
        cadDynamicInput?.classList.remove('is-visible');
        if (mode === 'select') map.dragging.disable();
    });
}

function syncCadStatusToggles() {
    const states = { snap: snapEnabled, grid: gridEnabled, ortho: orthoEnabled };
    Object.entries(states).forEach(([name, enabled]) => {
        const button = document.querySelector(`[data-cad-toggle="${name}"]`);
        if (!button) return;
        button.classList.toggle('is-on', enabled);
        button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        const state = button.querySelector('b');
        if (state) state.textContent = enabled ? 'ON' : 'OFF';
    });
    document.getElementById('map-container')?.classList.toggle('cad-grid-visible', gridEnabled);
}

function toggleCadSnap() {
    snapEnabled = !snapEnabled;
    if (!snapEnabled) hideSnapIndicator();
    syncCadStatusToggles();
    updateCommandBar();
    document.getElementById('cad-command').textContent = `OSNAP: ${snapEnabled ? 'ON' : 'OFF'}`;
}

function toggleCadGrid() {
    gridEnabled = !gridEnabled;
    syncCadStatusToggles();
    document.getElementById('cad-command').textContent = `GRID: ${gridEnabled ? 'ON' : 'OFF'}`;
}

function toggleCadOrtho() {
    orthoEnabled = !orthoEnabled;
    syncCadStatusToggles();
    updateCommandBar();
    document.getElementById('cad-command').textContent = `ORTHO: ${orthoEnabled ? 'ON' : 'OFF'}`;
}

function initCadCommandLine() {
    const input = document.getElementById('cad-command-input');
    if (!input) return;
    const history = [];
    let historyIndex = 0;
    const activate = tool => document.getElementById(`mode-${tool}`)?.click();
    const execute = rawCommand => {
        const command = String(rawCommand || '').trim().toUpperCase().replace(/\s+/g, ' ');
        if (!command) return;
        history.push(command);
        historyIndex = history.length;
        const modes = {
            L: 'draw', LINE: 'draw', PL: 'draw', POLYLINE: 'draw',
            P: 'pan', PAN: 'pan',
            SE: 'select', SELECT: 'select',
            E: 'select', ERASE: 'select', DELETE: 'select',
            DI: 'ruler', DIST: 'ruler', MEASURE: 'ruler',
            ODF: 'odf', FTTH: 'cabinet', CABINET: 'cabinet',
            H: 'house', HOUSE: 'house',
            KB: 'trace-branch', BRANCH: 'trace-branch',
        };
        if (modes[command]) {
            activate(modes[command]);
            if (['E', 'ERASE', 'DELETE'].includes(command)) document.getElementById('cad-command').textContent = 'ERASE: označi elemente pravougaonikom, zatim Obriši selektovano.';
            return;
        }
        if (['O', 'ORTHO'].includes(command)) { toggleCadOrtho(); return; }
        if (['OS', 'OSNAP'].includes(command)) { toggleCadSnap(); return; }
        if (['G', 'GRID'].includes(command)) { toggleCadGrid(); return; }
        if (['U', 'UNDO'].includes(command)) {
            if (routeEdit) undoRouteEdit();
            else if (mode === 'draw' && undoStack.length) undoLast();
            else mapHistory.undo();
            return;
        }
        if (['RE', 'REDO'].includes(command)) {
            if (routeEdit) redoRouteEdit(); else mapHistory.redo();
            return;
        }
        if (['ZE', 'ZOOM EXTENTS'].includes(command)) {
            if (bounds.length) map.fitBounds(bounds, { padding: [45, 45], maxZoom: 20 });
            document.getElementById('cad-command').textContent = 'ZOOM EXTENTS: prikazana cijela mreža.';
            return;
        }
        document.getElementById('cad-command').textContent = `Nepoznata komanda: ${command}`;
    };
    input.addEventListener('keydown', event => {
        event.stopPropagation();
        if (event.key === 'Enter') {
            event.preventDefault();
            const command = input.value;
            input.value = '';
            execute(command);
            input.blur();
        } else if (event.key === 'Escape') {
            input.value = '';
            input.blur();
        } else if (event.key === 'ArrowUp' && history.length) {
            event.preventDefault();
            historyIndex = Math.max(0, historyIndex - 1);
            input.value = history[historyIndex] || '';
        } else if (event.key === 'ArrowDown' && history.length) {
            event.preventDefault();
            historyIndex = Math.min(history.length, historyIndex + 1);
            input.value = history[historyIndex] || '';
        }
    });
    document.querySelectorAll('[data-cad-toggle]').forEach(button => button.addEventListener('click', () => {
        const toggle = button.dataset.cadToggle;
        if (toggle === 'snap') toggleCadSnap();
        if (toggle === 'grid') toggleCadGrid();
        if (toggle === 'ortho') toggleCadOrtho();
    }));
    syncCadStatusToggles();
}
