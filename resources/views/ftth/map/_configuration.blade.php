<script>
window.MapEditor = window.MapEditor || {};
if (window.MapEditor.initialized) {
    throw new Error('MapEditor already initialized.');
}
window.MapEditor.initialized = true;
window.ftthMapConfig = {
    mode: 'editor',
    projectId: null,
    endpoints: {
        autoOdoPreviewBaseUrl: @json(url('/projekti/__ID__/odo-plan/preview')),
        autoOdoConfirmBaseUrl: @json(url('/projekti/__ID__/odo-plan/confirm')),
        gisPlanPreviewBaseUrl: @json(url('/projekti/__ID__/gis-plan/preview')),
        gisPlanConfirmBaseUrl: @json(url('/projekti/__ID__/gis-plan/confirm')),
        projectValidationBaseUrl: @json(url('/projekti/__ID__/validacija')),
        projectDropFillBaseUrl: @json(url('/projekti/__ID__/drop-trase/popuni')),
        projectGeoJsonBaseUrl: @json(url('/projekti/__ID__/geojson')),
        projectDxfBaseUrl: @json(url('/projekti/__ID__/dxf')),
        projectPrintBaseUrl: @json(url('/projekti/__ID__/print')),
        routesBase: @json(url('/trase')),
        cabinetsBase: @json(url('/ormarici')),
        odfsBase: @json(url('/odf')),
        housesBase: @json(url('/kuce')),
        routesStore: @json(route('routes.store')),
        mapAutoRoute: @json(route('map.auto-route')),
        mapDraftStore: @json(route('map.draft.store')),
        projectsStore: @json(route('projects.store')),
    },
    data: @json($mapData),
};
</script>
