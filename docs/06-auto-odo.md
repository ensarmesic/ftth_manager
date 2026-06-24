# Automatski raspored ODO ormarića (Auto ODO)

Auto ODO je algoritam koji automatski grupiše nepovezane kuće u optimalne ODO ormariće i predlaže njihove pozicije na mapi.

---

## Pokretanje

Na mapi, alat **"4 FTTH"** pokreće Auto ODO za aktivni projekat.

Alternativno, na stranici `/projekti/{id}/pregled` dugme "Auto ODO preview".

---

## Parametri planiranja

| Parametar | Default | Opis |
|---|---|---|
| `max_houses_per_odo` | 12 | Max kuća po ODO (apsolutni max je 12) |
| `max_house_to_odo_m` | 120 m | Max udaljenost kuće od predloženog ODO-a |
| `max_branch_distance_m` | 120 m | Max udaljenost kuće od kraka da bi bila dodijeljena tom kraku |
| `max_gap_m` | 100 m | Max praznina između kuća u istoj grupi (chainage) |
| `preferred_fill_min` | 8 | Minimalan broj kuća po ODO-u za dobar score |
| `create_drop_routes` | false | Ako `true`, automatski kreira drop trase uz ODO ormariće |

---

## Tok algoritma

### 1. Dodjela kuća krakovima

Za svaku kuću bez dodjele ODO-a:

1. Ako kuća već ima `branch_id`, traži se samo na tom kraku.
2. Inače, traži se najbliži sekundarni krak (projektuje se kuća na geometriju trase).
3. Ako je udaljenost > `max_branch_distance_m`, kuća ostaje nedodijeljena (unassigned).

### 2. Chainage (kilometraža)

Svaka kuća dobija **chainage** — udaljenost projekcije kuće na trasu od početka trase. Kuće se sortiraju po chainageu unutar kraka.

### 3. Grupisanje

Unutar svakog kraka, kuće sortirane po chainageu se grupišu:

- Nova grupa počinje kad:
  - Trenutna grupa dostigla `max_houses_per_odo` kuća, **ili**
  - Praznina u chainageu između dvije uzastopne kuće > `max_gap_m`, **ili**
  - Dodavanjem sljedeće kuće, najudaljenija kuća od predloženog ODO-a bi prešla `max_house_to_odo_m`

### 4. Pozicija ODO-a (medoid)

Za svaku grupu:
1. Računa se **medoid** — kuća s najmanjim ukupnim zbirom udaljenosti do svih ostalih kuća u grupi.
2. Medoid se projektuje na geometriju kraka (trase).
3. Predložena pozicija ODO-a je ta projekcija (tačka na trasi).

Medoid je izabran umjesto centroida jer uvijek pada na stvarnu lokaciju (kuće), dok centroid može pasti između objekata.

### 5. Popunjavanje postojećih ODO-a

Ako projekat već ima ODO ormariće s slobodnim portovima na istom kraku, algoritam prvo pokušava smjestiti kuće u te ormariće (unutar `max_house_to_odo_m` udaljenosti). Tek preostale kuće dobijaju novi predloženi ODO.

### 6. Fallback (bez krakova)

Ako projekat nema definisanih sekundarnih krakova, algoritam radi geografskim k-means grupisanjem:
- Kuće se sortiraju po longitudi.
- Odabiru se ravnomjerno raspoređeni seed-ovi.
- Svaka kuća se dodjeljuje najbližem seed-u (k-means jedna iteracija).

Fallback generuje upozorenje jer rezultat može biti netačan bez stvarnih trasa.

---

## Preview

Preview ne snima ništa u bazu. Vraća JSON s:

- **`summary`** — ukupan broj kuća, predloženih ODO-a, prosječna udaljenost, score plana
- **`branches`** — po kraku: broj kuća, broj predloženih ODO-a, score
- **`cabinets`** — lista predloženih ODO-a s kućama, koordinatama, upozorenjima
- **`warnings`** — lista upozorenja (kuće bez kraka, nedostaje ODF, itd.)
- **`unassigned_houses`** — kuće koje nisu dodijeljene ni jednom kraku

### Score plana (0–100)

| Faktor | Težina | Detalji |
|---|---|---|
| Prosječna udaljenost kuća do ODO | 35% | ≤ 60 m = 100, 60–120 m linearno pada, > 120 m brzo pada |
| Prosječna popunjenost ODO-a | 25% | ≥ 70% = 100, linearno ispod |
| Broj upozorenja | 25% | Svako upozorenje oduzima bodove |
| Broj ODO-a vs idealni minimum | 15% | Idealan = ceil(kuće / 12) |

---

## Potvrda plana

Nakon pregleda, korisnik može:
- **Potvrditi cijeli plan** — svi predloženi ODO-i se kreiraju i kuće se vezuju
- **Urediti plan** — ručno preurediti grupe prije potvrde
- **Odbaciti** — plan se ne snima

Potvrda radi u DB transakciji. Ako bilo koji korak ne prođe validaciju, cijeli plan se rollbackuje:
- Kuća ne može biti u dvije ODO grupe
- Kuća iz drugog projekta se odbija
- Max 12 kuća po ODO-u
- ODO s kućama koje su već vezane na drugi ODO se odbija (osim ako je to bio prethodni Auto ODO)

### Kreiranje drop trasa uz potvrdu

Ako je `create_drop_routes = true`, za svaku kuću se automatski kreira drop trasa:
- Tip: `drop`, mikrocijev `10/8`, 4 vlakna
- Putanja: pametno routing — ako je kuća blizu sekundarnog kraka (≤ 90 m), drop trasa prati geometriju kraka do projekcije pa ide do kuće. Inače, ravna linija ODO → kuća.

---

## Splitter pravilo

| Kuća po ODO | Splitter count |
|---|---|
| 1–4 | 1 |
| 5–8 | 2 |
| 9–12 | 3 |
