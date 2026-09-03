// Global CAD keyboard shortcuts. Kept separate from map boot so commands can
// evolve without turning init.js back into a controller for every tool.
function initMapKeyboardInteractions() {
    document.addEventListener('keydown', event => {
        const target = event.target;
        const tag = target?.tagName?.toLowerCase();

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
            event.preventDefault(); orthoEnabled = !orthoEnabled; updateCommandBar();
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
