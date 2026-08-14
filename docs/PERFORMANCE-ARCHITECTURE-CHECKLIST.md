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

- [ ] Izmjeriti trajanje velikih importa, izvoza i Auto ODO planiranja.
- [ ] Prebaciti u queue samo poslove koji realno prelaze HTTP budžet.
- [ ] Definisati invalidaciju projektnih summary/validation cache podataka.
- [ ] Dodati health metrike za queue samo ako queue postane operativna zavisnost.
