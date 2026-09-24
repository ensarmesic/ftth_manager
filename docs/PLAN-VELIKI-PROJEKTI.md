# Plan rada — automatsko projektovanje velikih FTTH projekata

## Cilj

Dodati opcionalni režim **Veliki projekat** za projekte sa približno 1.000 ili više kuća.
Postojeći način rada ostaje podrazumijevan i ne mijenja se.

U velikom projektu projektant:

1. označi dozvoljene koridore/glavni rov;
2. unese ili označi kuće;
3. zada početnu tačku i tehnička pravila;
4. pokrene izradu prijedloga;
5. pregleda, koriguje i tek onda potvrđuje plan.

Sistem predlaže ODF-ove, primarne krakove, sekundarne krakove, ODO-e i veze kuća.

## Pravila kojih se držimo

- Automatski planer je uključen samo kada je projekat kreiran/prebačen u režim `large_auto`.
- Standardni projekti i postojeći Auto ODO nastavljaju raditi kao sada.
- Preview nikada ne mijenja trajne podatke.
- Snimanje plana ide tek nakon jasne potvrde korisnika i u jednoj transakciji.
- Projektant može zaključati prihvaćenu zonu i ponovo računati samo ostatak.
- Algoritam koristi isključivo dozvoljene koridore; kuće bez valjane rute prikazuje kao problem.
- Tehnička ograničenja imaju prednost nad najkraćom trasom.

## Faza 0 — dogovor tehničkih pravila

Prije implementacije potvrditi sljedeće vrijednosti:

- maksimalan broj kuća/portova po ODO-u;
- dozvoljene konfiguracije splitera;
- maksimalna dužina veze kuća–ODO;
- maksimalno opterećenje ODF-a;
- dozvoljeni kapaciteti primarnih, sekundarnih i drop kablova;
- obavezna rezerva vlakana;
- da li korisnik ručno postavlja početni/centralni ODF ili sistem smije predlagati nove ODF-ove;
- kriterij optimizacije: najmanje rova, najmanje kabla, najmanje ODO-a ili ponderisana kombinacija;
- način imenovanja ODF-ova, krakova i ODO-a.

**Rezultat:** kratka, potvrđena tabela ulaznih pravila i očekivanih izlaza.

## Faza 1 — režim projekta i postavke

- [ ] Dodati polje `planning_mode` na projekt: `standard` ili `large_auto`.
- [ ] Postojećim projektima migracijom postaviti `standard`.
- [ ] U kreiranju i izmjeni projekta dodati izbor načina rada sa jasnim opisom.
- [ ] Na mapi prikazati alate velikog planera samo za `large_auto` projekte.
- [ ] Dodati postavke planera po projektu, npr. ODO kapacitet, maksimalni drop, rezerva i cilj optimizacije.
- [ ] Dodati Feature test da standardni projekat zadržava postojeće ponašanje.

**Prihvat:** korisnik može izabrati novi režim, a postojeći projekti i ekrani rade bez promjene.

## Faza 2 — priprema ulaza na mapi

- [ ] Razdvojiti vrste dozvoljene trase: glavni koridor, primarni koridor, sekundarni koridor.
- [ ] Omogućiti označavanje obaveznih tačaka prolaza i zabranjenih područja.
- [ ] Omogućiti ručni unos i masovni uvoz kuća sa koordinatama.
- [ ] Validirati prekide u koridorima, kuće bez koordinata i kuće predaleko od dozvoljene mreže.
- [ ] Dodati prikaz „spremnosti za planiranje“ prije pokretanja algoritma.
- [ ] Sačuvati ulaznu reviziju/snapshot da se plan može ponoviti nad istim podacima.

**Prihvat:** projekat sa 1.000 kuća može se pripremiti bez učitavanja svih nepotrebnih slojeva i sistem jasno navodi sve neispravne ulaze.

## Faza 3 — zone velikog projekta

- [ ] Dodati model zone projekta sa nazivom, geometrijom i statusom: nacrt, izračunata, prihvaćena, zaključana.
- [ ] Omogućiti ručno crtanje zone i automatsku početnu podjelu kuća na zone.
- [ ] Obezbijediti da kuća pripada najviše jednoj aktivnoj zoni.
- [ ] Omogućiti zaključavanje završene zone.
- [ ] Ponovno računanje smije mijenjati samo izabrane, nezaključane zone.

**Prihvat:** moguće je obraditi npr. pet zona po 200 kuća, bez ponovnog računanja prihvaćenih zona.

## Faza 4 — algoritam i preview

- [ ] Napraviti novu servisnu cjelinu za veliki planer; ne proširivati postojeći servis jednom ogromnom metodom.
- [ ] Iz dozvoljenih koridora izgraditi povezan graf i prijaviti nepovezane dijelove.
- [ ] Grupisati kuće po geografiji, dostupnosti trase i kapacitetu.
- [ ] Predložiti položaje ODO-a na dozvoljenoj trasi.
- [ ] Predložiti sekundarne krakove ODF–ODO i drop veze ODO–kuća.
- [ ] Predložiti raspored ODF-ova i primarne krakove, ako je ta opcija uključena.
- [ ] Izračunati opterećenje svakog segmenta i odabrati kapacitet kabla sa rezervom.
- [ ] Vratiti upozorenja za prekoračenja udaljenosti, kapaciteta i kuće bez rute.
- [ ] Izračunavanje pokretati kao background posao, uz napredak i mogućnost sigurnog ponovnog pokušaja.
- [ ] Ukloniti trenutni praktični limit od 300 kuća za ovaj režim; obradu raditi po zonama/batchevima.

**Prihvat:** preview za probni skup prikazuje sve nivoe mreže i ne upisuje trajne ODF/ODO/trase.

## Faza 5 — pregled i ručne korekcije

- [ ] Različitim bojama prikazati primarne, sekundarne i drop trase.
- [ ] Prikazati ODF/ODO kapacitet, zauzeće, dužine i upozorenja.
- [ ] Omogućiti pomjeranje predloženog ODF-a/ODO-a samo na dozvoljeni koridor.
- [ ] Omogućiti prebacivanje kuće na drugi ODO uz trenutnu provjeru kapaciteta i udaljenosti.
- [ ] Omogućiti zaključavanje pojedinačnog elementa prije ponovnog proračuna.
- [ ] Dodati poređenje dvije varijante plana: dužina rova/kabla, broj ODF/ODO i broj problema.

**Prihvat:** projektant može ispraviti prijedlog bez rušenja cijelog plana i jasno vidi posljedice izmjene.

## Faza 6 — potvrda i upis u postojeću mrežu

- [ ] Prije potvrde pokrenuti završnu validaciju cijele zone.
- [ ] U jednoj transakciji kreirati ODF-ove, krakove, ODO-e, trase i veze kuća.
- [ ] Koristiti postojeće modele mreže kako izvještaji, materijali i fiber schema ne bi dobili paralelni format.
- [ ] Spriječiti dupliranje pri ponovljenoj potvrdi istog plana (idempotentnost).
- [ ] Sačuvati verziju plana i omogućiti vraćanje snapshot-a prije potvrde.
- [ ] Upisati audit trag ko je i kada generisao i potvrdio plan.

**Prihvat:** potvrđen veliki plan pojavljuje se u svim postojećim pregledima i izvještajima kao normalno projektovana mreža.

## Faza 7 — testiranje performansi i tačnosti

- [ ] Napraviti sintetičke skupove od 100, 500, 1.000 i 2.000 kuća.
- [ ] Testirati nepovezan rov, slijepu ulicu, zabranjenu zonu i kuću bez dostupnog koridora.
- [ ] Testirati granice ODF/ODO kapaciteta i maksimalnog dropa.
- [ ] Testirati zaključane zone i djelimični replan.
- [ ] Mjeriti vrijeme, memoriju i veličinu API odgovora.
- [ ] Dodati regresione testove za standardni projekat i postojeći Auto ODO.

**Prihvat:** skup od 1.000 kuća završava u dogovorenom vremenu, bez timeouta i bez promjene standardnog workflowa.

## Predloženi prvi radni dan

1. Dogovoriti pravila iz Faze 0 na jednom stvarnom projektu.
2. Implementirati `planning_mode` i izbor „Standardni / Veliki projekat“.
3. Sakriti novi workspace iza režima `large_auto`.
4. Definisati modele za zone i verziju preview plana.
5. Napraviti prvi testni dataset i kriterije prihvata za 50–100 kuća.

## Napomena o postojećoj osnovi

Već postoje korisne cjeline koje treba ponovo upotrijebiti:

- `RouteGraphService` i GIS segmenti za kretanje po dozvoljenoj mreži;
- `AutoGisPlannerService` za GIS preview i zajedničke mrežne segmente;
- `FtthIntelligenceService` za Auto ODO logiku;
- background poslovi za duže proračune;
- snapshot, validacija, audit, materijali i mapa.

Novi veliki planer treba orkestrirati i proširiti ove mogućnosti, dok postojeći standardni tok ostaje izolovan.

## Faza 8 — produkcijska pouzdanost

- [ ] Svaki proračun dobija jedinstveni ID, status, procenat napretka i razumljivu poruku greške.
- [ ] Prekid browsera ne prekida serverski proračun; rezultat je dostupan nakon ponovnog otvaranja projekta.
- [ ] Onemogućiti istovremenu potvrdu dva plana za istu zonu.
- [ ] Dodati vremensko ograničenje, kontrolisan retry i čišćenje zastarjelih privremenih rezultata.
- [ ] Provjeriti raspoloživu memoriju prije velikih operacija i koristiti batch/stream obradu gdje je moguće.
- [ ] Logovati trajanje svake faze algoritma i broj obrađenih kuća, čvorova i segmenata.
- [ ] Greška u jednoj zoni ne smije oštetiti već potvrđene ili zaključane zone.
- [ ] Prije svake potvrde automatski napraviti snapshot projekta.
- [ ] Dokumentovati postupak oporavka ako potvrda ili background posao zakaže.

**Prihvat:** osvježavanje stranice, ponavljanje zahtjeva ili greška tokom proračuna ne ostavljaju djelimične ni duplirane podatke.

## Faza 9 — kompletan korisnički tok

- [ ] Dodati čarobnjak sa koracima: Ulazni podaci → Pravila → Zone → Proračun → Pregled → Potvrda.
- [ ] Za svaki korak prikazati šta nedostaje i onemogućiti nastavak samo kada je to stvarno potrebno.
- [ ] Dodati legendu, filter po zoni/tipu elementa i brzo fokusiranje problematičnog mjesta na mapi.
- [ ] Omogućiti odabir više kuća i grupno prebacivanje na drugi ODO.
- [ ] Omogućiti poništavanje ručnih korekcija prije potvrde.
- [ ] Prikazati procjenu vremena prije pokretanja i napredak tokom obrade.
- [ ] Dodati završni izvještaj: broj kuća, ODF/ODO, dužine, kapaciteti, nepovezani elementi, upozorenja i materijali.
- [ ] Dodati izvoz plana i upozorenja u postojeće projektne izvještaje.
- [ ] Sve nove poruke i oznake napisati dosljedno na bosanskom jeziku.

**Prihvat:** korisnik može završiti cijeli veliki projekat bez ručnog pozivanja API-ja ili izlaska iz mapnog interfejsa.

## Faza 10 — integritet mreže i tehnička validacija

- [ ] Svaki ODO mora imati tačno jednog nadređenog ODF-a ili dozvoljenog nadređenog ODO-a.
- [ ] Svaka povezana kuća mora pripadati tačno jednom ODO-u.
- [ ] Primarni, sekundarni i drop segmenti moraju imati ispravne početne i završne elemente.
- [ ] Zabraniti cikluse, veze između različitih projekata i nepostojeće reference.
- [ ] Provjeriti kontinuitet trase od izvora do svake kuće.
- [ ] Provjeriti broj vlakana na svakom zajedničkom segmentu, uključujući rezervu.
- [ ] Provjeriti splitter i PON kapacitet, optički budžet i maksimalne dužine.
- [ ] Uskladiti automatski plan sa fiber schemom, granama, materijalima i validacijom projekta.
- [ ] Kritične greške moraju blokirati potvrdu; upozorenja korisnik mora svjesno potvrditi.

**Prihvat:** nakon potvrde postojeći audit integriteta i validacija projekta prolaze bez kritične greške.

## Faza 11 — kompatibilnost i migracija

- [ ] Migracija mora biti bezbjedna za postojeće podatke i imati provjeren rollback.
- [ ] Novi nullable/default podaci ne smiju pokvariti stare projekte, backup ili restore.
- [ ] Ažurirati project backup/export/import format novim režimom, zonama i verzijama plana.
- [ ] Stari backup mora se moći uvesti kao standardni projekat.
- [ ] Standardni projekat se može prebaciti u veliki režim tek nakon provjere ulaznih podataka.
- [ ] Povratak u standardni režim ne briše mrežne elemente; samo isključuje alat velikog planera.
- [ ] Provjeriti ovlaštenja, audit i CSP za svaki novi endpoint i ekran.

**Prihvat:** nadogradnja i povrat migracije prolaze nad kopijom stvarne baze, a postojeći projekti ostaju nepromijenjeni.

## Faza 12 — automatizovani testovi

- [ ] Unit testovi grafa, grupisanja, izbora položaja, kapaciteta i imenovanja.
- [ ] Feature testovi svih preview, replan, confirm, cancel i status endpointa.
- [ ] Testovi autorizacije i izolacije podataka između projekata.
- [ ] Testovi transakcijskog rollbacka i ponovljene potvrde.
- [ ] Testovi zona, zaključavanja i parcijalnog ponovnog proračuna.
- [ ] Testovi backup/restore i kompatibilnosti starog formata.
- [ ] Browser/E2E test kompletnog čarobnjaka.
- [ ] Regresioni testovi standardnog projekta, Auto ODO-a, mape, izvještaja, materijala i fiber scheme.
- [ ] Performance test sa realističnom gustinom mreže i najmanje 1.000 kuća.
- [ ] Test determinističnosti: isti ulaz i ista pravila daju isti plan.

**Prihvat:** kompletan `composer check` i ciljani veliki testovi prolaze bez grešaka.

## Faza 13 — probni rad i puštanje

- [ ] Prvo uključiti funkciju samo na testnom projektu putem feature flag-a.
- [ ] Uporediti automatski rezultat sa ručno urađenim dijelom stvarnog projekta.
- [ ] Evidentirati pogrešna grupisanja, loše položaje i tehnička odstupanja.
- [ ] Podesiti algoritam i ponoviti probu dok rezultat ne zadovolji dogovorene kriterije.
- [ ] Napraviti korisničko uputstvo sa slikama za cijeli tok.
- [ ] Napraviti kratku listu provjera prije potvrde plana.
- [ ] Tek nakon uspješnog pilot-projekta uključiti opciju za sve velike projekte.

**Prihvat:** projektant potvrđuje da pilot-plan može praktično koristiti i korigovati bez skrivenih problema.

## Redoslijed implementacije

### Paket A — temelj

Faze 0–3: pravila, režim projekta, ulazni podaci i zone.

### Paket B — planer

Faze 4–5: algoritam, background obrada, preview i ručne korekcije.

### Paket C — trajni rezultat

Faze 6, 10 i 11: sigurna potvrda, integritet, postojeći izvještaji i kompatibilnost.

### Paket D — završna obrada

Faze 7–9 i 12–13: performanse, kompletan UX, automatizovani testovi i pilot-projekat.

Nijedan paket se ne smatra završenim samo zato što ekran radi. Mora proći svoje testove i kriterije prihvata.

## Definicija potpuno završenog zadatka

Funkcija je **full završena** tek kada važi sve ispod:

- standardni mali projekti rade potpuno kao prije;
- veliki projekat može obraditi najmanje 1.000 kuća bez browser/server timeouta;
- planer koristi samo dozvoljene koridore i poštuje sva unesena tehnička pravila;
- korisnik može pregledati, korigovati, zaključati zonu i ponovo izračunati samo dio plana;
- potvrda je transakcijska, ponovljiva bez duplikata i zaštićena snapshotom;
- sve kuće i mrežni elementi imaju provjerljive veze, kapacitete i trase;
- fiber schema, materijali, izvještaji, export/import, backup i audit poznaju novi rezultat;
- nema kritičnih grešaka u validaciji niti poznatih gubitaka podataka;
- automatizovani testovi i performance testovi prolaze;
- stvarni pilot-projekat je pregledan i prihvaćen;
- korisničko i tehničko uputstvo su ažurirani.

## Kontrolna lista prije svakog sutrašnjeg završetka rada

- [ ] Sačuvati samo kompletne i migracijski bezbjedne izmjene.
- [ ] Pokrenuti testove vezane za urađeni paket.
- [ ] Pokrenuti regresione testove postojećeg standardnog toka.
- [ ] Provjeriti `git diff` da nema slučajnih izmjena.
- [ ] Ažurirati ovu checklistu stvarnim stanjem, bez označavanja nedovršenih stavki.
- [ ] Zapisati naredni konkretan korak i eventualni poznati rizik.
