SET NAMES utf8mb4;
USE scholix;

-- Počisti vse obstoječe podatke
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE Urnik;
TRUNCATE TABLE Izostanek;
TRUNCATE TABLE Ocena;
TRUNCATE TABLE Oddana_Naloga;
TRUNCATE TABLE Naloga;
TRUNCATE TABLE Gradivo;
TRUNCATE TABLE Dijak_Predmet;
TRUNCATE TABLE Ucitelj_Predmet;
TRUNCATE TABLE Predmet;
TRUNCATE TABLE Uporabnik;
SET FOREIGN_KEY_CHECKS = 1;

-- Geslo = ime + priimek, vse male črke brez presledka (admin: geslo123)
INSERT INTO Uporabnik (ime, priimek, e_posta, geslo, vloga) VALUES
('Admin', 'Scholix', 'admin@scholix.si', 'geslo123', 'admin'),
('Andraž', 'Pušnik', 'andraz.pusnik@sc-celje.si', 'andražpušnik', 'ucitelj'),
('Žiga', 'Vračun', 'ziga.vracun.20658@dijak.sc-celje.si', 'žigavračun', 'dijak'),
('Tilen', 'Kroflič', 'tilen.kroflic.20632@dijak.sc-celje.si', 'tilenkroflič', 'dijak'),
('Ožbej', 'Žurman', 'ozbej.zurman.20663@dijak.sc-celje.si', 'ožbejžurman', 'dijak');

INSERT INTO Predmet (naziv, opis) VALUES
('Matematika', 'Algebra, funkcije in geometrija.'),
('Programiranje', 'Osnove programiranja in spletnih aplikacij.'),
('Angleščina', 'Splošna in strokovna angleščina.');

SET @prof = (SELECT id_uporabnik FROM Uporabnik WHERE e_posta='andraz.pusnik@sc-celje.si');
SET @ziga = (SELECT id_uporabnik FROM Uporabnik WHERE e_posta='ziga.vracun.20658@dijak.sc-celje.si');
SET @tilen = (SELECT id_uporabnik FROM Uporabnik WHERE e_posta='tilen.kroflic.20632@dijak.sc-celje.si');
SET @ozbej = (SELECT id_uporabnik FROM Uporabnik WHERE e_posta='ozbej.zurman.20663@dijak.sc-celje.si');
SET @mat = (SELECT id_predmet FROM Predmet WHERE naziv='Matematika');
SET @pro = (SELECT id_predmet FROM Predmet WHERE naziv='Programiranje');
SET @ang = (SELECT id_predmet FROM Predmet WHERE naziv='Angleščina');

INSERT INTO Ucitelj_Predmet (ucitelj_id, predmet_id) VALUES (@prof, @mat), (@prof, @pro), (@prof, @ang);

INSERT INTO Dijak_Predmet (dijak_id, predmet_id)
SELECT d.id, p.id FROM
 (SELECT @ziga AS id UNION SELECT @tilen UNION SELECT @ozbej) d
 CROSS JOIN (SELECT @mat AS id UNION SELECT @pro UNION SELECT @ang) p;

INSERT INTO Gradivo (predmet_id, ucitelj_id, naslov, opis, datoteka_pot) VALUES
(@mat, @prof, 'Kvadratne funkcije', 'Povzetek snovi s primeri.', 'uploads/gradivo/kvadratne_funkcije.pdf'),
(@pro, @prof, 'Uvod v PHP', 'Osnove jezika PHP.', 'uploads/gradivo/uvod_php.pdf');

INSERT INTO Naloga (predmet_id, ucitelj_id, naslov, navodila, rok_oddaje) VALUES
(@mat, @prof, 'Domača naloga 1', 'Rešite naloge 1 do 10 na strani 45.', DATE_ADD(NOW(), INTERVAL 7 DAY)),
(@pro, @prof, 'Prijavni obrazec', 'Izdelajte prijavni obrazec v HTML in PHP.', DATE_ADD(NOW(), INTERVAL 14 DAY));

SET @n1 = (SELECT id_naloga FROM Naloga WHERE naslov='Domača naloga 1');
SET @n2 = (SELECT id_naloga FROM Naloga WHERE naslov='Prijavni obrazec');

INSERT INTO Oddana_Naloga (naloga_id, dijak_id, datoteka_pot, komentar_dijaka, ocena, komentar_ucitelja, ocenjeno_ob) VALUES
(@n1, @ziga, 'uploads/naloge/ziga_dn1.pdf', 'Oddano pravočasno.', 5, 'Odlično!', NOW()),
(@n1, @tilen, 'uploads/naloge/tilen_dn1.pdf', NULL, 3, 'Popravite zadnji dve nalogi.', NOW()),
(@n2, @ozbej, 'uploads/naloge/ozbej_obrazec.zip', 'Dodal sem tudi CSS.', NULL, NULL, NULL);

INSERT INTO Ocena (dijak_id, predmet_id, ucitelj_id, ocena, naslov_ocene, opis, datum_ocenitve) VALUES
(@ziga, @mat, @prof, 5, 'Test: funkcije', 'Pisni preizkus', CURDATE()),
(@ziga, @pro, @prof, 5, 'Projekt', 'Spletna stran', CURDATE()),
(@tilen, @mat, @prof, 3, 'Test: funkcije', 'Pisni preizkus', CURDATE()),
(@tilen, @pro, @prof, 4, 'Projekt', 'Spletna aplikacija', CURDATE()),
(@ozbej, @ang, @prof, 4, 'Esej', 'Pisni izdelek', CURDATE()),
(@ozbej, @mat, @prof, 2, 'Test: funkcije', 'Pisni preizkus', CURDATE());

INSERT INTO Izostanek (dijak_id, datum, ura, status, opomba) VALUES
(@ziga, CURDATE(), 2, 'opraviceno', 'Zdravniški pregled'),
(@tilen, CURDATE(), 3, 'neopraviceno', NULL),
(@ozbej, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 1, 'neopraviceno', 'Zamuda');

INSERT INTO Urnik (predmet_id, ucitelj_id, dan_v_tednu, ura, ucilnica) VALUES
(@mat, @prof, 'Ponedeljek', 1, 'A101'),
(@pro, @prof, 'Ponedeljek', 3, 'R2'),
(@ang, @prof, 'Sreda', 4, 'B203'),
(@mat, @prof, 'Četrtek', 1, 'A101'),
(@pro, @prof, 'Petek', 2, 'R2');
