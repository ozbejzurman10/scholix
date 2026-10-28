# Scholix – spletna učilnica (PHP + MySQL + Docker)

## Zahteve
Docker Desktop (Docker Compose v2).

## Namestitev in zagon
1. Kopirajte `.env.example` v `.env` in nastavite geslo (`MYSQL_ROOT_PASSWORD`). Datoteka `.env` ni v Gitu.
   > Če že imate Docker volumen `scholix-main_podatki_baze`, mora geslo ostati enako kot ob njegovi ustvaritvi.
2. V mapi projekta zaženite:
   ```
   docker compose up -d --build
   ```
3. Odprite:
   - aplikacija: http://localhost:8080
   - phpMyAdmin: http://localhost:8081 (uporabnik `root`, geslo iz `.env`)

## Kako deluje
| Storitev | Opis |
|---|---|
| `spletna-stran` | PHP 8.3 + Apache, vrata 8080. Do baze dostopa prek gostitelja `baza`. |
| `baza` | MySQL 8.4, podatki v trajnem volumnu `podatki_baze`. Vrata 3307 so dostopna samo na `127.0.0.1` (za razvoj). |
| `inicializacija` | Enkratni vsebnik: če baza `sola` še nima tabele `Uporabnik`, uvozi `Baza_Scholix.sql` in `Testni_podatki.sql`. Če tabele že obstajajo, ne naredi ničesar – podatkov nikoli ne briše. |
| `phpmyadmin` | Vrata 8081. |

- Povezava z bazo je na enem mestu: `baza.php` (PDO, nastavitve iz spremenljivk okolja `DB_*`, ponovni poskusi ob zagonu).
- Naloženi dokumenti so v volumnu `nalozeno` (`/var/www/html/uploads`).
- Vsebniki imajo `restart: unless-stopped`, zato se po ponovnem zagonu računalnika zaženejo sami.
- Apache zavrača neposreden dostop do `.env`, `.sql`, `.sh`, `.md`, `.yaml` in `Dockerfile`.

## Uporabni ukazi
```
docker compose logs -f spletna-stran     # Apache/PHP dnevnik
docker compose logs baza                 # MySQL dnevnik
docker compose stop                      # ustavitev (podatki ostanejo)
docker compose up -d                     # ponovni zagon
```
> **Pozor:** `docker compose down -v` in `docker volume rm` izbrišeta vse podatke baze. Teh ukazov ne uporabljajte, če podatkov ne želite izgubiti.

## Prijava
Testni podatki vsebujejo račun `admin@scholix.si` ter učitelje in dijake (geslo je shranjeno kot zgostitev `password_hash`). Če gesla ne poznate, lahko dijaka registrirate na `/registracija.php`, ali pa v phpMyAdmin nastavite novo zgostitev (`password_hash` v PHP).

## Znane omejitve
- Aplikacija se na MySQL povezuje kot `root` (lokalno razvojno okolje). Za produkcijo ustvarite namenskega uporabnika z omejenimi pravicami.
- `baza.php` ob vsakem zahtevku izvede nekaj poceni, ponovljivih preverjanj (dodatni stolpci, začetne ocene, če jih je < 20).

