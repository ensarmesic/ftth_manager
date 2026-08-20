# Preporučeni FTTH TXT format

Svaki red ima oblik:

`BROJ X Y Z OPIS`

Pravila:

- Broj tačke mora biti jedinstven, ali broj nije uputa za rutiranje.
- X/Y/Z moraju biti stvarne izmjerene Gauss–Krüger koordinate.
- Koristiti tačno `Kuca 10/8 Crvena x1 -ZO-N` za završetak kod korisnika.
- Koristiti tačno `Rov + mc 10/8 Crvena xN -ZO-N` za rovnu tačku.
- Koristiti `ZO-N` za položaj ormara.
- Kod račvanja ponovo snimiti raskrsnu koordinatu kao novu tačku sa novim brojem. X/Y mogu biti isti jer je fizički čvor isti.
- Nakon kuće ne nastavljati novu granu iz koordinatnog kraja kuće. Vratiti se na raskrsnu tačku i od nje snimati sljedeću granu.
- Broj `xN` predstavlja broj mikrocijevi koje stvarno prolaze tom dionicom: prema ZO-u je veći, a nakon račvanja se smanjuje.
- Svaka kuća i svaka rovna tačka korisničke mreže mora imati isti `-ZO-N` kao ciljni ormar.
- Ne upisivati procijenjene ili pomjerene koordinate. Ako tačka nije izmjerena, treba je izmjeriti.
