# Map editor

Glavna stranica aplikacije je `/mapa`. Leaflet.js karta s ESRI satelitskom podlogom (OSM dostupan kao alternativa).

Mapa se može koristiti globalno (svi projekti) ili filtrirana na jedan projekat (`/mapa?project=ID`). Preporučuje se rad u projektnom modu.

---

## Odabir projekta

Padajući meni u gornjem lijevom uglu. Promjena projekta mijenja vidljive elemente na mapi i aktivni nacrt. Ako postoji nespremljeni nacrt, aplikacija pita da li ga zadržati.

---

## Modovi mape

Prekidač u gornjem desnom uglu:

| Mod | Opis |
|---|---|
| **CAD** | Satelitska podloga — za crtanje i planiranje |
| **Hybrid** | Satelit + OpenStreetMap slojevi |

---

## Alati (toolbar)

### Pan (navigacija)
Podrazumijevani mod. Klik i povlačenje pomjera mapu.

### 1 ODF — dodavanje ODF-a
Klik na mapu postavlja ODF marker. Otvara se modal za unos naziva, adrese, kapaciteta vlakana i portova. ODF se odmah snima u bazu.

### 2 Trasa — crtanje trase
Klik tačku po tačku crta trasu. Dvostruki klik ili klik na zadnju tačku završava crtanje. `ESC` prekida bez snimanja, `Backspace` briše zadnju tačku.

Pri završetku crtanja otvara se modal za unos podataka trase:
- Naziv (auto-generisan)
- Tip trase
- Polazna tačka (ODF ili ODO)
- Odredišna tačka (ODO)
- Tip instalacije (podzemno / vazdušno)
- Mikrocijev (14/10 ili 10/8)
- Broj vlakana (4, 12, 24, 48)
- Status

Dužina se automatski računa iz geometrije.

### 3 Kuće — dodavanje kuća
Klik na mapu postavlja marker kuće. Otvara modal za unos oznake (label) i adrese.

### 4 FTTH — automatski raspored ODO ormarića
Pokreće Auto ODO algoritam. Vidi [06-auto-odo.md](06-auto-odo.md).

### Ruler — mjerenje udaljenosti
Klik tačku po tačku mjeri udaljenost. Ne snima ništa u bazu.

### Edit trase
Odabir trase aktivira draggable čvorove (vrhove). Klik na segment dodaje novu lomnu tačku. Klik na čvor i `Delete` briše ga (minimum 2 tačke). Izmjene se snimaju tek klikom "Sačuvaj izmjene".

---

## Snap

Automatski snap na:
- ODF markere
- ODO markere
- Kuće
- Krajnje tačke trase
- Vrhove trase (u edit modu)

Tolerancija: 30 piksela. Vizualni indikator (krug) prikazuje se kad je snap aktivan.

---

## ORTHO mod

Taster `O` uključuje/isključuje ORTHO mod. Dok je aktivan, nova tačka trase se zaključava na horizontalnu ili vertikalnu liniju u odnosu na prethodnu tačku.

---

## OSM routing

Taster `R` ili prekidač u toolbaru. Kad je uključen, trase između dvije tačke slijede puteve iz OpenStreetMap podataka umjesto ravne linije. Koristi OSRM API.

---

## Nacrt (draft) i auto-save

Sve dok korisnik crta na mapi a nije kliknuo "Sačuvaj na mapi", podaci se čuvaju kao **nacrt**. Nacrt se auto-čuva svakih 700 ms u `map_drafts` tabelu.

Pri sljedećem otvaranju projekta, nacrt se automatski restaurira — dosadašnji elementi nacrta vraćaju se na mapu bez ponovnog crtanja.

Nacrt se **briše** klikom "Sačuvaj na mapi" — u tom trenutku svi elementi iz nacrta postaju permanentni zapisi u bazi.

---

## Undo / Redo

- `Ctrl+Z` — poništi zadnju akciju u nacrtu
- `Ctrl+Y` ili `Ctrl+Shift+Z` — ponovi

Undo/redo funkcionira za:
- Crtanje trase (svaka nova tačka je korak)
- Dodavanje ODF/ODO/kuće u nacrt
- Edit trase (svaka pomjerena tačka je korak)

---

## Selekcija i property panel

Klik na ODF, ODO, kuću ili trasu otvara property panel s opcijama:
- **Uredi podatke** — modal za izmjenu atributa
- **Uredi geometriju** (samo trase) — aktivira edit mod
- **Pomjeri** — marker postaje draggable, nova pozicija se snima klikom "Sačuvaj poziciju"
- **Obriši** — brisanje uz potvrdu

### Višestruka selekcija (trase)
Shift+klik na više trasa selektuje sve. Desni klik nudi "Spoji trase" ako su selektovane trase spojive (dijele krajnju tačku).

---

## Split i Join trase

**Split** — klik na trasu u split modu dijeli je na dvije trase na kliknutoj tački.

**Join** — odabir dvije trase koje dijele krajnju tačku i spajanje u jednu. Dužine se sabiraju, geometrija se spaja.

---

## DXF podloga

Dugme "Učitaj DXF" u toolbaru. Podržani formati: `.dxf`, `.dwg`.

Aplikacija automatski detektuje koordinatni sistem:
- MGI Gauss-Krüger zona 6 (Bosna, zapadna Hrvatska)
- MGI Gauss-Krüger zona 7 (istočna Bosna, Srbija)
- WGS84

Podloga se renderuje na `<canvas>` elementu — nema usporavanja čak ni za velike fajlove. Čuva se u **IndexedDB** pregledača (preživi reload, ne šalje se na server). Svaka podloga je vezana za projekat.

---

## Sahtovi i bušenja (Prilog 3)

Alat "Saht" i alat "FI 130" na toolbaru. Klikom se postavlja marker na mapu:
- **Saht** — prolazni šaht (komadi)
- **FI 130** — bušenje pneumatskom raketom (dužina u metrima, ugao, širina)

Ovi elementi prikazuju se u Prilogu 3 izvještaju.

---

## Keyboard shortcuts

| Taster | Akcija |
|---|---|
| `O` | Uključi/isključi ORTHO mod |
| `R` | Uključi/isključi OSM routing |
| `Escape` | Prekini crtanje / zatvori modal / izađi iz edit moda |
| `Backspace` | Ukloni zadnju tačku crtanja |
| `Ctrl+Z` | Undo |
| `Ctrl+Y` | Redo |
| `Delete` | Obriši odabranu tačku trase (u edit modu) |
