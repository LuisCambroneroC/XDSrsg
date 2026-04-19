-- Base de datos para almacenar información de archivos XDS
CREATE DATABASE IF NOT EXISTS xds_repository;
USE xds_repository;

-- Tabla para versiones de archivos XDS
CREATE TABLE xds_file_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    version VARCHAR(50) NOT NULL,
    file_path VARCHAR(512),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_file_version (file_name, version)
);

-- Tabla para tipos de datos XDS
CREATE TABLE xds_data_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    is_primitive BOOLEAN DEFAULT FALSE,
    parent_type_id INT NULL,
    FOREIGN KEY (parent_type_id) REFERENCES xds_data_types(id)
);

-- Tabla para elementos XDS
CREATE TABLE xds_elements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    element_name VARCHAR(255) NOT NULL,
    element_path VARCHAR(512) NOT NULL,
    data_type_id INT,
    file_version_id INT,
    parent_element_id INT NULL,
    hierarchy_level INT DEFAULT 0,
    is_required BOOLEAN DEFAULT FALSE,
    min_occurrences INT DEFAULT 0,
    max_occurrences INT DEFAULT -1, -- -1 significa ilimitado
    description TEXT,
    FOREIGN KEY (data_type_id) REFERENCES xds_data_types(id),
    FOREIGN KEY (file_version_id) REFERENCES xds_file_versions(id),
    FOREIGN KEY (parent_element_id) REFERENCES xds_elements(id),
    INDEX idx_element_path (element_path),
    INDEX idx_hierarchy (hierarchy_level)
);

-- Tabla para atributos de elementos XDS
CREATE TABLE xds_attributes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    element_id INT NOT NULL,
    attribute_name VARCHAR(255) NOT NULL,
    data_type_id INT,
    is_required BOOLEAN DEFAULT FALSE,
    default_value VARCHAR(512),
    description TEXT,
    FOREIGN KEY (element_id) REFERENCES xds_elements(id) ON DELETE CASCADE,
    FOREIGN KEY (data_type_id) REFERENCES xds_data_types(id),
    UNIQUE KEY unique_element_attribute (element_id, attribute_name)
);

-- Tabla para validaciones XDS
CREATE TABLE xds_validations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    element_id INT NULL,
    attribute_id INT NULL,
    validation_type ENUM('regex', 'range', 'length', 'custom', 'required') NOT NULL,
    validation_rule VARCHAR(512) NOT NULL,
    error_message TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (element_id) REFERENCES xds_elements(id) ON DELETE CASCADE,
    FOREIGN KEY (attribute_id) REFERENCES xds_attributes(id) ON DELETE CASCADE,
    CHECK (element_id IS NOT NULL OR attribute_id IS NOT NULL)
);

-- Tabla para relaciones jerárquicas entre elementos
CREATE TABLE xds_hierarchy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_element_id INT NOT NULL,
    child_element_id INT NOT NULL,
    relationship_order INT DEFAULT 0,
    relationship_type ENUM('composition', 'aggregation', 'reference') DEFAULT 'composition',
    FOREIGN KEY (parent_element_id) REFERENCES xds_elements(id) ON DELETE CASCADE,
    FOREIGN KEY (child_element_id) REFERENCES xds_elements(id) ON DELETE CASCADE,
    UNIQUE KEY unique_parent_child (parent_element_id, child_element_id, relationship_order)
);

-- Insertar algunos tipos de datos primitivos comunes
INSERT INTO xds_data_types (type_name, description, is_primitive) VALUES
('string', 'Cadena de caracteres', TRUE),
('integer', 'Número entero', TRUE),
('decimal', 'Número decimal', TRUE),
('boolean', 'Valor booleano', TRUE),
('date', 'Fecha', TRUE),
('datetime', 'Fecha y hora', TRUE),
('time', 'Hora', TRUE),
('base64Binary', 'Datos binarios en base64', TRUE),
('anyURI', 'URI genérico', TRUE);

-- Índices adicionales para mejorar el rendimiento
CREATE INDEX idx_file_version ON xds_elements(file_version_id);
CREATE INDEX idx_data_type ON xds_elements(data_type_id);
CREATE INDEX idx_parent_element ON xds_elements(parent_element_id);
CREATE INDEX idx_validation_element ON xds_validations(element_id);
CREATE INDEX idx_validation_attribute ON xds_validations(attribute_id);

