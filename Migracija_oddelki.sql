USE scholix;
-- Za obstoječo bazo: doda oddelke (MariaDB/XAMPP)
CREATE TABLE IF NOT EXISTS Oddelek (id_oddelek INT AUTO_INCREMENT PRIMARY KEY, ime VARCHAR(20) NOT NULL UNIQUE, letnik INT NOT NULL, program VARCHAR(100) NOT NULL);
ALTER TABLE Uporabnik ADD COLUMN IF NOT EXISTS oddelek_id INT NULL, ADD CONSTRAINT fk_uporabnik_oddelek FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek);
ALTER TABLE Urnik ADD COLUMN IF NOT EXISTS oddelek_id INT NULL, ADD CONSTRAINT fk_urnik_oddelek FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek);
CREATE TABLE IF NOT EXISTS Gradivo_Oddelek (
    gradivo_id INT NOT NULL,
    oddelek_id INT NOT NULL,
    PRIMARY KEY (gradivo_id, oddelek_id),
    CONSTRAINT fk_go_gradivo FOREIGN KEY (gradivo_id) REFERENCES Gradivo(id_gradivo) ON DELETE CASCADE,
    CONSTRAINT fk_go_oddelek FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS Dan_Brez_Pouka (
    id_dan INT AUTO_INCREMENT PRIMARY KEY,
    datum DATE NOT NULL,
    razlog VARCHAR(150) NOT NULL,
    oddelek_id INT NULL,
    ustvarjeno DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dan_datum (datum),
    CONSTRAINT fk_dan_oddelek FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS Sprememba_Urnika (
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
    CONSTRAINT fk_sprememba_urnik FOREIGN KEY (urnik_id) REFERENCES Urnik(id_urnik) ON DELETE CASCADE,
    CONSTRAINT fk_sprememba_oddelek FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek) ON DELETE CASCADE,
    CONSTRAINT fk_sprememba_predmet FOREIGN KEY (predmet_id) REFERENCES Predmet(id_predmet) ON DELETE CASCADE,
    CONSTRAINT fk_sprememba_ucitelj FOREIGN KEY (ucitelj_id) REFERENCES Uporabnik(id_uporabnik) ON DELETE CASCADE
);
ALTER TABLE Oddelek ADD COLUMN IF NOT EXISTS razrednik_id INT NULL, ADD CONSTRAINT fk_oddelek_razrednik FOREIGN KEY (razrednik_id) REFERENCES Uporabnik(id_uporabnik) ON DELETE SET NULL;
