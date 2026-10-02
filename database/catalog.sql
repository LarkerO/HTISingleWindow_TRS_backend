CREATE TABLE IF NOT EXISTS mtr_depots (
 dimension VARCHAR(120) NOT NULL, depot_id VARCHAR(20) NOT NULL, name VARCHAR(512) NOT NULL,
 transport_mode VARCHAR(32) NOT NULL, route_ids LONGTEXT NOT NULL, departures LONGTEXT NOT NULL,
 frequencies LONGTEXT NOT NULL, use_real_time TINYINT NOT NULL, repeat_infinitely TINYINT NOT NULL,
 present TINYINT NOT NULL DEFAULT 1, planning_status VARCHAR(32) NOT NULL, planning_reason TEXT NOT NULL, planned_trip_count INT UNSIGNED NOT NULL,
 source_path VARCHAR(512) NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(dimension,depot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS mtr_depot_sidings (
 dimension VARCHAR(120) NOT NULL, depot_id VARCHAR(20) NOT NULL, siding_id VARCHAR(20) NOT NULL,
 name VARCHAR(512) NOT NULL, train_type VARCHAR(128) NOT NULL, path_segments INT UNSIGNED NOT NULL,
 is_manual TINYINT NOT NULL, max_trains INT NOT NULL, unlimited_trains TINYINT NOT NULL,
 PRIMARY KEY(dimension,depot_id,siding_id),
 FOREIGN KEY(dimension,depot_id) REFERENCES mtr_depots(dimension,depot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

