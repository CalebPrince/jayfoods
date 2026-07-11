<?php

declare(strict_types=1);

/**
 * Admin CRUD for the product catalogue.
 *   GET    /api/v1/admin/products        index()
 *   POST   /api/v1/admin/products        create()
 *   PUT    /api/v1/admin/products/{id}   update()
 *   DELETE /api/v1/admin/products/{id}   destroy()
 *
 * All money arrives and leaves as integer pesewas.
 */
final class AdminProductController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function index(): void
    {
        $rows = $this->db->query(
            'SELECT id, sku, name, flavour, description, unit_label, image_url,
                    unit_price_pesewas, bulk_available, bulk_min_quantity,
                    bulk_price_pesewas, stock_quantity, is_active
               FROM products
           ORDER BY name ASC'
        )->fetchAll();

        Response::json(['data' => array_map([$this, 'present'], $rows)]);
    }

    public function create(): void
    {
        try {
            $data = $this->validate($this->body(), null);
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage(), 'fields' => ['image' => $e->getMessage()]], 422);
            return;
        }
        if (isset($data['__errors'])) {
            Response::json(['error' => 'Validation failed.', 'fields' => $data['__errors']], 422);
            return;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO products
                    (sku, name, flavour, description, unit_label, image_url, unit_price_pesewas,
                     bulk_available, bulk_min_quantity, bulk_price_pesewas,
                     stock_quantity, is_active)
                 VALUES
                    (:sku, :name, :flavour, :description, :unit_label, :image_url, :unit_price,
                     :bulk_available, :bulk_min_quantity, :bulk_price,
                     :stock_quantity, :is_active)'
            );
            $stmt->execute($this->bind($data));
        } catch (Throwable $e) {
            Response::json(['error' => 'Could not create product. Is the SKU unique?'], 409);
            return;
        }

        $id = (int) $this->db->lastInsertId();
        $this->saveSizes($id, $data['sizes']);
        Response::json(['data' => $this->find($id)], 201);
    }

    public function update(int $id): void
    {
        if (!$this->find($id)) {
            Response::json(['error' => 'Product not found.'], 404);
            return;
        }

        try {
            $data = $this->validate($this->body(), $id);
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage(), 'fields' => ['image' => $e->getMessage()]], 422);
            return;
        }
        if (isset($data['__errors'])) {
            Response::json(['error' => 'Validation failed.', 'fields' => $data['__errors']], 422);
            return;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE products SET
                    sku = :sku, name = :name, flavour = :flavour, description = :description,
                    unit_label = :unit_label, image_url = :image_url, unit_price_pesewas = :unit_price,
                    bulk_available = :bulk_available, bulk_min_quantity = :bulk_min_quantity,
                    bulk_price_pesewas = :bulk_price, stock_quantity = :stock_quantity,
                    is_active = :is_active, updated_at = datetime(\'now\')
                 WHERE id = :id'
            );
            $stmt->execute($this->bind($data) + [':id' => $id]);
        } catch (Throwable $e) {
            Response::json(['error' => 'Could not update product. Is the SKU unique?'], 409);
            return;
        }

        $this->saveSizes($id, $data['sizes']);

        Response::json(['data' => $this->find($id)]);
    }

    public function destroy(int $id): void
    {
        if (!$this->find($id)) {
            Response::json(['error' => 'Product not found.'], 404);
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);

        Response::json(['data' => ['ok' => true]]);
    }

    public function toggleBulk(int $id): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $enabled = !empty($input['bulk_available']) ? 1 : 0;
        $stmt = $this->db->prepare('UPDATE products SET bulk_available=:enabled, updated_at=datetime(\'now\') WHERE id=:id');
        $stmt->execute([':enabled'=>$enabled, ':id'=>$id]);
        if (!$this->find($id)) { Response::json(['error'=>'Product not found.'],404); return; }
        Response::json(['data'=>['bulk_available'=>(bool)$enabled]]);
    }

    // ---- helpers ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function body(): array
    {
        if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
            $input = $_POST;
            $input['image_url'] = trim((string) ($input['existing_image_url'] ?? ''));

            if (isset($_FILES['image']) && (int) $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $input['image_url'] = $this->storeImage($_FILES['image']);
            }

            return $input;
        }

        $input = json_decode((string) file_get_contents('php://input'), true);
        return is_array($input) ? $input : [];
    }

    /** @param array<string, mixed> $file */
    private function storeImage(array $file): string
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The image could not be uploaded.');
        }
        if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
            throw new RuntimeException('The image must be 5 MB or smaller.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        if (!isset($extensions[$mime])) {
            throw new RuntimeException('Choose a JPG, PNG, WebP or GIF image.');
        }

        $directory = dirname(__DIR__, 2) . '/public/uploads/products';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('The image upload folder could not be created.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $filename)) {
            throw new RuntimeException('The image could not be saved.');
        }

        return '/uploads/products/' . $filename;
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>  Normalised values, or ['__errors' => [...]].
     */
    private function validate(array $in, ?int $id): array
    {
        $errors = [];

        $name       = trim((string) ($in['name'] ?? ''));
        $sku        = trim((string) ($in['sku'] ?? ''));
        $flavour    = trim((string) ($in['flavour'] ?? ''));
        $unitLabel  = trim((string) ($in['unit_label'] ?? '')) ?: 'bottle';
        $imageUrl   = trim((string) ($in['image_url'] ?? ''));
        $desc       = trim((string) ($in['description'] ?? ''));
        $unitPrice  = (int) round((float) ($in['unit_price_pesewas'] ?? 0));
        $bulkAvail  = !empty($in['bulk_available']) ? 1 : 0;
        $bulkMin    = max(0, (int) ($in['bulk_min_quantity'] ?? 0));
        $bulkPrice  = isset($in['bulk_price_pesewas']) && $in['bulk_price_pesewas'] !== null && $in['bulk_price_pesewas'] !== ''
            ? (int) round((float) $in['bulk_price_pesewas'])
            : null;
        $stock      = max(0, (int) ($in['stock_quantity'] ?? 0));
        $active     = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1;
        $rawSizes   = json_decode((string) ($in['sizes'] ?? '[]'), true);
        $sizes      = [];
        if (is_array($rawSizes)) {
            foreach ($rawSizes as $size) {
                $label = trim((string) ($size['label'] ?? ''));
                $price = (int) ($size['unit_price_pesewas'] ?? 0);
                if ($label === '' && $price === 0) continue;
                if ($label === '' || $price <= 0) { $errors['sizes'] = 'Every bottle size needs a label and price.'; break; }
                $sizes[] = ['label'=>$label,'unit_price_pesewas'=>$price,'stock_quantity'=>max(0,(int)($size['stock_quantity']??0)),'bulk_min_quantity'=>max(0,(int)($size['bulk_min_quantity']??0)),'bulk_price_pesewas'=>!empty($size['bulk_price_pesewas'])?(int)$size['bulk_price_pesewas']:null];
            }
        }
        if (!$sizes) $sizes[] = ['label'=>$unitLabel,'unit_price_pesewas'=>$unitPrice,'stock_quantity'=>$stock,'bulk_min_quantity'=>$bulkMin,'bulk_price_pesewas'=>$bulkPrice];
        $first = $sizes[0]; $unitLabel=$first['label']; $unitPrice=$first['unit_price_pesewas']; $stock=$first['stock_quantity']; $bulkMin=$first['bulk_min_quantity']; $bulkPrice=$first['bulk_price_pesewas'];

        if ($name === '')       { $errors['name'] = 'Name is required.'; }
        if ($sku === '')        { $errors['sku'] = 'SKU is required.'; }
        if ($unitPrice <= 0)    { $errors['unit_price_pesewas'] = 'Unit price must be greater than zero.'; }
        if ($bulkAvail === 1) {
            if ($bulkMin <= 0)   { $errors['bulk_min_quantity'] = 'Set the minimum bulk quantity.'; }
            if ($bulkPrice === null || $bulkPrice <= 0) {
                $errors['bulk_price_pesewas'] = 'Set the bulk price per unit.';
            }
        }

        if ($errors) {
            return ['__errors' => $errors];
        }

        return [
            'sku'               => $sku,
            'name'              => $name,
            'flavour'           => $flavour,
            'description'       => $desc,
            'unit_label'        => $unitLabel,
            'image_url'         => $imageUrl,
            'unit_price'        => $unitPrice,
            'bulk_available'    => $bulkAvail,
            'bulk_min_quantity' => $bulkAvail ? $bulkMin : 0,
            'bulk_price'        => $bulkAvail ? $bulkPrice : null,
            'stock_quantity'    => $stock,
            'is_active'         => $active,
            'sizes'             => $sizes,
        ];
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function bind(array $d): array
    {
        return [
            ':sku'               => $d['sku'],
            ':name'              => $d['name'],
            ':flavour'           => $d['flavour'],
            ':description'       => $d['description'],
            ':unit_label'        => $d['unit_label'],
            ':image_url'         => $d['image_url'],
            ':unit_price'        => $d['unit_price'],
            ':bulk_available'    => $d['bulk_available'],
            ':bulk_min_quantity' => $d['bulk_min_quantity'],
            ':bulk_price'        => $d['bulk_price'],
            ':stock_quantity'    => $d['stock_quantity'],
            ':is_active'         => $d['is_active'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, sku, name, flavour, description, unit_label, image_url,
                    unit_price_pesewas, bulk_available, bulk_min_quantity,
                    bulk_price_pesewas, stock_quantity, is_active
               FROM products WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->present($row) : null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function present(array $r): array
    {
        $stmt = $this->db->prepare('SELECT id,label,unit_price_pesewas,stock_quantity,bulk_min_quantity,bulk_price_pesewas FROM product_sizes WHERE product_id=:id ORDER BY sort_order,id');
        $stmt->execute([':id'=>$r['id']]);
        return [
            'id'                 => (int) $r['id'],
            'sku'                => $r['sku'],
            'name'               => $r['name'],
            'flavour'            => $r['flavour'],
            'description'        => $r['description'],
            'unit_label'         => $r['unit_label'],
            'image_url'          => $r['image_url'],
            'unit_price_pesewas' => (int) $r['unit_price_pesewas'],
            'bulk_available'     => (bool) $r['bulk_available'],
            'bulk_min_quantity'  => (int) $r['bulk_min_quantity'],
            'bulk_price_pesewas' => $r['bulk_price_pesewas'] !== null ? (int) $r['bulk_price_pesewas'] : null,
            'stock_quantity'     => (int) $r['stock_quantity'],
            'is_active'          => (bool) $r['is_active'],
            'sizes'              => array_map(static fn(array $s)=>['id'=>(int)$s['id'],'label'=>$s['label'],'unit_price_pesewas'=>(int)$s['unit_price_pesewas'],'stock_quantity'=>(int)$s['stock_quantity'],'bulk_min_quantity'=>(int)$s['bulk_min_quantity'],'bulk_price_pesewas'=>$s['bulk_price_pesewas']!==null?(int)$s['bulk_price_pesewas']:null],$stmt->fetchAll()),
        ];
    }

    private function saveSizes(int $productId, array $sizes): void
    {
        $this->db->prepare('DELETE FROM product_sizes WHERE product_id=:id')->execute([':id'=>$productId]);
        $stmt=$this->db->prepare('INSERT INTO product_sizes(product_id,label,unit_price_pesewas,stock_quantity,bulk_min_quantity,bulk_price_pesewas,sort_order) VALUES(:product,:label,:price,:stock,:bulk_min,:bulk_price,:sort)');
        foreach($sizes as $i=>$s)$stmt->execute([':product'=>$productId,':label'=>$s['label'],':price'=>$s['unit_price_pesewas'],':stock'=>$s['stock_quantity'],':bulk_min'=>$s['bulk_min_quantity'],':bulk_price'=>$s['bulk_price_pesewas'],':sort'=>$i]);
    }
}
