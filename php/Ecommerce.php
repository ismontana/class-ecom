<?php

class Ecommerce
{
    private string $codeStr = 'ECOM-';

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

    # Filtra proveedores por nombre
    private function filterProviders(array $platforms = []): array
    {
        if (empty($platforms)) {
            return $this->providers;
        }

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
        if (count($platforms) > 1) {
            return [
                'action' => 'getApiProducts',
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'En getApiProducts solo se permite una plataforma en platforms[]'
            ];
        }

        $providers = $this->filterProviders($platforms);
        $itemsByPlatform = [];
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';

        foreach ($providers as $provider) {
            $rawList = $provider->fetchRawProducts();
            $normalizedList = [];
            $origen = $provider->getName();

            foreach ($rawList as $raw) {
                # Normalizar campos
                $id_externo     = (string)($raw['item_id'] ?? '');
                $padre_id       = $raw['padre_id'] ?? null;
                $item_nombre    = $raw['item_nombre'] ?? '';
                $variants       = json_encode($raw['variants'] ?? [], JSON_UNESCAPED_UNICODE);
                $categoria      = json_encode($raw['categoría'] ?? $raw['categoria'] ?? [], JSON_UNESCAPED_UNICODE);
                $precio         = isset($raw['precio']) ? (float)$raw['precio'] : null;
                $codigo_barra   = $raw['codigo_barra'] ?? null;
                $codigo_interno = $raw['codigo_interno'] ?? null;
                $descripcion    = $raw['descripcion'] ?? '';
                $stock_actual   = (int)($raw['stock_actual'] ?? 0);
                $servicio       = (int)($raw['servicio'] ?? 0);

                # Buscar vínculo existente
                $stmt = $db->prepare("
                    SELECT id_interno
                    FROM platform_products
                    WHERE mypos_id = ?
                      AND id_externo = ?
                      AND origen = ?
                    LIMIT 1
                ");
                $stmt->bind_param('sss', $myposId, $id_externo, $origen);
                $stmt->execute();
                $stmt->bind_result($id_interno);
                $exists = $stmt->fetch();
                $stmt->close();

                if ($exists && $id_interno) {
                    # Actualizar producto existente
                    $uStmt = $db->prepare("
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
                    $uStmt->bind_param(
                        'sssdsssiisi',
                        $item_nombre,
                        $variants,
                        $categoria,
                        $precio,
                        $codigo_barra,
                        $codigo_interno,
                        $descripcion,
                        $stock_actual,
                        $servicio,
                        $myposId,
                        $id_interno
                    );
                    $uStmt->execute();
                    $uStmt->close();

                    $ppStmt = $db->prepare("
                        UPDATE platform_products
                        SET    padre_id   = ?,
                               updated_at = CURRENT_TIMESTAMP
                        WHERE  mypos_id   = ?
                          AND  id_externo = ?
                          AND  origen     = ?
                    ");
                    $ppStmt->bind_param('ssss', $padre_id, $myposId, $id_externo, $origen);
                    $ppStmt->execute();
                    $ppStmt->close();
                } else {
                    # Insertar nuevo producto
                    $iStmt = $db->prepare("
                        INSERT INTO products (
                            mypos_id, item_nombre, variants, categoria, precio,
                            codigo_barra, codigo_interno, descripcion, stock_actual, servicio
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $iStmt->bind_param(
                        'ssssdsssii',
                        $myposId,
                        $item_nombre,
                        $variants,
                        $categoria,
                        $precio,
                        $codigo_barra,
                        $codigo_interno,
                        $descripcion,
                        $stock_actual,
                        $servicio
                    );
                    $iStmt->execute();
                    $id_interno = $db->insert_id;
                    $iStmt->close();

                    $linkStmt = $db->prepare("
                        INSERT INTO platform_products (mypos_id, id_interno, id_externo, padre_id, origen)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $linkStmt->bind_param('sisss', $myposId, $id_interno, $id_externo, $padre_id, $origen);
                    $linkStmt->execute();
                    $linkStmt->close();
                }

                $normalizedList[] = $raw;
            }

            $itemsByPlatform[$origen] = $normalizedList;
        }

        return ['action' => 'getApiProducts', 'items' => $itemsByPlatform];
    }

    public function getProductsFromDb(array $session = [], int $limit = 100, int $offset = 0): array
    {
        $db = (new Database())->connect();
        $items = [];
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
                'categoria'      => !empty($row['categoria']) ? json_decode($row['categoria'], true) : [],
                'precio'         => $row['precio'] !== null ? (float)$row['precio'] : null,
                'codigo_barra'   => $row['codigo_barra'],
                'codigo_interno' => $row['codigo_interno'],
                'stock_actual'   => $row['stock_actual'] !== null ? (int)$row['stock_actual'] : null,
                'descripcion'    => $row['descripcion'],
                'ficha_tecnica'  => $row['ficha_tecnica'],
                'servicio'       => (int)($row['servicio'] ?? 0),
                'variants'       => !empty($row['variants']) ? json_decode($row['variants'], true) : [],
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

    public function createProduct(array $dataList, array $session = [], array $platforms = []): array
    {
        if (count($platforms) > 1) {
            return [
                'action' => 'createProduct',
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'En createProduct solo se permite una plataforma en platforms[]'
            ];
        }

        $myposId = $session['mypos_id'] ?? '';

        if (empty($dataList)) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'No se recibieron productos en "data"'
            ];
        }

        $db = (new Database())->connect();
        $providers = $this->filterProviders($platforms);
        $created = [];

        foreach ($dataList as $item) {
            # Normalizar campos principales
            $raw_variants = $item['variants'] ?? [];
            $item_nombre = $item['item_nombre'] ?? '';
            $categoria = json_encode($item['categoria'] ?? [], JSON_UNESCAPED_UNICODE);
            $descripcion = $item['descripcion'] ?? '';
            $ficha_tecnica = isset($item['ficha_tecnica']) ? json_encode($item['ficha_tecnica'], JSON_UNESCAPED_UNICODE) : null;
            $servicio = (int)($item['servicio'] ?? 0);

            $fiscal = $item['fiscal'] ?? [];
            $dimensiones = $item['dimensiones'] ?? [];

            $fiscal_unidad = $fiscal['unidad'] ?? null;
            $fiscal_clave  = $fiscal['clave'] ?? null;
            $fiscal_iva    = isset($fiscal['iva']) ? (float)$fiscal['iva'] : null;
            $fiscal_ieps   = isset($fiscal['ieps']) ? (float)$fiscal['ieps'] : null;

            $dim_alto  = isset($dimensiones['alto']) ? (float)$dimensiones['alto'] : null;
            $dim_ancho = isset($dimensiones['ancho']) ? (float)$dimensiones['ancho'] : null;
            $dim_largo = isset($dimensiones['largo']) ? (float)$dimensiones['largo'] : null;
            $dim_peso  = isset($dimensiones['peso']) ? (float)$dimensiones['peso'] : null;

            # Construir filas por variante (o una fila si no hay variantes)
            if (empty($raw_variants)) {
                $rows = [[
                    'variant' => null,
                    'precio' => isset($item['precio']) ? (float)$item['precio'] : null,
                    'stock_actual' => (int)($item['stock_actual'] ?? 0),
                    'codigo_barra' => $item['codigo_barra'] ?? null,
                    'codigo_interno' => $item['codigo_interno'] ?? null,
                    'variants_json' => '[]'
                ]];
            } else {
                $rows = [];
                foreach ($raw_variants as $v) {
                    $rows[] = [
                        'variant' => $v,
                        'precio' => isset($v['precio']) ? (float)$v['precio'] : (isset($item['precio']) ? (float)$item['precio'] : null),
                        'stock_actual' => isset($v['stock_actual']) ? (int)$v['stock_actual'] : (int)($item['stock_actual'] ?? 0),
                        'codigo_barra' => $v['codigo_barra'] ?? $item['codigo_barra'] ?? null,
                        'codigo_interno' => $v['codigo_interno'] ?? $item['codigo_interno'] ?? null,
                        'variants_json' => json_encode([['name' => $v['name'] ?? null, 'value' => $v['value'] ?? null]], JSON_UNESCAPED_UNICODE)
                    ];
                }
            }

            $inserted_ids = [];

            # Insertar cada fila en products
            foreach ($rows as $index => $row) {
                $precio = $row['precio'];
                $stock_actual = $row['stock_actual'];
                $codigo_barra = $row['codigo_barra'];
                $codigo_interno = $row['codigo_interno'];
                $variants_json = $row['variants_json'];

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
                    $item_nombre,
                    $categoria,
                    $precio,
                    $codigo_barra,
                    $codigo_interno,
                    $descripcion,
                    $ficha_tecnica,
                    $stock_actual,
                    $servicio,
                    $variants_json,
                    $fiscal_unidad,
                    $fiscal_clave,
                    $fiscal_iva,
                    $fiscal_ieps,
                    $dim_alto,
                    $dim_ancho,
                    $dim_largo,
                    $dim_peso
                );

                if (!$stmt->execute()) {
                    $stmt->close();
                    $inserted_ids[$index] = null;
                    $created[] = [
                        'item_nombre' => $item_nombre,
                        'variant' => $row['variant'],
                        'status' => 'error',
                        'code' => $this->codeStr . '500',
                        'answer' => 'Error al procesar el producto'
                    ];
                    continue;
                }

                $inserted_ids[$index] = $db->insert_id;
                $stmt->close();
            }

            # Crear en plataformas y vincular platform_products
            $platform_results = [];

            foreach ($providers as $provider) {
                $origen = $provider->getName();
                $created_variants = $provider->createProduct($item);

                if (empty($created_variants) || ($created_variants['status'] ?? '') === 'error') {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code' => $created_variants['code'] ?? $this->codeStr . '502',
                        'answer' => $created_variants['answer'] ?? 'Sin respuesta de la plataforma'
                    ];
                    continue;
                }

                foreach ($created_variants as $index => $variant) {
                    $id_externo = $variant['id_externo'] ?? null;
                    $padre_id   = $variant['padre_id'] ?? null;
                    $id_interno = $inserted_ids[$index] ?? null;

                    if (!$id_externo || !$id_interno) continue;

                    $linkStmt = $db->prepare("
                        INSERT INTO platform_products (mypos_id, id_interno, id_externo, padre_id, origen)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $linkStmt->bind_param('sisss', $myposId, $id_interno, $id_externo, $padre_id, $origen);
                    $linkStmt->execute();
                    $linkStmt->close();
                }

                $platform_results[$origen] = [
                    'status' => 'ok',
                    'variants_created' => count($created_variants)
                ];
            }

            # Construir resultado por cada fila insertada
            foreach ($rows as $index => $row) {
                $id_interno = $inserted_ids[$index] ?? null;
                if (!$id_interno) continue;

                $created[] = [
                    'id_interno' => $id_interno,
                    'item_nombre' => $item_nombre,
                    'variant' => $row['variant'] ? ['name' => $row['variant']['name'] ?? null, 'value' => $row['variant']['value'] ?? null] : null,
                    'status' => 'ok',
                    'platforms' => $platform_results
                ];
            }
        }

        return ['action' => 'createProduct', 'created' => $created];
    }

    # <--- ORDERS --->

    public function getApiOrders(array $platforms = [], array $session = []): array
    {
        if (count($platforms) > 1) {
            return [
                'action' => 'getApiOrders',
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'En getApiOrders solo se permite una plataforma en platforms[]'
            ];
        }

        $providers = $this->filterProviders($platforms);
        $ordersByPlatform = [];
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';

        foreach ($providers as $provider) {
            $rawList = $provider->fetchRawOrders();
            $normalizedList = [];
            $origen = $provider->getName();

            foreach ($rawList as $raw) {

                $id_externo = (string)($raw['id_externo'] ?? '');
                if ($id_externo === '') continue;

                $noPedido = isset($raw['noPedido']) ? (string)$raw['noPedido'] : null;

                $customer_id     = 0;
                $customer_ext_id = (string)($raw['customer_ext_id'] ?? '');

                if ($customer_ext_id !== '') {
                    $cStmt = $db->prepare("
                        SELECT id_interno
                        FROM platform_customers
                        WHERE mypos_id = ? AND id_externo = ? AND origen = ?
                        LIMIT 1
                    ");
                    $cStmt->bind_param('sss', $myposId, $customer_ext_id, $origen);
                    $cStmt->execute();
                    $cStmt->bind_result($resolved_id);
                    $cStmt->fetch();
                    $cStmt->close();
                    $customer_id = $resolved_id ? (int)$resolved_id : 0;
                }

                # direccion_entrega: buscar dirección de envío en tabla address
                $direccion_entrega = null;
                $shippingAddr      = $raw['shipping_address'] ?? null;

                if ($shippingAddr && $customer_id > 0) {
                    $addrCalle = $shippingAddr['calle'] ?? null;
                    $addrCp    = $shippingAddr['cp']    ?? null;

                    if ($addrCalle || $addrCp) {
                        $addrMatchSql    = "SELECT id FROM address WHERE mypos_id = ? AND customer_id = ?";
                        $addrMatchTypes  = 'si';
                        $addrMatchParams = [$myposId, $customer_id];

                        if ($addrCalle) {
                            $addrMatchSql    .= " AND calle = ?";
                            $addrMatchTypes  .= 's';
                            $addrMatchParams[] = $addrCalle;
                        }
                        if ($addrCp) {
                            $addrMatchSql    .= " AND cp = ?";
                            $addrMatchTypes  .= 's';
                            $addrMatchParams[] = $addrCp;
                        }
                        $addrMatchSql .= " LIMIT 1";

                        $addrMatchStmt = $db->prepare($addrMatchSql);
                        $addrMatchStmt->bind_param($addrMatchTypes, ...$addrMatchParams);
                        $addrMatchStmt->execute();
                        $addrMatchStmt->bind_result($matched_addr_id);
                        if ($addrMatchStmt->fetch()) {
                            $direccion_entrega = (int)$matched_addr_id;
                        }
                        $addrMatchStmt->close();
                    }
                }

                # partes
                $partes = $raw['partes'] ?? [];
                $id_productos_arr = [];
                $partes_actualizado = [];

                foreach ($partes as $parte) {
                    $item_ext = (string)($parte['item_id'] ?? '');
                    if ($item_ext === '') {
                        $partes_actualizado[] = array_merge($parte, ['item_aizu_id' => null]);
                        continue;
                    }

                    $ppStmt = $db->prepare("
                        SELECT id_interno
                        FROM platform_products
                        WHERE mypos_id = ? AND id_externo = ? AND origen = ?
                        LIMIT 1
                    ");
                    $ppStmt->bind_param('sss', $myposId, $item_ext, $origen);
                    $ppStmt->execute();
                    $ppStmt->bind_result($resolved_product_id);
                    $ppFound = $ppStmt->fetch();
                    $ppStmt->close();

                    if ($ppFound && $resolved_product_id) {
                        $id_productos_arr[] = (string)(int)$resolved_product_id;
                        $partes_actualizado[] = array_merge($parte, ['item_aizu_id' => (int)$resolved_product_id]);
                    } else {
                        $partes_actualizado[] = array_merge($parte, ['item_aizu_id' => null]);
                    }
                }

                $raw['partes'] = $partes_actualizado;

                $id_productos_arr = array_values(array_unique($id_productos_arr));
                $id_productos = !empty($id_productos_arr) ? implode(',', $id_productos_arr) : '0';

                $item_nombres = mb_substr(implode(',', array_column($partes, 'item_nombre')), 0, 150);
                $cantidades   = mb_substr(implode(',', array_column($partes, 'cant')),        0, 150);
                $precios      = mb_substr(implode(',', array_column($partes, 'precio')),      0, 150);

                # vendedor
                $vendedor        = $raw['vendedor'] ?? [];
                $vendedor_id     = $vendedor['aizu_id'] ?? null;
                $vendedor_user   = $vendedor['user']    ?? $origen;
                $vendedor_nombre = $vendedor['nombre']  ?? $origen;

                # pago
                $pago        = $raw['pago'] ?? [];
                $forma_pago  = $pago['forma_pago']  ?? null;
                $metodo_pago = $pago['metodo_pago'] ?? null;
                $moneda      = $pago['moneda']      ?? 'MXN';
                $tasa_mon    = isset($pago['tasa_moneda']) ? (float)$pago['tasa_moneda'] : 1.0;

                $anticipo       = isset($raw['anticipo'])    ? (float)$raw['anticipo']    : 0.00;
                $descuento      = isset($raw['descuento'])   ? (float)$raw['descuento']   : 0.00;
                $tipo_descuento = $raw['tipo_descuento']     ?? 'G';

                $fecha_pedido  = $raw['fecha_pedido'] ?? null;
                $fecha_inicio  = $raw['fecha_inicio'] ?? null;
                $fecha_entrega = $raw['fecha_entrega'] ?? ($raw['fecha_final'] ?? null);

                $iva            = isset($raw['iva'])            ? (float)$raw['iva']            : null;
                $porcentaje_iva = isset($raw['porcentaje_iva']) ? (float)$raw['porcentaje_iva'] : null;

                $pagado    = !empty($raw['pagado'])    ? 1 : 0;
                $entregado = !empty($raw['entregado']) ? 1 : 0;
                $cancelado = !empty($raw['cancelado']) ? 1 : 0;
                $facturado = !empty($raw['facturado']) ? 1 : 0;

                $hora_entrega_inicio = $raw['hora_entrega_inicio'] ?? '00:00:00';
                $hora_entrega_final  = $raw['hora_entrega_final']  ?? '23:59:59';
                $estimado            = $raw['estimado']            ?? null;

                $folio_fiscal = $raw['folio_fiscal'] ?? null;
                $rfc_emisor   = $raw['rfc_emisor']   ?? null;
                $rfc_receptor = $raw['rfc_receptor'] ?? null;

                $total = isset($raw['total']) ? (float)$raw['total'] : null;
                $notas = $raw['notas'] ?? null;

                # verificar si existe
                $id_interno = null;

                $chkStmt = $db->prepare("
                    SELECT id_interno
                    FROM platform_orders
                    WHERE mypos_id = ? AND id_externo = ? AND origen = ?
                    LIMIT 1
                ");
                $chkStmt->bind_param('sss', $myposId, $id_externo, $origen);
                $chkStmt->execute();
                $chkStmt->bind_result($id_interno);
                $exists = $chkStmt->fetch();
                $chkStmt->close();

                $id_interno = $id_interno ? (int)$id_interno : null;

                if ($exists && $id_interno) {

                    $uStmt = $db->prepare("
                        UPDATE orders SET
                            customer_id = ?,
                            vendedor_id = ?, vendedor_user = ?, vendedor_nombre = ?,
                            id_productos = ?, item_nombres = ?, cantidad = ?, precios = ?,
                            forma_pago = ?, metodo_pago = ?, moneda = ?, tasa_moneda = ?,
                            total = ?, anticipo = ?, descuento = ?, tipo_descuento = ?,
                            iva = ?, porcentaje_iva = ?,
                            fecha_pedido = ?, fecha_inicio = ?, fecha_entrega = ?,
                            hora_entrega_inicio = ?, hora_entrega_final = ?, estimado = ?,
                            pagado = ?, entregado = ?, cancelado = ?, facturado = ?,
                            folio_fiscal = ?, rfc_emisor = ?, rfc_receptor = ?,
                            notas = ?,
                            noPedido = ?, direccion_entrega = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE mypos_id = ? AND id = ?
                    ");

                    $uStmt->bind_param(
                        str_repeat('s', 36),
                        $customer_id,
                        $vendedor_id, $vendedor_user, $vendedor_nombre,
                        $id_productos, $item_nombres, $cantidades, $precios,
                        $forma_pago, $metodo_pago, $moneda, $tasa_mon,
                        $total, $anticipo, $descuento, $tipo_descuento,
                        $iva, $porcentaje_iva,
                        $fecha_pedido, $fecha_inicio, $fecha_entrega,
                        $hora_entrega_inicio, $hora_entrega_final, $estimado,
                        $pagado, $entregado, $cancelado, $facturado,
                        $folio_fiscal, $rfc_emisor, $rfc_receptor,
                        $notas,
                        $noPedido, $direccion_entrega,
                        $myposId, $id_interno
                    );

                    $uStmt->execute();
                    $uStmt->close();

                } else {

                    $iStmt = $db->prepare("
                        INSERT INTO orders (
                            mypos_id, customer_id,
                            vendedor_id, vendedor_user, vendedor_nombre,
                            id_productos, item_nombres, cantidad, precios,
                            forma_pago, metodo_pago, moneda, tasa_moneda,
                            total, anticipo, descuento, tipo_descuento,
                            iva, porcentaje_iva,
                            fecha_pedido, fecha_inicio, fecha_entrega,
                            hora_entrega_inicio, hora_entrega_final, estimado,
                            pagado, entregado, cancelado, facturado,
                            folio_fiscal, rfc_emisor, rfc_receptor,
                            notas,
                            noPedido, direccion_entrega
                        ) VALUES (
                            ?, ?,
                            ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?,
                            ?,
                            ?, ?
                        )
                    ");

                    $iStmt->bind_param(
                        str_repeat('s', 35),
                        $myposId, $customer_id,
                        $vendedor_id, $vendedor_user, $vendedor_nombre,
                        $id_productos, $item_nombres, $cantidades, $precios,
                        $forma_pago, $metodo_pago, $moneda, $tasa_mon,
                        $total, $anticipo, $descuento, $tipo_descuento,
                        $iva, $porcentaje_iva,
                        $fecha_pedido, $fecha_inicio, $fecha_entrega,
                        $hora_entrega_inicio, $hora_entrega_final, $estimado,
                        $pagado, $entregado, $cancelado, $facturado,
                        $folio_fiscal, $rfc_emisor, $rfc_receptor,
                        $notas,
                        $noPedido, $direccion_entrega
                    );

                    if (!$iStmt->execute()) {
                        error_log("INSERT error: " . $iStmt->error);
                        $iStmt->close();
                        continue;
                    }

                    $id_interno = (int)$db->insert_id;
                    $iStmt->close();

                    $ppStmt = $db->prepare("
                        INSERT IGNORE INTO platform_orders (mypos_id, id_interno, id_externo, origen)
                        VALUES (?, ?, ?, ?)
                    ");
                    $ppStmt->bind_param('siss', $myposId, $id_interno, $id_externo, $origen);
                    $ppStmt->execute();
                    $ppStmt->close();
                }

                $normalizedList[] = $raw;
            }

            $ordersByPlatform[$origen] = $normalizedList;
        }

        return ['action' => 'getApiOrders', 'orders' => $ordersByPlatform];
    }

    public function getOrdersFromDb(array $session = [], int $limit = 100, int $offset = 0): array
    {
        $db = (new Database())->connect();
        $orders = [];
        $myposId = $session['mypos_id'] ?? '';

        $sql = "
            SELECT
                o.id,
                o.mypos_id,
                o.customer_id,
                o.vendedor_id,
                o.vendedor_user,
                o.id_productos,
                o.item_nombres,
                o.cantidad,
                o.precios,
                o.total,
                o.descuento,
                o.tipo_descuento,
                o.iva,
                o.porcentaje_iva,
                o.forma_pago,
                o.metodo_pago,
                o.moneda,
                o.tasa_moneda,
                o.notas,
                o.descontar_stock,
                o.fecha_pedido,
                o.fecha_inicio,
                o.fecha_entrega,
                o.hora_entrega_inicio,
                o.hora_entrega_final,
                o.pagado,
                o.comision_aizu_id,
                o.comision_importe,
                o.entregado,
                o.cancelado,
                o.facturado,
                o.folio_fiscal,
                o.rfc_emisor,
                o.rfc_receptor,
                o.anticipo,
                o.noPedido,
                o.direccion_entrega,
                o.created_at,

                c.nombre          AS cliente_nombre,

                a.calle           AS addr_calle,
                a.no_ext          AS addr_no_ext,
                a.no_int          AS addr_no_int,
                a.colonia         AS addr_colonia,
                a.cp              AS addr_cp,
                a.municipio       AS addr_municipio,
                a.estado          AS addr_estado,
                a.ciudad          AS addr_ciudad,
                a.pais            AS addr_pais,
                a.referencias     AS addr_referencias,
                a.latitud         AS addr_latitud,
                a.longitud        AS addr_longitud

            FROM   orders o
            LEFT JOIN customers c
                ON c.id       = o.customer_id
                AND c.mypos_id = o.mypos_id
            LEFT JOIN address a
                ON a.id       = o.direccion_entrega
            WHERE  o.mypos_id = ?
            LIMIT  ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('sii', $myposId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $domicilio = implode(', ', array_filter([
                $row['addr_calle'],
                $row['addr_no_ext'] ? 'No. ' . $row['addr_no_ext'] : null,
                $row['addr_no_int'] ? 'Int. ' . $row['addr_no_int'] : null,
                $row['addr_colonia'],
                $row['addr_municipio'],
                $row['addr_estado'],
                $row['addr_cp'] ? 'C.P. ' . $row['addr_cp'] : null,
                $row['addr_pais'],
            ])) ?: null;

            $gps = ($row['addr_latitud'] !== null && $row['addr_longitud'] !== null)
                ? $row['addr_latitud'] . ',' . $row['addr_longitud']
                : null;

            $orders[] = [
                'mypos_id'                => $row['mypos_id'],
                'id'                      => (int)$row['id'],
                'noPedido'                => $row['noPedido'],
                'id_conekta'              => null,
                'status_conekta'          => null,
                'status_ruta'             => null,
                'tipo_precio'             => null,
                'tipos_precio'            => null,
                'producto'                => $row['item_nombres'],
                'id_producto'             => $row['id_productos'],
                'cantidad'                => $row['cantidad'],
                'precio'                  => $row['precios'],
                'total'                   => $row['total'] !== null ? (string)$row['total'] : null,
                'total_comision_ruta'     => null,
                'total_descuento'         => $row['descuento'] !== null ? (string)$row['descuento'] : null,
                'concepto_descuento'      => $row['tipo_descuento'],
                'monto_descuento_producto'=> null,
                'tipoProducto'            => null,
                'lotes'                   => null,
                'fecha_rensus'            => null,
                'iva'                     => $row['iva'],
                'porcentaje_iva'          => $row['porcentaje_iva'] !== null ? (float)$row['porcentaje_iva'] : null,
                'sumar_iva'               => null,
                'claveSat'                => null,
                'unidadSat'               => null,
                'tipo_pago'               => $row['forma_pago'],
                'banco'                   => null,
                'metodo_pago'             => $row['metodo_pago'],
                'moneda'                  => $row['moneda'],
                'monedaTipoCambio'        => $row['tasa_moneda'] !== null ? (float)$row['tasa_moneda'] : null,
                'notas'                   => $row['notas'],
                'descuento_stock'         => (int)$row['descontar_stock'],
                'fecha_pedido'            => $row['fecha_pedido'],
                'fecha_inicio'            => $row['fecha_inicio'],
                'fecha_entrega'           => $row['fecha_entrega'],
                'hora_entrega_inicio'     => $row['hora_entrega_inicio'],
                'hora_entrega_final'      => $row['hora_entrega_final'],
                'dias_retraso'            => null,
                'cliente'                 => $row['cliente_nombre'],
                'id_cliente'              => $row['customer_id'] !== null ? (int)$row['customer_id'] : null,
                'encargados'              => null,
                'pagado'                  => (int)$row['pagado'],
                'empleado_id'             => $row['vendedor_id'],
                'comision_vendedor'       => $row['comision_importe'] !== null ? (string)$row['comision_importe'] : null,
                'id_comision_general'     => $row['comision_aizu_id'] !== null ? (int)$row['comision_aizu_id'] : null,
                'entregado'               => (int)$row['entregado'],
                'fecha_entregado'         => null,
                'cancelado'               => (int)$row['cancelado'],
                'fecha_cancelado'         => null,
                'facturado'               => (int)$row['facturado'],
                'folioFiscal'             => $row['folio_fiscal'],
                'rfc_emisor'              => $row['rfc_emisor'],
                'rfc_receptor'            => $row['rfc_receptor'],
                'fecha_factura'           => null,
                'facturaXml'              => null,
                'facturaPdf'              => null,
                'facturaCancelada'        => null,
                'tipoFactura'             => null,
                'totalFactura'            => null,
                'id_ruta_origen'          => null,
                'id_ruta'                 => null,
                'corte'                   => null,
                'totalAnticipo'           => $row['anticipo'] !== null ? (string)$row['anticipo'] : null,
                'kiosko'                  => null,
                'recoleccion'             => null,
                'domicilioEntrega'        => $domicilio,
                'coordenadasGPSEntrega'   => $gps,
                'etq'                     => null,
                'idTasaDeInteres'         => null,
                'idAval'                  => null,
                'idBeneficiario'          => null,
                'factorISR'               => null,
                'factorIVA'               => null,
                'cantPeriodRecurrencia'   => null,
                'periodicidadRecurrencia' => null,
                'idInicioRecurrencia'     => null,
                'fechaAlta'               => $row['created_at'],
            ];
        }

        $stmt->close();

        return [
            'action'    => 'getOrdersFromDb',
            'timestamp' => date('c'),
            'orders'    => $orders
        ];
    }

        # Obtiene un pedido específico desde la BD con sus vínculos de plataforma
    public function getOrderById(int $idInterno, array $session = []): array
    {
        $db      = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';

        $stmt = $db->prepare("
            SELECT
                o.id, o.mypos_id, o.customer_id, o.noPedido,
                o.vendedor_id, o.vendedor_user, o.vendedor_nombre,
                o.id_productos, o.item_nombres, o.cantidad, o.precios,
                o.cuenta_receptora_id, o.cuenta_receptora_clabe, o.cuenta_receptora_beneficiario,
                o.comision_aizu_id, o.comision_nombre, o.comision_porcentaje, o.comision_importe,
                o.cuenta_emisora, o.forma_pago, o.metodo_pago, o.moneda, o.tasa_moneda,
                o.total, o.anticipo, o.descuento, o.tipo_descuento,
                o.iva, o.porcentaje_iva,
                o.direccion_entrega,
                o.fecha_pedido, o.fecha_inicio, o.fecha_entrega, o.estimado,
                o.hora_entrega_inicio, o.hora_entrega_final,
                o.pagado, o.entregado, o.cancelado, o.descontar_stock, o.facturado,
                o.folio_fiscal, o.rfc_emisor, o.rfc_receptor,
                o.notas, o.created_at, o.updated_at,
                po.id_externo AS order_id_externo,
                po.origen     AS order_origen
            FROM   orders o
            LEFT JOIN platform_orders po
                   ON po.mypos_id   = o.mypos_id
                  AND po.id_interno = o.id
            WHERE  o.id = ? AND o.mypos_id = ?
        ");
        $stmt->bind_param('is', $idInterno, $myposId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        $order     = null;
        $platforms = [];

        while ($row = $result->fetch_assoc()) {
            if (!$order) {
                $order = [
                    'id'                            => (int)$row['id'],
                    'mypos_id'                      => $row['mypos_id'],
                    'noPedido'                      => $row['noPedido'],
                    'customer_id'                   => (int)$row['customer_id'],
                    'vendedor_id'                   => $row['vendedor_id'],
                    'vendedor_user'                 => $row['vendedor_user'],
                    'vendedor_nombre'               => $row['vendedor_nombre'],
                    'id_productos'                  => $row['id_productos'],
                    'item_nombres'                  => $row['item_nombres'],
                    'cantidad'                      => $row['cantidad'],
                    'precios'                       => $row['precios'],
                    'cuenta_receptora_id'           => $row['cuenta_receptora_id'],
                    'cuenta_receptora_clabe'        => $row['cuenta_receptora_clabe'],
                    'cuenta_receptora_beneficiario' => $row['cuenta_receptora_beneficiario'],
                    'comision_aizu_id'              => $row['comision_aizu_id'],
                    'comision_nombre'               => $row['comision_nombre'],
                    'comision_porcentaje'           => $row['comision_porcentaje'] !== null ? (float)$row['comision_porcentaje'] : null,
                    'comision_importe'              => $row['comision_importe']    !== null ? (float)$row['comision_importe']    : null,
                    'cuenta_emisora'                => $row['cuenta_emisora'],
                    'forma_pago'                    => $row['forma_pago'],
                    'metodo_pago'                   => $row['metodo_pago'],
                    'moneda'                        => $row['moneda'],
                    'tasa_moneda'                   => $row['tasa_moneda']    !== null ? (float)$row['tasa_moneda']    : null,
                    'total'                         => $row['total']          !== null ? (float)$row['total']          : null,
                    'anticipo'                      => $row['anticipo']       !== null ? (float)$row['anticipo']       : null,
                    'descuento'                     => $row['descuento']      !== null ? (float)$row['descuento']      : null,
                    'tipo_descuento'                => $row['tipo_descuento'],
                    'iva'                           => $row['iva']            !== null ? (float)$row['iva']            : null,
                    'porcentaje_iva'                => $row['porcentaje_iva'] !== null ? (float)$row['porcentaje_iva'] : null,
                    'direccion_entrega'             => (int)$row['direccion_entrega'],
                    'fecha_pedido'                  => $row['fecha_pedido'],
                    'fecha_inicio'                  => $row['fecha_inicio'],
                    'fecha_entrega'                 => $row['fecha_entrega'],
                    'hora_entrega_inicio'           => $row['hora_entrega_inicio'],
                    'hora_entrega_final'            => $row['hora_entrega_final'],
                    'estimado'                      => $row['estimado'],
                    'pagado'                        => (int)$row['pagado'],
                    'entregado'                     => (int)$row['entregado'],
                    'cancelado'                     => (int)$row['cancelado'],
                    'descontar_stock'               => (int)$row['descontar_stock'],
                    'facturado'                     => (int)$row['facturado'],
                    'folio_fiscal'                  => $row['folio_fiscal'],
                    'rfc_emisor'                    => $row['rfc_emisor'],
                    'rfc_receptor'                  => $row['rfc_receptor'],
                    'notas'                         => $row['notas'],
                    'created_at'                    => $row['created_at'],
                    'updated_at'                    => $row['updated_at'],
                ];
            }

            if ($row['order_origen']) {
                $platforms[] = [
                    'origen'     => $row['order_origen'],
                    'id_externo' => $row['order_id_externo'],
                ];
            }
        }

        if (!$order) {
            return [
                'action' => 'getOrderById',
                'status' => 'error',
                'code'   => $this->codeStr . '404',
                'answer' => 'Pedido no encontrado'
            ];
        }

        $order['platforms'] = $platforms;

        return [
            'action' => 'getOrderById',
            'order'  => $order
        ];
    }

    # Crea uno o más pedidos en la BD y opcionalmente en plataformas
    public function createOrder(array $data, array $session = [], array $platforms = []): array
    {

        $providers = $this->filterProviders($platforms);
        $db        = (new Database())->connect();
        $myposId   = $session['mypos_id'] ?? '';
        $created   = [];

        foreach ($data as $order) {

            $customer_id = $order['customer_id'] ?? null;
            $partes      = $order['partes']       ?? [];
            $noPedido    = $order['noPedido']      ?? null;

            # Validar campos obligatorios
            if (!$customer_id) {
                $created[] = [
                    'status' => 'error',
                    'code'   => $this->codeStr . '422',
                    'answer' => 'El campo customer_id es obligatorio'
                ];
                continue;
            }

            if (empty($partes)) {
                $created[] = [
                    'status' => 'error',
                    'code'   => $this->codeStr . '422',
                    'answer' => 'El pedido debe tener al menos un producto en partes[]'
                ];
                continue;
            }

            # Verificar que el cliente existe
            $custStmt = $db->prepare("SELECT id FROM customers WHERE id = ? AND mypos_id = ? LIMIT 1");
            $custStmt->bind_param('is', $customer_id, $myposId);
            $custStmt->execute();
            $custStmt->store_result();

            if ($custStmt->num_rows === 0) {
                $custStmt->close();
                $created[] = [
                    'status' => 'error',
                    'code'   => $this->codeStr . '404',
                    'answer' => "Cliente con id {$customer_id} no encontrado"
                ];
                continue;
            }
            $custStmt->close();

            # Verificar duplicado por noPedido
            if ($noPedido !== null && $noPedido !== '') {
                $dupStmt = $db->prepare("SELECT id FROM orders WHERE mypos_id = ? AND noPedido = ? LIMIT 1");
                $dupStmt->bind_param('ss', $myposId, $noPedido);
                $dupStmt->execute();
                $dupStmt->store_result();

                if ($dupStmt->num_rows > 0) {
                    $dupStmt->close();
                    $created[] = [
                        'noPedido' => $noPedido,
                        'status'   => 'error',
                        'code'     => $this->codeStr . '409',
                        'answer'   => "Ya existe un pedido con noPedido {$noPedido}"
                    ];
                    continue;
                }
                $dupStmt->close();
            }

            # Construir campos derivados de partes
            $id_productos_arr = array_filter(
                array_map(fn($p) => isset($p['item_aizu_id']) ? (string)(int)$p['item_aizu_id'] : null, $partes)
            );
            $id_productos = !empty($id_productos_arr) ? implode(',', array_values($id_productos_arr)) : '0';
            $item_nombres = mb_substr(implode(',', array_column($partes, 'item_nombre')), 0, 150);
            $cantidades   = mb_substr(implode(',', array_column($partes, 'cant')),        0, 150);
            $precios      = mb_substr(implode(',', array_column($partes, 'precio')),      0, 150);

            # Datos de pago
            $pago        = $order['pago']        ?? [];
            $forma_pago  = $pago['forma_pago']   ?? null;
            $metodo_pago = $pago['metodo_pago']  ?? null;
            $moneda      = $pago['moneda']        ?? 'MXN';
            $tasa_mon    = isset($pago['tasa_moneda']) ? (float)$pago['tasa_moneda'] : 1.0;

            # Vendedor
            $vendedor        = $order['vendedor'] ?? [];
            $vendedor_id     = $vendedor['aizu_id'] ?? null;
            $vendedor_user   = $vendedor['user']    ?? null;
            $vendedor_nombre = $vendedor['nombre']  ?? null;

            # Cuenta receptora / comisión
            $cuenta_rec_id    = $order['cuenta_receptora_id']           ?? null;
            $cuenta_rec_clabe = $order['cuenta_receptora_clabe']         ?? null;
            $cuenta_rec_benef = $order['cuenta_receptora_beneficiario']  ?? null;
            $comision_id      = $order['comision_aizu_id']               ?? null;
            $comision_nombre  = $order['comision_nombre']                ?? null;
            $comision_porc    = isset($order['comision_porcentaje']) ? (float)$order['comision_porcentaje'] : null;
            $comision_imp     = isset($order['comision_importe'])    ? (float)$order['comision_importe']    : null;
            $cuenta_emisora   = $order['cuenta_emisora']                 ?? null;

            # Financiero
            $total          = isset($order['total'])          ? (float)$order['total']          : null;
            $anticipo       = isset($order['anticipo'])       ? (float)$order['anticipo']       : 0.00;
            $descuento      = isset($order['descuento'])      ? (float)$order['descuento']      : 0.00;
            $tipo_descuento = $order['tipo_descuento']        ?? 'G';
            $iva            = isset($order['iva'])            ? (float)$order['iva']            : null;
            $porcentaje_iva = isset($order['porcentaje_iva']) ? (float)$order['porcentaje_iva'] : null;

            # Fechas
            $fecha_pedido        = $order['fecha_pedido']        ?? null;
            $fecha_inicio        = $order['fecha_inicio']        ?? null;
            $fecha_entrega       = $order['fecha_entrega']       ?? null;
            $hora_entrega_inicio = $order['hora_entrega_inicio'] ?? '00:00:00';
            $hora_entrega_final  = $order['hora_entrega_final']  ?? '23:59:59';
            $estimado            = $order['estimado']            ?? null;

            # Dirección de entrega
            $direccion_entrega = $order['direccion_entrega'] ?? 0;

            # Estados
            $pagado          = !empty($order['pagado'])          ? 1 : 0;
            $entregado       = !empty($order['entregado'])       ? 1 : 0;
            $cancelado       = !empty($order['cancelado'])       ? 1 : 0;
            $descontar_stock = !empty($order['descontar_stock']) ? 1 : 0;
            $facturado       = !empty($order['facturado'])       ? 1 : 0;

            # Factura
            $folio_fiscal = $order['folio_fiscal'] ?? null;
            $rfc_emisor   = $order['rfc_emisor']   ?? null;
            $rfc_receptor = $order['rfc_receptor'] ?? null;
            $notas        = $order['notas']        ?? null;

            $stmt = $db->prepare("
                INSERT INTO orders (
                    mypos_id, customer_id, noPedido,
                    vendedor_id, vendedor_user, vendedor_nombre,
                    id_productos, item_nombres, cantidad, precios,
                    cuenta_receptora_id, cuenta_receptora_clabe, cuenta_receptora_beneficiario,
                    comision_aizu_id, comision_nombre, comision_porcentaje, comision_importe,
                    cuenta_emisora, forma_pago, metodo_pago, moneda, tasa_moneda,
                    total, anticipo, descuento, tipo_descuento,
                    iva, porcentaje_iva,
                    direccion_entrega,
                    fecha_pedido, fecha_inicio, fecha_entrega, estimado,
                    hora_entrega_inicio, hora_entrega_final,
                    pagado, entregado, cancelado, descontar_stock, facturado,
                    folio_fiscal, rfc_emisor, rfc_receptor,
                    notas
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?
                )
            ");

            $stmt->bind_param(
                str_repeat('s', 44),
                $myposId, $customer_id, $noPedido,
                $vendedor_id, $vendedor_user, $vendedor_nombre,
                $id_productos, $item_nombres, $cantidades, $precios,
                $cuenta_rec_id, $cuenta_rec_clabe, $cuenta_rec_benef,
                $comision_id, $comision_nombre, $comision_porc, $comision_imp,
                $cuenta_emisora, $forma_pago, $metodo_pago, $moneda, $tasa_mon,
                $total, $anticipo, $descuento, $tipo_descuento,
                $iva, $porcentaje_iva,
                $direccion_entrega,
                $fecha_pedido, $fecha_inicio, $fecha_entrega, $estimado,
                $hora_entrega_inicio, $hora_entrega_final,
                $pagado, $entregado, $cancelado, $descontar_stock, $facturado,
                $folio_fiscal, $rfc_emisor, $rfc_receptor,
                $notas
            );

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                $created[] = [
                    'noPedido' => $noPedido,
                    'status'   => 'error',
                    'code'     => $this->codeStr . '500',
                    'answer'   => 'Error al guardar el pedido: ' . $err
                ];
                continue;
            }

            $id_interno = (int)$db->insert_id;
            $stmt->close();

            # Crear en plataformas
            $platform_results = [];

            foreach ($providers as $provider) {
                $origen = $provider->getName();

                # Verificar si ya está vinculado
                $lnkStmt = $db->prepare("
                    SELECT id FROM platform_orders
                    WHERE mypos_id = ? AND id_interno = ? AND origen = ?
                    LIMIT 1
                ");
                $lnkStmt->bind_param('sis', $myposId, $id_interno, $origen);
                $lnkStmt->execute();
                $lnkStmt->store_result();
                $alreadyLinked = $lnkStmt->num_rows > 0;
                $lnkStmt->close();

                if ($alreadyLinked) {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code'   => $this->codeStr . '409',
                        'answer' => "El pedido ya está vinculado a {$origen}"
                    ];
                    continue;
                }

                # Resolver id_externo del cliente para esta plataforma
                $custExtStmt = $db->prepare("
                    SELECT id_externo FROM platform_customers
                    WHERE mypos_id = ? AND id_interno = ? AND origen = ?
                    LIMIT 1
                ");
                $custExtStmt->bind_param('sis', $myposId, $customer_id, $origen);
                $custExtStmt->execute();
                $custExtStmt->bind_result($customer_ext_id);
                $custExtStmt->fetch();
                $custExtStmt->close();

                # Resolver id_externo de cada producto para esta plataforma
                $partesResolved = [];
                foreach ($partes as $parte) {
                    $item_aizu_id = $parte['item_aizu_id'] ?? null;
                    $item_ext_id  = null;

                    if ($item_aizu_id) {
                        $ppStmt = $db->prepare("
                            SELECT id_externo FROM platform_products
                            WHERE mypos_id = ? AND id_interno = ? AND origen = ?
                            LIMIT 1
                        ");
                        $ppStmt->bind_param('sis', $myposId, $item_aizu_id, $origen);
                        $ppStmt->execute();
                        $ppStmt->bind_result($item_ext_id);
                        $ppStmt->fetch();
                        $ppStmt->close();
                    }

                    $partesResolved[] = array_merge($parte, ['item_id' => $item_ext_id]);
                }

                $orderPayload = array_merge($order, [
                    'customer_ext_id' => $customer_ext_id ?? null,
                    'partes'          => $partesResolved,
                ]);

                $externalOrders = $provider->createOrder($orderPayload);

                if (empty($externalOrders) || ($externalOrders['status'] ?? '') === 'error') {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code'   => $externalOrders['code']   ?? $this->codeStr . '502',
                        'answer' => $externalOrders['answer'] ?? 'Sin respuesta de la plataforma'
                    ];
                    continue;
                }

                foreach ($externalOrders as $ext) {
                    $id_externo = $ext['id_externo'] ?? null;
                    if (!$id_externo) continue;

                    $insStmt = $db->prepare("
                        INSERT INTO platform_orders (mypos_id, id_interno, id_externo, origen)
                        VALUES (?, ?, ?, ?)
                    ");
                    $insStmt->bind_param('siss', $myposId, $id_interno, $id_externo, $origen);
                    $insStmt->execute();
                    $insStmt->close();
                }

                $platform_results[$origen] = ['status' => 'ok'];
            }

            $created[] = [
                'id_interno' => $id_interno,
                'noPedido'   => $noPedido,
                'status'     => 'ok',
                'platforms'  => $platform_results
            ];
        }

        return [
            'action'  => 'createOrder',
            'created' => $created
        ];
    }

    # Actualiza uno o más pedidos en la BD y los propaga a sus plataformas vinculadas
    public function updateOrder(array $data, array $session = []): array
    {
        $db      = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';
        $updated = [];

        foreach ($data as $order) {

            $id_interno = $order['id_interno'] ?? null;

            if (!$id_interno) {
                $updated[] = [
                    'status' => 'error',
                    'code'   => $this->codeStr . '422',
                    'answer' => 'El campo id_interno es obligatorio'
                ];
                continue;
            }

            # Verificar que el pedido existe
            $chkStmt = $db->prepare("SELECT id FROM orders WHERE id = ? AND mypos_id = ? LIMIT 1");
            $chkStmt->bind_param('is', $id_interno, $myposId);
            $chkStmt->execute();
            $chkStmt->store_result();

            if ($chkStmt->num_rows === 0) {
                $chkStmt->close();
                $updated[] = [
                    'id_interno' => $id_interno,
                    'status'     => 'error',
                    'code'       => $this->codeStr . '404',
                    'answer'     => 'Pedido no encontrado'
                ];
                continue;
            }
            $chkStmt->close();

            # Campos actualizables — tipo 'i' para tinyints, 's' para el resto
            $scalarFields = [
                'noPedido', 'vendedor_id', 'vendedor_user', 'vendedor_nombre',
                'cuenta_receptora_id', 'cuenta_receptora_clabe', 'cuenta_receptora_beneficiario',
                'comision_aizu_id', 'comision_nombre',
                'cuenta_emisora', 'forma_pago', 'metodo_pago', 'moneda', 'tipo_descuento',
                'folio_fiscal', 'rfc_emisor', 'rfc_receptor', 'notas',
                'fecha_pedido', 'fecha_inicio', 'fecha_entrega', 'estimado',
                'hora_entrega_inicio', 'hora_entrega_final',
                # decimales — bind como 's', MySQL castea
                'comision_porcentaje', 'comision_importe', 'tasa_moneda',
                'total', 'anticipo', 'descuento', 'iva', 'porcentaje_iva',
            ];
            $intFields = ['pagado', 'entregado', 'cancelado', 'descontar_stock', 'facturado'];

            $setClauses = [];
            $setTypes   = '';
            $setParams  = [];

            foreach ($scalarFields as $field) {
                if (array_key_exists($field, $order)) {
                    $setClauses[] = "{$field} = ?";
                    $setTypes    .= 's';
                    $setParams[]  = $order[$field];
                }
            }

            foreach ($intFields as $field) {
                if (array_key_exists($field, $order)) {
                    $setClauses[] = "{$field} = ?";
                    $setTypes    .= 'i';
                    $setParams[]  = (int)$order[$field];
                }
            }

            # Si se envían partes, recalcular campos derivados
            if (array_key_exists('partes', $order) && is_array($order['partes'])) {
                $partes = $order['partes'];
                $id_productos_arr = array_filter(
                    array_map(fn($p) => isset($p['item_aizu_id']) ? (string)(int)$p['item_aizu_id'] : null, $partes)
                );
                $setClauses[] = 'id_productos = ?';  $setTypes .= 's'; $setParams[] = !empty($id_productos_arr) ? implode(',', array_values($id_productos_arr)) : '0';
                $setClauses[] = 'item_nombres = ?';  $setTypes .= 's'; $setParams[] = mb_substr(implode(',', array_column($partes, 'item_nombre')), 0, 150);
                $setClauses[] = 'cantidad = ?';      $setTypes .= 's'; $setParams[] = mb_substr(implode(',', array_column($partes, 'cant')),        0, 150);
                $setClauses[] = 'precios = ?';       $setTypes .= 's'; $setParams[] = mb_substr(implode(',', array_column($partes, 'precio')),      0, 150);
            }

            if (empty($setClauses)) {
                $updated[] = [
                    'id_interno' => $id_interno,
                    'status'     => 'error',
                    'code'       => $this->codeStr . '422',
                    'answer'     => 'No se enviaron campos a actualizar'
                ];
                continue;
            }

            $setClauses[] = 'updated_at = CURRENT_TIMESTAMP';
            $setTypes    .= 'is';
            $setParams[]  = $id_interno;
            $setParams[]  = $myposId;

            $sql     = 'UPDATE orders SET ' . implode(', ', $setClauses) . ' WHERE id = ? AND mypos_id = ?';
            $updStmt = $db->prepare($sql);
            $updStmt->bind_param($setTypes, ...$setParams);

            if (!$updStmt->execute()) {
                $updStmt->close();
                $updated[] = [
                    'id_interno' => $id_interno,
                    'status'     => 'error',
                    'code'       => $this->codeStr . '500',
                    'answer'     => 'Error al actualizar el pedido'
                ];
                continue;
            }
            $updStmt->close();

            # Propagar a plataformas vinculadas
            $platform_results = [];

            $platStmt = $db->prepare("
                SELECT id_externo, origen
                FROM platform_orders
                WHERE mypos_id = ? AND id_interno = ?
            ");
            $platStmt->bind_param('si', $myposId, $id_interno);
            $platStmt->execute();
            $platRows = $platStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $platStmt->close();

            foreach ($platRows as $platRow) {
                $id_externo = $platRow['id_externo'];
                $origen     = $platRow['origen'];

                if (!isset($this->providersByName[$origen])) continue;

                $provider = $this->providersByName[$origen];
                $response = $provider->updateOrder($id_externo, $order);

                $platform_results[$origen] = ($response['status'] ?? '') === 'error'
                    ? $response
                    : ['status' => 'ok'];
            }

            $updated[] = [
                'id_interno' => $id_interno,
                'status'     => 'ok',
                'platforms'  => $platform_results
            ];
        }

        return [
            'action'  => 'updateOrder',
            'updated' => $updated
        ];
    }

    # Vincula un pedido existente en la BD a una plataforma externa
    public function linkOrder(array $data, array $session = [], array $platforms = []): array
    {
        $db        = (new Database())->connect();
        $myposId   = $session['mypos_id'] ?? '';
        $providers = $this->filterProviders($platforms);
        $linked    = [];

        foreach ($data as $item) {

            $id_interno = $item['id_interno'] ?? null;

            if (!$id_interno) {
                $linked[] = [
                    'status' => 'error',
                    'code'   => $this->codeStr . '422',
                    'answer' => 'El campo id_interno es obligatorio'
                ];
                continue;
            }

            # Obtener el pedido completo
            $chkStmt = $db->prepare("
                SELECT id, customer_id, noPedido, id_productos, item_nombres,
                       cantidad, precios, total, notas, forma_pago, metodo_pago,
                       moneda, tasa_moneda, fecha_pedido, fecha_inicio, fecha_entrega
                FROM orders
                WHERE id = ? AND mypos_id = ?
                LIMIT 1
            ");
            $chkStmt->bind_param('is', $id_interno, $myposId);
            $chkStmt->execute();
            $order = $chkStmt->get_result()->fetch_assoc();
            $chkStmt->close();

            if (!$order) {
                $linked[] = [
                    'id_interno' => $id_interno,
                    'status'     => 'error',
                    'code'       => $this->codeStr . '404',
                    'answer'     => 'Pedido no encontrado'
                ];
                continue;
            }

            $platform_results = [];

            foreach ($providers as $provider) {
                $origen = $provider->getName();

                # Verificar si ya está vinculado
                $lnkStmt = $db->prepare("
                    SELECT id FROM platform_orders
                    WHERE mypos_id = ? AND id_interno = ? AND origen = ?
                    LIMIT 1
                ");
                $lnkStmt->bind_param('sis', $myposId, $id_interno, $origen);
                $lnkStmt->execute();
                $lnkStmt->store_result();
                $alreadyLinked = $lnkStmt->num_rows > 0;
                $lnkStmt->close();

                if ($alreadyLinked) {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code'   => $this->codeStr . '409',
                        'answer' => "El pedido ya está vinculado a {$origen}"
                    ];
                    continue;
                }

                # Resolver customer_ext_id
                $custExtStmt = $db->prepare("
                    SELECT id_externo FROM platform_customers
                    WHERE mypos_id = ? AND id_interno = ? AND origen = ?
                    LIMIT 1
                ");
                $custExtStmt->bind_param('sis', $myposId, $order['customer_id'], $origen);
                $custExtStmt->execute();
                $custExtStmt->bind_result($customer_ext_id);
                $custExtStmt->fetch();
                $custExtStmt->close();

                $orderPayload = array_merge($order, [
                    'customer_ext_id' => $customer_ext_id ?? null,
                    'partes'          => [],
                ]);

                $externalOrders = $provider->createOrder($orderPayload);

                if (empty($externalOrders) || ($externalOrders['status'] ?? '') === 'error') {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code'   => $externalOrders['code']   ?? $this->codeStr . '502',
                        'answer' => $externalOrders['answer'] ?? 'Sin respuesta de la plataforma'
                    ];
                    continue;
                }

                foreach ($externalOrders as $ext) {
                    $id_externo = $ext['id_externo'] ?? null;
                    if (!$id_externo) continue;

                    $insStmt = $db->prepare("
                        INSERT INTO platform_orders (mypos_id, id_interno, id_externo, origen)
                        VALUES (?, ?, ?, ?)
                    ");
                    $insStmt->bind_param('siss', $myposId, $id_interno, $id_externo, $origen);
                    $insStmt->execute();
                    $insStmt->close();
                }

                $platform_results[$origen] = ['status' => 'ok'];
            }

            $linked[] = [
                'id_interno' => $id_interno,
                'noPedido'   => $order['noPedido'],
                'status'     => 'ok',
                'platforms'  => $platform_results
            ];
        }

        return [
            'action' => 'linkOrder',
            'linked' => $linked
        ];
    }

    # Cancela uno o más pedidos en la BD y los propaga a sus plataformas vinculadas
    public function cancelOrder(array $data, array $session = []): array
    {
        $db      = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';
        $cancelled = [];

        foreach ($data as $item) {

            $id_interno = $item['id_interno'] ?? null;

            if (!$id_interno) {
                $cancelled[] = [
                    'status' => 'error',
                    'code'   => $this->codeStr . '422',
                    'answer' => 'El campo id_interno es obligatorio'
                ];
                continue;
            }

            # Verificar que existe y no está ya cancelado
            $chkStmt = $db->prepare("SELECT id, cancelado FROM orders WHERE id = ? AND mypos_id = ? LIMIT 1");
            $chkStmt->bind_param('is', $id_interno, $myposId);
            $chkStmt->execute();
            $chkRow = $chkStmt->get_result()->fetch_assoc();
            $chkStmt->close();

            if (!$chkRow) {
                $cancelled[] = [
                    'id_interno' => $id_interno,
                    'status'     => 'error',
                    'code'       => $this->codeStr . '404',
                    'answer'     => 'Pedido no encontrado'
                ];
                continue;
            }

            if ((int)$chkRow['cancelado'] === 1) {
                $cancelled[] = [
                    'id_interno' => $id_interno,
                    'status'     => 'error',
                    'code'       => $this->codeStr . '409',
                    'answer'     => 'El pedido ya está cancelado'
                ];
                continue;
            }

            # Marcar cancelado en BD
            $updStmt = $db->prepare("
                UPDATE orders SET cancelado = 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND mypos_id = ?
            ");
            $updStmt->bind_param('is', $id_interno, $myposId);

            if (!$updStmt->execute()) {
                $updStmt->close();
                $cancelled[] = [
                    'id_interno' => $id_interno,
                    'status'     => 'error',
                    'code'       => $this->codeStr . '500',
                    'answer'     => 'Error al cancelar el pedido'
                ];
                continue;
            }
            $updStmt->close();

            # Propagar a plataformas vinculadas
            $platform_results = [];

            $platStmt = $db->prepare("
                SELECT id_externo, origen
                FROM platform_orders
                WHERE mypos_id = ? AND id_interno = ?
            ");
            $platStmt->bind_param('si', $myposId, $id_interno);
            $platStmt->execute();
            $platRows = $platStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $platStmt->close();

            foreach ($platRows as $platRow) {
                $id_externo = $platRow['id_externo'];
                $origen     = $platRow['origen'];

                if (!isset($this->providersByName[$origen])) continue;

                $response = $this->providersByName[$origen]->cancelOrder($id_externo);

                $platform_results[$origen] = ($response['status'] ?? '') === 'error'
                    ? $response
                    : ['status' => 'ok'];
            }

            $cancelled[] = [
                'id_interno' => $id_interno,
                'status'     => 'ok',
                'platforms'  => $platform_results
            ];
        }

        return [
            'action'    => 'cancelOrder',
            'cancelled' => $cancelled
        ];
    }

    # Envía a las plataformas los pedidos que aún no tienen ningún vínculo externo
    public function pushOrders(array $session = [], array $platforms = []): array
    {
        if (count($platforms) > 1) {
            return [
                'action' => 'pushOrders',
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'En pushOrders solo se permite una plataforma en platforms[]'
            ];
        }

        $providers = $this->filterProviders($platforms);

        if (empty($providers)) {
            return [
                'action' => 'pushOrders',
                'status' => 'error',
                'answer' => 'No se encontraron plataformas válidas'
            ];
        }

        $db      = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';

        # Obtener pedidos sin ningún vínculo externo
        $stmt = $db->prepare("
            SELECT
                o.id AS id_interno, o.customer_id, o.noPedido,
                o.item_nombres, o.id_productos, o.cantidad, o.precios,
                o.total, o.forma_pago, o.metodo_pago, o.moneda, o.tasa_moneda,
                o.anticipo, o.descuento, o.tipo_descuento,
                o.iva, o.porcentaje_iva,
                o.fecha_pedido, o.fecha_inicio, o.fecha_entrega,
                o.hora_entrega_inicio, o.hora_entrega_final, o.estimado,
                o.notas, o.pagado, o.entregado, o.cancelado, o.facturado,
                o.direccion_entrega
            FROM orders o
            WHERE o.mypos_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM platform_orders po
                WHERE po.mypos_id   = o.mypos_id
                  AND po.id_interno = o.id
            )
        ");
        $stmt->bind_param('s', $myposId);
        $stmt->execute();
        $unlinked = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($unlinked)) {
            return [
                'action' => 'pushOrders',
                'pushed' => [],
                'answer' => 'Todos los pedidos ya están vinculados a al menos una plataforma'
            ];
        }

        $pushed = [];

        foreach ($unlinked as $order) {

            $id_interno  = (int)$order['id_interno'];
            $customer_id = (int)$order['customer_id'];

            $platform_results = [];

            foreach ($providers as $provider) {
                $origen = $provider->getName();

                # Resolver customer_ext_id
                $custExtStmt = $db->prepare("
                    SELECT id_externo FROM platform_customers
                    WHERE mypos_id = ? AND id_interno = ? AND origen = ?
                    LIMIT 1
                ");
                $custExtStmt->bind_param('sis', $myposId, $customer_id, $origen);
                $custExtStmt->execute();
                $custExtStmt->bind_result($customer_ext_id);
                $custExtStmt->fetch();
                $custExtStmt->close();

                $orderPayload = array_merge($order, [
                    'customer_ext_id' => $customer_ext_id ?? null,
                    'partes'          => [],
                ]);

                $externalOrders = $provider->createOrder($orderPayload);

                if (empty($externalOrders) || ($externalOrders['status'] ?? '') === 'error') {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code'   => $externalOrders['code']   ?? $this->codeStr . '502',
                        'answer' => $externalOrders['answer'] ?? 'Sin respuesta de la plataforma'
                    ];
                    continue;
                }

                foreach ($externalOrders as $ext) {
                    $id_externo = $ext['id_externo'] ?? null;
                    if (!$id_externo) continue;

                    $insStmt = $db->prepare("
                        INSERT IGNORE INTO platform_orders (mypos_id, id_interno, id_externo, origen)
                        VALUES (?, ?, ?, ?)
                    ");
                    $insStmt->bind_param('siss', $myposId, $id_interno, $id_externo, $origen);
                    $insStmt->execute();
                    $insStmt->close();
                }

                $platform_results[$origen] = ['status' => 'ok'];
            }

            $pushed[] = [
                'id_interno' => $id_interno,
                'noPedido'   => $order['noPedido'],
                'status'     => 'ok',
                'platforms'  => $platform_results
            ];
        }

        return [
            'action' => 'pushOrders',
            'total'  => count($pushed),
            'pushed' => $pushed
        ];
    }

    # <--- CUSTOMERS --->

    # Obtiene los clientes desde las APIs y los sincroniza con customers + address + platform_customers
    public function getApiCustomers(array $platforms = [], array $session = []): array
    {
        if (count($platforms) > 1) {
            return [
                'action' => 'getApiCustomers',
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'En getApiCustomers solo se permite una plataforma en platforms[]'
            ];
        }

        $providers = $this->filterProviders($platforms);
        $customersByPlatform = [];
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';

        foreach ($providers as $provider) {
            $rawList = $provider->fetchRawCustomers();
            $normalizedList = [];
            $origen = $provider->getName();
            $externalIds = [];

            foreach ($rawList as $raw) {
                $id_interno = null;
                $id_externo = (string)($raw['id'] ?? '');
                $externalIds[] = $id_externo;

                $nombre = $raw['nombre'] ?? '';
                $email = $raw['email'] ?? null;
                $telefono = $raw['telefono'] ?? null;
                $movil = $raw['movil'] ?? null;
                $lada = $raw['lada'] ?? null;
                $notas = $raw['notas'] ?? null;
                $rfc = $raw['rfc'] ?? null;
                $prospecto = (int)($raw['prospecto'] ?? 0);
                $direcciones = $raw['direcciones'] ?? [];

                # Buscar vínculo existente en platform_customers
                $lnkStmt = $db->prepare("
                    SELECT id_interno, platform_created_at
                    FROM platform_customers
                    WHERE mypos_id = ? AND id_externo = ? AND origen = ?
                    LIMIT 1
                ");
                $lnkStmt->bind_param('sss', $myposId, $id_externo, $origen);
                $lnkStmt->execute();
                $lnkRow = $lnkStmt->get_result()->fetch_assoc();
                $lnkStmt->close();

                $id_interno = $lnkRow ? (int)$lnkRow['id_interno'] : null;
                $exists = $lnkRow !== null;

                if ($exists && $id_interno) {
                    # Si existe vínculo, comparar fechas y actualizar si corresponde
                    $platform_created_at = $raw['created_at'] ?? null;
                    $saved_date = $lnkRow['platform_created_at'] ?? null;

                    if ($platform_created_at && !$saved_date) {
                        $updDateStmt = $db->prepare("
                            UPDATE platform_customers SET platform_created_at = ?
                            WHERE mypos_id = ? AND id_externo = ? AND origen = ?
                        ");
                        $updDateStmt->bind_param('ssss', $platform_created_at, $myposId, $id_externo, $origen);
                        $updDateStmt->execute();
                        $updDateStmt->close();
                        $saved_date = $platform_created_at;
                    }

                    # Obtener la fecha más reciente entre plataformas vinculadas
                    $bestStmt = $db->prepare("
                        SELECT platform_created_at FROM platform_customers
                        WHERE mypos_id = ? AND id_interno = ?
                        AND platform_created_at IS NOT NULL
                        ORDER BY platform_created_at DESC
                        LIMIT 1
                    ");
                    $bestStmt->bind_param('si', $myposId, $id_interno);
                    $bestStmt->execute();
                    $bestRow = $bestStmt->get_result()->fetch_assoc();
                    $bestStmt->close();
                    $bestDate = $bestRow['platform_created_at'] ?? null;

                    $currentIsWinner = (
                        $platform_created_at !== null &&
                        $bestDate !== null &&
                        strtotime($platform_created_at) >= strtotime($bestDate)
                    );

                    if ($currentIsWinner) {
                        # Actualizar customers y reemplazar addresses
                        $stmt = $db->prepare("
                            UPDATE customers SET
                                nombre     = ?,
                                email      = ?,
                                telefono   = ?,
                                movil      = ?,
                                lada       = ?,
                                notas      = ?,
                                rfc        = ?,
                                prospecto  = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE mypos_id = ? AND id = ?
                        ");
                        $stmt->bind_param(
                            'sssssssisi',
                            $nombre,
                            $email,
                            $telefono,
                            $movil,
                            $lada,
                            $notas,
                            $rfc,
                            $prospecto,
                            $myposId,
                            $id_interno
                        );
                        $stmt->execute();
                        $stmt->close();

                        if (!empty($direcciones)) {
                            # Reemplazar direcciones
                            $delStmt = $db->prepare("DELETE FROM address WHERE mypos_id = ? AND customer_id = ?");
                            $delStmt->bind_param('si', $myposId, $id_interno);
                            $delStmt->execute();
                            $delStmt->close();

                            foreach ($direcciones as $dir) {
                                $calle = $dir['calle'] ?? null;
                                $noExt = $dir['no_ext'] ?? null;
                                $noInt = $dir['no_int'] ?? null;
                                $colonia = $dir['colonia'] ?? null;
                                $cp = $dir['cp'] ?? null;
                                $municipio = $dir['municipio'] ?? null;
                                $estado = $dir['estado'] ?? null;
                                $ciudad = $dir['ciudad'] ?? null;
                                $pais = $dir['pais'] ?? null;
                                $referencias = $dir['referencias'] ?? null;
                                $latitud = isset($dir['gps']['latitud']) ? (float)$dir['gps']['latitud'] : null;
                                $longitud = isset($dir['gps']['longitud']) ? (float)$dir['gps']['longitud'] : null;
                                $pred = ($dir['predeterminada'] ?? false) ? 1 : 0;

                                $insAddr = $db->prepare("
                                    INSERT INTO address
                                    (mypos_id, customer_id, calle, no_ext, no_int, colonia, cp,
                                    municipio, estado, ciudad, pais, referencias,
                                    latitud, longitud, predeterminada)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                $insAddr->bind_param(
                                    'sissssssssssddi',
                                    $myposId,
                                    $id_interno,
                                    $calle,
                                    $noExt,
                                    $noInt,
                                    $colonia,
                                    $cp,
                                    $municipio,
                                    $estado,
                                    $ciudad,
                                    $pais,
                                    $referencias,
                                    $latitud,
                                    $longitud,
                                    $pred
                                );
                                $insAddr->execute();
                                $insAddr->close();
                            }

                            # Sincronizar nombre y dirección predeterminada a otras plataformas vinculadas
                            $defaultAddr = null;
                            foreach ($direcciones as $dir) {
                                if ($dir['predeterminada'] ?? false) {
                                    $defaultAddr = $dir;
                                    break;
                                }
                            }
                            if (!$defaultAddr && !empty($direcciones)) {
                                $defaultAddr = $direcciones[0];
                            }

                            $otherStmt = $db->prepare("
                                SELECT id_externo, origen FROM platform_customers
                                WHERE mypos_id = ? AND id_interno = ? AND origen != ?
                            ");
                            $otherStmt->bind_param('sis', $myposId, $id_interno, $origen);
                            $otherStmt->execute();
                            $otherRows = $otherStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            $otherStmt->close();

                            foreach ($otherRows as $otherRow) {
                                $otherOrigen = $otherRow['origen'];
                                $otherIdExterno = $otherRow['id_externo'];

                                if (!isset($this->providersByName[$otherOrigen])) continue;

                                $otherProvider = $this->providersByName[$otherOrigen];

                                $otherProvider->updateCustomer($otherIdExterno, [
                                    'nombre' => $nombre,
                                    'email' => $email,
                                    'telefono' => $telefono,
                                    'movil' => $movil,
                                    'lada' => $lada,
                                    'notas' => $notas,
                                ]);

                                if ($defaultAddr) {
                                    $otherProvider->syncDefaultAddress($otherIdExterno, $defaultAddr);
                                }
                            }
                        }
                    }
                    # Si no es ganador, no se toca nada
                } else {
                    # No existe vínculo: intentar merge por email o crear nuevo
                    $merge_id_interno = null;

                    if (!empty($email)) {
                        $mergeStmt = $db->prepare("
                            SELECT id FROM customers
                            WHERE mypos_id = ? AND email = ?
                            LIMIT 1
                        ");
                        $mergeStmt->bind_param('ss', $myposId, $email);
                        $mergeStmt->execute();
                        $mergeResult = $mergeStmt->get_result();
                        $mergeRow = $mergeResult->fetch_assoc();
                        $mergeStmt->close();

                        if ($mergeRow) {
                            $merge_id_interno = (int)$mergeRow['id'];
                        }
                    }

                    if ($merge_id_interno) {
                        # Merge: vincular plataforma al cliente existente
                        $platform_created_at = $raw['created_at'] ?? null;

                        $bestStmt = $db->prepare("
                            SELECT platform_created_at FROM platform_customers
                            WHERE mypos_id = ? AND id_interno = ?
                            AND platform_created_at IS NOT NULL
                            ORDER BY platform_created_at DESC
                            LIMIT 1
                        ");
                        $bestStmt->bind_param('si', $myposId, $merge_id_interno);
                        $bestStmt->execute();
                        $bestRow = $bestStmt->get_result()->fetch_assoc();
                        $bestStmt->close();
                        $existingBestDate = $bestRow['platform_created_at'] ?? null;

                        $currentIsNewer = (
                            $platform_created_at !== null &&
                            $existingBestDate !== null &&
                            strtotime($platform_created_at) > strtotime($existingBestDate)
                        );

                        $insStmt = $db->prepare("
                            INSERT INTO platform_customers
                            (mypos_id, id_interno, id_externo, origen, platform_created_at)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $insStmt->bind_param('sisss', $myposId, $merge_id_interno, $id_externo, $origen, $platform_created_at);
                        $insStmt->execute();
                        $insStmt->close();

                        # Determinar dirección predeterminada de la plataforma actual
                        $defaultAddr = null;
                        foreach ($direcciones as $dir) {
                            if ($dir['predeterminada'] ?? false) {
                                $defaultAddr = $dir;
                                break;
                            }
                        }
                        if (!$defaultAddr && !empty($direcciones)) {
                            $defaultAddr = $direcciones[0];
                        }

                        # insertar direcciones (manteniendo flags si $forcePred true)
                        $insertAddressRows = function (array $dirs, int $customerId, bool $forcePred) use ($db, $myposId) {
                            foreach ($dirs as $dir) {
                                $calle = $dir['calle'] ?? null;
                                $noExt = $dir['no_ext'] ?? null;
                                $noInt = $dir['no_int'] ?? null;
                                $colonia = $dir['colonia'] ?? null;
                                $cp = $dir['cp'] ?? null;
                                $municipio = $dir['municipio'] ?? null;
                                $estado = $dir['estado'] ?? null;
                                $ciudad = $dir['ciudad'] ?? null;
                                $pais = $dir['pais'] ?? null;
                                $referencias = $dir['referencias'] ?? null;
                                $latitud = isset($dir['gps']['latitud']) ? (float)$dir['gps']['latitud'] : null;
                                $longitud = isset($dir['gps']['longitud']) ? (float)$dir['gps']['longitud'] : null;
                                $pred = $forcePred ? (($dir['predeterminada'] ?? false) ? 1 : 0) : 0;

                                $s = $db->prepare("
                                    INSERT INTO address
                                    (mypos_id, customer_id, calle, no_ext, no_int, colonia, cp,
                                     municipio, estado, ciudad, pais, referencias,
                                     latitud, longitud, predeterminada)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                $s->bind_param(
                                    'sissssssssssddi',
                                    $myposId,
                                    $customerId,
                                    $calle,
                                    $noExt,
                                    $noInt,
                                    $colonia,
                                    $cp,
                                    $municipio,
                                    $estado,
                                    $ciudad,
                                    $pais,
                                    $referencias,
                                    $latitud,
                                    $longitud,
                                    $pred
                                );
                                $s->execute();
                                $s->close();
                            }
                        };

                        if ($currentIsNewer) {
                            # Plataforma actual gana: actualizar cliente y reemplazar direcciones
                            $updStmt = $db->prepare("
                                UPDATE customers SET
                                    nombre     = ?,
                                    email      = ?,
                                    telefono   = ?,
                                    movil      = ?,
                                    lada       = ?,
                                    notas      = ?,
                                    rfc        = ?,
                                    prospecto  = ?,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE id = ? AND mypos_id = ?
                            ");
                            $updStmt->bind_param(
                                'sssssssisi',
                                $nombre,
                                $email,
                                $telefono,
                                $movil,
                                $lada,
                                $notas,
                                $rfc,
                                $prospecto,
                                $merge_id_interno,
                                $myposId
                            );
                            $updStmt->execute();
                            $updStmt->close();

                            $delAddr = $db->prepare("DELETE FROM address WHERE mypos_id = ? AND customer_id = ?");
                            $delAddr->bind_param('si', $myposId, $merge_id_interno);
                            $delAddr->execute();
                            $delAddr->close();

                            $insertAddressRows($direcciones, $merge_id_interno, true);

                            # Para otras plataformas vinculadas: traer direcciones y sincronizar
                            $otherPlatStmt = $db->prepare("
                                SELECT id_externo, origen FROM platform_customers
                                WHERE mypos_id = ? AND id_interno = ? AND origen != ?
                            ");
                            $otherPlatStmt->bind_param('sis', $myposId, $merge_id_interno, $origen);
                            $otherPlatStmt->execute();
                            $otherRows = $otherPlatStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            $otherPlatStmt->close();

                            foreach ($otherRows as $otherRow) {
                                $otherOrigen = $otherRow['origen'];
                                $otherIdExterno = $otherRow['id_externo'];

                                if (!isset($this->providersByName[$otherOrigen])) continue;

                                $otherProvider = $this->providersByName[$otherOrigen];

                                $otherAddrs = $otherProvider->fetchCustomerAddresses($otherIdExterno);
                                if (!empty($otherAddrs)) {
                                    $insertAddressRows($otherAddrs, $merge_id_interno, false);
                                }

                                $otherProvider->updateCustomer($otherIdExterno, [
                                    'nombre' => $nombre,
                                    'email' => $email,
                                    'telefono' => $telefono,
                                    'movil' => $movil,
                                    'lada' => $lada,
                                    'notas' => $notas,
                                ]);

                                if ($defaultAddr) {
                                    $otherProvider->syncDefaultAddress($otherIdExterno, $defaultAddr);
                                }
                            }
                        } else {
                            # Datos existentes ganan: insertar direcciones de la plataforma actual como no predeterminadas
                            if (!empty($direcciones)) {
                                $insertAddressRows($direcciones, $merge_id_interno, false);
                            }

                            # Obtener datos ganadores desde BD y sincronizar a la plataforma actual
                            $winnerStmt = $db->prepare("
                                SELECT nombre, email, telefono, movil, lada, notas
                                FROM customers
                                WHERE id = ? AND mypos_id = ?
                                LIMIT 1
                            ");
                            $winnerStmt->bind_param('is', $merge_id_interno, $myposId);
                            $winnerStmt->execute();
                            $winnerData = $winnerStmt->get_result()->fetch_assoc();
                            $winnerStmt->close();

                            $defAddrStmt = $db->prepare("
                                SELECT calle, no_ext, no_int, colonia, cp,
                                       municipio, estado, ciudad, pais, referencias,
                                       latitud, longitud, predeterminada
                                FROM address
                                WHERE mypos_id = ? AND customer_id = ? AND predeterminada = 1
                                LIMIT 1
                            ");
                            $defAddrStmt->bind_param('si', $myposId, $merge_id_interno);
                            $defAddrStmt->execute();
                            $existingDefault = $defAddrStmt->get_result()->fetch_assoc();
                            $defAddrStmt->close();

                            if (isset($this->providersByName[$origen])) {
                                $currentProvider = $this->providersByName[$origen];

                                if ($winnerData) {
                                    $currentProvider->updateCustomer($id_externo, [
                                        'nombre' => $winnerData['nombre'],
                                        'email' => $winnerData['email'],
                                        'telefono' => $winnerData['telefono'],
                                        'movil' => $winnerData['movil'],
                                        'lada' => $winnerData['lada'],
                                        'notas' => $winnerData['notas'],
                                    ]);
                                }

                                if ($existingDefault) {
                                    $currentProvider->syncDefaultAddress($id_externo, $existingDefault);
                                }
                            }
                        }

                        $id_interno = $merge_id_interno;
                    } else {
                        # Cliente nuevo: insertar en BD
                        $stmt = $db->prepare("
                            INSERT INTO customers
                            (mypos_id, nombre, email, telefono, movil, lada, notas, rfc, prospecto)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param(
                            'ssssssssi',
                            $myposId,
                            $nombre,
                            $email,
                            $telefono,
                            $movil,
                            $lada,
                            $notas,
                            $rfc,
                            $prospecto
                        );
                        $stmt->execute();
                        $id_interno = $db->insert_id;
                        $stmt->close();

                        foreach ($direcciones as $dir) {
                            $calle = $dir['calle'] ?? null;
                            $noExt = $dir['no_ext'] ?? null;
                            $noInt = $dir['no_int'] ?? null;
                            $colonia = $dir['colonia'] ?? null;
                            $cp = $dir['cp'] ?? null;
                            $municipio = $dir['municipio'] ?? null;
                            $estado = $dir['estado'] ?? null;
                            $ciudad = $dir['ciudad'] ?? null;
                            $pais = $dir['pais'] ?? null;
                            $referencias = $dir['referencias'] ?? null;
                            $latitud = isset($dir['gps']['latitud']) ? (float)$dir['gps']['latitud'] : null;
                            $longitud = isset($dir['gps']['longitud']) ? (float)$dir['gps']['longitud'] : null;
                            $pred = ($dir['predeterminada'] ?? false) ? 1 : 0;

                            $insAddr = $db->prepare("
                                INSERT INTO address
                                (mypos_id, customer_id, calle, no_ext, no_int, colonia, cp,
                                municipio, estado, ciudad, pais, referencias,
                                latitud, longitud, predeterminada)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insAddr->bind_param(
                                'sissssssssssddi',
                                $myposId,
                                $id_interno,
                                $calle,
                                $noExt,
                                $noInt,
                                $colonia,
                                $cp,
                                $municipio,
                                $estado,
                                $ciudad,
                                $pais,
                                $referencias,
                                $latitud,
                                $longitud,
                                $pred
                            );
                            $insAddr->execute();
                            $insAddr->close();
                        }

                        $platform_created_at = $raw['created_at'] ?? null;

                        $stmt = $db->prepare("
                            INSERT INTO platform_customers (mypos_id, id_interno, id_externo, origen, platform_created_at)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param('sisss', $myposId, $id_interno, $id_externo, $origen, $platform_created_at);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $normalizedList[] = $raw;
            }

            # Limpiar platform_customers que ya no existen en la plataforma actual
            if (!empty($externalIds)) {
                $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
                $types = 'ss' . str_repeat('s', count($externalIds));
                $params = array_merge([$myposId, $origen], $externalIds);

                $stmt = $db->prepare("
                    DELETE FROM platform_customers
                    WHERE mypos_id = ?
                    AND origen = ?
                    AND id_externo NOT IN ($placeholders)
                ");

                # bind_param dinámico
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
            }

            $customersByPlatform[$origen] = $normalizedList;
        }

        return [
            'action' => 'getApiCustomers',
            'customers' => $customersByPlatform
        ];
    }

    # Obtiene los clientes desde la BD con todas sus direcciones
    public function getCustomersFromDb(array $session = [], int $limit = 100, int $offset = 0): array
    {
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';

        $stmt = $db->prepare("
            SELECT
                c.id          AS customer_aizu_id,
                c.nombre,
                c.email,
                c.telefono,
                c.movil,
                c.lada,
                c.rfc,
                c.notas,
                c.prospecto,
                c.created_at,
                c.updated_at,
                pc.id_externo AS customer_id,
                pc.origen
            FROM   customers             c
            LEFT JOIN platform_customers pc
                   ON pc.mypos_id   = c.mypos_id
                  AND pc.id_interno = c.id
            WHERE  c.mypos_id = ?
            LIMIT  ? OFFSET ?
        ");
        $stmt->bind_param('sii', $myposId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $map = [];
        while ($row = $result->fetch_assoc()) {
            $aid = $row['customer_aizu_id'];
            if (!isset($map[$aid])) {
                $map[$aid] = [
                    'customer_aizu_id' => $aid,
                    'customer_id'      => $row['customer_id'],
                    'origen'           => $row['origen'],
                    'nombre'           => $row['nombre'],
                    'email'            => $row['email'],
                    'telefono'         => $row['telefono'],
                    'movil'            => $row['movil'],
                    'lada'             => $row['lada'],
                    'rfc'              => $row['rfc'],
                    'notas'            => $row['notas'],
                    'prospecto'        => (int)$row['prospecto'],
                    'direcciones'      => [],
                    'created_at'       => $row['created_at'],
                    'updated_at'       => $row['updated_at'],
                ];
            }
        }
        $stmt->close();

        if (empty($map)) {
            return ['action' => 'getCustomersFromDb', 'timestamp' => date('c'), 'customers' => []];
        }

        $ids = array_keys($map);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 's' . str_repeat('i', count($ids));
        $params = array_merge([$myposId], $ids);

        $stmt = $db->prepare("
            SELECT
                id, customer_id,
                calle, no_ext, no_int, colonia, cp,
                municipio, estado, ciudad, pais, referencias,
                latitud, longitud, predeterminada
            FROM   address
            WHERE  mypos_id = ? AND customer_id IN ({$placeholders})
            ORDER  BY customer_id ASC, predeterminada DESC, id ASC
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $addrResult = $stmt->get_result();

        while ($addr = $addrResult->fetch_assoc()) {
            $cid = $addr['customer_id'];
            if (!isset($map[$cid])) continue;

            $map[$cid]['direcciones'][] = [
                'id' => (int)$addr['id'],
                'calle' => $addr['calle'],
                'no_ext' => $addr['no_ext'],
                'no_int' => $addr['no_int'],
                'colonia' => $addr['colonia'],
                'cp' => $addr['cp'],
                'municipio' => $addr['municipio'],
                'estado' => $addr['estado'],
                'ciudad' => $addr['ciudad'],
                'pais' => $addr['pais'],
                'referencias' => $addr['referencias'],
                'gps' => [
                    'latitud' => $addr['latitud'] !== null ? (float)$addr['latitud'] : null,
                    'longitud' => $addr['longitud'] !== null ? (float)$addr['longitud'] : null,
                ],
                'predeterminada' => (bool)$addr['predeterminada'],
            ];
        }
        $stmt->close();

        return [
            'action' => 'getCustomersFromDb',
            'timestamp' => date('c'),
            'customers' => array_values($map),
        ];
    }

    public function createCustomer(array $data, array $session = [], array $platforms = []): array
    {
        if (count($platforms) > 1) {
            return [
                'action' => 'createCustomer',
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'En createCustomer solo se permite una plataforma en platforms[]'
            ];
        }

        $providers = $this->filterProviders($platforms);
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';
        $created = [];

        foreach ($data as $customer) {
            $nombre = $customer['nombre'] ?? '';
            $email = $customer['email'] ?? null;
            $telefono = $customer['telefono'] ?? null;
            $movil = $customer['movil'] ?? null;
            $lada = $customer['lada'] ?? null;
            $rfc = $customer['rfc'] ?? null;
            $notas = $customer['notas'] ?? null;
            $prospecto = $customer['prospecto'] ?? 0;

            # Verificar duplicado por email/telefono/movil
            $conditions = [];
            $dupTypes = 's';
            $dupParams = [$myposId];

            if (!empty($email)) {
                $conditions[] = 'email = ?';
                $dupTypes .= 's';
                $dupParams[] = $email;
            }
            if (!empty($telefono)) {
                $conditions[] = 'telefono = ?';
                $dupTypes .= 's';
                $dupParams[] = $telefono;
            }
            if (!empty($movil)) {
                $conditions[] = 'movil = ?';
                $dupTypes .= 's';
                $dupParams[] = $movil;
            }

            if (!empty($conditions)) {
                $whereOr = implode(' OR ', $conditions);
                $dupStmt = $db->prepare("
                    SELECT id FROM customers
                    WHERE mypos_id = ?
                    AND ({$whereOr})
                    LIMIT 1
                ");
                $dupStmt->bind_param($dupTypes, ...$dupParams);
                $dupStmt->execute();
                $dupResult = $dupStmt->get_result();
                $dupRow = $dupResult->fetch_assoc();
                $dupStmt->close();

                if ($dupRow) {
                    $created[] = [
                        'id_interno' => (int)$dupRow['id'],
                        'nombre' => $nombre,
                        'status' => 'error',
                        'code' => $this->codeStr . '409',
                        'answer' => 'Ya existe un cliente con el mismo correo o teléfono'
                    ];
                    continue;
                }
            }

            # Insertar cliente
            $stmt = $db->prepare("
                INSERT INTO customers
                (mypos_id, nombre, email, telefono, movil, lada, rfc, notas, prospecto)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'ssssssssi',
                $myposId,
                $nombre,
                $email,
                $telefono,
                $movil,
                $lada,
                $rfc,
                $notas,
                $prospecto
            );

            if (!$stmt->execute()) {
                $stmt->close();
                $created[] = [
                    'nombre' => $nombre,
                    'status' => 'error',
                    'code' => $this->codeStr . '500',
                    'answer' => 'Error al procesar el cliente'
                ];
                continue;
            }

            $id_interno = $db->insert_id;
            $stmt->close();

            # Insertar direcciones si existen
            if (!empty($customer['direcciones'])) {
                foreach ($customer['direcciones'] as $dir) {
                    $pred = !empty($dir['predeterminada']) ? 1 : 0;
                    $lat = $dir['gps']['latitud'] ?? null;
                    $lng = $dir['gps']['longitud'] ?? null;

                    $stmt = $db->prepare("
                        INSERT INTO address
                        (mypos_id, customer_id, calle, no_ext, no_int, colonia, cp,
                         municipio, estado, ciudad, pais, referencias,
                         latitud, longitud, predeterminada)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        'sissssssssssddi',
                        $myposId,
                        $id_interno,
                        $dir['calle'],
                        $dir['no_ext'],
                        $dir['no_int'],
                        $dir['colonia'],
                        $dir['cp'],
                        $dir['municipio'],
                        $dir['estado'],
                        $dir['ciudad'],
                        $dir['pais'],
                        $dir['referencias'],
                        $lat,
                        $lng,
                        $pred
                    );
                    $stmt->execute();
                    $stmt->close();
                }
            }

            # Crear en plataformas (omitir ya vinculadas)
            $platform_results = [];

            foreach ($providers as $provider) {
                $origen = $provider->getName();

                $lnkStmt = $db->prepare("
                    SELECT id FROM platform_customers
                    WHERE mypos_id = ? AND id_interno = ? AND origen = ?
                    LIMIT 1
                ");
                $lnkStmt->bind_param('sis', $myposId, $id_interno, $origen);
                $lnkStmt->execute();
                $lnkStmt->store_result();
                $alreadyLinked = $lnkStmt->num_rows > 0;
                $lnkStmt->close();

                if ($alreadyLinked) {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code' => $this->codeStr . '409',
                        'answer' => "El cliente ya está vinculado a {$origen}"
                    ];
                    continue;
                }

                $externalCustomers = $provider->createCustomer($customer);

                if (empty($externalCustomers) || ($externalCustomers['status'] ?? '') === 'error') {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code' => $externalCustomers['code'] ?? $this->codeStr . '502',
                        'answer' => $externalCustomers['answer'] ?? 'Sin respuesta de la plataforma'
                    ];
                    continue;
                }

                foreach ($externalCustomers as $ext) {
                    $id_externo = $ext['id_externo'] ?? null;
                    if (!$id_externo) continue;

                    $stmt = $db->prepare("
                        INSERT INTO platform_customers
                        (mypos_id, id_interno, id_externo, origen)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->bind_param('siss', $myposId, $id_interno, $id_externo, $origen);
                    $stmt->execute();
                    $stmt->close();
                }

                $platform_results[$origen] = ['status' => 'ok'];
            }

            $created[] = [
                'id_interno' => $id_interno,
                'nombre' => $nombre,
                'status' => 'ok',
                'platforms' => $platform_results
            ];
        }

        return [
            'action' => 'createCustomer',
            'created' => $created
        ];
    }

    public function updateCustomer(array $data, array $session = []): array
    {
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';
        $updated = [];

        foreach ($data as $customer) {
            $id_interno = $customer['id_interno'] ?? null;

            if (!$id_interno) {
                $updated[] = [
                    'status' => 'error',
                    'code' => $this->codeStr . '422',
                    'answer' => 'El campo id_interno es obligatorio'
                ];
                continue;
            }

            $chkStmt = $db->prepare("
                SELECT id FROM customers
                WHERE id = ? AND mypos_id = ?
                LIMIT 1
            ");
            $chkStmt->bind_param('is', $id_interno, $myposId);
            $chkStmt->execute();
            $chkStmt->store_result();

            if ($chkStmt->num_rows === 0) {
                $chkStmt->close();
                $updated[] = [
                    'id_interno' => $id_interno,
                    'status' => 'error',
                    'code' => $this->codeStr . '404',
                    'answer' => 'Cliente no encontrado'
                ];
                continue;
            }
            $chkStmt->close();

            # Detección de duplicados excluyendo al propio cliente
            $email = $customer['email'] ?? null;
            $telefono = $customer['telefono'] ?? null;
            $movil = $customer['movil'] ?? null;

            $dupConditions = [];
            $dupTypes = 'si';
            $dupParams = [$myposId, $id_interno];

            if (!empty($email)) {
                $dupConditions[] = 'email = ?';
                $dupTypes .= 's';
                $dupParams[] = $email;
            }
            if (!empty($telefono)) {
                $dupConditions[] = 'telefono = ?';
                $dupTypes .= 's';
                $dupParams[] = $telefono;
            }
            if (!empty($movil)) {
                $dupConditions[] = 'movil = ?';
                $dupTypes .= 's';
                $dupParams[] = $movil;
            }

            if (!empty($dupConditions)) {
                $whereOr = implode(' OR ', $dupConditions);
                $dupStmt = $db->prepare("
                    SELECT id FROM customers
                    WHERE mypos_id = ? AND id != ?
                    AND ({$whereOr})
                    LIMIT 1
                ");
                $dupStmt->bind_param($dupTypes, ...$dupParams);
                $dupStmt->execute();
                $dupStmt->store_result();

                if ($dupStmt->num_rows > 0) {
                    $dupStmt->close();
                    $updated[] = [
                        'id_interno' => $id_interno,
                        'status' => 'error',
                        'code' => $this->codeStr . '409',
                        'answer' => 'Ya existe otro cliente con el mismo correo o teléfono'
                    ];
                    continue;
                }
                $dupStmt->close();
            }

            # Construir SET dinámico
            $fields = ['nombre', 'email', 'telefono', 'movil', 'lada', 'rfc', 'notas', 'prospecto'];
            $setClauses = [];
            $setTypes = '';
            $setParams = [];

            foreach ($fields as $field) {
                if (array_key_exists($field, $customer)) {
                    $setClauses[] = "{$field} = ?";
                    $setTypes .= ($field === 'prospecto') ? 'i' : 's';
                    $setParams[] = $customer[$field];
                }
            }

            if (!empty($setClauses)) {
                $setClauses[] = 'updated_at = CURRENT_TIMESTAMP';
                $setTypes .= 'is';
                $setParams[] = $id_interno;
                $setParams[] = $myposId;

                $sql = 'UPDATE customers SET ' . implode(', ', $setClauses) . ' WHERE id = ? AND mypos_id = ?';
                $updStmt = $db->prepare($sql);
                $updStmt->bind_param($setTypes, ...$setParams);

                if (!$updStmt->execute()) {
                    $updStmt->close();
                    $updated[] = [
                        'id_interno' => $id_interno,
                        'status' => 'error',
                        'code' => $this->codeStr . '500',
                        'answer' => 'Error al actualizar el cliente'
                    ];
                    continue;
                }
                $updStmt->close();
            }

            # Reemplazar direcciones si se enviaron
            if (array_key_exists('direcciones', $customer)) {
                $delStmt = $db->prepare("DELETE FROM address WHERE mypos_id = ? AND customer_id = ?");
                $delStmt->bind_param('si', $myposId, $id_interno);
                $delStmt->execute();
                $delStmt->close();

                foreach ($customer['direcciones'] as $dir) {
                    $calle = $dir['calle'] ?? null;
                    $noExt = $dir['no_ext'] ?? null;
                    $noInt = $dir['no_int'] ?? null;
                    $colonia = $dir['colonia'] ?? null;
                    $cp = $dir['cp'] ?? null;
                    $municipio = $dir['municipio'] ?? null;
                    $estado = $dir['estado'] ?? null;
                    $ciudad = $dir['ciudad'] ?? null;
                    $pais = $dir['pais'] ?? null;
                    $referencias = $dir['referencias'] ?? null;
                    $latitud = isset($dir['gps']['latitud']) ? (float)$dir['gps']['latitud'] : null;
                    $longitud = isset($dir['gps']['longitud']) ? (float)$dir['gps']['longitud'] : null;
                    $pred = ($dir['predeterminada'] ?? false) ? 1 : 0;

                    $addrStmt = $db->prepare("
                        INSERT INTO address
                        (mypos_id, customer_id, calle, no_ext, no_int, colonia, cp,
                         municipio, estado, ciudad, pais, referencias,
                         latitud, longitud, predeterminada)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $addrStmt->bind_param(
                        'sissssssssssddi',
                        $myposId,
                        $id_interno,
                        $calle,
                        $noExt,
                        $noInt,
                        $colonia,
                        $cp,
                        $municipio,
                        $estado,
                        $ciudad,
                        $pais,
                        $referencias,
                        $latitud,
                        $longitud,
                        $pred
                    );
                    $addrStmt->execute();
                    $addrStmt->close();
                }
            }

            # Propagar a plataformas vinculadas
            $platform_results = [];

            $platStmt = $db->prepare("
                SELECT id_externo, origen
                FROM platform_customers
                WHERE mypos_id = ? AND id_interno = ?
            ");
            $platStmt->bind_param('si', $myposId, $id_interno);
            $platStmt->execute();
            $platResult = $platStmt->get_result();

            while ($platRow = $platResult->fetch_assoc()) {
                $id_externo = $platRow['id_externo'];
                $origen = $platRow['origen'];

                if (!isset($this->providersByName[$origen])) continue;

                $provider = $this->providersByName[$origen];
                $response = $provider->updateCustomer($id_externo, $customer);

                if (($response['status'] ?? '') === 'error') {
                    $platform_results[$origen] = $response;
                } else {
                    $platform_results[$origen] = ['status' => 'ok'];
                }
            }
            $platStmt->close();

            $updated[] = [
                'id_interno' => $id_interno,
                'status' => 'ok',
                'platforms' => $platform_results
            ];
        }

        return [
            'action' => 'updateCustomer',
            'updated' => $updated
        ];
    }

    public function linkCustomer(array $data, array $session = [], array $platforms = []): array
    {
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';
        $providers = $this->filterProviders($platforms);
        $linked = [];

        foreach ($data as $item) {
            $id_interno = $item['id_interno'] ?? null;

            if (!$id_interno) {
                $linked[] = [
                    'status' => 'error',
                    'code' => $this->codeStr . '422',
                    'answer' => 'El campo id_interno es obligatorio'
                ];
                continue;
            }

            $chkStmt = $db->prepare("
                SELECT id, nombre, email, telefono, movil, lada, rfc, notas, prospecto
                FROM customers
                WHERE id = ? AND mypos_id = ?
                LIMIT 1
            ");
            $chkStmt->bind_param('is', $id_interno, $myposId);
            $chkStmt->execute();
            $chkResult = $chkStmt->get_result();
            $customer = $chkResult->fetch_assoc();
            $chkStmt->close();

            if (!$customer) {
                $linked[] = [
                    'id_interno' => $id_interno,
                    'status' => 'error',
                    'code' => $this->codeStr . '404',
                    'answer' => 'Cliente no encontrado'
                ];
                continue;
            }

            # Obtener direcciones del cliente
            $addrStmt = $db->prepare("
                SELECT calle, no_ext, no_int, colonia, cp,
                       municipio, estado, ciudad, pais, referencias,
                       latitud, longitud, predeterminada
                FROM address
                WHERE mypos_id = ? AND customer_id = ?
                ORDER BY predeterminada DESC, id ASC
            ");
            $addrStmt->bind_param('si', $myposId, $id_interno);
            $addrStmt->execute();
            $addrResult = $addrStmt->get_result();
            $direcciones = [];

            while ($addr = $addrResult->fetch_assoc()) {
                $direcciones[] = [
                    'calle' => $addr['calle'],
                    'no_ext' => $addr['no_ext'],
                    'no_int' => $addr['no_int'],
                    'colonia' => $addr['colonia'],
                    'cp' => $addr['cp'],
                    'municipio' => $addr['municipio'],
                    'estado' => $addr['estado'],
                    'ciudad' => $addr['ciudad'],
                    'pais' => $addr['pais'],
                    'referencias' => $addr['referencias'],
                    'gps' => [
                        'latitud' => $addr['latitud'] !== null ? (float)$addr['latitud'] : null,
                        'longitud' => $addr['longitud'] !== null ? (float)$addr['longitud'] : null,
                    ],
                    'predeterminada' => (bool)$addr['predeterminada'],
                ];
            }
            $addrStmt->close();

            $customer['direcciones'] = $direcciones;

            $platform_results = [];

            foreach ($providers as $provider) {
                $origen = $provider->getName();

                $lnkStmt = $db->prepare("
                    SELECT id FROM platform_customers
                    WHERE mypos_id = ? AND id_interno = ? AND origen = ?
                    LIMIT 1
                ");
                $lnkStmt->bind_param('sis', $myposId, $id_interno, $origen);
                $lnkStmt->execute();
                $lnkStmt->store_result();
                $alreadyLinked = $lnkStmt->num_rows > 0;
                $lnkStmt->close();

                if ($alreadyLinked) {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code' => $this->codeStr . '409',
                        'answer' => "El cliente ya está vinculado a {$origen}"
                    ];
                    continue;
                }

                $externalCustomers = $provider->createCustomer($customer);

                if (empty($externalCustomers) || ($externalCustomers['status'] ?? '') === 'error') {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code' => $externalCustomers['code'] ?? $this->codeStr . '502',
                        'answer' => $externalCustomers['answer'] ?? 'Sin respuesta de la plataforma'
                    ];
                    continue;
                }

                foreach ($externalCustomers as $ext) {
                    $id_externo = $ext['id_externo'] ?? null;
                    if (!$id_externo) continue;

                    $insStmt = $db->prepare("
                        INSERT INTO platform_customers
                        (mypos_id, id_interno, id_externo, origen)
                        VALUES (?, ?, ?, ?)
                    ");
                    $insStmt->bind_param('siss', $myposId, $id_interno, $id_externo, $origen);
                    $insStmt->execute();
                    $insStmt->close();
                }

                $platform_results[$origen] = ['status' => 'ok'];
            }

            $linked[] = [
                'id_interno' => $id_interno,
                'nombre' => $customer['nombre'],
                'status' => 'ok',
                'platforms' => $platform_results
            ];
        }

        return [
            'action' => 'linkCustomer',
            'linked' => $linked
        ];
    }

    public function getCustomerById(int $idInterno, array $session = []): array
    {
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';

        $stmt = $db->prepare("
            SELECT
                c.id              AS customer_aizu_id,
                c.nombre,
                c.email,
                c.telefono,
                c.movil,
                c.lada,
                c.rfc,
                c.notas,
                c.prospecto,
                c.created_at,
                c.updated_at,
                pc.id_externo     AS customer_id,
                pc.origen,
                pc.platform_created_at
            FROM   customers             c
            LEFT JOIN platform_customers pc
                   ON pc.mypos_id   = c.mypos_id
                  AND pc.id_interno = c.id
            WHERE  c.id = ? AND c.mypos_id = ?
        ");
        $stmt->bind_param('is', $idInterno, $myposId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        $customer = null;
        $platforms = [];

        while ($row = $result->fetch_assoc()) {
            if (!$customer) {
                $customer = [
                    'customer_aizu_id' => $row['customer_aizu_id'],
                    'nombre' => $row['nombre'],
                    'email' => $row['email'],
                    'telefono' => $row['telefono'],
                    'movil' => $row['movil'],
                    'lada' => $row['lada'],
                    'rfc' => $row['rfc'],
                    'notas' => $row['notas'],
                    'prospecto' => (int)$row['prospecto'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ];
            }
            if ($row['origen']) {
                $platforms[] = [
                    'origen' => $row['origen'],
                    'customer_id' => $row['customer_id'],
                    'platform_created_at' => $row['platform_created_at'],
                ];
            }
        }

        if (!$customer) {
            return [
                'action' => 'getCustomerById',
                'status' => 'error',
                'code' => $this->codeStr . '404',
                'answer' => 'Cliente no encontrado'
            ];
        }

        $addrStmt = $db->prepare("
            SELECT id, calle, no_ext, no_int, colonia, cp,
                   municipio, estado, ciudad, pais, referencias,
                   latitud, longitud, predeterminada
            FROM   address
            WHERE  mypos_id = ? AND customer_id = ?
            ORDER  BY predeterminada DESC, id ASC
        ");
        $addrStmt->bind_param('si', $myposId, $idInterno);
        $addrStmt->execute();
        $addrResult = $addrStmt->get_result();
        $addrStmt->close();

        $direcciones = [];
        while ($addr = $addrResult->fetch_assoc()) {
            $direcciones[] = [
                'address_id' => (int)$addr['id'],
                'calle' => $addr['calle'],
                'no_ext' => $addr['no_ext'],
                'no_int' => $addr['no_int'],
                'colonia' => $addr['colonia'],
                'cp' => $addr['cp'],
                'municipio' => $addr['municipio'],
                'estado' => $addr['estado'],
                'ciudad' => $addr['ciudad'],
                'pais' => $addr['pais'],
                'referencias' => $addr['referencias'],
                'gps' => [
                    'latitud' => $addr['latitud'] !== null ? (float)$addr['latitud'] : null,
                    'longitud' => $addr['longitud'] !== null ? (float)$addr['longitud'] : null,
                ],
                'predeterminada' => (bool)$addr['predeterminada'],
            ];
        }

        $customer['platforms'] = $platforms;
        $customer['direcciones'] = $direcciones;

        return [
            'action' => 'getCustomerById',
            'customer' => $customer
        ];
    }

    # inserta una nueva dirección para un cliente en la base de datos
    private function insertAddress(object $db, string $myposId, int $customerId, array $dir): int
    {
        $calle = $dir['calle'] ?? null;
        $noExt = $dir['no_ext'] ?? null;
        $noInt = $dir['no_int'] ?? null;
        $colonia = $dir['colonia'] ?? null;
        $cp = $dir['cp'] ?? null;
        $municipio = $dir['municipio'] ?? null;
        $estado = $dir['estado'] ?? null;
        $ciudad = $dir['ciudad'] ?? null;
        $pais = $dir['pais'] ?? null;
        $referencias = $dir['referencias'] ?? null;
        $latitud = isset($dir['gps']['latitud']) ? (float)$dir['gps']['latitud'] : null;
        $longitud = isset($dir['gps']['longitud']) ? (float)$dir['gps']['longitud'] : null;
        $pred = ($dir['predeterminada'] ?? false) ? 1 : 0;

        if ($pred) {
            $clrStmt = $db->prepare("UPDATE address SET predeterminada = 0 WHERE mypos_id = ? AND customer_id = ?");
            $clrStmt->bind_param('si', $myposId, $customerId);
            $clrStmt->execute();
            $clrStmt->close();
        }

        $stmt = $db->prepare("
            INSERT INTO address
            (mypos_id, customer_id, calle, no_ext, no_int, colonia, cp,
             municipio, estado, ciudad, pais, referencias,
             latitud, longitud, predeterminada)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'sissssssssssddi',
            $myposId,
            $customerId,
            $calle,
            $noExt,
            $noInt,
            $colonia,
            $cp,
            $municipio,
            $estado,
            $ciudad,
            $pais,
            $referencias,
            $latitud,
            $longitud,
            $pred
        );
        $stmt->execute();
        $newId = $db->insert_id;
        $stmt->close();

        return $newId;
    }

    public function createAddress(array $data, array $session = []): array
    {
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';
        $created = [];

        foreach ($data as $item) {
            $id_interno = $item['id_interno'] ?? null;

            if (!$id_interno) {
                $created[] = [
                    'status' => 'error',
                    'code' => $this->codeStr . '422',
                    'answer' => 'El campo id_interno es obligatorio'
                ];
                continue;
            }

            $chkStmt = $db->prepare("SELECT id FROM customers WHERE id = ? AND mypos_id = ? LIMIT 1");
            $chkStmt->bind_param('is', $id_interno, $myposId);
            $chkStmt->execute();
            $chkStmt->store_result();

            if ($chkStmt->num_rows === 0) {
                $chkStmt->close();
                $created[] = [
                    'id_interno' => $id_interno,
                    'status' => 'error',
                    'code' => $this->codeStr . '404',
                    'answer' => 'Cliente no encontrado'
                ];
                continue;
            }
            $chkStmt->close();

            $newAddressId = $this->insertAddress($db, $myposId, $id_interno, $item);

            # Obtener plataformas vinculadas
            $platStmt = $db->prepare("
                SELECT id_externo, origen FROM platform_customers
                WHERE mypos_id = ? AND id_interno = ?
            ");
            $platStmt->bind_param('si', $myposId, $id_interno);
            $platStmt->execute();
            $platRows = $platStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $platStmt->close();

            $platform_results = [];
            $isPred = (bool)($item['predeterminada'] ?? false);

            foreach ($platRows as $platRow) {
                $origen = $platRow['origen'];
                $id_externo = $platRow['id_externo'];

                if (!isset($this->providersByName[$origen])) continue;

                $provider = $this->providersByName[$origen];

                if ($isPred) {
                    $response = $provider->syncDefaultAddress($id_externo, $item);
                } elseif ($provider->supportsMultipleAddresses()) {
                    $response = $provider->addAddress($id_externo, $item);
                } else {
                    continue;
                }

                $platform_results[$origen] = ($response['status'] ?? '') === 'error' ? $response : ['status' => 'ok'];
            }

            $created[] = [
                'address_id' => $newAddressId,
                'id_interno' => $id_interno,
                'status' => 'ok',
                'platforms' => $platform_results
            ];
        }

        return [
            'action' => 'createAddress',
            'created' => $created
        ];
    }

    public function updateAddress(array $data, array $session = []): array
    {
        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';
        $updated = [];

        foreach ($data as $item) {
            $addressId = $item['address_id'] ?? null;
            $id_interno = $item['id_interno'] ?? null;

            if (!$addressId || !$id_interno) {
                $updated[] = [
                    'status' => 'error',
                    'code' => $this->codeStr . '422',
                    'answer' => 'Los campos address_id e id_interno son obligatorios'
                ];
                continue;
            }

            $chkStmt = $db->prepare("
                SELECT id FROM address
                WHERE id = ? AND customer_id = ? AND mypos_id = ?
                LIMIT 1
            ");
            $chkStmt->bind_param('iis', $addressId, $id_interno, $myposId);
            $chkStmt->execute();
            $chkStmt->store_result();

            if ($chkStmt->num_rows === 0) {
                $chkStmt->close();
                $updated[] = [
                    'address_id' => $addressId,
                    'status' => 'error',
                    'code' => $this->codeStr . '404',
                    'answer' => 'Dirección no encontrada'
                ];
                continue;
            }
            $chkStmt->close();

            # Construir SET dinámico
            $fields = ['calle', 'no_ext', 'no_int', 'colonia', 'cp', 'municipio', 'estado', 'ciudad', 'pais', 'referencias'];
            $setClauses = [];
            $setTypes = '';
            $setParams = [];

            foreach ($fields as $field) {
                if (array_key_exists($field, $item)) {
                    $setClauses[] = "{$field} = ?";
                    $setTypes .= 's';
                    $setParams[] = $item[$field];
                }
            }

            if (array_key_exists('gps', $item)) {
                $setClauses[] = 'latitud = ?';
                $setClauses[] = 'longitud = ?';
                $setTypes .= 'dd';
                $setParams[] = isset($item['gps']['latitud']) ? (float)$item['gps']['latitud'] : null;
                $setParams[] = isset($item['gps']['longitud']) ? (float)$item['gps']['longitud'] : null;
            }

            $isDefaultChange = array_key_exists('predeterminada', $item);
            $newPred = ($item['predeterminada'] ?? false) ? 1 : 0;

            if ($isDefaultChange) {
                if ($newPred) {
                    $clrStmt = $db->prepare("UPDATE address SET predeterminada = 0 WHERE mypos_id = ? AND customer_id = ?");
                    $clrStmt->bind_param('si', $myposId, $id_interno);
                    $clrStmt->execute();
                    $clrStmt->close();
                }
                $setClauses[] = 'predeterminada = ?';
                $setTypes .= 'i';
                $setParams[] = $newPred;
            }

            if (!empty($setClauses)) {
                $setClauses[] = 'updated_at = CURRENT_TIMESTAMP';
                $setTypes .= 'iis';
                $setParams[] = $addressId;
                $setParams[] = $id_interno;
                $setParams[] = $myposId;

                $sql = 'UPDATE address SET ' . implode(', ', $setClauses) . ' WHERE id = ? AND customer_id = ? AND mypos_id = ?';
                $updStmt = $db->prepare($sql);
                $updStmt->bind_param($setTypes, ...$setParams);

                if (!$updStmt->execute()) {
                    $updStmt->close();
                    $updated[] = [
                        'address_id' => $addressId,
                        'status' => 'error',
                        'code' => $this->codeStr . '500',
                        'answer' => 'Error al actualizar la dirección'
                    ];
                    continue;
                }
                $updStmt->close();
            }

            # Obtener estado actual de la dirección
            $addrStmt = $db->prepare("
                SELECT calle, no_ext, no_int, colonia, cp,
                       municipio, estado, ciudad, pais, referencias,
                       latitud, longitud, predeterminada
                FROM address WHERE id = ? LIMIT 1
            ");
            $addrStmt->bind_param('i', $addressId);
            $addrStmt->execute();
            $addrData = $addrStmt->get_result()->fetch_assoc();
            $addrStmt->close();

            $platform_results = [];

            if ($addrData) {
                $addrData['gps'] = [
                    'latitud' => $addrData['latitud'],
                    'longitud' => $addrData['longitud'],
                ];

                $isCurrentlyDefault = (bool)$addrData['predeterminada'];

                $platStmt = $db->prepare("
                    SELECT id_externo, origen FROM platform_customers
                    WHERE mypos_id = ? AND id_interno = ?
                ");
                $platStmt->bind_param('si', $myposId, $id_interno);
                $platStmt->execute();
                $platRows = $platStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $platStmt->close();

                foreach ($platRows as $platRow) {
                    $origen = $platRow['origen'];
                    $id_externo = $platRow['id_externo'];

                    if (!isset($this->providersByName[$origen])) continue;

                    $provider = $this->providersByName[$origen];

                    if ($isCurrentlyDefault) {
                        $response = $provider->syncDefaultAddress($id_externo, $addrData);
                    } elseif ($provider->supportsMultipleAddresses()) {
                        $response = $provider->addAddress($id_externo, $addrData);
                    } else {
                        continue;
                    }

                    $platform_results[$origen] = ($response['status'] ?? '') === 'error' ? $response : ['status' => 'ok'];
                }
            }

            $updated[] = [
                'address_id' => $addressId,
                'id_interno' => $id_interno,
                'status' => 'ok',
                'platforms' => $platform_results
            ];
        }

        return [
            'action' => 'updateAddress',
            'updated' => $updated
        ];
    }

    private function getDefaultAddress(array $direcciones): ?array
    {
        foreach ($direcciones as $dir) {
            if ((int)$dir['predeterminada'] === 1) {
                return $dir;
            }
        }
        return null;
    }

    public function pushCustomers(array $session = [], array $platforms = []): array
    {
        if (count($platforms) > 1) {
            return [
                'action' => 'pushCustomers',
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'En pushCustomers solo se permite una plataforma en platforms[]'
            ];
        }

        $providers = $this->filterProviders($platforms);

        if (empty($providers)) {
            return [
                'action' => 'pushCustomers',
                'status' => 'error',
                'answer' => 'No se encontraron plataformas válidas'
            ];
        }

        $db = (new Database())->connect();
        $myposId = $session['mypos_id'] ?? '';

        $stmt = $db->prepare("
            SELECT
                c.id        AS id_interno,
                c.nombre,
                c.email,
                c.telefono,
                c.movil,
                c.lada,
                c.rfc,
                c.notas,
                c.prospecto,
                c.created_at
            FROM customers c
            WHERE c.mypos_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM platform_customers pc
                WHERE pc.mypos_id = c.mypos_id
                AND pc.id_interno = c.id
            )
        ");
        $stmt->bind_param('s', $myposId);
        $stmt->execute();
        $result = $stmt->get_result();

        $unlinked = [];
        while ($row = $result->fetch_assoc()) {
            $unlinked[] = $row;
        }
        $stmt->close();

        if (empty($unlinked)) {
            return [
                'action' => 'pushCustomers',
                'pushed' => [],
                'answer' => 'Todos los clientes ya están vinculados a al menos una plataforma'
            ];
        }

        $ids = array_column($unlinked, 'id_interno');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = 's' . str_repeat('i', count($ids));
        $params = array_merge([$myposId], $ids);

        $addrStmt = $db->prepare("
            SELECT
                customer_id,
                calle, no_ext, no_int, colonia, cp,
                municipio, estado, ciudad, pais, referencias,
                latitud, longitud, predeterminada
            FROM address
            WHERE mypos_id = ? AND customer_id IN ({$placeholders})
            ORDER BY customer_id ASC, predeterminada DESC, id ASC
        ");
        $addrStmt->bind_param($types, ...$params);
        $addrStmt->execute();
        $addrResult = $addrStmt->get_result();

        $addressMap = [];
        while ($addr = $addrResult->fetch_assoc()) {
            $cid = $addr['customer_id'];
            $addressMap[$cid][] = [
                'calle' => $addr['calle'],
                'no_ext' => $addr['no_ext'],
                'no_int' => $addr['no_int'],
                'colonia' => $addr['colonia'],
                'cp' => $addr['cp'],
                'municipio' => $addr['municipio'],
                'estado' => $addr['estado'],
                'ciudad' => $addr['ciudad'],
                'pais' => $addr['pais'],
                'referencias' => $addr['referencias'],
                'gps' => [
                    'latitud' => $addr['latitud'] !== null ? (float)$addr['latitud'] : null,
                    'longitud' => $addr['longitud'] !== null ? (float)$addr['longitud'] : null,
                ],
                'predeterminada' => (bool)$addr['predeterminada'],
            ];
        }
        $addrStmt->close();

        $pushed = [];

        foreach ($unlinked as $customer) {
            $id_interno = (int)$customer['id_interno'];
            $direcciones = $addressMap[$id_interno] ?? [];
            $defaultAddr = $this->getDefaultAddress($direcciones);

            $customer['direcciones'] = $direcciones;
            $customer['default_address'] = $defaultAddr;

            $platform_results = [];

            foreach ($providers as $provider) {
                $origen = $provider->getName();

                $externalCustomers = $provider->createCustomer($customer);

                if (empty($externalCustomers) || ($externalCustomers['status'] ?? '') === 'error') {
                    $platform_results[$origen] = [
                        'status' => 'error',
                        'code' => $externalCustomers['code'] ?? $this->codeStr . '502',
                        'answer' => $externalCustomers['answer'] ?? 'Sin respuesta de la plataforma'
                    ];
                    continue;
                }

                foreach ($externalCustomers as $ext) {
                    $id_externo = $ext['id_externo'] ?? null;
                    if (!$id_externo) continue;

                    $insStmt = $db->prepare("
                        INSERT IGNORE INTO platform_customers
                        (mypos_id, id_interno, id_externo, origen)
                        VALUES (?, ?, ?, ?)
                    ");
                    $insStmt->bind_param('siss', $myposId, $id_interno, $id_externo, $origen);
                    $insStmt->execute();
                    $insStmt->close();

                    foreach ($direcciones as $dir) {
                        if (!empty($dir['predeterminada'])) {
                            continue;
                        }
                        $provider->addAddress($id_externo, $dir);
                    }
                }

                $platform_results[$origen] = ['status' => 'ok'];
            }

            $pushed[] = [
                'id_interno' => $id_interno,
                'nombre' => $customer['nombre'],
                'status' => 'ok',
                'platforms' => $platform_results
            ];
        }

        return [
            'action' => 'pushCustomers',
            'total' => count($pushed),
            'pushed' => $pushed
        ];
    }
}
