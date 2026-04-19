# Aplicación PHP para Carga de Archivos XDS

## Descripción
Aplicación web en PHP que permite subir archivos XDS/XML, leer su estructura y guardarla en una base de datos MySQL con información sobre elementos, atributos, tipos de datos, orden jerárquico y validaciones.

## Requisitos

- PHP 7.4 o superior
- MySQL 5.7 o superior
- Servidor web (Apache, Nginx, etc.)
- Extensiones PHP: PDO, pdo_mysql, SimpleXML, libxml

## Instalación

### 1. Configurar la Base de Datos

Ejecuta el script SQL para crear la estructura de la base de datos:

```bash
mysql -u root -p < xds_database.sql
```

O conecta a MySQL y ejecuta manualmente el contenido del archivo `xds_database.sql`.

### 2. Configurar la Conexión

Edita el archivo `index.php` y modifica las constantes de configuración de la base de datos según tu entorno:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'xds_database');
define('DB_USER', 'root');
define('DB_PASS', 'tu_contraseña');
define('DB_CHARSET', 'utf8mb4');
```

### 3. Permisos de Carpeta

Asegúrate de que el servidor web tenga permisos de escritura en el directorio del proyecto:

```bash
chmod 755 /workspace
mkdir -p /workspace/uploads
chmod 777 /workspace/uploads
```

### 4. Desplegar la Aplicación

Copia todos los archivos al directorio de tu servidor web:

- `index.php` - Archivo principal de la aplicación
- `xds_database.sql` - Script de creación de la base de datos
- `example.xds` - Archivo de ejemplo para pruebas

## Uso

1. Abre tu navegador y navega a la URL donde desplegaste la aplicación.

2. En el formulario "Subir Nuevo Archivo":
   - Haz clic en "Seleccionar Archivo" y elige un archivo XDS/XML
   - Ingresa la versión del archivo (ej: 1.0, 2.1.3)
   - Presiona el botón **"Cargar"**

3. La aplicación:
   - Validará el archivo (extensión y formato XML)
   - Analizará la estructura del archivo
   - Detectará automáticamente los tipos de datos
   - Extraerá atributos y sus valores
   - Generará validaciones básicas
   - Guardará toda la información en la base de datos
   - Mostrará la estructura jerárquica del archivo cargado

## Estructura de la Base de Datos

La base de datos incluye las siguientes tablas:

- **xds_file_versions**: Almacena información de versiones de archivos
- **xds_data_types**: Tipos de datos detectados (string, integer, date, etc.)
- **xds_elements**: Elementos del archivo con su jerarquía
- **xds_attributes**: Atributos de cada elemento
- **xds_validations**: Reglas de validación para cada elemento
- **xds_hierarchy**: Relaciones jerárquicas entre elementos

## Características

### Detección Automática de Tipos de Datos
- string, integer, decimal, date, datetime, email, url, text, complex, empty

### Validaciones Automáticas
- Validación de formato según tipo de dato
- Validación de longitud máxima
- Validación de campos requeridos
- Expresiones regulares para validación numérica

### Jerarquía de Elementos
- Soporte para estructuras XML anidadas ilimitadas
- Orden secuencial de elementos
- Referencias padre-hijo

## Archivo de Ejemplo

Se incluye `example.xds`, un archivo de ejemplo con estructura clínica que contiene:
- Metadatos del documento
- Información de paciente
- Órdenes médicas
- Resultados de laboratorio
- Conclusiones

## Seguridad

- Validación de extensión de archivos (.xml, .xds, .xsd)
- Validación de formato XML
- Sanitización de salida con htmlspecialchars()
- Consultas preparadas para prevenir SQL injection
- Namespaces únicos para archivos subidos

## Personalización

Puedes modificar:
- Los tipos de datos detectados en `XDSParser::detectDataType()`
- Las reglas de validación en `XDSParser::addBasicValidations()`
- Los atributos considerados como requeridos en `XDSParser::isRequiredAttribute()`
- El diseño CSS en la sección `<style>` del HTML

## Solución de Problemas

### Error de conexión a la base de datos
Verifica que las credenciales en `index.php` sean correctas y que la base de datos exista.

### Error al subir archivos
- Verifica que el directorio `uploads/` exista y tenga permisos de escritura
- Revisa la configuración de `upload_max_filesize` y `post_max_size` en php.ini

### Error de análisis XML
Asegúrate de que el archivo sea XML bien formado. Puedes validarlo con herramientas online o usando:
```bash
xmlstarlet val example.xds
```

## Licencia

Este código es de uso libre para fines educativos y comerciales.
