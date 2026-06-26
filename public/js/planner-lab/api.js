(function () {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    PlannerLab.api = {
        loadProject: async function (projectId) {
            const res = await fetch('/planner-lab/projects/' + projectId + '/data');
            if (!res.ok) throw new Error('Greška pri učitavanju projekta');
            return res.json();
        },

        preview: async function (projectId, options) {
            const res = await fetch('/planner-lab/projects/' + projectId + '/preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify(options),
            });
            if (!res.ok) throw new Error('Greška pri generisanju pregleda');
            return res.json();
        },

        savePlan: async function (projectId, plan) {
            const res = await fetch('/planner-lab/projects/' + projectId + '/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ plan }),
            });
            if (!res.ok) throw new Error('Greška pri snimanju plana');
            return res.json();
        },

        exportJson: async function (projectId, plan) {
            const res = await fetch('/planner-lab/projects/' + projectId + '/export-json', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ plan }),
            });
            if (!res.ok) throw new Error('Greška pri exportu');
            return res.json();
        },
    };
})();
