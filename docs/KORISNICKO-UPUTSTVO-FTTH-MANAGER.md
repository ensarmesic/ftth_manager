# FTTH Manager — kompletno korisničko uputstvo

Ovo uputstvo opisuje svakodnevno korištenje FTTH Manager aplikacije: prijavu,
projekte, mrežnu evidenciju, rad na mapi, uvoz podataka, kontrolu kvaliteta,
izvještaje, sigurnosne kopije i preporučeni tok rada.

> Važno: prije većeg uvoza, automatskog planiranja, masovne izmjene ili brisanja
> napravite verziju projekta i preuzmite JSON backup.

## 1. Namjena aplikacije

FTTH Manager objedinjuje projektovanje i evidenciju FTTH mreže. U aplikaciji se
vode projekti, ODF lokacije, ODO/FTTH ormarići, kuće, trase, krakovi, mikrocijevi,
optički kapaciteti, splitteri, materijali i terenski podaci. Mapa služi kao
centralni editor, dok liste i projektni pregled daju tabelarnu kontrolu podataka.

Osnovni odnos elemenata je:

```text
Projekat
└── ODF
    ├── primarna/feeder trasa
    └── ODO ormarić
        ├── distribucijska trasa ili naredni ODO
        └── drop trasa → kuća/priključak
```

Rov predstavlja fizički koridor iskopa. Mikrocijev i optički kabl predstavljaju
infrastrukturu položenu kroz taj koridor. Zato dužina rova, mikrocijevi i kabla
ne moraju uvijek biti ista količina.

## 2. Prijava, odjava i lozinka

1. Otvorite adresu aplikacije i unesite korisničko ime i lozinku.
2. Nakon prijave otvara se kontrolni centar.
3. Korisnički meni u gornjem desnom uglu sadrži pristup postavkama i odjavu.
4. Po završetku rada koristite **Odjava**, naročito na zajedničkom računaru.

Lozinka se mijenja u **Postavke → Sigurnost računa**. Potrebno je unijeti
trenutnu lozinku, novu lozinku i potvrdu nove lozinke. Lozinke su hashirane i ne
mogu se pročitati iz baze. Nemojte dijeliti račun između više osoba jer audit
zapis tada ne može pouzdano pokazati ko je napravio izmjenu.

## 3. Glavna navigacija

Lijevi meni je podijeljen u cjeline:

- **Radni prostor** — Pregled, Projekti i Mapa;
- **Mrežna evidencija** — ODF, ODO ormarići, Kuće, Trase i Krakovi;
- **Analitika i kontrola** — Izvještaji, Splitteri, Fiber šema i Provjera projekta;
- **Sistem** — Postavke.

Gornja traka sadrži:

- globalnu pretragu menija (`Ctrl+K`);
- uključivanje prikaza preko cijelog ekrana;
- obavijesti o nepovezanim kućama, ODO ormarićima bez ODF-a i nepotpunim trasama;
- korisnički meni.

Na manjem ekranu lijevi meni i alati mape otvaraju se posebnim dugmetom.

## 4. Kontrolni centar — Pregled

Početna stranica daje operativni pregled svih projekata:

- broj i stanje projekata;
- broj ODF-ova, ODO ormarića, kuća i trasa;
- ukupne dužine;
- projektni portfolio;
- stavke koje zahtijevaju pažnju;
- brze prečice do najčešćih funkcija.

Kartica **Zahtijeva pažnju** nije samo informativna. Otvorite prijavljene probleme
i ispravite veze ili tehničke podatke prije izrade konačnih izvještaja.

## 5. Projekti

### 5.1. Kreiranje projekta

Otvorite **Projekti** i izaberite **Novi projekat**. Unose se:

- **Naziv projekta** — jasan puni naziv;
- **Šifra projekta** — kratka i jedinstvena oznaka;
- **Lokacija** — naselje/općina ili radno područje;
- **Investitor** — opcionalno;
- **Status** — Planiranje, Aktivan, Pauziran ili Završen;
- **Početak i rok** — projektni datumi;
- **Opis** — obuhvat i važne napomene.

Preporuka je prvo kreirati projekat, zatim podesiti fiber standard i tek onda
unositi mrežne elemente. Ne koristite jedan projekat za nepovezana područja samo
da biste smanjili broj projekata.

### 5.2. Uređivanje i status

Na listi projekata otvorite uređivanje za promjenu osnovnih podataka. Status
koristite dosljedno:

- **Planiranje** — mreža se projektuje i još nije odobrena;
- **Aktivan** — odobren ili tekući projekat;
- **Pauziran** — rad je privremeno zaustavljen;
- **Završen** — podaci su kontrolisani i zaključeni kao izvedeno stanje.

Promjena statusa ne popravlja podatke i ne znači da je validacija prošla.

### 5.3. Fiber postavke projekta

Projekt sadrži izbor rasporeda fiber kabla, standard boja i rezervu po tubi.
Ove postavke utiču na fiber šemu i prikaz raspoloživih vlakana. Mijenjajte ih
samo kada znate stvarnu specifikaciju kabla.

### 5.4. Pregled jednog projekta

Stranica projekta prikazuje:

- ključne pokazatelje projekta;
- kontrolu kvaliteta sa greškama i upozorenjima;
- kapacitet ODF-a;
- trase i krakove;
- izračun materijala sa rezervom;
- popunjenost ODO ormarića.

Klik na problem validacije vodi do odgovarajućeg elementa na mapi. Greške treba
riješiti prije upozorenja; upozorenje može biti prihvatljivo samo kada je stvarno
stanje provjereno i dokumentovano.

### 5.5. Izvoz projekta

Dostupni formati imaju različitu namjenu:

- **DXF** — razmjena sa CAD/geodetskim alatima u Gauss–Krüger koordinatama;
- **GeoJSON** — GIS razmjena;
- **Print** — štampani projektni pregled;
- **JSON backup** — potpuna sigurnosna kopija za vraćanje u FTTH Manager.

DXF podloge mogu biti sačuvane lokalno u browseru. Ako export prijavi da podloga
nema cache ključ, ponovo je učitajte u DXF panelu na istom računaru/browseru.

### 5.6. Brisanje projekta

Brisanje projekta je rizična operacija jer uklanja podatke koji mu pripadaju.
Prije brisanja preuzmite JSON backup i provjerite naziv projekta u dijalogu za
potvrdu. Brisanje nije metoda za čišćenje pojedinačne pogrešne trase.

## 6. Verzije projekta i backup

Postoje dvije različite zaštite:

- **Verzija/snapshot** — brza interna tačka povratka unutar aplikacije;
- **JSON backup** — datoteka izvan aplikacije za arhivu ili potpuni restore.

Na mapi izaberite **Verzije**, unesite opis (npr. „Prije TXT uvoza“) i kliknite
**Sačuvaj verziju**. Čuva se do posljednjih deset verzija. Restore snapshot-a
zamjenjuje trenutno stanje projekta stanjem iz odabrane verzije.

JSON backup preuzmite sa stranice Projekti. Za vraćanje koristite **Vrati backup**
i odaberite isključivo JSON koji je napravio FTTH Manager. Backup čuvajte na
drugom disku ili sigurnoj mrežnoj lokaciji, ne samo u Downloads folderu.

Preporučeni trenuci za backup:

- prije prvog uvoza podataka;
- prije novog TXT/DXF/GIS uvoza;
- prije automatskog planiranja;
- prije masovnog brisanja ili popravke ruta;
- nakon završene i provjerene projektne faze.

## 7. ODF evidencija

U **ODF** listi možete kreirati, pregledati, urediti i obrisati ODF lokacije.
Osnovna polja su projekat, naziv, adresa, kapacitet vlakana, broj portova,
koordinate i napomena.

Pravila dobrog unosa:

- naziv mora biti stabilan i jedinstven unutar projekta;
- kapacitet i portovi moraju odgovarati ugrađenoj/opredijeljenoj opremi;
- koordinate unesite samo ako su poznate, po mogućnosti preko mape ili uvoza;
- ODF povežite samo sa elementima istog projekta.

Pomjeranje ODF-a na mapi mijenja njegovu koordinatu i može uticati na geometriju
i logiku povezanih ruta. Nakon pomjeranja pokrenite provjeru projekta.

## 8. ODO/FTTH ormarići

U **Ormarići** se vode distribucijski ormarići. Ormarić pripada projektu i može
biti napajan direktno iz ODF-a ili iz roditeljskog ODO ormarića. Evidentiraju se
naziv, adresa, broj splittera, portovi po splitteru, koordinate i veze.

Uobičajeni kapacitet je ograničen konfiguracijom ormarića; interfejs prikazuje
popunjenost. Ne dodjeljujte nove kuće punom ormariću. Ako je ormarić serijski
vezan iza drugog ormarića, postavite roditeljski ODO umjesto direktne ODF veze.

Opcija povezivanja kuća sa ormarićem koristi se samo kada fizička pripadnost
kuća tom ormariću odgovara stvarnom projektu. Poslije izmjene pregledajte drop
rute i kapacitete splittera.

## 9. Kuće i priključci

Kuća ima projekat, oznaku, adresu, opcionalno dodijeljeni ODO, koordinate i status:

- **Planirana** — priključak još nije izveden;
- **Spojena** — priključak je realizovan;
- **Otkazana** — priključak se više ne planira.

Oznaka kuće treba biti stabilna jer se koristi u izvještajima i fiber šemi.
Koordinata predstavlja stvarnu kuću/priključnu tačku, ne tačku na glavnom rovu.
Jedna kuća smije pripadati samo odgovarajućem projektu i ODO-u.

Ako kuću pomjerite, provjerite drop trasu. Sama promjena markera ne garantuje da
je geometrija postojeće trase automatski ispravna.

## 10. Trase

Trasa sadrži geometriju i tehničke podatke:

- projekat, naziv, početnu i krajnju vezu;
- tip: glavni rov, backbone, feeder/primarna, distribucijska ili drop;
- način polaganja: podzemno ili zračno;
- dužinu mikrocijevi i kabla;
- broj i profil mikrocijevi (`14/10` ili `10/8`);
- broj vlakana (npr. 4F, 12F, 24F, 48F);
- status i napomenu.

**Glavni rov** opisuje iskop. **Feeder/primarna** povezuje ODF sa distribucijom,
**distribucijska** vodi prema ODO-ima, a **drop** završava na kući. Ne koristite
drop tip za zajednički rov više korisnika.

Trase se mogu:

- ručno kreirati u tabeli ili nacrtati na mapi;
- uređivati i brisati;
- geometrijski pomjerati;
- podijeliti na odabranoj tački;
- spojiti kada stvarno predstavljaju jednu kontinuiranu dionicu;
- uvesti iz DXF-a.

Spajanje i dijeljenje mijenja topologiju. Napravite snapshot i poslije operacije
provjerite početak/kraj, dužine, tip, mikrocijevi i veze sa ODF/ODO/kućom.

## 11. Krakovi mreže

Krak grupiše povezani dio mreže radi preglednosti, redoslijeda i izvještavanja.
Može se kreirati, preimenovati, urediti, obrisati i promijeniti mu redoslijed.
Krak nije zamjena za fizičku trasu: trase nose geometriju i tehničke podatke,
dok krak daje logičku organizaciju mreže.

Koristite dosljedno imenovanje, npr. `KRAK-01 — Centar`, i ne mijenjajte
redoslijed bez razloga ako se taj redoslijed koristi u dokumentaciji.

## 12. Mapa — osnovna organizacija

Mapa je glavni prostorni editor. Prije rada izaberite aktivni projekat u filteru.
Filter **Svi projekti** služi za pregled; za unos i izmjene sigurnije je raditi sa
jednim aktivnim projektom.

Na mapi postoje:

- gornja CAD alatna traka;
- brzi vertikalni alati za elemente;
- bočni panel sa konfiguracijom, slojevima i podacima elementa;
- paneli za DXF, geodetske tačke i verzije;
- prikaz statusa snimanja.

Klik na postojeći element otvara njegove podatke. Prije izmjene provjerite naziv
projekta i tip elementa.

## 13. Mapa — selekcija i transformacije

**Selekt** bira jedan ili više elemenata. Povlačenjem pravougaonika možete
obuhvatiti više elemenata. Nad selekcijom su dostupni:

- **Kopiraj (`K`)** — pravi kopiju odabranog;
- **Pomjeri (`P`)** — premješta selekciju;
- **Rotiraj (`V`)** — rotira oko referentne tačke;
- **Zrcali (`Z`)** — preslikava geometriju;
- **Skaliraj (`S`)** — mijenja razmjer;
- **Niz (`N`)** — pravi ponovljene kopije;
- **Poravnaj** — poravnava odabrane elemente;
- **Obriši** — briše selektovane elemente nakon potvrde.

Transformacije koristite oprezno na stvarnim mrežnim elementima. One su korisne
za CAD podloge i planiranje, ali ne smiju zamijeniti izmjerene koordinate izvedene
mreže. `Ctrl+Z` i `Ctrl+Y` koriste Undo/Redo dok je historija dostupna.

## 14. Crtanje trase na mapi

1. Izaberite aktivni projekat.
2. Izaberite alat **Trasa**.
3. Odredite početnu vezu ili ostavite automatsko/bez veze.
4. Izaberite tip trase, polaganje, mikrocijev, broj vlakana i naziv.
5. Klikajte tačke redom duž stvarne trase.
6. Dvostrukim klikom završite crtanje.
7. Pregledajte podatke i izaberite **Sačuvaj na mapi**.

Korisne tipke:

- `Esc` — prekid trenutne operacije;
- `Backspace` — uklanjanje posljednje tačke tokom crtanja;
- `V` — selekcija (kada transformacijski alat nije aktivan);
- `D` — crtanje trase;
- `Ctrl+Z` / `Ctrl+Y` — poništi/ponovi.

**Radna verzija** čuva nacrt koji još nije konačno upisan kao mrežni element.
Nemojte je zamijeniti sa projektnim snapshotom; snapshot čuva stanje cijelog
projekta.

## 15. Uređivanje trase na mapi

Selektujte trasu i otvorite panel njenih atributa. Možete urediti naziv, tip,
profil i broj mikrocijevi, broj vlakana, način polaganja i napomenu. Opcija
**Uredi geometriju** omogućava pomjeranje čvorova trase.

Nakon uređivanja:

1. sačuvajte geometriju/podatke;
2. provjerite da krajevi i dalje dodiruju odgovarajući ODF, ODO ili kuću;
3. provjerite dužinu;
4. pokrenite validaciju projekta.

## 16. Dodavanje elemenata na mapi

Brzi alati omogućavaju dodavanje ODF-a, ODO-a, kuće i šahta klikom na mapu.
Koordinate se popunjavaju iz mjesta klika, a zatim unosite ostale podatke.

Za ODF unesite projekat, naziv, adresu, fiber kapacitet i portove. Za ODO unesite
projekat, povezani ODF ili roditeljski ODO, naziv, adresu, splittere i portove.
Za kuću unesite stabilnu oznaku/adresu i pripadajući ODO kada je poznat.

Ne dodajte dva markera za isti fizički objekat. Ako element postoji, uredite ili
pomjerite postojeći zapis.

## 17. Prikaz trase i slojeva

Alati prikaza uključuju:

- **Boja F** — bojenje trasa prema broju vlakana;
- **Paralelno** — razdvojeni prikaz paralelnih mikrocijevi;
- **Specs** — prikaz tehničkih specifikacija kabla;
- slojeve sa uključivanjem/isključivanjem, providnošću i zaključavanjem;
- **Goto** — skok na zadane koordinate;
- prošireni prikaz mape;
- štampu/izvoz prikaza.

Zaključan sloj štiti od slučajne selekcije/izmjene. Smanjena providnost mijenja
samo prikaz, ne podatke. **Očisti trag** uklanja privremeni prikaz, ne mora značiti
brisanje trajnih mrežnih elemenata.

## 18. DXF podloga i DXF uvoz

Postoje dvije povezane, ali različite funkcije:

- **DXF panel na mapi** — učitava CAD podlogu za vizuelni rad;
- **DXF import trase** — pokušava pretvoriti podržane DXF linije u trase projekta.

Kod podloge provjerite koordinatni sistem, mjerilo i položaj prema poznatoj
kontrolnoj tački. Podloge se mogu čuvati u lokalnom browser cacheu; zbog toga se
na drugom računaru možda moraju ponovo učitati.

Prije pretvaranja DXF linija u mrežu napravite snapshot. Nakon uvoza provjerite
projekat, duplikate, tipove, veze, dužine i sve neočekivane segmente.

## 19. Geodetski TXT uvoz

Na mapi otvorite **Tačke** i odaberite `.txt` fajl. Aplikacija prvo radi preview.
Provjerite broj tačaka, objekte, trase i sve prijavljene greške. Dugme za potvrdu
koristite tek kada je preview ispravan.

Panel prikazuje ranije uvezene TXT batcheve:

- **Obriši samo odabrani TXT fajl** uklanja podatke tog importa;
- **Obriši SVE TXT uvoze** uklanja sve geodetski uvezene podatke projekta.

Druga opcija je destruktivna. Prije nje obavezno napravite JSON backup.
Aplikacija automatski pravi snapshot prije potvrđenog TXT uvoza, ali se ne treba
oslanjati samo na jednu vrstu zaštite.

Potpuni standard za snimanje i TXT format nalazi se u
[uputstvu za geodetski TXT](UPUTSTVO-ZA-GEODETSKI-TXT-FTTH.txt).

## 20. Terensko GPS snimanje iz aplikacije

U panelu **Tačke** dostupno je direktno snimanje:

1. dozvolite browseru pristup lokaciji;
2. izaberite **Očitaj trenutnu GPS lokaciju**;
3. sačekajte stabilno očitanje i pregledajte prikazanu tačnost;
4. izaberite tip tačke (rov, ODO, ODF, šaht, spojnica, kuća/priključak,
   rezerva, bušenje, stub ili ostalo);
5. unesite naziv/šifru i terensku napomenu;
6. po potrebi dodajte JPEG/PNG/WebP fotografiju;
7. sačuvajte GPS tačku.

Lokacija telefona nije automatski geodetsko mjerenje. Za izvedeno stanje i
ugovorenu tačnost koristite odgovarajući GNSS instrument i ovlaštenog geodetu.
Fotografija treba prikazivati relevantan objekt, oznaku i okolinu, bez osjetljivih
ličnih podataka koji nisu potrebni projektu.

## 21. GIS slojevi i automatsko planiranje

U **Postavke → GIS slojevi** učitava se GeoJSON za izabrani projekat. Podržani
tipovi su ceste/putevi, dozvoljeni koridor, trotoar/ivica puta i zabranjena zona.
Podržane geometrije uključuju LineString, MultiLineString i Polygon.

Opcija **Zamijeni postojeći sloj** prvo uklanja prethodne podatke istog tipa.
Provjerite projekat i tip sloja prije potvrde.

Na mapi:

- **Predloži FTTH** izračunava prijedlog rasporeda ODO ormarića;
- **GIS plan** pravi preview mreže prema dostupnim GIS pravilima;
- **Potvrdi raspored/Snimi GIS mrežu** trajno upisuje pregledani prijedlog.

Automatski rezultat je prijedlog, ne konačna odluka. Prije potvrde pregledajte
prepreke, vlasništvo, stvarne koridore, kapacitete, maksimalne udaljenosti i
izvodljivost na terenu.

## 22. Splitteri i fiber šema

**Splitteri** prikazuju kapacitet po ODO-u: raspoložive i zauzete izlaze te
pripadajuće kuće. Ako je popunjenost nelogična, prvo provjerite dodjelu kuća i
konfiguraciju ormarića.

**Fiber šema** daje logički prikaz vlakana kroz projekat prema odabranom fiber
rasporedu, standardu boja i rezervi. Dostupni su PDF i DXF izvozi za projekat.
Prije izvoza provjerite:

- ODF kapacitet i portove;
- ODO veze i roditeljske ormariće;
- broj vlakana na trasama;
- dodjelu kuća i splittera;
- standard boja projekta.

Fiber šema je tačna koliko su tačni izvorni mrežni podaci.

## 23. Izvještaji i Prilog 3

Stranica **Izvještaji** prikazuje projektne statistike i otvara dokumente po
projektu. **Prilog 3** sadrži segmente kablovske kanalizacije i rekapitulaciju.
Stavke priloga mogu se dopuniti ili ukloniti kada dokumentacija zahtijeva ručnu
stavku koja nije nastala iz trase.

Prije štampe:

1. pokrenite provjeru projekta;
2. izračunajte materijale;
3. provjerite dužine i jedinice;
4. pregledajte ručne stavke;
5. koristite **Print dokument** i pregled prije štampe/PDF-a.

## 24. Materijali

Materijali se računaju iz stvarnih elemenata projekta uz definisani procenat
rezerve. Izračun može uključivati rov, mikrocijevi, kablove i pripadajuću opremu.

Ako je rezultat previsok ili prenizak, ne ispravljajte samo konačan broj. Prvo
provjerite duple trase, tipove, `counts_as_trench`, broj mikrocijevi, dužine,
vlakna i status elemenata. Pogrešan model mreže daje pogrešan predmjer.

## 25. Provjera projekta

**Provjera projekta** analizira sve projekte ili izabrani projekat. Tipični
problemi su:

- kuća bez ODO ormarića;
- ODO bez ODF-a ili roditeljske veze;
- nepotpuna tehnička svojstva trase;
- nepovezana ili nedostajuća drop ruta;
- prekoračen kapacitet;
- nelogična topologija ili geometrija.

Greška znači da podatak treba ispraviti. Upozorenje znači da ga treba pregledati;
može biti opravdano stvarnim stanjem. Nakon svake ispravke ponovo pokrenite
provjeru, jer jedna promjena može uticati na više elemenata.

Funkcije za audit, popunjavanje ili popravku drop ruta koristite nakon backupa.
Automatska popravka ne može znati stvarnu trasu ako ulazni objekti i veze nisu
tačni.

## 26. Postavke

U postavkama se upravlja:

- prikazom/interfejsom;
- promjenom korisničke lozinke;
- GIS slojevima;
- preuzimanjem sistemskog backupa kada je dostupan.

Postavke prikaza utiču na korisničko iskustvo, dok GIS i backup opcije utiču na
projektne podatke. Prije učitavanja ili zamjene GIS sloja provjerite odabrani
projekat.

## 27. Preporučeni tok novog projekta

1. Kreirajte projekat i popunite metapodatke.
2. Podesite fiber raspored, standard boja i rezervu.
3. Preuzmite početni JSON backup.
4. Dodajte ili uvezite ODF.
5. Učitajte provjerene GIS/DXF podloge ako su potrebne.
6. Dodajte/snimite ODO ormariće i njihove veze.
7. Nacrtajte ili uvezite glavni rov i primarne/distribucijske trase.
8. Dodajte kuće i drop rute.
9. Organizujte krakove.
10. Pregledajte splittere i fiber šemu.
11. Pokrenite validaciju i ispravite sve greške.
12. Izračunajte materijale i pregledajte izvještaje.
13. Sačuvajte završni snapshot i JSON backup.
14. Tek tada označite projekat završenim.

## 28. Preporučeni dnevni tok rada

Na početku:

1. provjerite da ste otvorili pravi projekat;
2. pregledajte obavijesti i posljednju verziju;
3. napravite snapshot prije većeg zahvata.

Tokom rada:

- unosite elemente odmah u odgovarajući projekat;
- koristite dosljedne nazive;
- nakon grananja ili pomjeranja provjerite veze;
- ne ignorišite greške previewa;
- radije ispravite izvorni podatak nego rezultat u više izvještaja.

Na kraju:

1. sačuvajte sve otvorene izmjene;
2. pokrenite provjeru projekta;
3. sačuvajte imenovanu verziju sa opisom rada;
4. periodično preuzmite JSON backup;
5. odjavite se.

## 29. Imenovanje elemenata

Dosljedno imenovanje olakšava pretragu i izvještaje. Primjer standarda:

```text
Projekat: RAINCI-GORNJI-2026
ODF:      ODF RAINCI GORNJI
ODO:      ZO-01, ZO-02, ZO-03
Trasa:    P-01 ODF–ZO-01
Krak:     KRAK-01 CENTAR
Kuća:     H-001, H-002, H-003
```

Ne koristite isti naziv za različite fizičke elemente. Ne mijenjajte naziv samo
zbog drugačijih razmaka, velikih slova ili crtica kada se radi o istom objektu.

## 30. Najčešće greške korisnika

- Rad u filteru **Svi projekti** i unos elementa u pogrešan projekat.
- Crtanje trase bez početne/krajnje veze.
- Miješanje rova, mikrocijevi i kabla kao iste količine.
- Dodjela kuće punom ili pogrešnom ODO-u.
- Pomjeranje markera bez provjere povezane geometrije.
- Potvrda automatskog plana bez vizuelnog pregleda.
- Brisanje svih TXT uvoza umjesto jednog batcha.
- Restore starog snapshota bez prethodnog backupa trenutnog stanja.
- Izvoz izvještaja prije validacije i obračuna materijala.
- Pretpostavka da „Spremno“ ili „Završen“ automatski garantuje tačnost.

## 31. Rješavanje problema

### Mapa ili stilovi nisu učitani

Osvježite stranicu. Administrator treba provjeriti da postoji frontend build i
da su map vendor asseti sinhronizovani.

### Element se ne vidi

Provjerite projektni filter, uključene slojeve, providnost, koordinate i da li je
element izvan trenutnog prikaza. Koristite **Goto** za poznate koordinate.

### Dugme za uvoz nije aktivno

Preview vjerovatno nije završen ili je pronašao blokirajuću grešku. Pregledajte
poruku, ispravite izvorni fajl i ponovite preview.

### Kuća nema drop rutu

Provjerite pripadajući ODO, koordinatu kuće, kontinuitet rova, `-ZO-` oznaku kod
TXT uvoza i da li postoji valjana putanja do ormarića. Zatim koristite provjeru
ili audit drop ruta.

### DXF podloga nedostaje u exportu

Podloga je možda bila sačuvana u drugom browseru ili je lokalni cache uklonjen.
Ponovo učitajte DXF u panelu mape i ponovite export.

### Podaci izgledaju pogrešno poslije uvoza

Ne nastavljajte sa ručnim prepravljanjem velikog broja elemenata. Sačuvajte
dijagnostičke informacije, uklonite samo pogrešni import batch ili vratite
snapshot, ispravite izvorni fajl i ponovite preview.

## 32. Sigurnost i odgovornost

- Aplikacija je vlasnički, zatvoreni softver; pristup i podatke ne dijelite
  neovlaštenim osobama.
- Ne šaljite `.env`, bazu, backup ili terenske fotografije javnim servisima.
- Provjerite projekat prije svake destruktivne operacije.
- Browser ostavite otvoren samo na kontrolisanom računaru.
- Konačne geodetske, građevinske i telekomunikacijske podatke mora potvrditi
  odgovorna stručna osoba.
- Aplikacija pomaže u planiranju i kontroli, ali ne zamjenjuje stručni pregled,
  dozvole, ugovorne zahtjeve niti provjeru izvedenog stanja.

## 33. Završna kontrolna lista projekta

- [ ] Projekat ima ispravan naziv, šifru, lokaciju i status.
- [ ] Fiber standard i rezerva su potvrđeni.
- [ ] Svaki ODF ima ispravan kapacitet i lokaciju.
- [ ] Svaki ODO ima ODF ili roditeljski ODO.
- [ ] Nijedan ODO nije preko dozvoljenog kapaciteta.
- [ ] Svaka kuća ima tačnu oznaku, koordinatu i pripadajući ODO.
- [ ] Sve trase imaju tip, veze, profil/broj mikrocijevi i potrebna vlakna.
- [ ] Rovovi, cijevi i kablovi nisu pogrešno dvostruko obračunati.
- [ ] Sve drop rute dolaze do pravog ODO-a.
- [ ] Krakovi su pravilno imenovani i poredani.
- [ ] Nema neočekivanih duplikata ili prekida geometrije.
- [ ] Provjera projekta nema neriješenih grešaka.
- [ ] Upozorenja su pregledana i opravdana.
- [ ] Materijali, splitteri, fiber šema i Prilog 3 su pregledani.
- [ ] Napravljen je završni snapshot.
- [ ] JSON backup je preuzet i sigurno arhiviran.

