<?php

namespace App\Services\PlannerLab;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Lokalni road graph engine.
 *
 * Učitava OSM puteve za bounding box projekta, gradi adjacency graph,
 * snapuje elemente na najbliži put, računa shortest path (Dijkstra),
 * i spaja sve pathove u jedno granajuće stablo.
 *
 * Ovo zamjenjuje OSRM za krakove — nema rate-limita, nema straight-line
 * fallbackova, ODO-i su garantovano na cesti.
 */
class PlannerRoadGraphEngine
{
    private const OVERPASS = 'https://overpass-api.de/api/interpreter';

    // Tipovi puteva koji nose FTTH kabel (bez footway/cycleway/path)
    private const HIGHWAY_TYPES = [
        'primary', 'primary_link',
        'secondary', 'secondary_link',
        'tertiary', 'tertiary_link',
        'residential', 'living_street',
        'service', 'unclassified',
        'track', 'road',
    ];

    // ── Javni API ─────────────────────────────────────────────────────────

    /**
     * Izgradi graph iz korisničkih polilajnova (WGS84 [[lat,lng],...] arrays).
     * Isti output format kao buildGraph() — kompatibilno s ostatkom pipeline-a.
     */
    public function buildGraphFromPolylines(array $polylines, array $excludedPolygons = []): array
    {
        $osmLike = [];
        foreach ($polylines as $pl) {
            if (count($pl) < 2) {
                continue;
            }
            $osmLike[] = [
                'geometry' => array_map(fn ($p) => ['lat' => (float) $p[0], 'lon' => (float) $p[1]], $pl),
            ];
        }

        $graph = $this->buildGraph($osmLike);

        if (! empty($excludedPolygons)) {
            $graph = $this->removeExcludedEdges($graph, $excludedPolygons);
        }

        return $graph;
    }

    /**
     * Ukloni graph edges čija sredina pada unutar bilo kojeg od izuzetih poligona.
     * Koristi se kad korisnik izuzme privatne katastarske parcele.
     */
    private function removeExcludedEdges(array $graph, array $polygons): array
    {
        foreach ($graph['adj'] as $nodeId => &$edges) {
            $fromNode = $graph['nodes'][$nodeId] ?? null;
            if (! $fromNode) {
                continue;
            }
            $edges = array_values(array_filter($edges, function ($edge) use ($fromNode, $graph, $polygons) {
                [$toId] = $edge;
                $toNode = $graph['nodes'][$toId] ?? null;
                if (! $toNode) {
                    return true;
                }
                $midLat = ((float) $fromNode['lat'] + (float) $toNode['lat']) / 2;
                $midLng = ((float) $fromNode['lng'] + (float) $toNode['lng']) / 2;
                foreach ($polygons as $poly) {
                    if ($this->pointInPolygon($midLat, $midLng, $poly)) {
                        return false;
                    }
                }
                return true;
            }));
        }
        unset($edges);
        return $graph;
    }

    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $n = count($polygon);
        if ($n < 3) {
            return false;
        }
        $inside = false;
        $j      = $n - 1;
        for ($i = 0; $i < $n; $i++) {
            $yi = (float) $polygon[$i][0];
            $xi = (float) $polygon[$i][1];
            $yj = (float) $polygon[$j][0];
            $xj = (float) $polygon[$j][1];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
            $j = $i;
        }
        return $inside;
    }

    /**
     * Učitaj OSM ways za bounding box.
     * Cachuje rezultat 2 sata (isti bbox → nema ponovnog API poziva).
     */
    public function loadOsmRoads(float $minLat, float $minLng, float $maxLat, float $maxLng): array
    {
        $key   = 'planner_osm_' . md5("{$minLat},{$minLng},{$maxLat},{$maxLng}");
        $roads = Cache::get($key);

        if ($roads !== null) {
            return $roads;
        }

        $roads = $this->fetchOverpass($minLat, $minLng, $maxLat, $maxLng);

        if (! empty($roads)) {
            Cache::put($key, $roads, 7200);
        }

        return $roads;
    }

    /**
     * Izgradi adjacency graph iz OSM ways.
     *
     * Svaki put je niz segmenata. Svaki segment postaje dvosmjerni edge.
     * Čvorovi su identifikovani preciznim "lat_lng" ključem.
     *
     * Vraća:
     *   'nodes' => [nodeId => ['lat', 'lng']]
     *   'adj'   => [nodeId => [[$toId, $lengthM, $segPath], ...]]
     */
    public function buildGraph(array $osmWays): array
    {
        $nodes = [];
        $adj   = [];

        foreach ($osmWays as $way) {
            $geom = $way['geometry'] ?? [];
            if (count($geom) < 2) {
                continue;
            }

            for ($i = 0, $n = count($geom) - 1; $i < $n; $i++) {
                $a = $geom[$i];
                $b = $geom[$i + 1];

                $aId = $this->nid((float) $a['lat'], (float) $a['lon']);
                $bId = $this->nid((float) $b['lat'], (float) $b['lon']);

                $nodes[$aId] = ['lat' => (float) $a['lat'], 'lng' => (float) $a['lon']];
                $nodes[$bId] = ['lat' => (float) $b['lat'], 'lng' => (float) $b['lon']];

                $len = PlannerGeometry::haversine(
                    (float) $a['lat'], (float) $a['lon'],
                    (float) $b['lat'], (float) $b['lon']
                );

                $segAB = [[(float) $a['lat'], (float) $a['lon']], [(float) $b['lat'], (float) $b['lon']]];
                $segBA = [[(float) $b['lat'], (float) $b['lon']], [(float) $a['lat'], (float) $a['lon']]];

                $adj[$aId][] = [$bId, $len, $segAB];
                $adj[$bId][] = [$aId, $len, $segBA];
            }
        }

        return ['nodes' => $nodes, 'adj' => $adj];
    }

    /**
     * Snapuj tačku na najbliži čvor u grafu.
     * Linearna pretraga — dovoljno brza za < 50 000 čvorova.
     */
    public function snapToNearestNode(float $lat, float $lng, array $nodes): array
    {
        $bestId   = null;
        $bestDist = PHP_FLOAT_MAX;

        foreach ($nodes as $id => $node) {
            $d = PlannerGeometry::haversine($lat, $lng, $node['lat'], $node['lng']);
            if ($d < $bestDist) {
                $bestDist = $d;
                $bestId   = $id;
            }
        }

        if ($bestId === null) {
            return ['node_id' => null, 'lat' => $lat, 'lng' => $lng, 'distance' => PHP_FLOAT_MAX];
        }

        return [
            'node_id'  => $bestId,
            'lat'      => $nodes[$bestId]['lat'],
            'lng'      => $nodes[$bestId]['lng'],
            'distance' => $bestDist,
        ];
    }

    /**
     * Dijkstra shortest path kroz road graph.
     *
     * Vraća:
     *   'node_sequence' => ['nodeA', 'nodeB', ...]
     *   'segments'      => [[[lat,lng],[lat,lng]], ...]  (geometry per edge)
     *   'path'          => [[lat,lng], ...]              (ravna ruta za prikaz)
     *   'length_m'      => float
     *
     * ili null ako put ne postoji.
     */
    public function dijkstra(array $nodes, array $adj, string $startId, string $targetId): ?array
    {
        if ($startId === $targetId) {
            $pt = $nodes[$startId] ?? null;
            return [
                'node_sequence' => [$startId],
                'segments'      => [],
                'path'          => $pt ? [[$pt['lat'], $pt['lng']]] : [],
                'length_m'      => 0.0,
            ];
        }

        $dist    = [$startId => 0.0];
        $prev    = [];   // prev[$nodeId] = previousNodeId
        $prevSeg = [];   // prevSeg[$nodeId] = geometry from prev→node
        $visited = [];

        $pq = new \SplPriorityQueue();
        $pq->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $pq->insert($startId, 0.0);

        while (! $pq->isEmpty()) {
            ['data' => $cur, 'priority' => $negD] = $pq->extract();
            $d = -$negD;

            if (isset($visited[$cur])) {
                continue;
            }
            $visited[$cur] = true;

            if ($cur === $targetId) {
                break;
            }

            foreach ($adj[$cur] ?? [] as [$to, $len, $seg]) {
                if (isset($visited[$to])) {
                    continue;
                }
                $nd = $d + $len;
                if ($nd < ($dist[$to] ?? PHP_FLOAT_MAX)) {
                    $dist[$to]    = $nd;
                    $prev[$to]    = $cur;
                    $prevSeg[$to] = $seg;
                    $pq->insert($to, -$nd);
                }
            }
        }

        if (! isset($visited[$targetId])) {
            return null; // No path found
        }

        // Reconstruct path backwards
        $segments     = [];
        $nodeSequence = [];
        $cur          = $targetId;

        while ($cur !== $startId) {
            $segments[]     = $prevSeg[$cur];
            $nodeSequence[] = $cur;
            $cur            = $prev[$cur] ?? null;
            if ($cur === null) {
                return null; // Broken path
            }
        }

        $nodeSequence[] = $startId;
        $segments       = array_reverse($segments);
        $nodeSequence   = array_reverse($nodeSequence);

        // Flatten segments into a single polyline
        $path = [];
        foreach ($segments as $seg) {
            if (empty($path)) {
                $path = $seg;
            } else {
                // Skip first point (shared with end of previous segment)
                foreach (array_slice($seg, 1) as $pt) {
                    $path[] = $pt;
                }
            }
        }

        return [
            'node_sequence' => $nodeSequence,
            'segments'      => $segments,
            'path'          => $path,
            'length_m'      => $dist[$targetId],
        ];
    }

    /**
     * Spoji više Dijkstra pathova u jednu mrežu bez duplikata.
     *
     * Input: array of ['cab' => cabinet_data, 'dijkstra' => dijkstra_result]
     *
     * Vraća branches array gdje svaka grana ima:
     *   'cab'           => cabinet
     *   'path'          => puni polyline (za tooltip i bounds)
     *   'new_segments'  => segmenti koji se crtaju prvi put (za bojenje)
     *   'length_m'      => dužina
     */
    public function mergePaths(array $pathEntries): array
    {
        $drawnEdges = []; // edgeKey → branchIdx that first drew it
        $branches   = [];

        foreach ($pathEntries as $idx => $entry) {
            $cab      = $entry['cab'];
            $dijkstra = $entry['dijkstra'];

            if ($dijkstra === null) {
                continue;
            }

            $nodeSeq = $dijkstra['node_sequence'];
            $segs    = $dijkstra['segments'];
            $newSegs = [];

            for ($i = 0, $n = count($nodeSeq) - 1; $i < $n; $i++) {
                $fromId = $nodeSeq[$i];
                $toId   = $nodeSeq[$i + 1];
                $seg    = $segs[$i] ?? [];

                $key = $this->edgeKey($fromId, $toId);

                if (! isset($drawnEdges[$key])) {
                    $drawnEdges[$key] = $idx;
                    $newSegs[]        = $seg; // nový segment → crtati s bojom grane
                }
                // Stari segment → preskočiti (već ucrtan prethodnom granom)
            }

            $branches[] = [
                'cab'          => $cab,
                'path'         => $dijkstra['path'],
                'new_segments' => $newSegs,
                'length_m'     => $dijkstra['length_m'],
            ];
        }

        return $branches;
    }

    // ── Private ───────────────────────────────────────────────────────────

    private function fetchOverpass(float $minLat, float $minLng, float $maxLat, float $maxLng): array
    {
        $types = implode('|', self::HIGHWAY_TYPES);
        $bbox  = "{$minLat},{$minLng},{$maxLat},{$maxLng}";
        $query = "[out:json][timeout:25];(way[\"highway\"~\"^({$types})$\"]({$bbox}););out geom;";

        // Pokušaj Overpass
        try {
            $response = Http::timeout(20)
                ->withoutVerifying()
                ->withHeaders(['Accept' => '*/*', 'User-Agent' => 'ftth-planner/1.0'])
                ->asForm()
                ->post(self::OVERPASS, ['data' => $query]);

            if ($response->ok()) {
                $elements = $response->json()['elements'] ?? [];
                if (! empty($elements)) {
                    return $elements;
                }
            }
        } catch (\Throwable) {}

        // Fallback: direktni OSM API (XML)
        return $this->fetchOsmDirect($minLat, $minLng, $maxLat, $maxLng);
    }

    private function fetchOsmDirect(float $minLat, float $minLng, float $maxLat, float $maxLng): array
    {
        // OSM API bbox = west,south,east,north
        $url = 'https://api.openstreetmap.org/api/0.6/map'
             . "?bbox={$minLng},{$minLat},{$maxLng},{$maxLat}";

        try {
            $response = Http::timeout(30)
                ->withoutVerifying()
                ->withHeaders(['Accept' => 'application/xml', 'User-Agent' => 'ftth-planner/1.0'])
                ->get($url);

            if (! $response->ok()) {
                return [];
            }

            return $this->parseOsmXml($response->body());
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseOsmXml(string $xml): array
    {
        $allowed = array_flip(self::HIGHWAY_TYPES);

        try {
            $doc = new \SimpleXMLElement($xml);
        } catch (\Throwable) {
            return [];
        }

        // Izgradnja mape čvorova: id → ['lat', 'lon']
        $nodeMap = [];
        foreach ($doc->node as $node) {
            $nodeMap[(string) $node['id']] = [
                'lat' => (float) $node['lat'],
                'lon' => (float) $node['lon'],
            ];
        }

        $ways = [];
        foreach ($doc->way as $way) {
            $highwayType = null;
            foreach ($way->tag as $tag) {
                if ((string) $tag['k'] === 'highway') {
                    $highwayType = (string) $tag['v'];
                    break;
                }
            }

            if ($highwayType === null || ! isset($allowed[$highwayType])) {
                continue;
            }

            $geom = [];
            foreach ($way->nd as $nd) {
                $ref = (string) $nd['ref'];
                if (isset($nodeMap[$ref])) {
                    $geom[] = $nodeMap[$ref];
                }
            }

            if (count($geom) < 2) {
                continue;
            }

            $ways[] = [
                'type'     => 'way',
                'id'       => (int) $way['id'],
                'geometry' => $geom,
                'tags'     => ['highway' => $highwayType],
            ];
        }

        return $ways;
    }

    private function nid(float $lat, float $lng): string
    {
        return number_format($lat, 6, '.', '') . '_' . number_format($lng, 6, '.', '');
    }

    private function edgeKey(string $a, string $b): string
    {
        return $a < $b ? "{$a}|{$b}" : "{$b}|{$a}";
    }
}
