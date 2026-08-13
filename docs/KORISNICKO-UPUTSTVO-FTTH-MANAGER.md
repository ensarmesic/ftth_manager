# FTTH Manager — kompletno korisničko uputstvo

Ovo uputstvo opisuje svakodnevno korištenje FTTH Manager aplikacije: prijavu,
projekte, mrežnu evidenciju, rad na mapi, uvoz podataka, kontrolu kvaliteta,
izvještaje, sigurnosne kopije i preporučeni tok rada.

| Podatak dokumenta | Vrijednost |
|---|---|
| Naziv | FTTH Manager — kompletno korisničko uputstvo |
| Vrsta | Operativna korisnička i funkcionalna dokumentacija |
| Verzija | 2.0 |
| Datum revizije | 13. august 2026. |
| Namjena | Projektovanje, evidencija, kontrola i izvještavanje FTTH mreže |
| Povjerljivost | Vlasnička dokumentacija — nije za neovlaštenu distribuciju |

## Sadržaj dokumentacije

1. **Uvod i pristup** — poglavlja 1–4
2. **Projekti i zaštita podataka** — poglavlja 5–6
3. **Mrežna evidencija** — poglavlja 7–11
4. **Kompletan rad na mapi** — poglavlja 12–20 i 37–38
5. **Auto ODO i GIS planiranje** — poglavlje 21.1–21.16
6. **Analitika, izvještaji i provjera** — poglavlja 22–26 i 40
7. **Standardni radni tokovi** — poglavlja 27–33 i 42–45
8. **Referentni podaci i administracija** — poglavlja 34–36, 39, 41 i 46–47

> Važno: prije većeg uvoza, automatskog planiranja, masovne izmjene ili brisanja
> napravite verziju projekta i preuzmite JSON backup.

## Kako koristiti ovu dokumentaciju

Dokument je namijenjen projektantima, geodetama, operaterima i osobama koje
kontrolišu projekat. Početnik treba prvo pročitati poglavlja 1–6 i 27–30, a zatim
detaljno poglavlje funkcije koju koristi. Iskusni korisnik može koristiti
kontrolne liste i referentne tabele na kraju dokumenta.

Oznake u dokumentu:

- **Preview** — privremeni prikaz prijedloga; još nije konačni rezultat;
- **Potvrda/Snimi** — trajni upis u bazu;
- **Snapshot/verzija** — interna tačka povratka jednog projekta;
- **Backup** — JSON datoteka za vanjsku arhivu i restore;
- **Greška** — problem koji se mora ispraviti;
- **Upozorenje** — problem koji se mora pregledati i opravdati.

Nazivi dugmadi su napisani onako kako se prikazuju u aplikaciji. Funkcija može
izgledati drugačije na uskom ekranu, ali joj je značenje isto.

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

### 21.1. Dva različita automatska procesa

U panelu **Auto raspored FTTH** postoje dvije odvojene funkcije:

| Funkcija | Primarni ulaz | Glavni rezultat nakon potvrde |
|---|---|---|
| **Predloži FTTH** (Auto ODO) | sekundarni krakovi, nepovezane kuće i postojeći ODO-i | novi ili dopunjeni ODO-i i dodjela kuća |
| **GIS plan** | ODF-ovi, nepovezane kuće i GIS/rutni graf | distribucijske trase, krakovi, ODO-i i dodjela kuća |

Ove funkcije nisu dvije verzije istog dugmeta. Auto ODO koristi već nacrtanu
mrežnu strukturu da rasporedi ormariće. GIS plan prvo traži najkraće prohodne
putanje od ODF-a do kuća i iz njih gradi zajedničku distribucijsku mrežu.

### 21.2. Preduslovi za Auto ODO

Prije klika na **Predloži FTTH** potrebno je:

1. odabrati tačan projekat, ne **Svi projekti**;
2. unijeti kuće sa koordinatama;
3. ostaviti nepovezane samo kuće koje treba planirati;
4. postaviti najmanje jedan ODF sa koordinatama;
5. nacrtati distribucijske/sekundarne trase;
6. imati krakove tipa `secondary` povezane sa trasama;
7. provjeriti postojeće ODO-e i njihove krakove/kapacitete;
8. napraviti snapshot i JSON backup.

Bez sekundarnih krakova algoritam prelazi na rezervno geografsko grupisanje.
Takav rezultat može izgledati uredno, ali ne mora pratiti stvarnu mrežu. Zato je
precizno planiranje po krakovima preporučeni i profesionalni način rada.

Ako na mapi postoje nove nesnimljene kuće, krakovi ili nacrtani ODF-i, klik na
**Predloži FTTH** prvo pokušava sačuvati nacrt, a zatim računa prijedlog. Zbog
toga se ovaj korak ne smije tretirati kao potpuno read-only operacija.

### 21.3. Auto ODO parametri

| Polje | Dozvoljeno | Zadano | Značenje |
|---|---:|---:|---|
| **Min** | 1–12 | 8 | preferirani minimalni broj kuća u ODO-u |
| **Max** | 1–12 | 12 | maksimalni broj kuća koje novi ODO smije dobiti |
| **Max m** | najmanje 20 m | 90 m | maksimalna planirana putanja kuća–ODO unutar grupe |

**Min nije čvrsta zabrana.** Ako geografija, kraj kraka ili udaljenost ne
dozvoljavaju bolje grupisanje, prijedlog može imati manje kuća i prikazati
upozorenje o slaboj popunjenosti.

**Max je čvrsti kapacitet grupisanja** i dodatno je ograničen fizičkim modelom
ormarića na najviše 12 kuća (tri splittera × četiri porta).

**Max m** se računa duž planirane putanje. Kada postoji krak, putanja ide od ODO
projekcije po kraku do projekcije kuće, pa od kraka do kuće. To nije nužno ista
vrijednost kao zračna udaljenost između markera.

Interni parametri koje ekran trenutno ne izlaže su:

- maksimalna udaljenost kuće od kraka: zadano 120 m;
- maksimalni razmak kuća po stacionaži prije nove grupe: zadano 100 m;
- maksimalno 12 kuća po ODO-u.

Ako kuća već ima eksplicitno dodijeljen krak, algoritam poštuje taj krak. Ako
nema, traži najbliži sekundarni krak i odbacuje kuću koja je izvan dozvoljene
udaljenosti.

### 21.4. Kako Auto ODO računa prijedlog

Algoritam radi ovim redoslijedom:

1. uzima samo kuće bez `cabinet_id` veze;
2. razdvaja kuće sa i bez koordinata;
3. učitava ODF-ove sa koordinatama;
4. učitava samo valjane sekundarne krakove sa geometrijom; glavni rov se ne
   koristi kao logički krak;
5. kuću dodjeljuje njenom zadanom ili najbližem kraku;
6. računa položaj kuće duž kraka (stacionažu/chainage);
7. prvo pokušava popuniti postojeći ODO istog kraka koji ima slobodne portove i
   nalazi se dovoljno blizu;
8. preostale kuće sortira redom duž kraka;
9. otvara novu grupu kada je dostignut Max, kada je razmak po kraku prevelik ili
   bi najduži drop prešao Max m;
10. bira medoid kuću — stvarnu kuću sa najmanjim ukupnim udaljenjem do ostalih
    kuća grupe;
11. projicira medoid na sekundarnu trasu i tu predlaže ODO;
12. bira najbliži ODF sa koordinatama;
13. računa broj splittera kao `ceil(broj kuća / 4)`, najviše tri;
14. računa prosječnu i maksimalnu drop dužinu, popunjenost, upozorenja i score.

Ako nema krakova, kuće se grupišu geografskom blizinom, uz ograničenje Max.
Položaj ODO-a tada može biti na koordinati medoid kuće jer nema trase na koju bi
se tačka projicirala. Takav prijedlog obavezno ručno doradite.

### 21.5. Kako čitati Auto ODO preview

Na mapi se predloženi ODO prikazuje posebnim markerom. Kuće iste grupe dobijaju
istu boju. Crveno označena kuća nije dodijeljena nijednom kraku/grupi.

Za svaki ODO provjerite:

- naziv i broj kraka;
- broj kuća, npr. `10/12`;
- predloženi broj splittera;
- poziciju markera na stvarno dostupnom mjestu uz trasu;
- najbliži ODF i fizički smisao te veze;
- prosječnu i maksimalnu drop dužinu;
- spisak kuća i njihov redoslijed/stacionažu;
- upozorenja i score.

Preview drop linije služe za procjenu dužina. Kod standardne potvrde Auto ODO
plana one se **ne upisuju kao trajne drop trase**. To je važna razlika između
vizuelnog prijedloga i snimljenih podataka.

### 21.6. Kada odbaciti Auto ODO prijedlog

Nemojte potvrditi plan ako:

- postoji kuća bez grupe;
- ODO je na privatnoj parceli, objektu, vodi ili drugoj nedostupnoj lokaciji;
- kuće različitih stvarnih krakova završavaju u istoj grupi;
- Max m je prekoračen ili je drop putanja nelogična;
- ormarić nema ODF;
- prijedlog koristi rezervno grupisanje i ne prati projektovanu trasu;
- postojeći ODO je pogrešno vezan za krak;
- kapacitet ili broj splittera ne odgovara opremi;
- kuće ili trase još nemaju potvrđene koordinate.

Ispravite izvor: kuću, krak, trasu, ODF ili parametre. Zatim kliknite **Očisti**
i ponovo izračunajte plan. Nemojte potvrditi loš plan s namjerom da kasnije
ručno popravljate desetine izvedenih veza.

### 21.7. Šta radi Potvrdi raspored

Potvrda se izvršava u transakciji: ako se desi greška, sve izmjene tog pokušaja
se poništavaju. Sistem provjerava da:

- svaki prijedlog ima kuće;
- nijedan ODO nema više od 12 kuća;
- grupa ne miješa kuće različitih krakova;
- ista kuća nije u dvije grupe;
- sve kuće i ODF pripadaju istom projektu;
- postojeći ODO ima dovoljno slobodnih portova;
- kuća u međuvremenu nije povezana na drugi ODO.

Nakon uspješne potvrde aplikacija:

- dopuni odgovarajući postojeći ODO ili kreira novi;
- novom ODO-u postavlja četiri porta po splitteru;
- dodjeljuje potreban broj splittera;
- veže ODO na predloženi ODF;
- veže kuće na ODO;
- po potrebi upisuje ODF vezu kraka koji je ranije nije imao;
- daje jedinstven naziv ako predloženi naziv već postoji.

Standardni ekran šalje potvrdu bez opcije `create_drop_routes`, pa potvrda **ne
kreira trajne drop trase**. Njih treba nacrtati, uvesti ili naknadno popuniti
odgovarajućom kontrolisanom funkcijom.

### 21.8. Kontrola odmah nakon Auto ODO potvrde

1. Otvorite **Ormarići** i provjerite broj novih/dopunjenih ODO-a.
2. Otvorite svaki ODO i provjerite ODF, krak, splittere i kuće.
3. Na mapi provjerite fizičku lokaciju svakog ODO-a.
4. Otvorite **Splitteri** i provjerite zauzeće.
5. Pokrenite **Provjera projekta**.
6. Provjerite kuće bez drop ruta.
7. Nacrtajte ili uvezite stvarne drop trase.
8. Napravite snapshot „Poslije Auto ODO — kontrolisano“ tek nakon pregleda.

### 21.9. Preduslovi za GIS plan

GIS plan zahtijeva:

- najmanje jedan ODF sa koordinatama;
- nepovezane kuće sa koordinatama;
- prohodan graf sastavljen od dozvoljenih GIS linija ili postojećih nedrop ruta;
- ispravno učitane zabranjene zone ako se koriste;
- slobodan kapacitet ODF-a;
- backup prije potvrde.

Planner obrađuje samo kuće bez ODO veze. Ekran trenutno traži do 120 takvih kuća
po pokretanju. Ako ih ima više, proces se ponavlja inkrementalno nakon kontrole
prvog rezultata.

### 21.10. Izvor GIS/rutnog grafa

Redoslijed izvora je:

1. dozvoljeni GIS segmenti projekta tipa `road`, `corridor` ili `sidewalk`;
2. ako njih nema, postojeće nedrop trase projekta;
3. ako nema ni jednog izvora, ruta se ne može izračunati.

Čvorovi grafa udaljeni do 2 m mogu se povezati radi uklanjanja malih geometrijskih
praznina. Početna i krajnja tačka se projiciraju na najbliži segment. Najkraći
put se računa Dijkstra algoritmom prema stvarnim dužinama segmenata.

Kod GIS izvora, segment se isključuje ako ulazi u, izlazi iz, prolazi sredinom
ili presijeca poligon zabranjene zone. Kod fallbacka na postojeće mrežne trase
zabranjene GIS zone se ne primjenjuju na isti način; zato provjerite prikazani
`graph source` i rezultat na mapi.

### 21.11. Kako GIS plan bira ODF

Za svaku kuću računa se moguća najkraća ruta od svakog ODF-a. U pravilu se bira
najkraća dostupna ruta, ali algoritam vodi računa o preostalom ODF kapacitetu.
Ako najbliži ODF nema dovoljno mjesta, kuća može biti usmjerena na drugi ODF i
označena kao kapacitetom prisiljena dodjela.

Kapacitet se procjenjuje kroz fiber kapacitet i već korištene splittere. Zbog
toga pogrešan kapacitet ODF-a ili splitteri postojećih ODO-a daju pogrešan izbor.

### 21.12. Kako GIS plan gradi mrežu i ODO-e

1. računa najkraću ODF–kuća putanju;
2. spaja identične zajedničke dionice u jedinstvene mrežne segmente;
3. za svaki segment bilježi koje kuće ga koriste;
4. računa zbir pojedinačnih ruta i jedinstvenu dužinu mreže;
5. kuće grupiše po ODF-u, sortira prema dužini rute i dijeli u grupe do 12;
6. ODO stavlja na završnu tačku medijalne rute grupe;
7. računa drop preview od ODO-a do svake kuće kroz graf;
8. dodjeljuje jedan splitter na svaka četiri korisnika;
9. računa kvalitet plana i upozorenja.

Score počinje od 100 i smanjuje se zbog dugih prosječnih dropova, upozorenja i
kuća bez rute, a može se blago povećati zbog dobre popunjenosti ODO-a. Score je
alat za poređenje varijanti, ne potvrda izvodljivosti.

### 21.13. Kako čitati GIS preview

Sažetak prikazuje:

- **score / 100** — heurističku kvalitetu;
- **routed/total houses** — koliko kuća ima putanju;
- **ODF used/total** — koliko ODF-ova učestvuje;
- **ODO count** — broj predloženih ormarića;
- **Rov / unique network m** — jedinstvenu dužinu mrežnih segmenata;
- **average/max drop** — prosječnu i najveću drop dužinu;
- **ODO utilization** — prosječnu popunjenost;
- upozorenja i kuće bez rute.

Deblja/tamnija linija na previewu znači da segment koristi više kuća. Klik na
segment prikazuje dužinu i korisnike. Posebno pregledajte:

- drop duži od 120 m;
- ODO popunjen manje od 50%;
- kapacitetom prisiljene ODF dodjele;
- ODF prekoračenje ili visoku popunjenost;
- kuće bez GIS rute;
- segmente koji vizuelno sijeku prepreku ili privatnu parcelu.

### 21.14. Šta radi Snimi GIS mrežu

Potvrda ponovo računa plan za trenutno stanje projekta, pa rezultat može biti
drugačiji ako su podaci izmijenjeni između previewa i potvrde. Zatim u jednoj
transakciji:

- kreira jedinstvene distribucijske trase;
- za svaku trasu kreira/sinhronizuje mrežni krak;
- postavlja podzemno polaganje, `14/10`, jednu mikrocijev i status Planirano;
- dužinu mikrocijevi i kabla postavlja na dužinu segmenta;
- bira 4F za opterećenje do 4, 12F do 12, 24F do 24, inače 48F;
- kreira ODO-e `GIS ODO N` sa najviše 12 kuća;
- postavlja četiri porta po splitteru;
- veže kuće na nove ODO-e.

GIS potvrda **ne kreira zasebnu trajnu drop trasu za svaku kuću**. Kreira
zajedničke distribucijske segmente i ODO veze. Dropove nakon toga treba provjeriti
i evidentirati posebnim tokom.

### 21.15. Kontrola nakon GIS potvrde

1. Uporedite broj kreiranih trasa i ODO-a sa previewom.
2. Provjerite da nijedna kuća nije ostala bez rute ili ODO-a.
3. Pregledajte svaki novi `GIS krak` i `GIS ODO` na mapi.
4. Preimenujte elemente prema projektnom standardu tek nakon potvrde položaja.
5. Provjerite da su trase stvarno distribucijske, podzemne i `14/10` gdje to
   odgovara projektu; ručno ispravite opravdane izuzetke.
6. Provjerite fiber count koji je izabran prema opterećenju.
7. Dodajte stvarne drop trase.
8. Pokrenite validaciju, materijale, splittere i fiber šemu.
9. Sačuvajte novu verziju i backup.

### 21.16. Auto ODO ili GIS plan — odluka

Koristite **Auto ODO** kada već imate pouzdane sekundarne trase/krakove i želite
rasporediti kuće u ormariće. Koristite **GIS plan** kada imate kvalitetan GIS
koridor i želite da aplikacija predloži i zajedničku distribucijsku mrežu.

Ne pokrećite oba procesa uzastopno nad istim nepovezanim kućama bez kontrole.
Prvi potvrđeni proces dodjeljuje kućama ODO, pa ih drugi više neće uključiti.
Ako želite porediti varijante, napravite snapshot, izračunajte previewe bez
potvrde, dokumentujte rezultate i potvrdite samo odabranu varijantu.

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

## 34. Referentni model podataka

Razumijevanje veza sprečava većinu grešaka:

| Element | Obavezno pripada | Može biti povezan sa | Ključni podaci |
|---|---|---|---|
| Projekat | — | svi mrežni elementi | šifra, lokacija, status, fiber postavke |
| ODF | projektom | ODO-ima i trasama | kapacitet, portovi, koordinata |
| ODO | projektom | ODF-om ili roditeljskim ODO-om, krakom, kućama | splitteri, portovi, koordinata |
| Kuća | projektom | jednim ODO-om, krakom i drop trasom | oznaka, adresa, koordinata, status |
| Trasa | projektom | ODF/ODO/kućom i krakom | geometrija, tip, cijevi, vlakna, dužine |
| Krak | projektom | trasom, ODF-om, ODO-ima i kućama | naziv, šifra, tip, redoslijed |
| Survey tačka | projektom/import batchom | izvedenim elementom | broj, GK i WGS84 koordinate, opis |
| Snapshot | projektom | kompletnim stanjem projekta | oznaka, vrijeme, korisnik |

### 34.1. Granice projekta

Aplikacija validira da plan ne koristi kuću ili ODF iz drugog projekta. Ipak,
korisnik mora paziti na projektni filter kod ručnog unosa, importa i izvoza.
Elementi s istim nazivom u dva projekta nisu isti objekt.

### 34.2. Veza ODF–ODO

ODO mora imati ili direktni `odf_id` ili `parent_cabinet_id`. Direktna veza znači
da se napaja iz ODF-a. Roditeljska veza znači serijsko napajanje iz drugog ODO-a.
Nemojte postaviti obje logike samo radi uklanjanja upozorenja; veza mora pratiti
stvarnu fiber topologiju.

### 34.3. Veza kuća–ODO

`cabinet_id` određuje kojem ODO-u kuća pripada i koristi se u kapacitetima,
splitterima i automatskom planiranju. Geometrijska blizina markera sama po sebi
ne znači formalnu vezu.

### 34.4. Veza trase i krajeva

Trasa može imati početni i krajnji tip/ID. Geometrija i formalna veza trebaju se
slagati: linija koja vizuelno završava kod ODO-a, ali nema odgovarajući kraj,
može proizvesti netačnu topologiju i izvještaj.

## 35. Statusi i njihovo značenje

### 35.1. Status projekta

| Status | Kada se koristi | Šta ne znači |
|---|---|---|
| Planiranje | projekt se izrađuje | da je mreža odobrena |
| Aktivan | rad/izgradnja je u toku | da nema grešaka |
| Pauziran | rad je privremeno obustavljen | da su podaci zaključani |
| Završen | završna kontrola je provedena | automatsku pravnu/geodetsku ovjeru |

### 35.2. Status trase

| Status | Značenje |
|---|---|
| Planirano | projektovana, nije potvrđena kao izvedena |
| U toku | izvođenje je započeto |
| Izgrađeno | izvedeno stanje je potvrđeno odgovarajućom dokumentacijom |

### 35.3. Status kuće

Planirana, Spojena i Otkazana kuća ostaju dio evidencije. Otkazanu kuću ne treba
brisati ako je važno sačuvati historiju odluke.

## 36. Tehnička polja trase — detaljno

### 36.1. `route_type`

- `trench` — fizički rov i količina iskopa;
- `backbone` — glavna optička okosnica;
- `feeder` — primarna veza ODF–distribucija;
- `distribution` — distribucija prema ODO-ima;
- `drop` — pojedinačni završni priključak.

### 36.2. `installation_type`

- `underground` — podzemno polaganje;
- `aerial` — zračno/nadzemno polaganje.

Način polaganja utiče na materijale i tumačenje trase. Ne označavajte zračnu
trasu kao podzemnu samo zato što prati istu liniju na mapi.

### 36.3. Dužine

- **Dužina geometrije** — izračun iz tačaka na mapi;
- **Dužina rova** — stvarna količina iskopa;
- **Mikrocijev m** — zbir dužina svih relevantnih cijevi;
- **Kabl m** — stvarno planirana/ugrađena dužina kabla, uključujući opravdanu
  rezervu kada je model tako definisan.

Primjer: kroz 100 m zajedničkog rova prolaze tri mikrocijevi. Rov može biti
100 m, dok je ukupna količina mikrocijevi 300 m. Ne množite rov brojem cijevi.

### 36.4. Mikrocijev i vlakna

`microduct_count` je broj fizičkih cijevi na dionici, a `microduct_type` njihov
profil. `fiber_count` opisuje kapacitet kabla, ne broj trenutno aktivnih korisnika.
Prazna mikrocijev može imati nultu/nepostojeću dužinu kabla dok se kabl ne planira
ili ugradi.

## 37. Plan projekta na mapi — detaljan tok

Panel **Plan projekta** vodi kroz četiri koraka: ODF, Trasa, Kuće i FTTH.

### 37.1. Aktivni projekat i aktivni ODF

Aktivni projekat određuje gdje će nacrt biti sačuvan. Aktivni ODF određuje na
koji se ODF novi nacrtani ODO veže. Lista može sadržavati već snimljene ODF-ove i
ODF-ove iz trenutnog nacrta. Status ispod liste jasno prikazuje aktivnu vezu.

Ako aktivni ODF nije izabran, ODO može ostati bez veze i validacija će prijaviti
upozorenje. Prije postavljanja serije ODO-a uvijek provjerite aktivni ODF.

### 37.2. Postavke trase

- **Početak trase** — automatski/bez veze, ODF ili ODO;
- **Tip** — glavni rov, primarni ili sekundarni;
- **Mikrocijevi** — broj cijevi;
- **Polaganje** — podzemno ili zračno;
- **Mikrocijev** — 14/10 ili 10/8;
- **Niti** — 4F, 12F, 24F ili 48F;
- **Oznaka** — naziv poput `P-01` ili `S-01`.

Prvo nacrtajte glavni rov, a zatim logičke mikrocijevi/krakove kada dijele isti
koridor. Tip trase određuje da li se dionica računa kao rov.

### 37.3. Prati ulice (OSM)

Opcija pokušava usmjeriti novo crtanje prema uličnom routing servisu. Rezultat
zavisi od dostupnosti mreže i kvaliteta OSM podataka. OSM put nije dokaz prava
prolaza, izvedivosti iskopa niti tačne pozicije infrastrukture.

### 37.4. Interni GIS graf (BETA)

Ova opcija koristi interne GIS segmente/projektne rute. Oznaka BETA znači da se
svaka putanja mora posebno provjeriti. Ako graf nije povezan ili podaci nisu
potpuni, putanja može izostati ili koristiti neželjeni koridor.

### 37.5. Radna verzija i Sačuvaj na mapi

**Radna verzija** čuva nacrt radi nastavka rada. **Sačuvaj na mapi** pokreće
preflight i trajni upis plana. Preflight panel navodi elemente koje treba doraditi.
Ako su prikazane greške, vratite se na element, ispravite naziv, vezu ili podatke
i tek onda ponovite spremanje.

Po uspješnom spremanju pojavljuju se izvozi GeoJSON, DXF i Print za aktivni
projekat.

## 38. Ručno uređivanje nacrtanih elemenata

Klik na draft ili trajni ODF/ODO/kuću otvara editor elementa.

### 38.1. ODF editor

Provjerava pozitivan cijeli broj fiber kapaciteta i portova. Kod trajnog elementa
izmjena se odmah šalje u bazu nakon **Sačuvaj podatke**.

### 38.2. ODO editor

Broj splittera mora biti 1–3, a portova po splitteru 1–4. Može se izabrati
snimljeni ili draft ODF. Ukupni kapacitet je proizvod ova dva broja.

### 38.3. Kuća editor

Naziv/oznaka je obavezna. Adresa je opcionalna, ali preporučena. Kod trajne kuće
čuvaju se i postojeća ODO veza i status, osim kada ih namjerno promijenite drugim
kontrolama.

### 38.4. Draft naspram trajnog elementa

Draft postoji u trenutnom planu i može nestati ako se nacrt odbaci. Trajni
element ima ID u bazi. Poruka editora govori da li se čuva nacrt ili trajna
izmjena. Prije zatvaranja panela pročitajte potvrdu spremanja.

## 39. Import/export matrica

| Format | Ulaz | Izlaz | Namjena | Glavni rizik |
|---|---|---|---|---|
| TXT | da | ne | geodetske tačke i rekonstrukcija mreže | pogrešan opis/redoslijed |
| DXF | da | da | CAD podloga, trase i razmjena | koordinatni sistem/slojevi |
| GeoJSON | da | da | GIS koridori i mrežni podaci | pogrešan tip/projekat |
| JSON backup | restore | da | potpuni FTTH Manager backup | zamjena trenutnog stanja |
| PDF/Print | ne | da | dokumentacija i izvještaji | zastarjeli/nevalidirani podaci |

Nikada ne koristite export format kao zamjenu za backup osim JSON backupa koji
je napravila sama aplikacija. DXF i GeoJSON mogu izgubiti aplikacijske veze,
kapacitete, audit i druge domenske podatke.

## 40. Validacija — profesionalno tumačenje

Validacija provjerava konzistentnost podataka koje aplikacija može dokazati.
Primjeri:

- ODF/ODO/kuća bez koordinata;
- ODO bez napajanja;
- više od 12 kuća ili neispravni splitteri;
- kuća udaljena više od 120 m od ODO-a;
- kuća više od 60 m od kraka;
- ODO više od 10 m od pripadajućeg kraka;
- kuća i ODO na različitim krakovima;
- nedostajući tehnički podaci trase;
- visoka ili prekoračena iskorištenost ODF-a.

Validacija ne može dokazati:

- vlasništvo i dozvolu prolaza;
- stvarnu dubinu rova;
- tačnost geodetskog instrumenta;
- postojanje prepreke koja nije u GIS-u;
- da je kabl zaista ugrađen;
- da naziv na terenu odgovara etiketi u aplikaciji.

Zato je profesionalno prihvatanje projekta kombinacija automatske validacije,
vizuelne kontrole, terenskih dokaza i potpisa odgovorne osobe.

## 41. Audit i trag izmjena

Aplikacija bilježi mutacije i polja koja su mijenjana, bez snimanja lozinki u
metadata zapis. Audit pomaže utvrditi šta se desilo, ali nije zamjena za backup.

Za pouzdan audit:

- svaki korisnik koristi vlastiti račun;
- ne dijelite sesiju ili lozinku;
- snapshotu dajte smislen naziv;
- veće uvoze i automatske planove radite u odvojenim, imenovanim fazama;
- u napomenu elementa upišite razlog ručnog odstupanja od plana.

## 42. Procedura sigurne izmjene projekta

Za svaku veću izmjenu koristite ovaj SOP:

1. **Identifikacija** — zapišite projekat, cilj i elemente koji se mijenjaju.
2. **Početna kontrola** — pokrenite validaciju i zabilježite trenutno stanje.
3. **Zaštita** — napravite snapshot i JSON backup.
4. **Preview** — gdje postoji, prvo koristite preview bez potvrde.
5. **Pregled** — provjerite mapu, brojeve, veze, upozorenja i obuhvat.
6. **Potvrda** — izvršite jednu logičku grupu izmjena.
7. **Kontrola rezultata** — ponovo validirajte i uporedite KPI/materijale.
8. **Dokumentovanje** — sačuvajte novu verziju sa opisom.
9. **Arhiva** — čuvajte rezultat i backup prema pravilima organizacije.

Ne spajajte u jednom koraku TXT uvoz, GIS plan, ručno brisanje i preimenovanje.
Odvojene faze olakšavaju otkrivanje i vraćanje greške.

## 43. Procedura oporavka od pogrešne izmjene

### 43.1. Pogrešan TXT batch

U panelu Tačke odaberite tačno taj import i izbrišite samo njega. Ponovo
validirajte. Ne koristite „Obriši SVE“ ako je problem u jednom fajlu.

### 43.2. Pogrešan Auto ODO/GIS plan

Ako je upravo potvrđen i mnogo elemenata je pogođeno, restore prethodnog
snapshota je sigurniji od ručnog pojedinačnog brisanja. Prije restorea preuzmite
backup trenutnog stanja radi analize.

### 43.3. Pogrešna pojedinačna izmjena

Uredite element nazad prema potvrđenom izvoru, zatim pokrenite validaciju. Undo
na mapi nije dugoročna historija i nije dostupan nakon svakog refresh-a.

### 43.4. Restore JSON backupa

Koristite kada interni snapshot nije dostupan ili se projekat vraća iz vanjske
arhive. Provjerite naziv, datum i izvor datoteke. Nakon restorea pregledajte broj
svih elemenata i pokrenite validaciju prije nastavka rada.

## 44. Kontrola kvaliteta po ulozi

### Projektant

- potvrđuje topologiju, kapacitete, tipove trasa i automatske prijedloge;
- odlučuje o prihvatljivim upozorenjima;
- odobrava završni materijal i fiber šemu.

### Geodeta/terenska ekipa

- potvrđuje koordinatni sistem, tačnost, položaj i opise;
- čuva originalna mjerenja i terenski zapisnik;
- ne mijenja projektni kapacitet bez odobrenja projektanta.

### Operater aplikacije

- radi u pravom projektu;
- poštuje proceduru preview–backup–potvrda–validacija;
- ne nagađa nedostajuće tehničke podatke.

### Kontrolor/odgovorna osoba

- pregledava greške i upozorenja;
- poredi aplikaciju sa izvorima i terenom;
- odobrava završni status i arhivu.

Jedna osoba može obavljati više uloga, ali kontrole i dalje treba izvršiti kao
odvojene korake.

## 45. Kriteriji za prihvatanje automatskog plana

Auto ODO ili GIS plan je spreman za potvrdu samo kada su svi kriteriji ispunjeni:

- [ ] izabran je pravi projekat i napravljen backup;
- [ ] sve uključene kuće imaju potvrđene koordinate;
- [ ] nema kuća bez grupe/rute ili su izuzeci dokumentovani;
- [ ] svaki predloženi ODO je na pristupačnoj fizičkoj lokaciji;
- [ ] nijedan ODO nema više od 12 kuća;
- [ ] splitter konfiguracija odgovara opremi;
- [ ] kuće ne prelaze granice stvarnog kraka;
- [ ] maksimalne drop dužine su prihvatljive;
- [ ] svaki ODO ima logičan ODF;
- [ ] ODF kapacitet nije prekoračen;
- [ ] trase ne prolaze zabranjenim ili neizvodljivim koridorom;
- [ ] score i sva upozorenja su pregledani, ne samo zbirni broj;
- [ ] projektant je odabrao Auto ODO ili GIS varijantu svjesno;
- [ ] poznato je šta potvrda kreira, a šta ostaje za ručni rad;
- [ ] definisan je postupak za drop trase nakon potvrde.

## 46. Brzi referentni vodič — koje dugme koristiti

| Željeni rezultat | Funkcija |
|---|---|
| Rasporediti nepovezane kuće u ODO-e uz postojeće krakove | Predloži FTTH → Potvrdi raspored |
| Predložiti distribucijsku mrežu preko GIS koridora | GIS plan → Snimi GIS mrežu |
| Sačuvati privremeni nacrt mape | Radna verzija |
| Sačuvati tačku povratka cijelog projekta | Verzije → Sačuvaj verziju |
| Napraviti vanjsku sigurnosnu kopiju | Projekti → JSON backup |
| Uvesti geodetsko izvedeno stanje | Tačke → TXT preview → Uvezi |
| Koristiti CAD kao vizuelnu podlogu | DXF panel na mapi |
| Pretvoriti DXF linije u trase | Trase → DXF import |
| Pronaći podatkovne/topološke probleme | Provjera projekta |
| Provjeriti portove po ODO-u | Splitteri |
| Pregledati raspored vlakana | Fiber šema |
| Pripremiti dokument kablovske kanalizacije | Izvještaji → Prilog 3 |

## 47. Verzija dokumentacije i održavanje

Ovo uputstvo prati funkcije prisutne u trenutnoj verziji FTTH Managera. Nakon
izmjene interfejsa, algoritma, kapaciteta ili formata uvoza potrebno je ažurirati
i dokumentaciju. Korisnik treba prijaviti kada naziv dugmeta, rezultat ili pravilo
u aplikaciji više ne odgovara ovom tekstu.

Kod svake revizije posebno provjeriti:

- parametre Auto ODO i GIS planera;
- šta preview čita ili prethodno snima;
- šta potvrda trajno kreira;
- validacijske pragove;
- podržane formate i limite uvoza;
- kapacitet ODO/ODF modela;
- backup i restore ponašanje.
