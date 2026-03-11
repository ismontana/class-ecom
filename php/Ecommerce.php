<?php

class Ecommerce
{
    private $providers = [];
    private $providersByName = [];

    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
        foreach ($providers as $p) {
            if (method_exists($p, 'getName')) {
                $this->providersByName[$p->getName()] = $p;
            }
        }
    }

    private function filterProviders(array $platforms = []): array
    {
        if (empty($platforms)) return $this->providers;
        $out = [];
        foreach ($platforms as $name) {
            if (isset($this->providersByName[$name])) {
                $out[] = $this->providersByName[$name];
            }
        }
        return $out;
    }

    # <--- PRODUCTS --->

    # Obtiene desde las APIs de las plataformas los productos
    public function getApiProducts(array $platforms = [], array $session = []): array
    {
        $providers = $this->filterProviders($platforms);
        $itemsByPlatform = [];
        $db       = (new Database())->connect();
        $myposId  = $session['mypos_id'] ?? '';

        foreach ($providers as $provider) {
            $rawList = $provider->fetchRawProducts();
            $normalizedList = [];
            $origen  = $provider->getName();

            foreach ($rawList as $raw) {

                $idExterno     = $raw['item_id']        ?? '';
                $padreId       = $raw['padre_id']        ?? null;
                $itemNombre    = $raw['item_nombre']     ?? '';
                $variants      = json_encode($raw['variants']  ?? [], JSON_UNESCAPED_UNICODE);
                $categoria     = json_encode($raw['categoría'] ?? $raw['categoria'] ?? [], JSON_UNESCAPED_UNICODE);
                $precio        = isset($raw['precio'])   ? (float)$raw['precio'] : null;
                $codigoBarra   = $raw['codigo_barra']    ?? null;
                $codigoInterno = $raw['codigo_interno']  ?? null;
                $descripcion   = $raw['descripcion']     ?? '';
                $stockActual   = (int)($raw['stock_actual'] ?? 0);
                $servicio      = (int)($raw['servicio']     ?? 0);

                $stmt = $db->prepare("
                    SELECT id_interno
                    FROM   platform_products
                    WHERE  mypos_id   = ?
                      AND  id_externo = ?
                      AND  origen     = ?
                    LIMIT 1
                ");
                $stmt->bind_param('sss', $myposId, $idExterno, $origen);
                $stmt->execute();
                $stmt->bind_result($idInterno);
                $exists = $stmt->fetch();
                $stmt->close();

                if ($exists && $idInterno) {

                    $stmt = $db->prepare("
                        UPDATE products SET
                            item_nombre    = ?,
                            variants       = ?,
                            categoria      = ?,
                            precio         = ?,
                            codigo_barra   = ?,
                            codigo_interno = ?,
                            descripcion    = ?,
                            stock_actual   = ?,
                            servicio       = ?,
                            updated_at     = CURRENT_TIMESTAMP
                        WHERE mypos_id = ? AND id = ?
                    ");
                    
                    $stmt->bind_param(
                        'sssdsssiisi',
                        $itemNombre, $variants, $categoria, $precio,
                        $codigoBarra, $codigoInterno, $descripcion,
                        $stockActual, $servicio,
                        $myposId, $idInterno
                    );
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $db->prepare("
                        UPDATE platform_products
                        SET    padre_id   = ?,
                               updated_at = CURRENT_TIMESTAMP
                        WHERE  mypos_id   = ?
                          AND  id_externo  = ?
                          AND  origen      = ?
                    ");
                    $stmt->bind_param('ssss', $padreId, $myposId, $idExterno, $origen);
                    $stmt->execute();
                    $stmt->close();

                } else {

                    $stmt = $db->prepare("
                        INSERT INTO products (
                            mypos_id, item_nombre, variants, categoria, precio,
                            codigo_barra, codigo_interno, descripcion, stock_actual, servicio
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->bind_param(
                        'ssssdsssii',
                        $myposId, $itemNombre, $variants, $categoria, $precio,
                        $codigoBarra, $codigoInterno, $descripcion, $stockActual, $servicio
                    );
                    $stmt->execute();
                    $idInterno = $db->insert_id;
                    $stmt->close();

                    $stmt = $db->prepare("
                        INSERT INTO platform_products (mypos_id, id_interno, id_externo, padre_id, origen)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->bind_param('sisss', $myposId, $idInterno, $idExterno, $padreId, $origen);
                    $stmt->execute();
                    $stmt->close();
                }

                $normalizedList[] = $raw;
            }

            $itemsByPlatform[$origen] = $normalizedList;
        }

        return ['action' => 'getApiProducts', 'items' => $itemsByPlatform];
    }

    # Obtiene desde la base de datos los productos
    public function getProductsFromDb(array $session = [], int $limit = 100, int $offset = 0): array
    {
        $db      = (new Database())->connect();
        $items   = [];
        $myposId = $session['mypos_id'] ?? '';

        $sql = "
            SELECT
                p.id              AS item_aizu_id,
                p.mypos_id,
                p.item_nombre,
                p.categoria,
                p.precio,
                p.codigo_barra,
                p.codigo_interno,
                p.descripcion,
                p.ficha_tecnica,
                p.stock_actual,
                p.servicio,
                p.variants,
                p.fiscal_unidad,
                p.fiscal_clave,
                p.fiscal_iva,
                p.fiscal_ieps,
                p.dim_alto,
                p.dim_ancho,
                p.dim_largo,
                p.dim_peso,
                p.created_at,
                p.updated_at,
                pp.id_externo  AS item_id,
                pp.padre_id,
                pp.origen
            FROM   products             p
            LEFT JOIN platform_products pp
                   ON pp.mypos_id   = p.mypos_id
                  AND pp.id_interno = p.id
            WHERE  p.mypos_id = ?
            LIMIT  ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('sii', $myposId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'item_id'        => $row['item_id'],
                'item_aizu_id'   => $row['item_aizu_id'],   
                'padre_id'       => $row['padre_id'],
                'item_nombre'    => $row['item_nombre'],
                'categoria'      => !empty($row['categoria'])
                                        ? json_decode($row['categoria'], true)
                                        : [],
                'precio'         => $row['precio'] !== null ? (float)$row['precio'] : null,
                'codigo_barra'   => $row['codigo_barra'],
                'codigo_interno' => $row['codigo_interno'],
                'stock_actual'   => $row['stock_actual'] !== null ? (int)$row['stock_actual'] : null,
                'descripcion'    => $row['descripcion'],
                'ficha_tecnica'  => $row['ficha_tecnica'],
                'servicio'       => (int)($row['servicio'] ?? 0),
                'variants'       => !empty($row['variants'])
                                        ? json_decode($row['variants'], true)
                                        : [],
                'fiscal' => [
                    'unidad' => $row['fiscal_unidad'],
                    'clave'  => $row['fiscal_clave'],
                    'iva'    => $row['fiscal_iva'],
                    'ieps'   => $row['fiscal_ieps']
                ],
                'dimensiones' => [
                    'alto'  => $row['dim_alto'],
                    'ancho' => $row['dim_ancho'],
                    'largo' => $row['dim_largo'],
                    'peso'  => $row['dim_peso']
                ],
                'origen'     => $row['origen'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        $stmt->close();
        return ['action' => 'getProductsFromDb', 'timestamp' => date('c'), 'items' => $items];
    }

    # Crea productos en la db y en las plataformas
    public function createProduct(array $dataList, array $session = [], array $platforms = []): array
    {
        $myposId = $session['mypos_id'] ?? '';

        if (empty($dataList)) {
            return ['status' => false, 'error' => 'No se recibieron productos en "data"'];
        }

        $db        = (new Database())->connect();
        $providers = $this->filterProviders($platforms);
        $created   = [];

        foreach ($dataList as $item) {

            $rawVariants = $item['variants'] ?? [];

            $itemNombre   = $item['item_nombre']  ?? '';
            $categoria    = json_encode($item['categoria']    ?? [], JSON_UNESCAPED_UNICODE);
            $descripcion  = $item['descripcion']  ?? '';
            $fichaTecnica = isset($item['ficha_tecnica'])
                                ? json_encode($item['ficha_tecnica'], JSON_UNESCAPED_UNICODE)
                                : null;
            $servicio     = (int)($item['servicio'] ?? 0);

            $fiscal      = $item['fiscal']      ?? [];
            $dimensiones = $item['dimensiones'] ?? [];

            $fiscalUnidad = $fiscal['unidad'] ?? null;
            $fiscalClave  = $fiscal['clave']  ?? null;
            $fiscalIva    = isset($fiscal['iva'])   ? (float)$fiscal['iva']  : null;
            $fiscalIeps   = isset($fiscal['ieps'])  ? (float)$fiscal['ieps'] : null;

            $dimAlto  = isset($dimensiones['alto'])  ? (float)$dimensiones['alto']  : null;
            $dimAncho = isset($dimensiones['ancho']) ? (float)$dimensiones['ancho'] : null;
            $dimLargo = isset($dimensiones['largo']) ? (float)$dimensiones['largo'] : null;
            $dimPeso  = isset($dimensiones['peso'])  ? (float)$dimensiones['peso']  : null;

            if (empty($rawVariants)) {
                $rows = [[
                    'variant'        => null,
                    'precio'         => isset($item['precio'])       ? (float)$item['precio']       : null,
                    'stock_actual'   => (int)($item['stock_actual']  ?? 0),
                    'codigo_barra'   => $item['codigo_barra']        ?? null,
                    'codigo_interno' => $item['codigo_interno']      ?? null,
                    'variants_json'  => '[]',
                ]];
            } else {
                $rows = [];
                foreach ($rawVariants as $v) {
                    $rows[] = [
                        'variant'        => $v,
                        'precio'         => isset($v['precio'])        ? (float)$v['precio']        : (isset($item['precio']) ? (float)$item['precio'] : null),
                        'stock_actual'   => isset($v['stock_actual'])   ? (int)$v['stock_actual']    : (int)($item['stock_actual'] ?? 0),
                        'codigo_barra'   => $v['codigo_barra']          ?? $item['codigo_barra']     ?? null,
                        'codigo_interno' => $v['codigo_interno']        ?? $item['codigo_interno']   ?? null,
                        'variants_json'  => json_encode([
                            ['name' => $v['name'] ?? null, 'value' => $v['value'] ?? null]
                        ], JSON_UNESCAPED_UNICODE),
                    ];
                }
            }

            $insertedIds = [];

            foreach ($rows as $index => $row) {

                $precio        = $row['precio'];
                $stockActual   = $row['stock_actual'];
                $codigoBarra   = $row['codigo_barra'];
                $codigoInterno = $row['codigo_interno'];
                $variantsJson  = $row['variants_json'];

                $stmt = $db->prepare("
                    INSERT INTO products (
                        mypos_id,
                        item_nombre, categoria, precio,
                        codigo_barra, codigo_interno, descripcion, ficha_tecnica,
                        stock_actual, servicio, variants,
                        fiscal_unidad, fiscal_clave, fiscal_iva, fiscal_ieps,
                        dim_alto, dim_ancho, dim_largo, dim_peso
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    'sssdssssiisssdddddd',
                    $myposId,
                    $itemNombre, $categoria, $precio,
                    $codigoBarra, $codigoInterno, $descripcion, $fichaTecnica,
                    $stockActual, $servicio, $variantsJson,
                    $fiscalUnidad, $fiscalClave, $fiscalIva, $fiscalIeps,
                    $dimAlto, $dimAncho, $dimLargo, $dimPeso
                );

                if (!$stmt->execute()) {
                    $error = $stmt->error;
                    $stmt->close();
                    $insertedIds[$index] = null;
                    $created[] = [
                        'item_nombre' => $itemNombre,
                        'variant'     => $row['variant'],
                        'status'      => false,
                        'error'       => "Error al insertar en BD: {$error}"
                    ];
                    continue;
                }

                $insertedIds[$index] = $db->insert_id;
                $stmt->close();
            }

            $platformResults = [];

            foreach ($providers as $provider) {
                $origen = $provider->getName();

                $createdVariants = $provider->createProduct($item);

                if (empty($createdVariants) || isset($createdVariants['error'])) {
                    $platformResults[$origen] = [
                        'status' => false,
                        'error'  => $createdVariants['error'] ?? 'Sin respuesta de la plataforma'
                    ];
                    continue;
                }

                foreach ($createdVariants as $index => $variant) {
                    $idExterno = $variant['id_externo'] ?? null;
                    $padreId   = $variant['padre_id']   ?? null;
                    $idInterno = $insertedIds[$index]   ?? null;

                    if (!$idExterno || !$idInterno) continue;

                    $stmt = $db->prepare("
                        INSERT INTO platform_products (mypos_id, id_interno, id_externo, padre_id, origen)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param('sisss', $myposId, $idInterno, $idExterno, $padreId, $origen);
                    $stmt->execute();
                    $stmt->close();
                }

                $platformResults[$origen] = [
                    'status'           => true,
                    'variants_created' => count($createdVariants)
                ];
            }

            foreach ($rows as $index => $row) {
                $idInterno = $insertedIds[$index] ?? null;
                if (!$idInterno) continue;

                $created[] = [
                    'id_interno'  => $idInterno,
                    'item_nombre' => $itemNombre,
                    'variant'     => $row['variant']
                                        ? ['name' => $row['variant']['name'] ?? null, 'value' => $row['variant']['value'] ?? null]
                                        : null,
                    'status'      => true,
                    'platforms'   => $platformResults
                ];
            }
        }

        return ['action' => 'createProduct', 'created' => $created];
    }

    # <--- ORDERS --->

    public function getApiOrders(array $platforms = [], array $session = []): array
    {
        $providers = $this->filterProviders($platforms);
        $ordersByPlatform = [];
        $db = (new Database())->connect();

        foreach ($providers as $provider) {
            $rawList = $provider->fetchRawOrders();
            $normalizedList = [];
            $values = [];

            foreach ($rawList as $raw) {
                $values[] = [
                    $session['mypos_id'] ?? '',
                    (int)($session['client_id'] ?? 0),
                    $raw['id'] ?? '',
                    $provider->getName(),
                    json_encode($raw['cliente'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($raw['vendedor'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($raw['pago'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($raw['partes'] ?? [], JSON_UNESCAPED_UNICODE),
                    $raw['notas'] ?? null
                ];
                $normalizedList[] = $raw;
            }

            if (!empty($values)) {
                $placeholders = implode(',', array_fill(0, count($values), '(?,?,?,?,?,?,?,?,?)'));
                $stmt = $db->prepare("
                    INSERT INTO orders (
                        mypos_id, client_id, order_id, origen,
                        cliente, vendedor, pago, partes, notas
                    ) VALUES $placeholders
                    ON DUPLICATE KEY UPDATE
                        cliente = VALUES(cliente),
                        vendedor = VALUES(vendedor),
                        pago = VALUES(pago),
                        partes = VALUES(partes),
                        notas = VALUES(notas),
                        updated_at = CURRENT_TIMESTAMP
                ");
                
                $types = str_repeat('sisssssss', count($values));
                $flat = [];
                foreach ($values as $row) {
                    foreach ($row as $field) {
                        $flat[] = $field;
                    }
                }
                $stmt->bind_param($types, ...$flat);
                $stmt->execute();
                $stmt->close();
            }

            $ordersByPlatform[$provider->getName()] = $normalizedList;
        }

        return ['action' => 'getApiOrders', 'orders' => $ordersByPlatform];
    }

    public function getOrdersFromDb(array $session = [], int $limit = 100, int $offset = 0): array
    {
        $db = (new Database())->connect();
        $items = [];
        
        $clientId = (int)($session['client_id'] ?? 0);
        $myposId = $session['mypos_id'] ?? '';
        
        $sql = "SELECT * FROM orders WHERE client_id = ? AND mypos_id = ? LIMIT ? OFFSET ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("isii", $clientId, $myposId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'order_id'   => $row['order_id'],
                'origen'     => $row['origen'],
                'cliente'    => json_decode($row['cliente'] ?? '{}', true),
                'vendedor'   => json_decode($row['vendedor'] ?? '{}', true),
                'pago'       => json_decode($row['pago'] ?? '{}', true),
                'partes'     => json_decode($row['partes'] ?? '[]', true),
                'notas'      => $row['notas'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        $stmt->close();

        return ['action' => 'getOrdersFromDb', 'items' => $items];
    }

    # <--- CUSTOMERS --->

    public function getApiCustomers(array $platforms = [], array $session = []): array
    {
        $providers = $this->filterProviders($platforms);
        $customersByPlatform = [];
        $db = (new Database())->connect();

        foreach ($providers as $provider) {
            $rawList = $provider->fetchRawCustomers();
            $normalizedList = [];
            $values = [];

            foreach ($rawList as $raw) {
                $values[] = [
                    $session['mypos_id'] ?? '',
                    (int)($session['client_id'] ?? 0),
                    $raw['id'] ?? '',
                    $provider->getName(),
                    $raw['telefono'] ?? '',
                    $raw['movil'] ?? '',
                    $raw['lada'] ?? '',
                    $raw['notas'] ?? '',
                    $raw['nombre'] ?? '',
                    $raw['rfc'] ?? '',
                    $raw['email'] ?? '',
                    (int)($raw['prospecto'] ?? 0),
                    json_encode($raw['direccion'] ?? [], JSON_UNESCAPED_UNICODE)
                ];
                $normalizedList[] = $raw;
            }

            if (!empty($values)) {
                $placeholders = implode(',', array_fill(0, count($values), '(?,?,?,?,?,?,?,?,?,?,?,?,?)'));
                $stmt = $db->prepare("
                    INSERT INTO customers (
                        mypos_id, client_id, customer_id, origen,
                        telefono, movil, lada, notas, nombre, rfc, email, prospecto, direccion
                    ) VALUES $placeholders
                    ON DUPLICATE KEY UPDATE
                        telefono = VALUES(telefono),
                        movil = VALUES(movil),
                        lada = VALUES(lada),
                        notas = VALUES(notas),
                        nombre = VALUES(nombre),
                        rfc = VALUES(rfc),
                        email = VALUES(email),
                        prospecto = VALUES(prospecto),
                        direccion = VALUES(direccion),
                        updated_at = CURRENT_TIMESTAMP
                ");
                
                $types = str_repeat('sisssssssssis', count($values));
                
                $flat = [];
                foreach ($values as $row) {
                    foreach ($row as $field) {
                        $flat[] = $field;
                    }
                }
                $stmt->bind_param($types, ...$flat);
                $stmt->execute();
                $stmt->close();
            }

            $customersByPlatform[$provider->getName()] = $normalizedList;
        }

        return ['action' => 'getApiCustomers', 'customers' => $customersByPlatform];
    }

    public function getCustomersFromDb(array $session = [], int $limit = 100, int $offset = 0): array
    {
        $db = (new Database())->connect();
        $items = [];

        $clientId = (int)($session['client_id'] ?? 0);
        $myposId = $session['mypos_id'] ?? '';

        $sql = "SELECT customer_aizu_id, customer_id, origen, telefono, movil, lada, notas, 
                       nombre, rfc, email, prospecto, direccion, created_at, updated_at 
                FROM customers 
                WHERE client_id = ? AND mypos_id = ? 
                LIMIT ? OFFSET ?";

        $stmt = $db->prepare($sql);
        $stmt->bind_param("isii", $clientId, $myposId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id'        => $row['customer_id'],
                'aizu_id'   => $row['customer_aizu_id'] ?? null,
                'origen'    => $row['origen'],
                'telefono'  => $row['telefono'],
                'movil'     => $row['movil'],
                'lada'      => $row['lada'],
                'notas'     => $row['notas'],
                'nombre'    => $row['nombre'],
                'rfc'       => $row['rfc'],
                'email'     => $row['email'],
                'prospecto' => (int)$row['prospecto'],
                'direccion' => !empty($row['direccion']) ? json_decode($row['direccion'], true) : [],
                'created_at'=> $row['created_at'],
                'updated_at'=> $row['updated_at']
            ];
        }

        $stmt->close();

        return [
            'action'    => 'getCustomersFromDb',
            'timestamp' => date('c'),
            'customers' => $items
        ];
    }
}