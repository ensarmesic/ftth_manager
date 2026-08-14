# Performance & Architecture etapa

SQLite ostaje podržana i aktivna baza. Ova etapa ne uključuje MySQL/PostgreSQL migraciju.

## Route graph

- [x] Evidentirati O(n²) povezivanje bliskih čvorova.
- [x] Uvesti spatial grid koji provjerava samo vlastitu i susjedne ćelije.
- [x] Dodati regresijski test spajanja odvojenih segmenata unutar tolerancije.
- [x] Dodati scale test sa 2.000 čvorova i mjeriti broj kandidatskih poređenja.
- [x] Zamijeniti linearni Dijkstra red sa `SplPriorityQueue`.

## Mapa

- [x] Uvesti performance ugovor za SQL upite, HTML/JSON payload i vrijeme velikog projekta.
- [x] Definisati autorizovan i testiran map-data API ugovor po projektu.
- [x] Uvesti lazy loading aktivnog projekta bez promjene postojećeg korisničkog toka.
- [ ] Dodati viewport/bbox učitavanje tek nakon testiranja projektnog API-ja.

## Pozadinski poslovi i cache

- [x] Proširiti slow-request metrike brojem SQL upita, DB vremenom, memorijom i veličinom odgovora.
- [x] Definisati pragove za izdvajanje velikih importa, izvoza i Auto ODO planiranja.
- [x] Odgoditi queue dok produkcijske metrike ne pokažu posao iznad praga; trenutni workflow ostaje jednostavniji.
- [x] Definisati pravilo invalidacije budućeg projektnog summary/validation cachea.
- [x] Ne uvoditi queue health metrike dok queue nije operativna zavisnost.

### Odluka za queue i cache

Posao prelazi u queue kada na stvarnim projektima ispuni najmanje jedan uslov: p95 trajanje iznad 5 sekundi, memorijski peak iznad 256 MB, ulaz veći od 10 MB ili rezultat koji korisnik ne mora dobiti u istom HTTP odgovoru. Prvi kandidati su veliki DXF/TXT import, veliki DXF/PDF/GeoJSON izvoz i Auto ODO nad cijelim gradskim projektom. Mali izvozi i planiranje ostaju sinhroni jer queue trenutno uvodi više operativne složenosti nego koristi.

Projektni summary/validation cache uvodi se tek ako izmjereno računanje prelazi 250 ms. Ključ mora sadržavati ID projekta i njegov `updated_at`/verziju podataka, tako da svaka izmjena ODF-a, ODO-a, kuće, trase, kraka, splice zapisa ili materijala automatski čini staru vrijednost nedostupnom. Ručno cache čišćenje nije prihvatljiva strategija invalidacije.
