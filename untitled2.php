<?php
// Month detail view — pemasukan & pengeluaran terpisah + catatan per hari
$dbFile = __DIR__ . '/data.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// set server timezone
date_default_timezone_set('Asia/Jakarta');

function day_name_id($datetime) {
    $days = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $d = date('l', strtotime($datetime));
    return $days[$d] ?? $d;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

// Handle add note (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $note_date_raw = $_POST['note_date'] ?? '';
    $note_text = trim($_POST['note_text'] ?? '');
    if ($note_date_raw !== '' && $note_text !== '') {
        $nd = date('Y-m-d', strtotime($note_date_raw));
        $ny = (int)date('Y', strtotime($nd));
        $nm = (int)date('n', strtotime($nd));
        $nday = (int)date('j', strtotime($nd));
        $created = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO notes (note_date,year,month,day,text,created_at) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$nd, $ny, $nm, $nday, $note_text, $created]);
    }
    header('Location: ?year=' . $year . '&month=' . $month);
    exit;
}

// Handle delete note
if (isset($_GET['delete_note'])) {
    $nid = (int)$_GET['delete_note'];
    $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ?");
    $stmt->execute([$nid]);
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

// Fetch notes for the month, group by day
$stmt = $pdo->prepare("SELECT * FROM notes WHERE year = ? AND month = ? ORDER BY note_date DESC, created_at DESC");
$stmt->execute([$year, $month]);
$notesAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
$notesByDay = [];
foreach ($notesAll as $n) {
    $d = (int)$n['day'];
    if (!isset($notesByDay[$d])) $notesByDay[$d] = [];
    $notesByDay[$d][] = $n;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transaksi Bulan <?= htmlspecialchars($month) ?> - <?= htmlspecialchars($months[$month-1] ?? '') ?> <?= htmlspecialchars($year) ?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;max-width:1000px;margin:20px auto;padding:0 15px;color:#222}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px;border:1px solid #ddd;text-align:left}
.actions a{color:#06c;text-decoration:none;margin-right:6px}
.summary{display:flex;gap:12px;margin-bottom:10px}
.summary div{padding:8px;border:1px solid #ddd;background:#fafafa}
.section{margin-bottom:20px}
.note-box{border:1px solid #ddd;padding:8px;margin-bottom:8px;background:#fff}
.note-meta{color:#666;font-size:12px;margin-bottom:6px}
</style>
</head>
<body>
<h1>Transaksi Bulan <?= $month ?> - <?= $months[$month-1] ?? '' ?> <?= $year ?></h1>

<div class="summary">
    <div>Total Masuk: <?= $total_in ?></div>
    <div>Total Keluar: <?= $total_out ?></div>
    <div>Selisih: <?= $total_in - $total_out ?></div>
</div>

<!-- Form tambah catatan -->
<div class="section">
    <h2>Tambah Catatan Harian</h2>
    <form method="post" style="max-width:520px">
        <input type="hidden" name="action" value="add_note">
        <label>Tanggal</label>
        <input type="date" name="note_date" value="<?= htmlspecialchars(sprintf('%04d-%02d-%02d', $year, $month, 1)) ?>" required>
        <label>Catatan</label>
        <textarea name="note_text" rows="3" style="width:100%" required></textarea>
        <div style="margin-top:8px"><button type="submit">Simpan Catatan</button></div>
    </form>
</div>

<!-- Daftar catatan per hari -->
<div class="section">
    <h2>Catatan Bulanan (<?= $months[$month-1] ?? '' ?> <?= $year ?>)</h2>
    <?php if (empty($notesByDay)): ?>
        <p>Tidak ada catatan untuk bulan ini.</p>
    <?php else: ?>
        <?php ksort($notesByDay); foreach($notesByDay as $day => $notes): ?>
            <div style="margin-bottom:12px">
                <h3><?= $day ?> <?= $months[$month-1] ?? '' ?> <?= $year ?></h3>
                <?php foreach($notes as $n): ?>
                    <div class="note-box">
                        <div class="note-meta">Waktu: <?= htmlspecialchars($n['created_at']) ?> — ID: <?= (int)$n['id'] ?></div>
                        <div><?= nl2br(htmlspecialchars($n['text'])) ?></div>
                        <div style="margin-top:6px"><a href="?year=<?= $year ?>&month=<?= $month ?>&delete_note=<?= $n['id'] ?>" onclick="return confirm('Hapus catatan ini?')">Hapus</a></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="section">
    <h2>Pemasukan (Masuk)</h2>
    <table>
        <thead><tr><th>#</th><th>Tanggal</th><th>Hari</th><th>Waktu</th><th>Barang</th><th>Qty</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (empty($ins)): ?>
            <tr><td colspan="7">Tidak ada pemasukan.</td></tr>
        <?php else: foreach($ins as $t): ?>
            <tr>
                <td><?= $t['id'] ?></td>
                <td><?= date('Y-m-d H:i', strtotime($t['dt'])) ?></td>
                <td><?= htmlspecialchars($t['day']) ?></td>
                <td><?= date('H:i:s', strtotime($t['dt'])) ?></td>
                <td><?= htmlspecialchars($t['item']) ?></td>
                <td><?= $t['qty'] ?></td>
                <td class="actions">
                    <a href="index.php?edit=<?= $t['id'] ?>">Edit</a>
                    <a href="?year=<?= $year ?>&month=<?= $month ?>&delete=<?= $t['id'] ?>" onclick="return confirm('Hapus transaksi?')">Hapus</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <h2>Pengeluaran (Keluar)</h2>
    <table>
        <thead><tr><th>#</th><th>Tanggal</th><th>Hari</th><th>Waktu</th><th>Barang</th><th>Qty</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (empty($outs)): ?>
            <tr><td colspan="7">Tidak ada pengeluaran.</td></tr>
        <?php else: foreach($outs as $t): ?>
            <tr>
                <td><?= $t['id'] ?></td>
                <td><?= date('Y-m-d H:i', strtotime($t['dt'])) ?></td>
                <td><?= htmlspecialchars($t['day']) ?></td>
                <td><?= date('H:i:s', strtotime($t['dt'])) ?></td>
                <td><?= htmlspecialchars($t['item']) ?></td>
                <td><?= $t['qty'] ?></td>
                <td class="actions">
                    <a href="index.php?edit=<?= $t['id'] ?>">Edit</a>
                    <a href="?year=<?= $year ?>&month=<?= $month ?>&delete=<?= $t['id'] ?>" onclick="return confirm('Hapus transaksi?')">Hapus</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<p><a href="index.php">Kembali ke ringkasan</a></p>
</body>
</html>
