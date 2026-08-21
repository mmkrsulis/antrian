CREATE TABLE sub_services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sub_services_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    UNIQUE KEY uq_sub_services_service_name (service_id, name),
    KEY idx_sub_services_picker (service_id, active, sort_order, name)
);

ALTER TABLE tickets
    ADD COLUMN sub_service_id BIGINT UNSIGNED NULL AFTER service_id,
    ADD COLUMN sub_service_name VARCHAR(150) NULL AFTER sub_service_id,
    ADD CONSTRAINT fk_tickets_sub_service FOREIGN KEY (sub_service_id) REFERENCES sub_services(id) ON DELETE SET NULL,
    ADD KEY idx_tickets_sub_service (sub_service_id);
