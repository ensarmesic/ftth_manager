# Razvojna checklista FTTH Managera

Ovaj dokument je radna lista za naredni ciklus razvoja. Stavka se označava završenom tek kada su implementacija, automatski testovi i relevantna dokumentacija gotovi.

## 1. Stabilnost optičke šeme

- [x] Provjeriti dodjelu vlakana za jedan i više ODF-ova.
- [x] Pokriti granice tuba, rezervisana vlakna i prekoračenje kapaciteta.
- [x] Provjeriti jedinstvenost kasete/pozicije i pripadnost splice zapisa projektu.
- [x] Provjeriti zaključavanje svih operacija koje mijenjaju šemu.
- [x] Provjeriti spremanje, poređenje, vraćanje i ograničenje broja verzija.
- [x] Provjeriti GPON/XGS-PON budžet u oba smjera i rubne statuse.
- [x] Provjeriti CSV, PDF, DXF i terenski list.
- [x] Proširiti E2E workflow optičke šeme.

## 2. Kompletan projektni workflow

- [x] Automatizovati tok: projekat → geodetski import → mreža → ODF/ODO/kuće.
- [x] Nastaviti tok kroz vlakna, provjeru projekta i izvještaje.
- [x] Završiti tok snapshotom, backupom i probnim vraćanjem.
- [x] Dodati scenarij velikog realnog projekta.

## 3. Refaktor bez promjene ponašanja

- [x] Razdvojiti `ProjectExportController` po formatima izvoza.
  - [x] Izdvojiti JSON backup/restore u `ProjectBackupController` uz očuvane rute.
  - [x] Zaključati fiber-schema DXF format karakterizacijskim testom prije izdvajanja.
  - [x] Izdvojiti zajedničke DXF primitive i transliteraciju iz velikog kontrolera.
  - [x] Izdvojiti fiber-schema DXF iz općeg projektnog DXF kontrolera.
- [x] Razdvojiti `FtthIntelligenceService` po domenskim provjerama.
  - [x] Izdvojiti materijale, zauzeće vlakana i proračun splittera u `ProjectMaterialService`.
  - [x] Izdvojiti validaciju projekta u zaseban servis.
  - [x] Ostaviti Auto-ODO planiranje kao jedinu glavnu odgovornost servisa.
- [x] Razdvojiti Blade/JavaScript optičke šeme u manje komponente.
- [x] Dodatno razdvojiti map editor module gdje imaju više odgovornosti.
- [x] Nakon svakog koraka pokrenuti puni regresijski test.

## 4. Uloge i dozvole

- [x] Uvesti uloge administrator, projektant, teren i pregled.
- [x] Definisati matricu dozvola po operacijama.
- [x] Zaštititi postavke, brisanje, backup/restore i sistemske operacije.
- [x] Sakriti nedostupne kontrole u korisničkom interfejsu.
- [x] Dodati testove dozvola i migraciju postojećih korisnika.

## 5. Produkciona pouzdanost

- [x] Testirati automatski backup i stvarno vraćanje kopije.
- [x] Provjeriti scheduler i evidentiranje neuspjelih poslova.
- [x] Ujednačiti limite i poruke za velike upload datoteke.
- [x] Provjeriti logove, health endpoint i spore zahtjeve.
- [x] Izmjeriti performanse velikog projekta i ukloniti uska grla.

## 6. Korisničko iskustvo

- [x] Dodati siguran undo/redo za podržane operacije mape.
- [x] Uvesti upozorenje za nesnimljene promjene.
- [x] Ujednačiti korisne poruke greške i oporavak nakon greške.
- [x] Poboljšati pretragu i filtere na glavnim listama.
- [x] Dodati vođeni početak novog projekta.

## 7. Završna provjera

- [x] PHP testovi, formatiranje i JavaScript provjera prolaze.
- [x] Production build prolazi.
- [x] Svi E2E workflowi i responsive vizuelni audit prolaze.
- [x] Korisničko i produkciono uputstvo je ažurirano.
