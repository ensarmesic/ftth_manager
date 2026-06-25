# Postavke

**URL:** `/postavke`

Postavke se čuvaju lokalno u browseru (`localStorage`) i važe samo za trenutni uređaj — ne utječu na druge korisnike niti na podatke u bazi.

---

## Opcije prikaza

| Opcija | Zadano | Opis |
|--------|--------|------|
| Kompaktni prikaz tabela | Isključeno | Smanjuje razmak redova u svim tabelama aplikacije. Korisno na manjim ekranima. |
| Smanjene oznake na mapi | Uključeno | Manji markeri za ODF, ODO i kuće. Preporučeno pri radu s gustim mrežama. |
| Brojač obavještenja u headeru | Uključeno | Prikazuje crveni badge (broj) na ikoni zvona kada projekat ima validacijska upozorenja. Isključite ako badge ometa rad. |
| Boje ODO ormarića po zauzetosti | Uključeno | Ormarići mijenjaju boju prema popunjenosti portova (zelena/žuta/narandžasta/crvena). Isključite ako preferirate jednoobrazne boje prema ID-u. |

Kliknite **Sačuvaj postavke** — promjene stupaju na snagu odmah, bez reloada.

---

## Boje zauzetosti ODO ormarića

Kad je uključena opcija boja po zauzetosti:

| Boja | Popunjenost | Značenje |
|------|-------------|----------|
| Zelena | 0–59% | Dovoljno slobodnih portova |
| Žuta | 60–79% | Polovično popunjen |
| Narandžasta | 80–94% | Skoro pun — planirati proširenje |
| Crvena | 95–100% | Pun — ne može primiti više kuća |

Kad je opcija isključena, svaki ormarić dobija fiksnu boju prema svom ID-u iz palete od 12 boja — korisno za razlikovanje susjednih ormarića.

---

## Sistemske informacije

Sekcija **Sistem** prikazuje read-only informacije:

| Stavka | Opis |
|--------|------|
| Aplikacija | FTTH Manager |
| Verzija | Trenutna verzija aplikacije |
| Mapa | Leaflet + ESRI / OpenStreetMap |
| Baza podataka | SQLite |
| Okruženje | `local` ili `production` |
| PHP verzija | Instalirana verzija PHP-a |
| Laravel | Verzija Laravel frameworka |

---

## Brzi pristup

Sekcija **Brzi pristup** nudi direktne linkove do najkorištenijih stranica:
- Provjera projekta
- Izvještaji
- Fiber šema
- Mapa
