(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        PlannerLab.initMap(44.0, 17.5, 8);

        const projectSelect = document.getElementById('project-select');
        const btnPreview = document.getElementById('btn-preview');

        if (projectSelect) {
            projectSelect.addEventListener('change', async function () {
                const id = this.value;
                if (!id) return;

                PlannerLab.projectId = id;
                PlannerLab.ui.clearPreview();

                // Spinner dok se projekt učitava
                const statsEl = document.getElementById('pl-stats');
                if (statsEl) {
                    statsEl.innerHTML = '<div style="text-align:center;padding:8px;color:#94a3b8;font-size:11px">Učitavam...</div>';
                    statsEl.style.display = 'block';
                }

                try {
                    const data = await PlannerLab.api.loadProject(id);
                    PlannerLab.data.odfs      = data.odfs      || [];
                    PlannerLab.data.houses    = data.houses    || [];
                    PlannerLab.data.cabinets  = data.cabinets  || [];
                    PlannerLab.data.routes    = data.routes    || [];

                    PlannerLab.renderExisting();
                    PlannerLab.ui.showProjectStats(data);

                } catch (e) {
                    if (statsEl) statsEl.innerHTML = '';
                    alert('Greška pri učitavanju projekta: ' + e.message);
                }
            });
        }

        if (btnPreview) {
            btnPreview.addEventListener('click', async function () {
                if (!PlannerLab.projectId) {
                    alert('Odaberi projekt.');
                    return;
                }

                PlannerLab.options = PlannerLab.ui.readOptions();
                PlannerLab.ui.setLoading(true);

                try {
                    const plan = await PlannerLab.api.preview(PlannerLab.projectId, PlannerLab.options);
                    PlannerLab.renderPreview(plan);
                    PlannerLab.ui.showSummary(plan);
                } catch (e) {
                    alert('Preview greška: ' + e.message);
                } finally {
                    PlannerLab.ui.setLoading(false);
                }
            });
        }
    });
})();
