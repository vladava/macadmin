-- Spustte na NOVEM serveru az po importu tabulek FreeRADIUS:
--   mysql -u root -p radius < setup.sql

-- Udaje o zarizenich (jmeno, trida, poznamka)
CREATE TABLE IF NOT EXISTS mac_info (
  mac        VARCHAR(17)  NOT NULL PRIMARY KEY,
  jmeno      VARCHAR(100) NOT NULL DEFAULT '',
  trida      VARCHAR(20)  NOT NULL DEFAULT '',
  poznamka   VARCHAR(255) NOT NULL DEFAULT '',
  vytvoreno  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  upraveno   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY trida (trida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Index pro rychle hledani podle data (zamitnuta zarizeni, promazavani)
-- (pokud uz existuje, prikaz skonci chybou - to nevadi)
ALTER TABLE radpostauth ADD INDEX authdate (authdate);
ALTER TABLE radpostauth ADD INDEX reply_date (reply, authdate);

-- Uzivatel pro webove rozhrani - jen nutna prava
CREATE USER IF NOT EXISTS 'macadmin'@'localhost' IDENTIFIED BY 'ZMENTE_HESLO';
GRANT SELECT, INSERT, UPDATE, DELETE ON radius.radcheck    TO 'macadmin'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON radius.mac_info    TO 'macadmin'@'localhost';
GRANT SELECT, DELETE                 ON radius.radpostauth TO 'macadmin'@'localhost';
FLUSH PRIVILEGES;
