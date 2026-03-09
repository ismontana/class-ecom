<?php

class Ecommerce
{
    private $providers = [];

    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    # filtra vendedores por nombre
    private function filterProviders(array $platforms = []): array
    {
        if (empty($platforms)) return $this->providers;
        $out = [];
        foreach ($this->providers as $p) {
            if (method_exists($p, 'getName') && in_array($p->getName(), $platforms)) {
                $out[] = $p;
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

            foreach ($rawList as $raw) {

                $mypos_id     = $session['mypos_id'];
                $client_id    = (int)$session['client_id'];
                $item_id      = $raw['item_id'];
                $padre_id     = $raw['padre_id'];
                $origen       = $provider->getName();
                $item_nombre  = $raw['item_nombre'];

                $variantsJson = json_encode($raw['variants'] ?? [], JSON_UNESCAPED_UNICODE);
                $categoriaJson = json_encode($raw['categoría'], JSON_UNESCAPED_UNICODE);

                $descripcion  = $raw['descripcion'];
                $stock_actual = (int)$raw['stock_actual'];
                $servicio     = (int)$raw['servicio'];
                $precio       = isset($raw['precio']) ? (float)$raw['precio'] : null;
                $codigo_barra = $raw['codigo_barra'] ?? null;
                $codigo_interno = $raw['codigo_interno'] ?? null;

                $stmt = $db->prepare("
                    INSERT INTO products (
                        mypos_id, client_id, item_id, padre_id, origen,
                        item_nombre, variants, categoria, precio, codigo_barra, codigo_interno,
                        descripcion, stock_actual, servicio
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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

                $stmt->bind_param(
                    "sisssssssdssii",
                    $mypos_id,
                    $client_id,
                    $item_id,
                    $padre_id,
                    $origen,
                    $item_nombre,
                    $variantsJson,
                    $categoriaJson,
                    $precio,
                    $codigo_barra,
                    $codigo_interno,
                    $descripcion,
                    $stock_actual,
                    $servicio
                );

                $stmt->execute();
                $stmt->close();

                $normalizedList[] = $raw;
            }

            $itemsByPlatform[$provider->getName()] = $normalizedList;
        }

        return [
            'action' => 'getApiProducts',
            'items' => $itemsByPlatform
        ];
    }

    public function getProductsFromDb(array $params = []): array
    {
        $db = (new Database())->connect();
        $items = [];

        $sql = "SELECT 
                    item_id, item_aizu_id, padre_id, item_nombre, categoria, precio, 
                    codigo_barra, codigo_interno, stock_actual, descripcion, 
                    ficha_tecnica, servicio, variants, fiscal_unidad, fiscal_clave, 
                    fiscal_iva, fiscal_ieps, dim_alto, dim_ancho, dim_largo, 
                    dim_peso, origen, created_at, updated_at
                FROM products";

        $result = $db->query($sql);

        while ($row = $result->fetch_assoc()) {

            $items[] = [
                'item_id'        => $row['item_id'],
                'item_aizu_id'   => $row['item_aizu_id'],
                'padre_id'       => $row['padre_id'],
                'item_nombre'    => $row['item_nombre'],
                'categoría'      => !empty($row['categoria']) ? json_decode($row['categoria'], true) : [],
                'precio'         => $row['precio'] !== null ? (float)$row['precio'] : null,
                'codigo_barra'   => $row['codigo_barra'],
                'codigo_interno' => $row['codigo_interno'],
                'stock_actual'   => $row['stock_actual'] !== null ? (int)$row['stock_actual'] : null,
                'descripcion'    => $row['descripcion'],
                'ficha_tecnica'  => $row['ficha_tecnica'],
                'servicio'       => (int)$row['servicio'],
                'variants' => !empty($row['variants']) ? json_decode($row['variants'], true) : [],
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
                'origen'        => $row['origen'],
                'created_at'    => $row['created_at'],
                'updated_at'    => $row['updated_at']
            ];
        }

        return [
            'action' => 'getProductsFromDb',
            'timestamp' => date('c'),
            'items' => $items
        ];
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

            foreach ($rawList as $raw) {
                $mypos_id  = $session['mypos_id'];
                $client_id = (int)$session['client_id'];
                $order_id  = $raw['id'];
                $origen    = $provider->getName();

                $clienteJson  = json_encode($raw['cliente'], JSON_UNESCAPED_UNICODE);
                $vendedorJson = json_encode($raw['vendedor'], JSON_UNESCAPED_UNICODE);
                $pagoJson     = json_encode($raw['pago'], JSON_UNESCAPED_UNICODE);
                $partesJson   = json_encode($raw['partes'], JSON_UNESCAPED_UNICODE);
                $notas        = $raw['notas'] ?? null;

                $stmt = $db->prepare("
                    INSERT INTO orders (
                        mypos_id, client_id, order_id, origen,
                        cliente, vendedor, pago, partes, notas
                    ) VALUES (?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        cliente = VALUES(cliente),
                        vendedor = VALUES(vendedor),
                        pago = VALUES(pago),
                        partes = VALUES(partes),
                        notas = VALUES(notas),
                        updated_at = CURRENT_TIMESTAMP
                ");

                $stmt->bind_param(
                    "sisssssss",
                    $mypos_id,
                    $client_id,
                    $order_id,
                    $origen,
                    $clienteJson,
                    $vendedorJson,
                    $pagoJson,
                    $partesJson,
                    $notas
                );

                $stmt->execute();
                $stmt->close();

                $normalizedList[] = $raw;
            }

            $ordersByPlatform[$provider->getName()] = $normalizedList;
        }

        return [
            'action' => 'getApiOrders',
            'orders' => $ordersByPlatform
        ];
    }

    public function getOrdersFromDb(array $session = []): array
    {
        $db = (new Database())->connect();
        $items = [];

        $sql = "SELECT order_id, origen, cliente, vendedor, pago, partes, notas, created_at, updated_at 
                FROM orders WHERE client_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $session['client_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'order_id'   => $row['order_id'],
                'origen'     => $row['origen'],
                'cliente'    => json_decode($row['cliente'], true),
                'vendedor'   => json_decode($row['vendedor'], true),
                'pago'       => json_decode($row['pago'], true),
                'partes'     => json_decode($row['partes'], true),
                'notas'      => $row['notas'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        $stmt->close();

        return [
            'action' => 'getOrders',
            'items' => $items
        ];
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

            foreach ($rawList as $raw) {
                $mypos_id   = $session['mypos_id'];
                $client_id  = (int)$session['client_id'];
                $customer_id= $raw['id'];
                $origen     = $provider->getName();

                $direccionJson = json_encode($raw['direccion'], JSON_UNESCAPED_UNICODE);

                $stmt = $db->prepare("
                    INSERT INTO customers (
                        mypos_id, client_id, customer_id, origen,
                        telefono, movil, lada, notas, nombre, rfc, email, prospecto, direccion
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
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

                $stmt->bind_param(
                    "sisssssssssis",
                    $mypos_id,
                    $client_id,
                    $customer_id,
                    $origen,
                    $raw['telefono'],
                    $raw['movil'],
                    $raw['lada'],
                    $raw['notas'],
                    $raw['nombre'],
                    $raw['rfc'],
                    $raw['email'],
                    $raw['prospecto'],
                    $direccionJson
                );

                $stmt->execute();
                $stmt->close();

                $normalizedList[] = $raw;
            }

            $customersByPlatform[$provider->getName()] = $normalizedList;
        }

        return [
            'action' => 'getApiCustomers',
            'customers' => $customersByPlatform
        ];
    }

    public function getCustomersFromDb(array $session = []): array
    {
        $db = (new Database())->connect();
        $items = [];

        $sql = "SELECT customer_aizu_id, customer_id, origen, telefono, movil, lada, notas, nombre, rfc, email, prospecto, direccion, created_at, updated_at 
                FROM customers WHERE client_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $session['client_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id'        => $row['customer_id'],
                'aizu_id'   => $row['customer_aizu_id'],
                'origen'    => $row['origen'],
                'telefono'  => $row['telefono'],
                'movil'     => $row['movil'],
                'lada'      => $row['lada'],
                'notas'     => $row['notas'],
                'nombre'    => $row['nombre'],
                'rfc'       => $row['rfc'],
                'email'     => $row['email'],
                'prospecto' => (int)$row['prospecto'],
                'direccion' => json_decode($row['direccion'], true),
                'created_at'=> $row['created_at'],
                'updated_at'=> $row['updated_at']
            ];
        }

        $stmt->close();

        return [
            'action' => 'getCustomers',
            'customers' => $items
        ];
    }
}
