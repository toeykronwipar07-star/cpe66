<?php
session_start();
require_once __DIR__ . '/inc/ConnDB.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$message = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];
$search = trim($_GET['q'] ?? '');
$editingProduct = null;

function redirectTo(string $query = ''): never
{
    header('Location: wk6_create2.php' . $query);
    exit;
}

function flash(string $type, string $text): never
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    redirectTo();
}

function productInput(): array
{
    return [
        'name' => trim($_POST['productName'] ?? ''),
        'supplier' => filter_var($_POST['supplier'] ?? null, FILTER_VALIDATE_INT),
        'category' => filter_var($_POST['category'] ?? null, FILTER_VALIDATE_INT),
        'unit' => trim($_POST['unit'] ?? ''),
        'price' => trim($_POST['price'] ?? ''),
    ];
}

function validateProduct(array $product): array
{
    $errors = [];
    if ($product['name'] === '') {
        $errors[] = 'กรุณาระบุชื่อสินค้า';
    } elseif (mb_strlen($product['name']) > 30) {
        $errors[] = 'ชื่อสินค้าต้องไม่เกิน 30 ตัวอักษร';
    }
    if ($product['supplier'] === false || $product['supplier'] < 1) {
        $errors[] = 'กรุณาระบุรหัสผู้จัดจำหน่ายเป็นจำนวนเต็มบวก';
    }
    if ($product['category'] === false || $product['category'] < 1) {
        $errors[] = 'กรุณาระบุรหัสหมวดหมู่เป็นจำนวนเต็มบวก';
    }
    if ($product['unit'] === '') {
        $errors[] = 'กรุณาระบุหน่วยนับ';
    } elseif (mb_strlen($product['unit']) > 30) {
        $errors[] = 'หน่วยนับต้องไม่เกิน 30 ตัวอักษร';
    }
    if ($product['price'] === '' || !is_numeric($product['price']) || (float) $product['price'] < 0) {
        $errors[] = 'กรุณาระบุราคาตั้งแต่ 0 ขึ้นไป';
    }
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $productId = filter_var($_POST['productId'] ?? null, FILTER_VALIDATE_INT);

    try {
        if ($action === 'delete') {
            if (!$productId || $productId < 1) {
                flash('danger', 'ไม่พบรหัสสินค้าที่ต้องการลบ');
            }
            $stmt = $conn->prepare('DELETE FROM tb_products WHERE i_ProductID = :id');
            $stmt->execute([':id' => $productId]);
            $deleted = $stmt->rowCount();
            flash($deleted ? 'success' : 'warning', $deleted ? 'ลบสินค้าเรียบร้อยแล้ว' : 'ไม่พบสินค้าที่ต้องการลบ');
        }

        if ($action === 'save') {
            $product = productInput();
            $errors = validateProduct($product);
            if (!$productId && ($_POST['productId'] ?? '') !== '') {
                $errors[] = 'รหัสสินค้าไม่ถูกต้อง';
            }
            if ($errors) {
                $editingProduct = array_merge($product, ['id' => $productId ?: '']);
            } elseif ($productId) {
                $stmt = $conn->prepare('UPDATE tb_products SET c_ProductName = :name, i_SupplierID = :supplier, i_CategoryID = :category, c_Unit = :unit, i_Price = :price WHERE i_ProductID = :id');
                $stmt->execute([
                    ':name' => $product['name'], ':supplier' => $product['supplier'],
                    ':category' => $product['category'], ':unit' => $product['unit'],
                    ':price' => $product['price'], ':id' => $productId,
                ]);
                flash('success', 'แก้ไขข้อมูลสินค้าเรียบร้อยแล้ว');
            } else {
                $stmt = $conn->prepare('INSERT INTO tb_products (c_ProductName, i_SupplierID, i_CategoryID, c_Unit, i_Price) VALUES (:name, :supplier, :category, :unit, :price)');
                $stmt->execute([
                    ':name' => $product['name'], ':supplier' => $product['supplier'],
                    ':category' => $product['category'], ':unit' => $product['unit'],
                    ':price' => $product['price'],
                ]);
                flash('success', 'เพิ่มสินค้าเรียบร้อยแล้ว รหัสสินค้า #' . $conn->lastInsertId());
            }
        }
    } catch (PDOException $e) {
        $errors[] = 'ไม่สามารถบันทึกข้อมูลได้ กรุณาตรวจสอบรหัส Supplier และ Category';
        if ($action === 'save') {
            $editingProduct = array_merge(productInput(), ['id' => $productId ?: '']);
        }
    }
}

try {
    $suppliers = $conn->query('SELECT i_SupplierID AS id, c_SupplierName AS name FROM tb_suppliers ORDER BY c_SupplierName')->fetchAll(PDO::FETCH_ASSOC);
    $categories = $conn->query('SELECT i_CategoryID AS id, c_CategoryName AS name FROM tb_categories ORDER BY c_CategoryName')->fetchAll(PDO::FETCH_ASSOC);

    if (!$editingProduct && isset($_GET['edit'])) {
        $stmt = $conn->prepare('SELECT i_ProductID AS id, c_ProductName AS name, i_SupplierID AS supplier, i_CategoryID AS category, c_Unit AS unit, i_Price AS price FROM tb_products WHERE i_ProductID = :id');
        $stmt->execute([':id' => (int) $_GET['edit']]);
        $editingProduct = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$editingProduct) {
            $errors[] = 'ไม่พบสินค้าที่ต้องการแก้ไข';
        }
    }

    $listSql = 'SELECT p.i_ProductID AS id, p.c_ProductName AS name, p.i_SupplierID AS supplier, p.i_CategoryID AS category, p.c_Unit AS unit, p.i_Price AS price, s.c_SupplierName AS supplierName, c.c_CategoryName AS categoryName FROM tb_products p LEFT JOIN tb_suppliers s ON s.i_SupplierID = p.i_SupplierID LEFT JOIN tb_categories c ON c.i_CategoryID = p.i_CategoryID';
    $params = [];
    if ($search !== '') {
        $listSql .= ' WHERE p.c_ProductName LIKE :search OR CAST(p.i_ProductID AS CHAR) LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }
    $listSql .= ' ORDER BY p.i_ProductID DESC';
    $stmt = $conn->prepare($listSql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = 'ไม่สามารถโหลดข้อมูลจากฐานข้อมูลได้';
    $suppliers = $categories = $products = [];
}

$form = $editingProduct ?: ['id' => '', 'name' => '', 'supplier' => '', 'category' => '', 'unit' => '', 'price' => ''];
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>จัดการข้อมูลสินค้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #172331; --blue: #1769aa; --line: #dce6eb; }
        body { font-family: Prompt, sans-serif; background: #f4f7f8; color: var(--ink); }
        .topbar { background: linear-gradient(110deg, #12344d, #1769aa); color: white; }
        .panel { border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 12px 28px rgba(21, 48, 67, .07); }
        .panel-title { color: #1769aa; font-weight: 600; }
        .table thead th { background: #eef5f6; color: #45606e; font-size: .85rem; white-space: nowrap; }
        .table td { vertical-align: middle; }
        .price { font-variant-numeric: tabular-nums; }
        .required { color: #cf3d48; }
    </style>
</head>
<body>
<header class="topbar py-4 mb-4">
    <div class="container d-flex justify-content-between align-items-center gap-3">
        <div><div class="small text-white-50">NORTHWIND INVENTORY</div><h1 class="h3 mb-0 fw-semibold"><i class="bi bi-box-seam me-2"></i>จัดการข้อมูลสินค้า</h1></div>
        <span class="badge rounded-pill bg-light text-primary px-3 py-2"><i class="bi bi-database-check me-1"></i>CRUD พร้อมใช้งาน</span>
    </div>
</header>
<main class="container pb-5">
    <?php if ($message): ?><div class="alert alert-<?= htmlspecialchars($message['type']) ?> alert-dismissible fade show" role="alert"><i class="bi bi-<?= $message['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i><?= htmlspecialchars($message['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><strong><i class="bi bi-exclamation-triangle me-2"></i>กรุณาตรวจสอบข้อมูล</strong><ul class="mb-0 mt-1"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="row g-4">
        <section class="col-xl-4">
            <div class="panel bg-white p-4 sticky-xl-top" style="top: 1rem;">
                <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 panel-title mb-0"><i class="bi bi-<?= $form['id'] ? 'pencil-square' : 'plus-circle' ?> me-2"></i><?= $form['id'] ? 'แก้ไขสินค้า #' . (int) $form['id'] : 'เพิ่มสินค้าใหม่' ?></h2><?php if ($form['id']): ?><a href="wk6_create2.php" class="btn btn-sm btn-outline-secondary">ยกเลิก</a><?php endif; ?></div>
                <form method="post" class="needs-validation" novalidate>
                    <input type="hidden" name="action" value="save"><input type="hidden" name="productId" value="<?= htmlspecialchars((string) $form['id']) ?>">
                    <div class="mb-3"><label class="form-label" for="productName">ชื่อสินค้า <span class="required">*</span></label><input class="form-control" id="productName" name="productName" maxlength="30" value="<?= htmlspecialchars((string) $form['name']) ?>" required><div class="invalid-feedback">กรุณาระบุชื่อสินค้า ไม่เกิน 30 ตัวอักษร</div></div>
                    <div class="row g-3"><div class="col-6"><label class="form-label" for="supplier">ผู้จัดจำหน่าย <span class="required">*</span></label><select class="form-select" id="supplier" name="supplier" required><option value="">เลือก Supplier</option><?php foreach ($suppliers as $item): ?><option value="<?= $item['id'] ?>" <?= (string) $form['supplier'] === (string) $item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option><?php endforeach; ?></select><div class="invalid-feedback">กรุณาเลือก Supplier</div></div>
                    <div class="col-6"><label class="form-label" for="category">หมวดหมู่ <span class="required">*</span></label><select class="form-select" id="category" name="category" required><option value="">เลือก Category</option><?php foreach ($categories as $item): ?><option value="<?= $item['id'] ?>" <?= (string) $form['category'] === (string) $item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option><?php endforeach; ?></select><div class="invalid-feedback">กรุณาเลือก Category</div></div></div>
                    <div class="row g-3 mt-0"><div class="col-7"><label class="form-label" for="unit">หน่วยนับ <span class="required">*</span></label><input class="form-control" id="unit" name="unit" maxlength="30" value="<?= htmlspecialchars((string) $form['unit']) ?>" required><div class="invalid-feedback">กรุณาระบุหน่วยนับ</div></div><div class="col-5"><label class="form-label" for="price">ราคา (บาท) <span class="required">*</span></label><input class="form-control" id="price" name="price" type="number" min="0" step="0.01" value="<?= htmlspecialchars((string) $form['price']) ?>" required><div class="invalid-feedback">ราคาต้องไม่ติดลบ</div></div></div>
                    <button class="btn btn-primary w-100 mt-4" type="submit"><i class="bi bi-check2-circle me-2"></i><?= $form['id'] ? 'บันทึกการแก้ไข' : 'เพิ่มสินค้า' ?></button>
                </form>
            </div>
        </section>
        <section class="col-xl-8"><div class="panel bg-white p-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h5 panel-title mb-1"><i class="bi bi-list-ul me-2"></i>รายการสินค้า</h2><span class="small text-secondary">พบ <?= count($products) ?> รายการ</span></div><form class="d-flex" method="get"><input class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาชื่อหรือรหัสสินค้า" aria-label="ค้นหาสินค้า"><button class="btn btn-dark ms-2" type="submit" title="ค้นหา"><i class="bi bi-search"></i></button><?php if ($search): ?><a class="btn btn-outline-secondary ms-2" href="wk6_create2.php" title="ล้างการค้นหา"><i class="bi bi-x-lg"></i></a><?php endif; ?></form></div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>รหัส</th><th>สินค้า</th><th>Supplier</th><th>หมวดหมู่</th><th>หน่วย</th><th class="text-end">ราคา</th><th class="text-end">จัดการ</th></tr></thead><tbody><?php if (!$products): ?><tr><td colspan="7" class="text-center text-secondary py-5"><i class="bi bi-inbox display-6 d-block mb-2"></i>ไม่พบข้อมูลสินค้า</td></tr><?php endif; ?><?php foreach ($products as $product): ?><tr><td><span class="badge text-bg-light">#<?= $product['id'] ?></span></td><td class="fw-semibold"><?= htmlspecialchars($product['name']) ?></td><td class="small"><?= htmlspecialchars($product['supplierName'] ?? ('รหัส ' . $product['supplier'])) ?></td><td class="small"><?= htmlspecialchars($product['categoryName'] ?? ('รหัส ' . $product['category'])) ?></td><td class="small text-secondary"><?= htmlspecialchars($product['unit']) ?></td><td class="text-end price"><?= number_format((float) $product['price'], 2) ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="?edit=<?= $product['id'] ?>" title="แก้ไข"><i class="bi bi-pencil"></i></a><form class="d-inline" method="post" onsubmit="return confirm('ยืนยันการลบสินค้านี้หรือไม่?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="productId" value="<?= $product['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit" title="ลบ"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div>
        </div></section>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script>document.querySelectorAll('.needs-validation').forEach(form => form.addEventListener('submit', event => { if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); } form.classList.add('was-validated'); }));</script>
</body>
</html>
