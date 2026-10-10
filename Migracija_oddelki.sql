USE scholix;
-- Za obstoječo bazo: doda oddelke (MariaDB/XAMPP)
CREATE TABLE IF NOT EXISTS Oddelek (id_oddelek INT AUTO_INCREMENT PRIMARY KEY, ime VARCHAR(20) NOT NULL UNIQUE, letnik INT NOT NULL, program VARCHAR(100) NOT NULL);
ALTER TABLE Uporabnik ADD COLUMN IF NOT EXISTS oddelek_id INT NULL, ADD CONSTRAINT fk_uporabnik_oddelek FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek);
ALTER TABLE Urnik ADD COLUMN IF NOT EXISTS oddelek_id INT NULL, ADD CONSTRAINT fk_urnik_oddelek FOREIGN KEY (oddelek_id) REFERENCES Oddelek(id_oddelek);
