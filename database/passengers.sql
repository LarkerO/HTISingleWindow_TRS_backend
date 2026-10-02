CREATE TABLE IF NOT EXISTS passenger_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_type VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
 document_number VARCHAR(80) COLLATE utf8mb4_bin NOT NULL,
 issuing_country CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 birth_date DATE NOT NULL, surname VARCHAR(80) NULL, given_name VARCHAR(80) NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(3) NOT NULL, updated_at DATETIME(3) NOT NULL,
 UNIQUE KEY document_identity(document_type,issuing_country,document_number),
 INDEX document_name(given_name,surname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS passenger_document_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(16) NOT NULL, actor_role VARCHAR(16) NOT NULL, actor_code VARCHAR(32) NOT NULL,
 reason VARCHAR(500) NOT NULL, before_data LONGTEXT NULL, after_data LONGTEXT NOT NULL,
 occurred_at DATETIME(3) NOT NULL,
 INDEX document_history(document_id,id),
 FOREIGN KEY(document_id) REFERENCES passenger_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

