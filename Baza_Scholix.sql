CREATE DATABASE IF NOT EXISTS scholix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE scholix;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE Oddelek (
    id_oddelek INT AUTO_INCREMENT PRIMARY KEY,
    ime VARCHAR(20) NOT NULL UNIQUE,
    letnik INT NOT NULL,
    program VARCHAR(100) NOT NULL,
    razrednik_id INT NULL
);

CREATE TABLE Uporabnik (
    id_uporabnik INT AUTO_INCREMENT PRIMARY KEY,
    ime VARCHAR(50) NOT NULL,
    priimek VARCHAR(50) NOT NULL,
    e_posta VARCHAR(100) NOT NULL UNIQUE,
    geslo VARCHAR(255) NOT NULL,
    vloga VARCHAR(20) NOT NULL,
    oddelek_id INT NULL,
    kabinet VARCHAR(50) NULL,
    telefon VARCHAR(50) NULL,
    govorilne_ure VARCHAR(150) NULL,
    ustvarjeno DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek),
    CHECK (vloga IN ('admin', 'ucitelj', 'dijak'))
);

CREATE TABLE Predmet (
    id_predmet INT AUTO_INCREMENT PRIMARY KEY,
    naziv VARCHAR(100) NOT NULL,
    opis TEXT
);

CREATE TABLE Ucitelj_Predmet (
    ucitelj_id INT,
    predmet_id INT,
    PRIMARY KEY (ucitelj_id, predmet_id),
    FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik),
    FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet)
);

CREATE TABLE Dijak_Predmet (
    dijak_id INT,
    predmet_id INT,
    PRIMARY KEY (dijak_id, predmet_id),
    FOREIGN KEY (dijak_id) REFERENCES Uporabnik(id_uporabnik),
    FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet)
);

CREATE TABLE Gradivo (
    id_gradivo INT AUTO_INCREMENT PRIMARY KEY,
    predmet_id INT NOT NULL,
    ucitelj_id INT NOT NULL,
    naslov VARCHAR(150) NOT NULL,
    opis TEXT,
    datoteka_pot VARCHAR(255) NOT NULL,
    ustvarjeno DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet),
    FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik)
);

CREATE TABLE Gradivo_Oddelek (
    gradivo_id INT NOT NULL,
    oddelek_id INT NOT NULL,
    PRIMARY KEY (gradivo_id, oddelek_id),
    FOREIGN KEY (gradivo_id) REFERENCES Gradivo(id_gradivo) ON DELETE CASCADE,
    FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek) ON DELETE CASCADE
);

CREATE TABLE Naloga (
    id_naloga INT AUTO_INCREMENT PRIMARY KEY,
    predmet_id INT NOT NULL,
    ucitelj_id INT NOT NULL,
    naslov VARCHAR(150) NOT NULL,
    navodila TEXT NOT NULL,
    rok_oddaje DATETIME NOT NULL,
    ustvarjeno DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet),
    FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik)
);

CREATE TABLE Naloga_Oddelek (
    naloga_id INT NOT NULL,
    oddelek_id INT NOT NULL,
    PRIMARY KEY (naloga_id, oddelek_id),
    FOREIGN KEY (naloga_id) REFERENCES Naloga(id_naloga) ON DELETE CASCADE,
    FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek) ON DELETE CASCADE
);

CREATE TABLE Oddana_Naloga (
    id_oddana INT AUTO_INCREMENT PRIMARY KEY,
    naloga_id INT NOT NULL,
    dijak_id INT NOT NULL,
    datoteka_pot VARCHAR(255) NOT NULL,
    komentar_dijaka TEXT,
    ocena INT,
    komentar_ucitelja TEXT,
    oddano_ob DATETIME DEFAULT CURRENT_TIMESTAMP,
    ocenjeno_ob DATETIME,
    FOREIGN KEY (naloga_id) REFERENCES Naloga(id_naloga),
    FOREIGN KEY (dijak_id) REFERENCES Uporabnik(id_uporabnik)
);

CREATE TABLE Ocena (
    id_ocena INT AUTO_INCREMENT PRIMARY KEY,
    dijak_id INT NOT NULL,
    predmet_id INT NOT NULL,
    ucitelj_id INT NOT NULL,
    ocena INT NOT NULL,
    vrsta_ocenjevanja VARCHAR(50) NOT NULL DEFAULT 'Pisno ocenjevanje',
    naslov_ocene VARCHAR(100) NOT NULL,
    opis TEXT,
    ocenjevanje_id INT NULL,
    datum_ocenitve DATE NOT NULL,
    FOREIGN KEY (dijak_id) REFERENCES Uporabnik(id_uporabnik),
    FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet),
    FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik)
);

CREATE TABLE Izostanek (
    id_izostanek INT AUTO_INCREMENT PRIMARY KEY,
    dijak_id INT NOT NULL,
    datum DATE NOT NULL,
    ura INT NOT NULL,
    status VARCHAR(20) DEFAULT 'neopraviceno',
    opomba TEXT,
    FOREIGN KEY (dijak_id) REFERENCES Uporabnik(id_uporabnik),
    CHECK (status IN ('neopraviceno', 'opraviceno'))
);

CREATE TABLE Urnik (
    id_urnik INT AUTO_INCREMENT PRIMARY KEY,
    predmet_id INT NOT NULL,
    ucitelj_id INT NOT NULL,
    dan_v_tednu VARCHAR(20) NOT NULL,
    ura INT NOT NULL,
    ucilnica VARCHAR(50),
    oddelek_id INT NULL,
    FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet),
    FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik),
    FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek),
    CHECK (dan_v_tednu IN ('Ponedeljek', 'Torek', 'Sreda', 'Četrtek', 'Petek'))
);

CREATE TABLE Dan_Brez_Pouka (
    id_dan INT AUTO_INCREMENT PRIMARY KEY,
    datum DATE NOT NULL,
    razlog VARCHAR(150) NOT NULL,
    oddelek_id INT NULL,
    ustvarjeno DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dan_datum (datum),
    FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek) ON DELETE CASCADE
);

CREATE TABLE Sprememba_Urnika (
    id_sprememba INT AUTO_INCREMENT PRIMARY KEY,
    datum DATE NOT NULL,
    tip ENUM('odpade', 'dodatna') NOT NULL,
    vrsta VARCHAR(20) DEFAULT 'dodatna',
    urnik_id INT NULL,
    oddelek_id INT NULL,
    ura INT NULL,
    predmet_id INT NULL,
    ucitelj_id INT NULL,
    ucilnica VARCHAR(50) NULL,
    opomba VARCHAR(200) NULL,
    ustvarjeno DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sprememba_datum (datum),
    FOREIGN KEY (urnik_id) REFERENCES Urnik(id_urnik) ON DELETE CASCADE,
    FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek) ON DELETE CASCADE,
    FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet) ON DELETE CASCADE,
    FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik) ON DELETE CASCADE
);

CREATE TABLE Pisno_Ocenjevanje (
    id_ocenjevanje INT AUTO_INCREMENT PRIMARY KEY,
    datum DATE NOT NULL,
    ura INT NOT NULL,
    predmet_id INT NOT NULL,
    ucitelj_id INT NOT NULL,
    oddelek_id INT NOT NULL,
    naslov VARCHAR(150) NOT NULL,
    opis VARCHAR(255) NULL,
    ustvarjeno DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ocenjevanje_datum (datum),
    FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet) ON DELETE CASCADE,
    FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik) ON DELETE CASCADE,
    FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek) ON DELETE CASCADE
);

ALTER TABLE Oddelek
    ADD CONSTRAINT fk_oddelek_razrednik FOREIGN KEY (razrednik_id) REFERENCES Uporabnik(id_uporabnik) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
