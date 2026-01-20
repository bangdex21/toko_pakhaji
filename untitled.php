<?php
// Month detail view — pemasukan & pengeluaran terpisah
$dbFile = __DIR__ . '/data.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// set server timezone agar waktu mengikuti jam yang sekarang
date_default_timezone_set('Asia/Jakarta');

function day_name_id($datetime) {
    $days = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $d = date('l', strtotime($datetime));
    return $days[$d] ?? $d;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: ?year=' . $year . '&month=' . $month);
    exit;
}

// Fetch transactions for the month
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE month = ? AND year = ? ORDER BY dt DESC");
$stmt->execute([$month, $year]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Split and totals
$ins = array_values(array_filter($transactions, function($t){ return $t['type'] === 'in'; }));
$outs = array_values(array_filter($transactions, function($t){ return $t['type'] === 'out'; }));
$total_in = array_sum(array_map(function($t){ return (int)$t['qty']; }, $ins));
$total_out = array_sum(array_map(function($t){ return (int)$t['qty']; }, $outs));
$months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

// current server timestamp for JS sync
$server_now_ts = time();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transaksi Bulan <?= htmlspecialchars($month) ?> - <?= htmlspecialchars($months[$month-1] ?? '') ?> <?= htmlspecialchars($year) ?></title>
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
    h1 { text-align: center; color: #111827; margin-bottom: 1rem; font-size: 1.8rem; font-weight: 700; }
    h2 { font-size: 1.25rem; color: #374151; margin-bottom: 1rem; border-left: 4px solid var(--primary); padding-left: 10px; }
    
    .card { background: var(--card); border-radius: 10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 24px; margin-bottom: 24px; border: 1px solid var(--border); }
    
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
    
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 24px; }
    .stat-card { background: white; padding: 20px; border-radius: 10px; border: 1px solid var(--border); box-shadow: 0 2px 4px rgba(0,0,0,0.05); text-align: center; }
    .stat-label { color: #6b7280; font-size: 0.9rem; font-weight: 500; }
    .stat-value { font-size: 1.5rem; font-weight: 700; margin-top: 5px; }
    
    .server-clock { text-align: center; color: #6b7280; font-size: 0.9rem; margin-bottom: 20px; }
    .back-btn { display: inline-block; background-color: #9ca3af; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; margin-top: 20px; }
    .back-btn:hover { background-color: #6b7280; }
    
    .text-center { text-align: center; }
</style>
</head>
<body>
<div class="container">
<h1>Transaksi Bulan <?= $month ?> - <?= $months[$month-1] ?? '' ?> <?= $year ?></h1>

<!-- Server clock (auto-update) -->
<div class="server-clock">Waktu Server: <span id="server-clock"></span></div>

<div class="summary-grid">
    <div class="stat-card" style="border-top: 4px solid var(--success)">
        <div class="stat-label">Total Masuk</div>
        <div class="stat-value" style="color: var(--success)"><?= $total_in ?></div>
    </div>
    <div class="stat-card" style="border-top: 4px solid var(--danger)">
        <div class="stat-label">Total Keluar</div>
        <div class="stat-value" style="color: var(--danger)"><?= $total_out ?></div>
    </div>
    <div class="stat-card" style="border-top: 4px solid var(--primary)">
        <div class="stat-label">Selisih</div>
        <div class="stat-value"><?= $total_in - $total_out ?></div>
    </div>
</div>

<div class="card">
    <h2 style="color:var(--success); border-color:var(--success)">Pemasukan (Masuk)</h2>
    <div class="table-wrapper">
    <table>
        <thead><tr><th>Tgl</th><th>Barang</th><th>Qty</th><th>Harga Satuan</th><th>Total</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (empty($ins)): ?>
            <tr><td colspan="6" class="text-center">Tidak ada pemasukan.</td></tr>
        <?php else: foreach($ins as $t): ?>
            <?php
                // gunakan waktu dari dt jika ada, jika tidak fallback ke waktu server sekarang
                $rowTime = !empty($t['dt']) ? date('H:i:s', strtotime($t['dt'])) : date('H:i:s');
                $amount = (float)($t['amount'] ?? 0);
                $qty = (int)($t['qty'] ?? 0);
                $totalMoney = $amount * $qty;
            ?>
            <tr>
                <td>
                    <div><?= date('d/m/Y', strtotime($t['dt'])) ?></div>
                    <div style="font-size:0.75rem;color:#666"><?= $rowTime ?></div>
                </td>
                <td><?= htmlspecialchars($t['item']) ?></td>
                <td><?= $qty ?></td>
                <td><?= number_format($amount, 2) ?></td>
                <td><?= number_format($totalMoney, 2) ?></td>
                <td class="actions">
                    <a href="index.php?edit=<?= $t['id'] ?>" class="btn-edit" title="Edit">✏️ Edit</a>
                    <a href="?year=<?= $year ?>&month=<?= $month ?>&delete=<?= $t['id'] ?>" onclick="return confirm('Hapus transaksi?')" class="btn-delete" title="Hapus">🗑️ Hapus</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2 style="color:var(--danger); border-color:var(--danger)">Pengeluaran (Keluar)</h2>
    <div class="table-wrapper">
    <table>
        <thead><tr><th>Tgl</th><th>Barang</th><th>Qty</th><th>Harga Satuan</th><th>Total</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (empty($outs)): ?>
            <tr><td colspan="6" class="text-center">Tidak ada pengeluaran.</td></tr>
        <?php else: foreach($outs as $t): ?>
            <?php
                $rowTime = !empty($t['dt']) ? date('H:i:s', strtotime($t['dt'])) : date('H:i:s');
                $amount = (float)($t['amount'] ?? 0);
                $qty = (int)($t['qty'] ?? 0);
                $totalMoney = $amount * $qty;
            ?>
            <tr>
                <td>
                    <div><?= date('d/m/Y', strtotime($t['dt'])) ?></div>
                    <div style="font-size:0.75rem;color:#666"><?= $rowTime ?></div>
                </td>
                <td><?= htmlspecialchars($t['item']) ?></td>
                <td><?= $qty ?></td>
                <td><?= number_format($amount, 2) ?></td>
                <td><?= number_format($totalMoney, 2) ?></td>
                <td class="actions">
                    <a href="index.php?edit=<?= $t['id'] ?>" class="btn-edit" title="Edit">✏️ Edit</a>
                    <a href="?year=<?= $year ?>&month=<?= $month ?>&delete=<?= $t['id'] ?>" onclick="return confirm('Hapus transaksi?')" class="btn-delete" title="Hapus">🗑️ Hapus</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
     </table>
     </div>
 </div>
 
 <div class="text-center"><a href="index.php" class="back-btn">Kembali ke Ringkasan</a></div>
</div>
 
 <!-- JS: sync server clock and increment tiap detik -->
 <script>
 (function(){
     var ts = <?= (int)$server_now_ts ?> * 1000;
     function fmt(d){ return d.toLocaleTimeString('en-GB'); } // HH:MM:SS
     var el = document.getElementById('server-clock');
     function tick(){
         var d = new Date(ts);
         if(el) el.textContent = fmt(d);
         ts += 1000;
     }
     tick();
     setInterval(tick,1000);
 })();
 </script>
</body>
</html>
