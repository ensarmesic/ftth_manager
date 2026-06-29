window.PlannerLab = {
    map: null,
    projectId: null,

    data: {
        odfs: [],
        houses: [],
        cabinets: [],
        routes: [],
    },

    options: {
        useOsm:              true,
        followRoads:         true,
        maxHousesPerCabinet: 12,
        odoSpacing:          150,
        maxDropDistance:     150,
        installation:        'underground',
    },

    preview: {
        plan: null,
        layers: [],
        warnings: [],
    },

    _toastTimer: null,

    _existingLayers: {
        odfs: [],
        houses: [],
        cabinets: [],
        routes: [],
    },
};
