<?php
/* ALABIBAS orders / tracking API.
   ?action=place   (POST, logged in)  create an order from the cart
   ?action=track   (POST)             email + order number -> order + timeline
   ?action=mine    (GET, logged in)   the logged-in customer's orders
   ?action=update  (POST, admin)      change status / tracking number

   >>> ADJUST THE 3 THINGS MARKED "MATCH YOUR auth_api.php" BELOW <<< */
session_start();
header('Content-Type: application/json; charset=utf-8');

/* MATCH YOUR auth_api.php (1/3): database login. Copy the same values it uses. */
$DB = ['host' => 'localhost', 'name' => 'YOUR_DB', 'user' => 'YOUR_USER', 'pass' => 'YOUR_PASS'];

/* MATCH YOUR auth_api.php (2/3): how the logged-in customer is stored in $_SESSION.
   Must return ['name'=>..., 'email'=>...] or null. */
function current_user() {
  if (!empty($_SESSION['user']['email'])) return $_SESSION['user'];
  if (!empty($_SESSION['email'])) return ['name' => $_SESSION['name'] ?? '', 'email' => $_SESSION['email']];
  return null;
}
/* MATCH YOUR auth_api.php (3/3): true when the admin is logged in. */
function is_admin() { return !empty($_SESSION['admin']); }

function out($code, $data) { http_response_code($code); echo json_encode($data); exit; }
function body() { $j = json_decode(file_get_contents('php://input'), true); return is_array($j) ? $j : []; }

try {
  $pdo = new PDO("mysql:host={$DB['host']};dbname={$DB['name']};charset=utf8mb4", $DB['user'], $DB['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Exception $e) { out(500, ['error' => 'Database unavailable.']); }

$LABELS = [
  'placed' => 'Order placed', 'processing' => 'Preparing your order', 'shipped' => 'Shipped',
  'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled',
];

function load_order($pdo, $where, $args) {
  $st = $pdo->prepare("SELECT * FROM orders WHERE $where LIMIT 1"); $st->execute($args);
  $o = $st->fetch(); if (!$o) return null;
  $i = $pdo->prepare("SELECT name,size,qty,price FROM order_items WHERE order_id=?"); $i->execute([$o['id']]);
  $e = $pdo->prepare("SELECT status,message,at FROM order_events WHERE order_id=? ORDER BY at,id"); $e->execute([$o['id']]);
  return [
    'order_number' => $o['order_number'], 'status' => $o['status'], 'total' => (float)$o['total'],
    'payment' => $o['payment'], 'courier' => $o['courier'], 'tracking_no' => $o['tracking_no'],
    'created_at' => $o['created_at'], 'items' => $i->fetchAll(), 'events' => $e->fetchAll(),
  ];
}
function add_event($pdo, $id, $status, $msg) {
  $pdo->prepare("INSERT INTO order_events (order_id,status,message) VALUES (?,?,?)")->execute([$id, $status, $msg]);
}

$action = $_GET['action'] ?? '';

if ($action === 'place') {
  $u = current_user(); if (!$u) out(401, ['error' => 'Please log in to place an order.']);
  $b = body(); $items = $b['items'] ?? [];
  if (!is_array($items) || !$items || count($items) > 50) out(400, ['error' => 'Your cart is empty.']);
  $total = 0; $clean = [];
  foreach ($items as $it) {
    $qty = (int)($it['qty'] ?? 0); $price = (float)($it['price'] ?? 0);
    if ($qty < 1 || $qty > 99 || $price <= 0) out(400, ['error' => 'Invalid cart item.']);
    $clean[] = [mb_substr((string)($it['name'] ?? ''), 0, 200), mb_substr((string)($it['size'] ?? ''), 0, 20), $qty, $price];
    $total += $qty * $price;
  }
  // NOTE: prices come from the browser. If you have a products table, look prices up here instead.
  $pay = mb_substr((string)($b['payment'] ?? ''), 0, 40); $note = mb_substr((string)($b['note'] ?? ''), 0, 500);
  $pdo->beginTransaction();
  try {
    $num = 'AB-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $pdo->prepare("INSERT INTO orders (order_number,user_email,customer_name,payment,note,total) VALUES (?,?,?,?,?,?)")
        ->execute([$num, strtolower($u['email']), $u['name'] ?? '', $pay, $note, $total]);
    $id = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO order_items (order_id,name,size,qty,price) VALUES (?,?,?,?,?)");
    foreach ($clean as $c) $ins->execute(array_merge([$id], $c));
    add_event($pdo, $id, 'placed', 'We received your order.');
    $pdo->commit();
  } catch (Exception $e) { $pdo->rollBack(); out(500, ['error' => 'Could not save your order.']); }
  out(200, ['order_number' => $num, 'total' => $total]);
}

if ($action === 'track') {
  $b = body(); $email = strtolower(trim((string)($b['email'] ?? ''))); $num = strtoupper(trim((string)($b['order'] ?? '')));
  if (!$email || !$num) out(400, ['error' => 'Enter your email and order number.']);
  $o = load_order($pdo, 'order_number=? AND user_email=?', [$num, $email]);
  if (!$o) out(404, ['error' => 'not_found']);   // same reply whether the number or email is wrong
  out(200, $o);
}

if ($action === 'mine') {
  $u = current_user(); if (!$u) out(401, ['error' => 'Please log in.']);
  $st = $pdo->prepare("SELECT order_number,status,total,created_at FROM orders WHERE user_email=? ORDER BY id DESC LIMIT 50");
  $st->execute([strtolower($u['email'])]); out(200, $st->fetchAll());
}

if ($action === 'update') {
  if (!is_admin()) out(403, ['error' => 'Admins only.']);
  $b = body(); $num = strtoupper(trim((string)($b['order'] ?? ''))); $status = (string)($b['status'] ?? '');
  if (!isset($LABELS[$status])) out(400, ['error' => 'Unknown status.']);
  $st = $pdo->prepare("SELECT id FROM orders WHERE order_number=?"); $st->execute([$num]); $row = $st->fetch();
  if (!$row) out(404, ['error' => 'Order not found.']);
  $pdo->prepare("UPDATE orders SET status=?, courier=COALESCE(NULLIF(?,''),courier), tracking_no=COALESCE(NULLIF(?,''),tracking_no) WHERE id=?")
      ->execute([$status, mb_substr((string)($b['courier'] ?? ''), 0, 60), mb_substr((string)($b['tracking_no'] ?? ''), 0, 60), $row['id']]);
  add_event($pdo, $row['id'], $status, mb_substr((string)($b['message'] ?? '') ?: $LABELS[$status], 0, 255));
  out(200, ['ok' => true]);
}

out(400, ['error' => 'Unknown action.']);
