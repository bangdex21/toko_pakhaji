<?php
// Simple inventory app: pemasokan (in) & pengeluaran (out) with full date/time/day/month/year summaries

// Setup SQLite DB in same folder
$dbFile = __DIR__ . '/data.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// set server timezone and server timestamp for defaults
date_default_timezone_set('Asia/Jakarta');
$server_now_ts = time();

// Create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL, -- 'in' or 'out'
    item TEXT NOT NULL,
    qty INTEGER NOT NULL,
    dt TEXT NOT NULL, -- ISO datetime
    day TEXT NOT NULL,
    month INTEGER NOT NULL,
    year INTEGER NOT NULL,
    time TEXT NOT NULL
)");

// ===== ADDED: ensure 'amount' column exists =====
$cols = $pdo->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_ASSOC);
$hasAmount = false;
foreach ($cols as $c) { if ($c['name'] === 'amount') { $hasAmount = true; break; } }
if (!$hasAmount) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN amount REAL NOT NULL DEFAULT 0");
}
// ===== end added =====

// Helper: Indonesian day names
function day_name_id($datetime) {
    $days = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $d = date('l', strtotime($datetime));
    return $days[$d] ?? $d;
}

// Handle create (use server time fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $type = ($_POST['type'] === 'out') ? 'out' : 'in';
    $item = trim($_POST['item'] ?? '');
    $qty = max(0, (int)($_POST['qty'] ?? 0));
    $amount = (float)($_POST['amount'] ?? 0);
    // tanggal dari input (jika ada), waktu mengikuti server saat ini
    $dt_input = $_POST['dt'] ?? date('Y-m-d\TH:i', $server_now_ts);
    $date_part = date('Y-m-d', strtotime($dt_input));
    $time_part = date('H:i:s', $server_now_ts);
    $dt_iso = $date_part . ' ' . $time_part;
    $day = day_name_id($dt_iso);
    $month = (int)date('n', strtotime($dt_iso));
    $year = (int)date('Y', strtotime($dt_iso));
    $time = date('H:i:s', strtotime($dt_iso));
    if ($item !== '' && $qty > 0) {
        $stmt = $pdo->prepare("INSERT INTO transactions (type,item,qty,amount,dt,day,month,year,time) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$type,$item,$qty,$amount,$dt_iso,$day,$month,$year,$time]);
    }
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ====== ADDED: load edit record (GET) and handle update (POST) ======
$editTransaction = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$eid]);
    $editTransaction = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $type = ($_POST['type'] === 'out') ? 'out' : 'in';
    $item = trim($_POST['item'] ?? '');
    $qty = max(0, (int)($_POST['qty'] ?? 0));
    $amount = (float)($_POST['amount'] ?? 0);
    // tanggal dari input (jika ada), waktu mengikuti server saat ini
    $dt_input = $_POST['dt'] ?? date('Y-m-d\TH:i', $server_now_ts);
    $date_part = date('Y-m-d', strtotime($dt_input));
    $time_part = date('H:i:s', $server_now_ts);
    $dt_iso = $date_part . ' ' . $time_part;
    $day = day_name_id($dt_iso);
    $month = (int)date('n', strtotime($dt_iso));
    $year = (int)date('Y', strtotime($dt_input));
    $time = date('H:i:s', strtotime($dt_iso));
    if ($id > 0 && $item !== '' && $qty > 0) {
        $stmt = $pdo->prepare("UPDATE transactions SET type=?, item=?, qty=?, amount=?, dt=?, day=?, month=?, year=?, time=? WHERE id=?");
        $stmt->execute([$type, $item, $qty, $amount, $dt_iso, $day, $month, $year, $time, $id]);
    }
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}
// ====== end added ======

// Select year and month for viewing
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$viewMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0; // 0 = all months list

// Get transactions for selected month (if month>0)
if ($viewMonth > 0) {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE month = ? AND year = ? ORDER BY dt DESC");
    $stmt->execute([$viewMonth, $year]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ins = array_values(array_filter($transactions, function($t){ return $t['type'] === 'in'; }));
    $outs = array_values(array_filter($transactions, function($t){ return $t['type'] === 'out'; }));
} else {
    $transactions = [];
    $ins = $outs = [];
}

// Monthly summaries for Jan-Dec
$monthly = [];
for ($m = 1; $m <= 12; $m++) {
    $stmt = $pdo->prepare("SELECT
        COUNT(DISTINCT item) AS total_items,
        SUM(CASE WHEN type='in' THEN qty ELSE 0 END) as total_in,
        SUM(CASE WHEN type='out' THEN qty ELSE 0 END) as total_out
        FROM transactions WHERE month = ? AND year = ?");
    $stmt->execute([$m,$year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $monthly[$m] = [
        'items' => (int)($row['total_items'] ?? 0),
        'in' => (int)($row['total_in'] ?? 0),
        'out' => (int)($row['total_out'] ?? 0),
        'net' => (int)(($row['total_in'] ?? 0) - ($row['total_out'] ?? 0))
    ];
}

// Simple HTML output
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplikasi Pemasokan & Pengeluaran Barang</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
            --bg: #f3f4f6;
            --card: #ffffff;
            --text: #1f2937;
            --border: #e5e7eb;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg); color: var(--text); margin: 0; padding: 20px; line-height: 1.5; }
        .container { max-width: 1100px; margin: 0 auto; }
        h1 { text-align: center; color: #111827; margin-bottom: 2rem; font-size: 1.8rem; font-weight: 700; }
        h2 { font-size: 1.25rem; color: #374151; margin-bottom: 1rem; border-left: 4px solid var(--primary); padding-left: 10px; }
        h3 { margin-top: 0; font-size: 1.1rem; color: #4b5563; }
        
        /* Cards */
        .card { background: var(--card); border-radius: 10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 24px; margin-bottom: 24px; border: 1px solid var(--border); }
        
        /* Forms */
        form { margin: 0; }
        label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 0.9rem; color: #4b5563; }
        input, select { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 16px; box-sizing: border-box; font-size: 0.95rem; transition: border-color 0.2s; }
        input:focus, select:focus { outline: none; border-color: var(--primary); ring: 2px solid var(--primary); }
        
        button { background-color: var(--primary); color: white; border: none; padding: 12px; border-radius: 6px; cursor: pointer; font-weight: 600; width: 100%; transition: background 0.2s; font-size: 0.95rem; }
        button:hover { background-color: var(--primary-dark); }
        
        .btn-cancel { display: inline-block; text-align: center; background-color: #9ca3af; color: white; padding: 12px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.95rem; width: 100%; box-sizing: border-box; }
        .btn-cancel:hover { background-color: #6b7280; }
        
        /* Grid Layouts */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
        
        /* Tables */
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.9rem; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background-color: #f9fafb; font-weight: 600; color: #374151; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; }
        tr:hover { background-color: #f9fafb; }
        
        .actions a { display: inline-block; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 0.8rem; font-weight: 500; margin-right: 4px; }
        .btn-edit { background-color: #dbeafe; color: var(--primary-dark); }
        .btn-edit:hover { background-color: #bfdbfe; }
        .btn-delete { background-color: #fee2e2; color: #991b1b; }
        .btn-delete:hover { background-color: #fecaca; }
        
        .text-center { text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <h1>Aplikasi Pemasokan & Pengeluaran Barang</h1>

    <!-- FORM: support add + edit -->
    <?php if ($editTransaction): ?>
        <div class="card">
        <form method="post">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= $editTransaction['id'] ?>">
            <h3>Edit Transaksi</h3>
            <label>Jenis</label>
            <select name="type" required>
                <option value="in" <?= $editTransaction['type'] === 'in' ? 'selected' : '' ?>>Pemasukan (Masuk)</option>
                <option value="out" <?= $editTransaction['type'] === 'out' ? 'selected' : '' ?>>Pengeluaran (Keluar)</option>
            </select>
            <label>Nama Barang</label>
            <input name="item" value="<?= htmlspecialchars($editTransaction['item']) ?>" required>
            <label>Jumlah</label>
            <input name="qty" type="number" min="1" value="<?= (int)$editTransaction['qty'] ?>" required>
            <label>Uang</label>
            <input name="amount" type="number" step="0.01" value="<?= isset($editTransaction['amount']) ? htmlspecialchars($editTransaction['amount']) : '0.00' ?>">
            <label>Tanggal & Waktu</label>
            <input name="dt" type="datetime-local" value="<?= date('Y-m-d\TH:i', strtotime($editTransaction['dt'])) ?>">
            <div class="grid-2" style="gap:10px; margin-top:8px">
                <button type="submit">Simpan Perubahan</button>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn-cancel">Batal</a>
            </div>
        </form>
        </div>
    <?php else: ?>
        <div class="grid-2">
            <div class="card" style="border-top: 4px solid var(--success);">
            <form method="post">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="type" value="in">
                <h3 style="color:var(--success)">Pemasokan (Masuk)</h3>
                <label>Nama Barang</label>
                <input name="item" placeholder="Contoh: Beras 5kg" required>
                <label>Jumlah</label>
                <input name="qty" type="number" min="1" value="1" required>
                <label>Uang</label>
                <input name="amount" type="number" step="0.01" value="0.00">
                <label>Tanggal & Waktu</label>
                <input name="dt" type="datetime-local" value="<?= date('Y-m-d\TH:i', $server_now_ts) ?>">
                <div style="margin-top:8px"><button type="submit" style="background-color:var(--success)">Simpan Pemasokan</button></div>
            </form>
            </div>

            <div class="card" style="border-top: 4px solid var(--danger);">
            <form method="post">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="type" value="out">
                <h3 style="color:var(--danger)">Pengeluaran (Keluar)</h3>
                <label>Nama Barang</label>
                <input name="item" placeholder="Contoh: Gula Pasir" required>
                <label>Jumlah</label>
                <input name="qty" type="number" min="1" value="1" required>
                <label>Uang</label>
                <input name="amount" type="number" step="0.01" value="0.00">
                <label>Tanggal & Waktu</label>
                <input name="dt" type="datetime-local" value="<?= date('Y-m-d\TH:i', $server_now_ts) ?>">
                <div style="margin-top:8px"><button type="submit" style="background-color:var(--danger)">Simpan Pengeluaran</button></div>
            </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h2 style="margin:0; border:none; padding:0;">Ringkasan Bulanan</h2>
        <form method="get" style="display:flex; gap:10px; align-items:center;">
            <label style="margin:0">Tahun:</label>
            <select name="year" onchange="this.form.submit()">
                <?php for($y = date('Y')-2; $y <= date('Y')+1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <select name="month" onchange="this.form.submit()">
                <option value="0" <?= $viewMonth==0?'selected':'' ?>>Tampilkan per bulan (Semua)</option>
                <?php
                $months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                for($m=1;$m<=12;$m++):
                ?>
                    <option value="<?= $m ?>" <?= $viewMonth==$m?'selected':'' ?>><?= $m ?> - <?= $months[$m-1] ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Bulan</th>
                <th>Total Masuk</th>
                <th>Total Keluar</th>
                <th>Selisih</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php for($m=1;$m<=12;$m++): ?>
                <tr>
                    <td><b><?= $months[$m-1] ?></b></td>
                    <td style="color:var(--success)"><?= $monthly[$m]['in'] ?></td>
                    <td style="color:var(--danger)"><?= $monthly[$m]['out'] ?></td>
                    <td style="font-weight:bold"><?= $monthly[$m]['net'] ?></td>
                    <td class="actions"><a href="untitled.php?year=<?= $year ?>&month=<?= $m ?>" class="btn-edit" style="background:var(--primary);color:white">Detail</a></td>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>
    </div>
    </div>

    <!-- tampilkan daftar pemasukan/pengeluaran jika pilih bulan (keberadaan tabel tetap di beranda) -->
    <?php if ($viewMonth > 0): ?>
        <h2 style="text-align:center; border:none; margin-top:30px">Transaksi Bulan <?= $months[$viewMonth-1] ?> <?= $year ?></h2>

        <div class="grid-2">
            <div class="card">
                <h3>Daftar Pemasukan</h3>
                <div class="table-wrapper">
                <table>
                    <thead><tr><th>Tgl</th><th>Barang</th><th>Qty</th><th>Uang</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php if (empty($ins)): ?>
                        <tr><td colspan="5" class="text-center">Tidak ada pemasukan.</td></tr>
                    <?php else: foreach($ins as $t): ?>
                        <tr>
                            <td>
                                <div><?= date('d/m', strtotime($t['dt'])) ?></div>
                                <div style="font-size:0.75rem;color:#666"><?= date('H:i', strtotime($t['dt'])) ?></div>
                            </td>
                            <td><?= htmlspecialchars($t['item']) ?></td>
                            <td><?= $t['qty'] ?></td>
                            <td><?= number_format((float)($t['amount'] ?? 0),2) ?></td>
                            <td class="actions">
                                <a href="?edit=<?= $t['id'] ?>" class="btn-edit">Edit</a>
                                <a href="?delete=<?= $t['id'] ?>" onclick="return confirm('Hapus transaksi?')" class="btn-delete">Hapus</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="card">
                <h3>Daftar Pengeluaran</h3>
                <div class="table-wrapper">
                <table>
                    <thead><tr><th>Tgl</th><th>Barang</th><th>Qty</th><th>Uang</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php if (empty($outs)): ?>
                        <tr><td colspan="5" class="text-center">Tidak ada pengeluaran.</td></tr>
                    <?php else: foreach($outs as $t): ?>
                        <tr>
                            <td>
                                <div><?= date('d/m', strtotime($t['dt'])) ?></div>
                                <div style="font-size:0.75rem;color:#666"><?= date('H:i', strtotime($t['dt'])) ?></div>
                            </td>
                            <td><?= htmlspecialchars($t['item']) ?></td>
                            <td><?= $t['qty'] ?></td>
                            <td><?= number_format((float)($t['amount'] ?? 0),2) ?></td>
                            <td class="actions">
                                <a href="?edit=<?= $t['id'] ?>" class="btn-edit">Edit</a>
                                <a href="?delete=<?= $t['id'] ?>" onclick="return confirm('Hapus transaksi?')" class="btn-delete">Hapus</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <footer style="margin-top:40px;color:#9ca3af;font-size:13px;text-align:center">
        &copy; <?= date('Y') ?> Aplikasi Inventaris Sederhana &mdash; DB: <?= basename($dbFile) ?>
    </footer>
</div>
</body>
</html>