# TXT import audit (TASK 1)

Datum audita: 2026-08-20

## Opseg i zaključak

Aktivni geodetski TXT import nije jednostavan parser niti koristi jedan postojeći `RouteGraphService`. Ulaz ide kroz `SurveyPointController`, a `SurveyPointImportService` koordinira parser, klasifikaciju, dva survey grafa (rovovi i mikrocijevi), rekonstrukciju korisničkih 10/8 trasa, preview i trajno spremanje.

Kod već pokušava rutirati korisničku trasu prema oznaci ZO iz opisa i kroz polilinije rovova. Međutim, aktivni flow sadrži više heuristika blizine i nekoliko eksplicitnih fallbackova koji nisu u skladu s novim pravilima FAZE 0. Posebno: terminal se može spojiti na najbliži neterminalni čvor, ZO se može dohvatiti preko čvora do 30 m od ormara, nepovezana ruta može preuzeti putanju druge korisničke rute istog ZO-a do 60 m, a završna koordinata ormara se po potrebi direktno dodaje na path.

Ovaj task ne mijenja funkcionalni kod.

## HTTP i frontend call-flow

1. `routes/web.php`
   - `POST /projekti/{project}/tacke/preview` -> `SurveyPointController::preview()`
   - `POST /projekti/{project}/tacke/import` -> `SurveyPointController::import()`
2. `public/js/map/survey.js`
   - `previewFile()` šalje `points_file` na preview endpoint.
   - `drawMapPreview()` crta `trench_runs[].path` i `ducts[].path`; crveno/isprekidano označava `routing_status === unreachable`.
   - `ductRowHtml()` prikazuje pronađeni ormar, status puta i ručni izbor samo kada je `match_confidence === ambiguous`.
   - potvrda ponovo šalje originalni fajl, uz opcionalni mapirani `overrides[duct.key] = cabinet_id`.
3. `app/Http/Controllers/SurveyPointController.php`
   - `preview()` validira TXT i poziva `SurveyPointImportService::preview()`; nema DB upisa.
   - `import()` prije importa pravi project snapshot, zatim poziva `SurveyPointImportService::confirm()`.
4. `SurveyPointImportService::preview()` i `confirm()` oba ponovo parsiraju isti sadržaj, pa koriste isti `buildNetwork()`.
5. `buildNetwork()` poziva `SurveyGraphBuilder`, `SurveyChainWalker`, `SurveyPathGeometryService`, `SurveyColoredFlowService` i `SurveyDropRoutingService`.
6. Preview vraća izračunate polilinije. Confirm prvo sprema osnovne elemente kroz `SurveyBaseElementImportService`, zatim mrežu kroz `SurveyNetworkPersistenceService` unutar DB transakcije.

## Ulazni TXT format i očuvanje izvora

`SurveyPointParser::parse()` prepoznaje zapise oblika:

```text
broj_tačke X Y Z opis
```

- broj: 1-5 cifara;
- X: sedmocifrena Gauss-Krüger koordinata koja počinje sa 4-7;
- Y: sedmocifrena koordinata koja počinje sa 3-5;
- Z: potpisana visina do četiri cijele cifre i do tri decimale;
- opis: tekst do početka sljedećeg prepoznatog zapisa.

Parser regexom pronalazi i slijepljene zapise, uklanja navodnike i CR, te sažima whitespace u opisu. Zato `code` čuva sadržaj opisa, ali ne potpuno byte-identičan original. Originalni cijeli red/fajl se ne čuva kao zasebna vrijednost.

`point_no`, izvorne `x`, `y`, `z`, izračunate `lat`, `lng`, normalizovani `code` i `kind` spremaju se u `survey_points`. Migracija je `database/migrations/2026_07_10_000001_create_survey_points_table.php`, a model `app/Models/SurveyPoint.php`.

Koordinate se pretvaraju u WGS84 u `SurveyPointParser` preko `GeoTransformService::detectZone()` i `gaussKrugerToWgs84()`. Parser ne sortira rezultate: redoslijed je redoslijed regex zapisa u sadržaju. `point_no` se sprema, ali se ne koristi kao ključ za sortiranje geometrije.

## Klasifikacija i ZO oznaka

`SurveyPointClassifier::classify()` normalizuje opis radi pretrage i vraća:

- `kind` (`trench`, `cabinet`, `odf`, `sling`, `loop`, `splice`, `manhole`, `boring`, `pole`, `other`);
- tip, broj i boje mikrocijevi;
- `zo_tag`;
- više identiteta mikrocijevi kada su razdvojeni `;` ili `|`;
- oznake `prepared_sling`, `house_ref` i `transit`.

ZO se trenutno čita regexom direktno u `SurveyPointClassifier` i ponovo sličnim regexom u `SurveyImportIdentityService::cabinetTag()`. `SurveyPointCodeNormalizer::cabinetTag()` uklanja vodeće nule i završne `.0`, ali rezultat je interni broj/tag poput `3` ili `1.1`, ne kanonski `ZO-3` objekt sa `raw_description`, `matched_text` i `explicit`.

Podržani oblici su širi od čistog `ZO`: regex prihvata `Z`, `ZO`, `Z0`, separatore razmak/crtica/tačka/underscore i decimalno/hijerarhijsko numerisanje. Klasifikator koristi posljednje ZO podudaranje u opisu. Posebno prepoznaje i tekst tipa „zelena ormarica br. 7“.

Nema izdvojene semantike „target nije naveden“ naspram „target je naveden, ali Cabinet ne postoji“. Oba slučaja se kasnije uglavnom vide kao `null` binding ili nedostignut ormar.

## Grupisanje, redoslijed i graf

`SurveyPointImportService::buildNetwork()` pravi odvojene skupove trench i duct tačaka. `SurveyGraphBuilder::build()`:

- zadržava ulazni redoslijed tačaka;
- spaja koordinate u isti node kada su unutar `NODE_MERGE_M = 1.5 m` (terminal-terminal koristi 0.5 m);
- normalno pravi edge između susjednih zapisa u originalnom survey walku;
- prekida edge kada je razmak veći od dozvoljenog (`TRENCH_GAP_M = 20 m`, tagged duct 30 m, customer spur 60 m);
- može nakon terminala preskočiti na raniji čvor za koji zaključi da je povratak geodete (`return/re-anchor` heuristika);
- za terminal bira jedan kompatibilan kandidat iz oba smjera izvornog reda i sortira kandidate po udaljenosti (`usort`);
- edge nosi identitete mikrocijevi koji su zajednički susjednim tačkama.

`SurveyChainWalker` zatim pretvara edgeove u polilinije. Za 10/8 koristi `walkHouseDrops()` i gradi zasebnu putanju po house/loop checkpointu; za ostale mikrocijevi koristi `walk()`. `SurveyPathGeometryService::mergeCollinearChains()` spaja kolinearne trench lance, a `weldChainEnds()` može spojiti krajeve istog označenog/obojenog ducta iz različitih komponenti do 10 m.

Dakle, tačke se globalno ne sortiraju po `point_no` niti nearest-neighbor metodom. Ipak, postoje lokalna sortiranja/izbori po udaljenosti i re-anchor logika koja može promijeniti koji par tačaka postaje edge. Zahtjev da originalni redoslijed korisničkih tačaka uvijek ostane neizmijenjen nije zasebno modeliran niti garantovan.

## Glavne trase i postojeći grafovi

Glavne i korisničke polilinije trajno se čuvaju u `routes.path` (JSON), model `NetworkRoute` nad tabelom `routes`. `route_type` razlikuje najmanje `trench`, `drop`, `feeder`, `distribution` i druge mrežne tipove. `SurveyNetworkPersistenceService` sprema trench i duct rezultate kao zasebne `NetworkRoute` redove.

`SurveyRouteReconciliationService::projectRoutingTrenchPaths()` učitava postojeće `route_type = trench` putanje (osim trenutnog batcha) i uključuje ih u routing. Isti servis može spajati dodirujuće postojeće i nove pathove.

Postoje dva graph enginea:

- `SurveyGraphBuilder` i interni graf u `SurveyDropRoutingService` koriste se u aktivnom TXT flowu;
- `RouteGraphService` je opći GIS/network shortest-path servis, ali ga TXT importer trenutno ne poziva.

`RouteGraphService` gradi edge samo iz segmenata polilinija i stvarnih presjeka, ali dodatno `connectNearbyNodes()` povezuje sve čvorove do 2 m, a `attachPoint()` projektuje proizvoljnu početnu/ciljnu tačku na najbliži segment bez vidljivog maksimalnog odmaka. Zbog toga se ne može bez izmjena direktno proglasiti sigurnim engineom za nova pravila.

`NetworkBranch` (`network_branches`) predstavlja poslovnu/hijerarhijsku granu vezanu za jedan `NetworkRoute`; nije geometrijski graf segmenata. Nema trajne tabele graph node/edge niti stabilnih ID-eva svakog fizičkog segmenta. Graf se ponovo gradi u memoriji iz `routes.path`.

## Trenutna rekonstrukcija korisničke trase

`SurveyDropRoutingService::process()` radi tri koraka:

1. `createImplicitTaggedDrops()` može napraviti implicitni dvočvorni drop od sling tačke do najbližeg trench vertexa do 30 m.
2. `attachDropMetadata()` veže terminalnu survey tačku uz drop; tačan endpoint ima prednost, a blizina je dokumentovana u kodu kao fallback.
3. `routeTaggedDropsThroughTrenches()` gradi in-memory graf iz survey i postojećih trench polilinija, nalazi Cabinet čiji normalizovani naziv odgovara `duct.zo_tag`, te Dijkstrom traži put.

Pozitivno: kada `zo_tag` postoji, cilj se traži po oznaci, ne izborom geografski najbližeg ZO-a. Ako Cabinet sa tim tagom ne postoji, metoda ne bira drugi Cabinet. Dijkstra koristi edgeove nastale iz trench pathova.

Međutim, početak i završetak nisu strogo potvrđeni topološki spojevi:

- terminal se pridružuje najbližem neterminalnom graph nodeu do 30 m;
- novi survey endpoint može se projektovati na postojeći trench segment (0.5 m za terminal, 5 m za ostale krajeve), stvarajući spojni edge;
- ciljni graph node može biti do 30 m od koordinata Cabinet-a;
- ako nema ciljnog čvora do 10 m, koristi se fallback najbližeg reachable čvora do 30 m;
- ako običan graph path ne uspije, servis može projektovati join i Cabinet na isti postojeći trench corridor i napraviti corridor path;
- ako ni to ne uspije, može preuzeti path druge već „dokazane“ korisničke rute istog ZO-a kada je terminal do 60 m od nje;
- na završetku se koordinata Cabinet-a direktno dodaje ako nije unutar 0.5 m od zadnje tačke.

Ovo znači da postoje straight connector segmenti terminal -> node/projekcija i node/projekcija -> Cabinet koji ne moraju biti segmenti već nacrtane mreže. Peer-route fallback je posebno rizičan jer može nacrtati direktan spoj terminala na tuđu path projekciju.

## Trenutni izbor i vezivanje ZO/ODO

Za tagged duct `SurveyDropRoutingService` traži prvu cabinet/binding point tačku čiji `SurveyImportIdentityService::cabinetTag(code)` odgovara `duct.zo_tag`.

Kasnije `SurveyDuctBindingService::resolve()`:

- prvo poštuje ručni preview override;
- zatim traži Cabinet sa jednakim tagom;
- ako nije nađen i duct nije transit, traži kabinete blizu početnog endpointa do dozvoljene tolerance;
- jedan kandidat se automatski bira kao proximity match, više kandidata daje ambiguous stanje.

Zato nearest/proximity Cabinet fallback i dalje postoji za netagovane ili neuspješno tagovane ductove. Za eksplicitno tagovani duct sa nepostojećim Cabinetom exact lookup ne bi smio pasti na drugi Cabinet, ali trenutni kod nema dovoljno eksplicitno stanje i test koji dokazuje tu zabranu kroz cijeli preview/save flow.

`SurveyNetworkPersistenceService` za pripremljeni tagged customer duct prekida import ako `cabinet_reached` nije potvrđen. Ipak, `cabinet_reached` može postati true putem navedenih proximity/fallback grana.

## Preview i save format

Preview vraća:

- `trench_runs` sa cijelim trench `path`;
- `ducts` sa jednim cijelim rekonstruisanim `path`, `zo_tag`, matched Cabinet informacijama, `routing_status`, tipom i dužinom;
- listu Cabinet i ODF tačaka, quality nalaz i bounds.

Frontend ne dobija odvojeno `own_geometry`, `entry_point`, `shared_main_geometry` i `full_geometry`. Zato trenutno ne može vizuelno dokazati gdje prestaju korisničke geodetske tačke, a počinje shared glavni rov.

Save sprema rekonstruisani puni drop path u `routes.path`. Nema odvojene reference na fizičke shared edgeove. Više korisnika zato može imati duplicirane koordinate istog glavnog rova u svojim drop redovima, iako `counts_as_trench = false` sprečava da se drop računa kao zaseban rov u nekim izvještajima.

## Mjesta proizvoljne ili pogrešne geometrije

Najvažniji potvrđeni rizici su:

1. `SurveyGraphBuilder::build()` — terminal attachment kandidat bira se po udaljenosti; return/re-anchor može zamijeniti edge između uzastopnih zapisa edgeom prema ranijem nodeu.
2. `SurveyPathGeometryService::weldChainEnds()` — različite connected komponente istog označenog ducta mogu se spojiti do 10 m bez postojećeg segmenta.
3. `SurveyDropRoutingService::createImplicitTaggedDrops()` — stvara ravni stub terminal -> najbliži trench vertex do 30 m.
4. `SurveyDropRoutingService::routeTaggedDropsThroughTrenches()` — najbliži neterminalni join node do 30 m, fallback cabinet access do 30 m, corridor projekcije i peer-route fallback do 60 m.
5. Ista metoda eksplicitno dodaje završnu Cabinet koordinatu na path, što je direktni segment ako Cabinet nije već graph node.
6. `SurveyDuctBindingService::resolve()` — proximity Cabinet fallback kada exact tag binding nije ostvaren.
7. `SurveyPathGeometryService::mergeCollinearChains()` i `SurveyRouteReconciliationService::mergeTouchingPaths()` mijenjaju/objedinjuju reprezentaciju pathova na osnovu tolerancije; treba dokazati da ne gube lomne tačke relevantne korisničkoj ruti.
8. `RouteGraphService::connectNearbyNodes()` — opći engine stvara edge između čvorova samo zato što su unutar 2 m.
9. `RouteGraphService::attachPoint()` — dodaje spur do najbliže projekcije bez vidljivog maksimuma; nije trenutno u TXT flowu, ali je rizik ako ga TASK 3 ponovo iskoristi bez prilagodbe.
10. Preview i baza čuvaju samo spojeni puni path, pa se iz rezultata ne može pouzdano dokazati porijeklo svakog edgea niti granica own/shared geometrije.

## Testovi koji trenutno pokrivaju flow

Glavni regression skup je `tests/Feature/SurveyPointImportTest.php`. Pokriva parser, slijepljene redove, više boja i duct identiteta, grane, shared 10/8 trase, named ZO routing, postojeće trench putanje, preview, persistence, realne fixture fajlove, import/brisanje i veće fajlove.

Dodatno:

- `tests/Unit/SurveyPointCodeNormalizerTest.php`
- `tests/Unit/SurveyDuctIdentityServiceTest.php`
- `tests/Unit/SurveyChainWalkerTest.php`
- `tests/Unit/SurveyPreviewQualityServiceTest.php`
- `tests/Feature/SurveyPersistenceServicesTest.php`
- `tests/Feature/RouteGraphPerformanceTest.php`
- `tests/Feature/CompleteProjectWorkflowTest.php`

Postoje stvarni/realistični fixturei u `tests/Fixtures/survey/`, uključujući `uredjen-i-ispravljen-opis.txt`, `test-rainci-gornji-osm.txt`, `test-gps-odf-1753-2113.txt` i kompletne višestruke ZO mreže.

Nije potvrđeno da trenutni testovi dokazuju sva nova stroga pravila: nema zasebnog parser rezultata sa `explicit`, nema trajnog edge provenancea, nema eksplicitnog testa zabrane bliske nepovezane trase za sve fallback grane, niti preview strukture own/shared geometrije.

## Vjerovatni fajlovi za TASK 2-10

Primarni kandidati:

- `app/Services/SurveyPointClassifier.php`
- `app/Services/SurveyPointCodeNormalizer.php`
- `app/Services/SurveyImportIdentityService.php`
- novi ili postojeći izdvojeni target parser (odluka u TASK 2)
- `app/Services/SurveyPointImportService.php`
- `app/Services/SurveyGraphBuilder.php`
- `app/Services/SurveyDropRoutingService.php`
- `app/Services/SurveyPathGeometryService.php`
- `app/Services/SurveyDuctBindingService.php`
- `app/Services/SurveyRouteReconciliationService.php`
- `app/Services/SurveyPreviewQualityService.php`
- `app/Services/SurveyNetworkPersistenceService.php`
- `app/Services/RouteGraphService.php` (ako se ponovo koristi; trenutno nije dio TXT call-flowa)
- `public/js/map/survey.js`
- `tests/Feature/SurveyPointImportTest.php`
- relevantni novi Unit testovi za parser, graph, entry point i routing.

Mogući modeli/migracije tek nakon analize TASK 7:

- `app/Models/NetworkRoute.php`
- `app/Models/NetworkBranch.php`
- tabela `routes` i eventualna minimalna referenca shared edgeova.

Ne treba unaprijed uvoditi novu tabelu: postojeći `routes.path` predstavlja fizičke trase, ali trenutno nema stabilan identitet pojedinačnih segmenata potreban za dokazivo `shared_route_edges` ponašanje.

## Nepotvrđeno

- Iz samog koda nije moguće potvrditi poslovno značenje svih stvarnih geodetskih opisa; classifier sadrži heuristike, ali one nisu specifikacija terenskih oznaka.
- Nije potvrđeno da je svaka postojeća ručno nacrtana `trench` polilinija topološki povezana sa Cabinet koordinatom; trenutni kod upravo zbog toga koristi tolerancije i projekcije.
- U TASK 1 nije ručno otvorena mapa niti izvršen realni import; obavezna realna provjera pripada TASK 10 nakon implementacije.

