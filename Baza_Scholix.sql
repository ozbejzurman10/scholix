CREATE TABLE Uporabnik (
    id_uporabnik INT AUTO_INCREMENT PRIMARY KEY,
    ime VARCHAR(50) NOT NULL,
    priimek VARCHAR(50) NOT NULL,
    e_posta VARCHAR(100) NOT NULL UNIQUE,
    geslo VARCHAR(255) NOT NULL,
    vloga VARCHAR(20) NOT NULL,
    ustvarjeno DATETIME DEFAULT CURRENT_TIMESTAMP,
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
    naslov_ocene VARCHAR(100) NOT NULL,
    opis TEXT,
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
    FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet),
    FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik),
    CHECK (dan_v_tednu IN ('Ponedeljek', 'Torek', 'Sreda', 'Četrtek', 'Petek'))
);