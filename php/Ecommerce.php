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

    public function getApiProducts(array $platforms = [], array $session = []): array
    {
        $providers = $this->filterProviders($platforms);
        $itemsByPlatform = [];
        $db = (new Database())->connect();

        foreach ($providers as $provider) {
            $rawList = $provider->fetchRawProducts();
            $normalizedList = [];
            $values = [];

            foreach ($rawList as $raw) {
                $values[] = [
                    $session['mypos_id'] ?? '',
                    (int)($session['client_id'] ?? 0),
                    $raw['item_id'] ?? '',
                    $raw['padre_id'] ?? '',
                    $provider->getName(),
                    $raw['item_nombre'] ?? '',
                    json_encode($raw['variants'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($raw['categoria'] ?? [], JSON_UNESCAPED_UNICODE),
                    isset($raw['precio']) ? (float)$raw['precio'] : null,
                    $raw['codigo_barra'] ?? null,
                    $raw['codigo_interno'] ?? null,
                    $raw['descripcion'] ?? '',
                    (int)($raw['stock_actual'] ?? 0),
                    (int)($raw['servicio'] ?? 0)
                ];
                $normalizedList[] = $raw;
            }

            if (!empty($values)) {
                $placeholders = implode(',', array_fill(0, count($values), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
                $stmt = $db->prepare("
                    INSERT INTO products (
                        mypos_id, client_id, item_id, padre_id, origen,
                        item_nombre, variants, categoria, precio, codigo_barra, codigo_interno,
                        descripcion, stock_actual, servicio
                    ) VALUES $placeholders
                    ON DUPLICATE KEY UPDATE
                        padre_id = VALUES(padre_id),
                        item_nombre = VALUES(item_nombre),
                        variants = VALUES(variants),
                        categoria = VALUES(categoria),
                        precio = VALUES(precio),
                        codigo_barra = VALUES(codigo_barra),
                        codigo_interno = VALUES(codigo_interno),
                        descripcion = VALUES(descripcion),
                        stock_actual = VALUES(stock_actual),
                        servicio = VALUES(servicio),
                        updated_at = CURRENT_TIMESTAMP
                ");

                $rowTypes = 'sissssssdsssii';
                $types = str_repeat($rowTypes, count($values));

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

            $itemsByPlatform[$provider->getName()] = $normalizedList;
        }

        return ['action' => 'getApiProducts', 'items' => $itemsByPlatform];
    }

    public function getProductsFromDb(array $session = [], int $limit = 100, int $offset = 0): array
    {
        $db = (new Database())->connect();
        $items = [];
        
        $clientId = (int)($session['client_id'] ?? 0);
        $myposId = $session['mypos_id'] ?? '';
        
        $sql = "SELECT * FROM products WHERE client_id = ? AND mypos_id = ? LIMIT ? OFFSET ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("isii", $clientId, $myposId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'item_id' => $row['item_id'],
                'item_aizu_id' => $row['item_aizu_id'] ?? null,
                'padre_id' => $row['padre_id'],
                'item_nombre' => $row['item_nombre'],
                'categoria' => !empty($row['categoria']) ? json_decode($row['categoria'], true) : [],
                'precio' => $row['precio'] !== null ? (float)$row['precio'] : null,
                'codigo_barra' => $row['codigo_barra'],
                'codigo_interno' => $row['codigo_interno'],
                'stock_actual' => $row['stock_actual'] !== null ? (int)$row['stock_actual'] : null,
                'descripcion' => $row['descripcion'],
                'ficha_tecnica' => $row['ficha_tecnica'] ?? null,
                'servicio' => (int)($row['servicio'] ?? 0),
                'variants' => !empty($row['variants']) ? json_decode($row['variants'], true) : [],
                'fiscal' => [
                    'unidad' => $row['fiscal_unidad'] ?? null,
                    'clave' => $row['fiscal_clave'] ?? null,
                    'iva' => $row['fiscal_iva'] ?? null,
                    'ieps' => $row['fiscal_ieps'] ?? null
                ],
                'dimensiones' => [
                    'alto' => $row['dim_alto'] ?? null,
                    'ancho' => $row['dim_ancho'] ?? null,
                    'largo' => $row['dim_largo'] ?? null,
                    'peso' => $row['dim_peso'] ?? null
                ],
                'origen' => $row['origen'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        $stmt->close();
        return ['action' => 'getProductsFromDb', 'timestamp' => date('c'), 'items' => $items];
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