CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario VARCHAR(80) NOT NULL,
  clave_hash VARCHAR(255) NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registros (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  f_produccion DATE NULL,
  mes VARCHAR(20) NOT NULL,
  fecha DATE NULL,
  ruc VARCHAR(20) NOT NULL DEFAULT '',
  razon_social VARCHAR(255) NOT NULL,
  url VARCHAR(500) NOT NULL DEFAULT '',
  estado VARCHAR(20) NOT NULL DEFAULT 'PRODUCCION',
  soporte VARCHAR(100) NOT NULL DEFAULT '',
  vendedor VARCHAR(100) NOT NULL DEFAULT '',
  celular VARCHAR(35) NOT NULL DEFAULT '',
  proyecto VARCHAR(100) NOT NULL DEFAULT '',
  plan VARCHAR(100) NOT NULL DEFAULT '',
  monto DECIMAL(12,2) NULL DEFAULT NULL,
  pago VARCHAR(12) NOT NULL DEFAULT '',
  emite VARCHAR(12) NOT NULL DEFAULT '',
  observacion TEXT NULL,
  serie_comprobante VARCHAR(30) NOT NULL DEFAULT '',
  numero_comprobante VARCHAR(40) NOT NULL DEFAULT '',
  imagen_voucher VARCHAR(255) NOT NULL DEFAULT '',
  informacion_soporte TEXT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mes_estado (mes, estado),
  KEY idx_ruc (ruc),
  KEY idx_razon (razon_social),
  KEY idx_soporte (soporte),
  KEY idx_vendedor (vendedor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS importaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hash_archivo CHAR(64) NOT NULL,
  filas_importadas INT UNSIGNED NOT NULL,
  importado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hash_archivo (hash_archivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Catálogos administrables para los campos destacados en el formulario
CREATE TABLE IF NOT EXISTS opciones_catalogo (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo VARCHAR(30) NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  precio DECIMAL(12,2) NULL DEFAULT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tipo_nombre (tipo, nombre),
  KEY idx_tipo_activo (tipo, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
