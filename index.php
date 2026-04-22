<?php
// Habilitar reporte de errores para debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Configuración de la base de datos
 */
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'xds_repository');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Clase para manejar la conexión y operaciones con la base de datos XDS
 */
class XDSDatabase {
    private $pdo;

    public function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST .";dbport=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            throw new Exception("Error de conexión: " . $e->getMessage());
        }
    }

    /**
     * Guarda o actualiza la versión del archivo XDS
     */
    public function saveFileVersion($filename, $version, $uploadDate) {
        $sql = "INSERT INTO xds_file_versions (file_name, version, file_path, created_at) 
                VALUES (:file_name, :version, :file_path, :created_at)
                ON DUPLICATE KEY UPDATE version = :version_upd, updated_at = CURRENT_TIMESTAMP";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':file_name' => $filename,
            ':version' => $version,
            ':file_path' => 'uploads/' . $filename,
            ':created_at' => $uploadDate,
            ':version_upd' => $version
        ]);

        return $this->pdo->lastInsertId();
    }


    /**
     * Obtiene o crea un tipo de dato
     */
    public function getOrCreateDataType($typeName, $description = null) {
        // Primero intentamos obtenerlo
        $sql = "SELECT id FROM xds_data_types WHERE type_name = :type_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':type_name' => $typeName]);
        $result = $stmt->fetch();
        
        if ($result) {
            return $result['id'];
        }
        
        // Si no existe, lo creamos
        $sql = "INSERT INTO xds_data_types (type_name, description) VALUES (:type_name, :description)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':type_name' => $typeName,
            ':description' => $description
        ]);
        
        return $this->pdo->lastInsertId();
    }

    /**
     * Guarda un elemento XDS
     */
    public function saveElement($fileVersionId, $elementName, $dataTypeId, $parentId = null, $orderIndex = 0) {
        $sql = "INSERT INTO xds_elements (file_version_id, element_name, data_type_id, parent_element_id, order_index) 
                VALUES (:file_version_id, :element_name, :data_type_id, :parent_element_id, :order_index)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':file_version_id' => $fileVersionId,
            ':element_name' => $elementName,
            ':data_type_id' => $dataTypeId,
            ':parent_element_id' => $parentId,
            ':order_index' => $orderIndex
        ]);
        
        return $this->pdo->lastInsertId();
    }

    /**
     * Guarda un atributo para un elemento
     */
    public function saveAttribute($elementId, $attributeName, $attributeValue, $isRequired = false) {
        $sql = "INSERT INTO xds_attributes (element_id, attribute_name, attribute_value, is_required) 
                VALUES (:element_id, :attribute_name, :attribute_value, :is_required)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':element_id' => $elementId,
            ':attribute_name' => $attributeName,
            ':attribute_value' => $attributeValue,
            ':is_required' => $isRequired ? 1 : 0
        ]);
        
        return $this->pdo->lastInsertId();
    }

    /**
     * Guarda una validación para un elemento
     */
    public function saveValidation($elementId, $validationType, $validationRule, $errorMessage) {
        $sql = "INSERT INTO xds_validations (element_id, validation_type, validation_rule, error_message) 
                VALUES (:element_id, :validation_type, :validation_rule, :error_message)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':element_id' => $elementId,
            ':validation_type' => $validationType,
            ':validation_rule' => $validationRule,
            ':error_message' => $errorMessage
        ]);
        
        return $this->pdo->lastInsertId();
    }

    /**
     * Obtiene todos los archivos cargados
     */
    public function getFileVersions() {
        $sql = "SELECT fv.id, fv.file_name as filename, fv.version, fv.created_at as upload_date,
                       COUNT(DISTINCT e.id) as element_count
                FROM xds_file_versions fv
                LEFT JOIN xds_elements e ON fv.id = e.file_version_id
                GROUP BY fv.id
                ORDER BY fv.created_at DESC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Obtiene la estructura jerárquica de un archivo
     */
    public function getFileHierarchy($fileVersionId) {
        $sql = "SELECT e.id, e.element_name, dt.type_name, e.parent_element_id, e.order_index,
                       GROUP_CONCAT(a.attribute_name SEPARATOR ', ') as attributes
                FROM xds_elements e
                LEFT JOIN xds_data_types dt ON e.data_type_id = dt.id
                LEFT JOIN xds_attributes a ON e.id = a.element_id
                WHERE e.file_version_id = :file_version_id
                GROUP BY e.id
                ORDER BY e.order_index";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':file_version_id' => $fileVersionId]);
        return $stmt->fetchAll();
    }
}

/**
 * Analiza un archivo XML/XDS y extrae su estructura
 */
class XDSParser {
    private $db;
    private $elementOrder = 0;

    public function __construct(XDSDatabase $db) {
        $this->db = $db;
    }

    /**
     * Procesa un archivo XML y guarda su estructura en la base de datos
     */
    public function processFile($filePath, $fileVersionId) {
        $this->elementOrder = 0;
        
        if (!file_exists($filePath)) {
            throw new Exception("El archivo no existe: " . $filePath);
        }

        $xmlContent = file_get_contents($filePath);
        if ($xmlContent === false) {
            throw new Exception("No se pudo leer el archivo: " . $filePath);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        libxml_clear_errors();

        if ($xml === false) {
            throw new Exception("El archivo no es un XML válido");
        }

        $this->processElement($xml, $fileVersionId, null);
    }

    /**
     * Procesa recursivamente un elemento XML
     */
    private function processElement($xmlElement, $fileVersionId, $parentElementId) {
        $elementName = $xmlElement->getName();
        $this->elementOrder++;

        // Determinar el tipo de dato basado en el contenido
        $dataType = $this->detectDataType($xmlElement);
        $dataTypeId = $this->db->getOrCreateDataType($dataType);

        // Guardar el elemento
        $elementId = $this->db->saveElement(
            $fileVersionId,
            $elementName,
            $dataTypeId,
            $parentElementId,
            $this->elementOrder
        );

        // Procesar atributos del elemento
        foreach ($xmlElement->attributes() as $attrName => $attrValue) {
            $isRequired = $this->isRequiredAttribute($attrName);
            $this->db->saveAttribute($elementId, $attrName, (string)$attrValue, $isRequired);
        }

        // Agregar validaciones básicas
        $this->addBasicValidations($elementId, $dataType, $xmlElement);

        // Procesar hijos recursivamente
        foreach ($xmlElement->children() as $child) {
            $this->processElement($child, $fileVersionId, $elementId);
        }
    }

    /**
     * Detecta el tipo de dato basado en el contenido del elemento
     */
    private function detectDataType($xmlElement) {
        $hasChildren = count($xmlElement->children()) > 0;
        
        if ($hasChildren) {
            return 'complex';
        }

        $value = trim((string)$xmlElement);
        
        if (empty($value)) {
            return 'empty';
        }

        if (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                return 'decimal';
            }
            return 'integer';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return 'date';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
            return 'datetime';
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return 'url';
        }

        if (strlen($value) > 255) {
            return 'text';
        }

        return 'string';
    }

    /**
     * Determina si un atributo es requerido (convención común)
     */
    private function isRequiredAttribute($attrName) {
        $requiredAttrs = ['id', 'name', 'type', 'required', 'mandatory'];
        return in_array(strtolower($attrName), $requiredAttrs);
    }

    /**
     * Agrega validaciones básicas según el tipo de dato
     */
    private function addBasicValidations($elementId, $dataType, $xmlElement) {
        $validations = [];

        switch ($dataType) {
            case 'integer':
                $validations[] = [
                    'type' => 'numeric',
                    'rule' => '^[0-9]+$',
                    'message' => 'El valor debe ser un número entero'
                ];
                break;
            case 'decimal':
                $validations[] = [
                    'type' => 'numeric',
                    'rule' => '^[0-9]+(\.[0-9]+)?$',
                    'message' => 'El valor debe ser un número decimal'
                ];
                break;
            case 'date':
                $validations[] = [
                    'type' => 'format',
                    'rule' => 'YYYY-MM-DD',
                    'message' => 'La fecha debe tener formato YYYY-MM-DD'
                ];
                break;
            case 'email':
                $validations[] = [
                    'type' => 'format',
                    'rule' => 'email',
                    'message' => 'Debe ser una dirección de email válida'
                ];
                break;
            case 'string':
                $value = trim((string)$xmlElement);
                if (!empty($value)) {
                    $validations[] = [
                        'type' => 'length',
                        'rule' => 'max:255',
                        'message' => 'La longitud máxima es 255 caracteres'
                    ];
                }
                break;
        }

        // Validación de campo requerido si el elemento tiene valor
        $value = trim((string)$xmlElement);
        $hasChildren = count($xmlElement->children()) > 0;
        if (!empty($value) || $hasChildren) {
            $validations[] = [
                'type' => 'required',
                'rule' => 'not_empty',
                'message' => 'Este campo es requerido'
            ];
        }

        // Guardar validaciones
        foreach ($validations as $validation) {
            $this->db->saveValidation(
                $elementId,
                $validation['type'],
                $validation['rule'],
                $validation['message']
            );
        }
    }
}

// Iniciar sesión para mensajes flash
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Manejar el envío del formulario
$message = '';
$messageType = '';
$fileVersions = [];
$db = null;

try {
    $db = new XDSDatabase();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xds_file'])) {
        try {
            $file = $_FILES['xds_file'];
            $version = $_POST['version'] ?? '1.0';
            
            // Validar archivo
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'El archivo excede el límite de upload_max_filesize en php.ini',
                    UPLOAD_ERR_FORM_SIZE => 'El archivo excede el límite MAX_FILE_SIZE especificado en el formulario',
                    UPLOAD_ERR_PARTIAL => 'El archivo solo fue parcialmente subido',
                    UPLOAD_ERR_NO_FILE => 'Ningún archivo fue subido',
                    UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal',
                    UPLOAD_ERR_CANT_WRITE => 'Falló la escritura del archivo en disco',
                    UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la carga del archivo'
                ];
                $errorMsg = $uploadErrors[$file['error']] ?? 'Error desconocido';
                throw new Exception("Error al subir el archivo: {$errorMsg} (Código: {$file['error']})");
            }

            $allowedExtensions = ['xml', 'xds', 'xsd'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($extension, $allowedExtensions)) {
                throw new Exception("Extensión de archivo no permitida. Use: " . implode(', ', $allowedExtensions));
            }

            // Mover archivo temporal
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    throw new Exception("No se pudo crear el directorio de uploads: {$uploadDir}");
                }
            }

            if (!is_writable($uploadDir)) {
                throw new Exception("El directorio de uploads no tiene permisos de escritura: {$uploadDir}");
            }

            $uniqueFilename = uniqid() . '_' . basename($file['name']);
            $targetPath = $uploadDir . $uniqueFilename;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                $error = error_get_last();
                throw new Exception("No se pudo guardar el archivo subido. Error: " . ($error['message'] ?? 'Desconocido') . ". Verifique permisos en: {$uploadDir}");
            }

            // Guardar información del archivo en la base de datos
            $uploadDate = date('Y-m-d H:i:s');
            $fileVersionId = $db->saveFileVersion($file['name'], $version, $uploadDate);

            // Procesar el archivo XML
            $parser = new XDSParser($db);
            $parser->processFile($targetPath, $fileVersionId);

            $message = "Archivo '{$file['name']}' cargado exitosamente. Versión: {$version}";
            $messageType = 'success';

            // Limpiar archivo temporal después de procesar
            // unlink($targetPath); // Descomentar si no quieres mantener los archivos
            
        } catch (Exception $e) {
            // Si hay un error durante la subida, lanzarlo para que lo capture el catch externo
            throw $e;
        }
    }

    // Obtener lista de archivos cargados
    $fileVersions = $db->getFileVersions();

} catch (Exception $e) {
    // Solo establecer mensaje de error si no es un error fatal de PHP
    if (!isset($message) || empty($message)) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
    // Si ya hay un mensaje de error del bloque interno, no lo sobrescribimos
    
    // Intentar obtener archivos si la DB está disponible
    if ($db !== null) {
        try {
            $fileVersions = $db->getFileVersions();
        } catch (Exception $ex) {
            // Ignorar error al obtener versiones
        }
    }
}

// Obtener lista de archivos cargados si no se ha hecho y la DB está disponible
if (empty($fileVersions) && $db !== null) {
    try {
        $fileVersions = $db->getFileVersions();
    } catch (Exception $ex) {
        // Ignorar error al obtener versiones
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargador de Archivos XDS</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        input[type="file"],
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        input[type="file"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 6px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            width: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .btn:active {
            transform: translateY(0);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-version {
            background: #667eea;
            color: white;
        }

        .badge-count {
            background: #28a745;
            color: white;
        }

        .hierarchy-item {
            padding: 8px 12px;
            margin: 4px 0;
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            border-radius: 4px;
            font-family: monospace;
            font-size: 14px;
        }

        .hierarchy-level-1 { margin-left: 0; }
        .hierarchy-level-2 { margin-left: 20px; }
        .hierarchy-level-3 { margin-left: 40px; }
        .hierarchy-level-4 { margin-left: 60px; }

        .section-title {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 1.5em;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📁 Cargador de Archivos XDS</h1>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Formulario de carga -->
        <div class="card">
            <h2 class="section-title">Subir Nuevo Archivo</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="xds_file">Seleccionar Archivo XDS/XML:</label>
                    <input type="file" 
                           id="xds_file" 
                           name="xds_file" 
                           accept=".xml,.xds,.xsd" 
                           required>
                </div>

                <div class="form-group">
                    <label for="version">Versión del Archivo:</label>
                    <input type="text" 
                           id="version" 
                           name="version" 
                           placeholder="Ej: 1.0, 2.1.3" 
                           value="1.0"
                           required>
                </div>

                <button type="submit" class="btn">⬆️ Cargar</button>
            </form>
        </div>

        <!-- Lista de archivos cargados -->
        <?php if (!empty($fileVersions)): ?>
        <div class="card">
            <h2 class="section-title">Archivos Cargados</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre del Archivo</th>
                        <th>Versión</th>
                        <th>Fecha de Carga</th>
                        <th>Elementos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fileVersions as $fv): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($fv['id']); ?></td>
                        <td><?php echo htmlspecialchars($fv['filename']); ?></td>
                        <td>
                            <span class="badge badge-version">
                                v<?php echo htmlspecialchars($fv['version']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($fv['upload_date']); ?></td>
                        <td>
                            <span class="badge badge-count">
                                <?php echo htmlspecialchars($fv['element_count']); ?> elementos
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Ejemplo de estructura jerárquica (para el último archivo cargado) -->
        <?php 
        if (!empty($fileVersions) && isset($db)) {
            $lastFile = reset($fileVersions);
            $hierarchy = $db->getFileHierarchy($lastFile['id']);
            
            if (!empty($hierarchy)):
        ?>
        <div class="card">
            <h2 class="section-title">
                Estructura Jerárquica - <?php echo htmlspecialchars($lastFile['filename']); ?>
            </h2>
            <div style="margin-top: 15px;">
                <?php 
                // Función simple para determinar el nivel basado en parent_id
                function getLevel($elementId, $elements, $level = 1) {
                    foreach ($elements as $elem) {
                        if ($elem['id'] == $elementId && $elem['parent_element_id'] !== null) {
                            return getLevel($elem['parent_element_id'], $elements, $level + 1);
                        }
                    }
                    return $level;
                }

                foreach ($hierarchy as $item): 
                    $level = getLevel($item['id'], $hierarchy);
                    $levelClass = 'hierarchy-level-' . min($level, 4);
                ?>
                <div class="hierarchy-item <?php echo $levelClass; ?>">
                    <strong><?php echo htmlspecialchars($item['element_name']); ?></strong>
                    <span style="color: #666;">
                        [<?php echo htmlspecialchars($item['type_name']); ?>]
                    </span>
                    <?php if (!empty($item['attributes'])): ?>
                    <div style="font-size: 12px; color: #888; margin-top: 4px;">
                        Atributos: <?php echo htmlspecialchars($item['attributes']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php 
            endif;
        }
        ?>
    </div>
</body>
</html>
