#!/bin/sh
set -eu
M="mysql -h baza -uroot --default-character-set=utf8mb4"
DB="${MYSQL_DATABASE:-sola}"

IMA=$($M -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name='Uporabnik'")
if [ "$IMA" = "0" ]; then
  echo "Baza '$DB' nima tabel - ustvarjam shemo ..."
  $M < /sql/Baza_Scholix.sql
fi

# Testne podatke uvozi samo, ce sta Uporabnik in Oddelek povsem prazna (obstojecih podatkov ne spreminja).
ST=$($M -N -B "$DB" -e "SELECT (SELECT COUNT(*) FROM Uporabnik) + (SELECT COUNT(*) FROM Oddelek)")
if [ "$ST" = "0" ]; then
  echo "Uvazam testne podatke ..."
  $M < /sql/Testni_podatki.sql
else
  echo "Baza '$DB' ze vsebuje podatke - uvoz preskocen."
fi
echo "Inicializacija koncana."