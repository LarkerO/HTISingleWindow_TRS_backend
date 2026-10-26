CREATE TABLE IF NOT EXISTS station_dictionary (
 station_id VARCHAR(160) PRIMARY KEY, visible TINYINT NOT NULL DEFAULT 0,
 operator_code VARCHAR(16) NOT NULL,
 telegraph_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 name_zh VARCHAR(160) NOT NULL, name_en VARCHAR(160) NOT NULL,
 admin_level_1 VARCHAR(160) NOT NULL DEFAULT '', admin_level_2 VARCHAR(160) NOT NULL DEFAULT '',
 FOREIGN KEY(station_id) REFERENCES stations(id), FOREIGN KEY(operator_code) REFERENCES operators(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;